<?php
/**
 * Confirmacion de compra: cuadrar lo que se pidio contra lo que si hubo.
 *
 * El resumen de "Ver productos" es la lista de lo que hay que ir a comprar a las
 * casas. Al volver, con el ticket en la mano, algunos productos no habia. Esto
 * sirve para quitarlos de las ventas donde se pidieron, sin tener que abrir una
 * por una y acordarse de cual era.
 *
 *   accion=lineas   Dados unos codigos de producto y el periodo, dice en que
 *                   ventas estan y cuantas piezas lleva cada una. Es el paso
 *                   intermedio: se ve a quien le afecta antes de tocar nada.
 *
 *   accion=aplicar  Quita esas piezas de esas ventas, recalcula cada total y su
 *                   estado de pago, y lo deja en la auditoria. Todo en una sola
 *                   transaccion: o se aplica completo o no se aplica nada.
 *                   Se quita por cantidad, no por renglon: si de 5 piezas solo
 *                   faltaron 2, el renglon se queda con 3.
 *
 * Es cosa del administrador: el vendedor no compra ni ve de que casa es cada
 * producto, que es justo lo que esta pantalla ordena.
 */

session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Sesion expirada, vuelve a iniciar sesion']);
    exit;
}

if (($_SESSION['user_role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Solo un administrador puede confirmar la compra']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Metodo no permitido']);
    exit;
}

require_once __DIR__ . '/../../config/conexionBD.php';
require_once __DIR__ . '/../helpers/casas.php';

$usuarioId = (int) $_SESSION['user_id'];

$datos  = json_decode(file_get_contents('php://input'), true) ?? [];
$accion = $datos['accion'] ?? '';

/** Deja constancia del cambio en la auditoria de la venta. */
function auditarCompra(PDO $pdo, int $ventaId, int $usuarioId, string $campo, ?string $anterior, ?string $nuevo): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO auditoria_ventas (venta_id, usuario_id, campo, valor_anterior, valor_nuevo)
         VALUES (:venta_id, :usuario_id, :campo, :anterior, :nuevo)'
    );
    $stmt->execute([
        'venta_id'   => $ventaId,
        'usuario_id' => $usuarioId,
        'campo'      => mb_substr($campo, 0, 80),
        'anterior'   => $anterior === null ? null : mb_substr($anterior, 0, 255),
        'nuevo'      => $nuevo === null ? null : mb_substr($nuevo, 0, 255),
    ]);
}

/**
 * Estado de pago de una venta contra un total nuevo. Misma regla que al editar:
 * cuenta el efectivo cobrado mas el saldo a favor que se le aplico.
 */
function estadoPorTotal(float $efectivo, float $total): string
{
    if ($efectivo > $total) {
        return 'devolucion';
    }
    if ($efectivo >= $total) {
        return 'pagado';
    }
    return $efectivo > 0 ? 'parcial' : 'pendiente';
}

try {
    $pdo = Database::getConnection();

    // ---------- Paso 1: en que ventas estan esos productos ----------
    if ($accion === 'lineas') {
        $desde = $datos['desde'] ?? date('Y-m-d');
        $hasta = $datos['hasta'] ?? $desde;

        if (!DateTime::createFromFormat('Y-m-d', $desde) || !DateTime::createFromFormat('Y-m-d', $hasta)) {
            throw new RuntimeException('Fechas no validas');
        }

        if ($desde > $hasta) {
            [$desde, $hasta] = [$hasta, $desde];
        }

        $codigos = array_values(array_unique(array_filter(
            array_map(fn($c) => trim((string) $c), (array) ($datos['codigos'] ?? []))
        )));

        if ($codigos === []) {
            throw new RuntimeException('No marcaste ningun producto');
        }

        $filtroUsuario    = (int) ($datos['usuario'] ?? 0);
        $condicionUsuario = $filtroUsuario > 0 ? ' AND v.usuario_id = :usuario' : '';

        $marcadores = implode(',', array_fill(0, count($codigos), '?'));

        $stmt = $pdo->prepare(
            "SELECT d.venta_id, d.codigo_interno_producto, d.nombre_producto,
                    d.cantidad, d.subtotal, c.codigo_casa,
                    v.fecha, v.cliente, v.total, v.monto_cobrado, v.credito_aplicado,
                    v.tipo_pago, v.estado_pago, v.entregada_en,
                    CONCAT(u.nombre, ' ', COALESCE(u.apellido, '')) AS vendedor,
                    (SELECT COUNT(*) FROM detalle_venta dd WHERE dd.venta_id = v.id) AS lineas_venta
               FROM detalle_venta d
               JOIN ventas v   ON v.id = d.venta_id
               JOIN usuarios u ON u.id = v.usuario_id
               JOIN casas c    ON c.id = d.casa_id
              WHERE v.eliminada_en IS NULL
                AND DATE(v.fecha) BETWEEN ? AND ?
                AND d.codigo_interno_producto IN ({$marcadores})"
            . ($filtroUsuario > 0 ? ' AND v.usuario_id = ?' : '') .
            " ORDER BY v.fecha, v.id, d.id"
        );

        $parametros = array_merge([$desde, $hasta], $codigos);

        if ($filtroUsuario > 0) {
            $parametros[] = $filtroUsuario;
        }

        $stmt->execute($parametros);
        $filas = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Se agrupa por venta: asi se ve de un vistazo a quien le toca y cuanto
        // le quedaria, y se sabe si la venta se quedaria sin nada.
        $ventas = [];

        foreach ($filas as $fila) {
            $id = (int) $fila['venta_id'];

            if (!isset($ventas[$id])) {
                $ventas[$id] = [
                    'venta_id'     => $id,
                    'fecha'        => $fila['fecha'],
                    'cliente'      => $fila['cliente'],
                    'vendedor'     => trim($fila['vendedor']),
                    'tipo_pago'    => $fila['tipo_pago'],
                    'estado_pago'  => $fila['estado_pago'],
                    'entregada'    => $fila['entregada_en'] !== null,
                    'total'        => (float) $fila['total'],
                    'cobrado'      => (float) $fila['monto_cobrado'],
                    'credito'      => (float) $fila['credito_aplicado'],
                    'lineas_venta' => (int) $fila['lineas_venta'],
                    'lineas'       => [],
                ];
            }

            $ventas[$id]['lineas'][] = [
                'codigo'   => $fila['codigo_interno_producto'],
                'nombre'   => $fila['nombre_producto'],
                'casa'     => etiquetaCasa($fila['codigo_casa']),
                'cantidad' => (int) $fila['cantidad'],
                'subtotal' => (float) $fila['subtotal'],
            ];
        }

        echo json_encode([
            'ok'     => true,
            'ventas' => array_values($ventas),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ---------- Paso 2: quitar las piezas y recalcular ----------
    if ($accion === 'aplicar') {
        $quitar = (array) ($datos['quitar'] ?? []);

        // Lo que se va a quitar, agrupado por venta: codigo => piezas.
        $porVenta = [];

        foreach ($quitar as $item) {
            $ventaId  = (int) ($item['venta_id'] ?? 0);
            $codigo   = trim((string) ($item['codigo'] ?? ''));
            $cantidad = (int) ($item['cantidad'] ?? 0);

            if ($ventaId > 0 && $codigo !== '' && $cantidad > 0) {
                $porVenta[$ventaId][$codigo] = $cantidad;
            }
        }

        if ($porVenta === []) {
            throw new RuntimeException('No hay nada que quitar');
        }

        $eliminarVacias = !empty($datos['eliminar_vacias']);

        $pdo->beginTransaction();

        try {
            $resumen   = ['ventas' => 0, 'piezas' => 0, 'importe' => 0.0, 'eliminadas' => 0, 'detalle' => []];
            $sinPiezas = [];

            foreach ($porVenta as $ventaId => $codigos) {
                // FOR UPDATE: nadie mas toca esta venta mientras se recalcula.
                $stmt = $pdo->prepare(
                    'SELECT id, total, monto_cobrado, credito_aplicado, cliente, eliminada_en
                       FROM ventas WHERE id = :id FOR UPDATE'
                );
                $stmt->execute(['id' => $ventaId]);
                $venta = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$venta || $venta['eliminada_en'] !== null) {
                    throw new RuntimeException("La venta #{$ventaId} ya no está disponible");
                }

                $marcadores = implode(',', array_fill(0, count($codigos), '?'));

                // Lo que hay ahora en la venta de esos productos.
                $stmt = $pdo->prepare(
                    "SELECT id, codigo_interno_producto, nombre_producto, cantidad, precio_aplicado
                       FROM detalle_venta
                      WHERE venta_id = ? AND codigo_interno_producto IN ({$marcadores})"
                );
                $stmt->execute(array_merge([$ventaId], array_keys($codigos)));
                $lineas = $stmt->fetchAll(PDO::FETCH_ASSOC);

                if ($lineas === []) {
                    // Alguien ya las quito entretanto: no es un error, se salta.
                    continue;
                }

                $borrar    = $pdo->prepare('DELETE FROM detalle_venta WHERE id = :id');
                $reducir   = $pdo->prepare('UPDATE detalle_venta SET cantidad = :cantidad WHERE id = :id');
                $quitadas  = [];

                foreach ($lineas as $linea) {
                    $tiene = (int) $linea['cantidad'];

                    // Nunca mas de lo que hay: si el navegador pide de mas, se
                    // quita lo que exista y ya.
                    $quita = min((int) $codigos[$linea['codigo_interno_producto']], $tiene);

                    if ($quita <= 0) {
                        continue;
                    }

                    if ($quita >= $tiene) {
                        $borrar->execute(['id' => $linea['id']]);
                    } else {
                        $reducir->execute(['cantidad' => $tiene - $quita, 'id' => $linea['id']]);
                    }

                    $quitadas[] = [
                        'codigo'  => $linea['codigo_interno_producto'],
                        'nombre'  => $linea['nombre_producto'],
                        'antes'   => $tiene,
                        'quita'   => $quita,
                        'importe' => $quita * (float) $linea['precio_aplicado'],
                    ];
                }

                if ($quitadas === []) {
                    continue;
                }

                // El total nuevo sale de lo que quedo, no de una resta: asi no se
                // arrastra ningun redondeo.
                $stmt = $pdo->prepare(
                    'SELECT COALESCE(SUM(subtotal), 0), COUNT(*) FROM detalle_venta WHERE venta_id = :id'
                );
                $stmt->execute(['id' => $ventaId]);
                [$totalNuevo, $quedan] = $stmt->fetch(PDO::FETCH_NUM);

                $totalNuevo = (float) $totalNuevo;
                $quedan     = (int) $quedan;

                if ($quedan === 0 && !$eliminarVacias) {
                    $sinPiezas[] = $ventaId;
                    throw new RuntimeException('hay ventas que se quedarian sin piezas');
                }

                foreach ($quitadas as $q) {
                    // Ojo con el nombre: $quedan cuenta RENGLONES de la venta y
                    // decide si se elimina. Las piezas que sobran en este renglon
                    // van aparte.
                    $piezasRestantes = $q['antes'] - $q['quita'];

                    auditarCompra(
                        $pdo, $ventaId, $usuarioId,
                        'no hubo · ' . $q['codigo'],
                        $q['nombre'] . ' x' . $q['antes'],
                        // Si quedan piezas se deja constancia de cuantas; si no,
                        // el renglon desaparecio y se marca como null.
                        $piezasRestantes > 0 ? $q['nombre'] . ' x' . $piezasRestantes : null
                    );

                    $resumen['piezas']  += $q['quita'];
                    $resumen['importe'] += $q['importe'];
                }

                $efectivo   = (float) $venta['monto_cobrado'] + (float) $venta['credito_aplicado'];
                $estadoNuevo = estadoPorTotal($efectivo, $totalNuevo);

                if ($quedan === 0) {
                    // Sin piezas la venta no existe: se va al borrado suave, el
                    // mismo que usa el boton de eliminar del historial.
                    $pdo->prepare(
                        'UPDATE ventas
                            SET total = :total, estado_pago = :estado,
                                eliminada_en = NOW(), eliminada_por = :usuario
                          WHERE id = :id'
                    )->execute([
                        'total'   => $totalNuevo,
                        'estado'  => $estadoNuevo,
                        'usuario' => $usuarioId,
                        'id'      => $ventaId,
                    ]);

                    auditarCompra($pdo, $ventaId, $usuarioId, 'eliminada',
                        'se quedó sin piezas al confirmar la compra', null);

                    $resumen['eliminadas']++;

                } else {
                    $pdo->prepare(
                        'UPDATE ventas SET total = :total, estado_pago = :estado WHERE id = :id'
                    )->execute([
                        'total'  => $totalNuevo,
                        'estado' => $estadoNuevo,
                        'id'     => $ventaId,
                    ]);
                }

                auditarCompra(
                    $pdo, $ventaId, $usuarioId, 'total',
                    number_format((float) $venta['total'], 2, '.', ''),
                    number_format($totalNuevo, 2, '.', '')
                );

                $resumen['ventas']++;
                $resumen['detalle'][] = [
                    'venta_id'   => $ventaId,
                    'cliente'    => $venta['cliente'],
                    'total'      => number_format($totalNuevo, 2, '.', ''),
                    'eliminada'  => $quedan === 0,
                    // Lo que el cliente pago de mas queda a su favor.
                    'devolucion' => number_format(max($efectivo - $totalNuevo, 0), 2, '.', ''),
                ];
            }

            $pdo->commit();

        } catch (Throwable $e) {
            $pdo->rollBack();

            if ($sinPiezas !== []) {
                http_response_code(409);
                echo json_encode([
                    'ok'         => false,
                    'sin_piezas' => $sinPiezas,
                    'error'      => 'Hay ventas que se quedarían sin ninguna pieza.',
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }

            throw $e;
        }

        $resumen['importe'] = number_format($resumen['importe'], 2, '.', '');

        echo json_encode(['ok' => true, 'resumen' => $resumen], JSON_UNESCAPED_UNICODE);
        exit;
    }

    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Accion no valida']);

} catch (RuntimeException $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    error_log($e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'No se pudo completar la confirmacion']);
}
