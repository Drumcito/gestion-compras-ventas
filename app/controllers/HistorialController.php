<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Sesion expirada']);
    exit;
}

require_once __DIR__ . '/../../config/conexionBD.php';
require_once __DIR__ . '/../helpers/casas.php';
require_once __DIR__ . '/../helpers/resumen_productos.php';

$usuarioId = (int) $_SESSION['user_id'];
$esAdmin   = ($_SESSION['user_role'] ?? '') === 'admin';
$accion    = $_GET['accion'] ?? 'listar';

// Minutos que una venta eliminada sigue siendo recuperable. Debe coincidir con
// VentaAccionController.
const MINUTOS_RECUPERACION = 10;

try {
    $pdo = Database::getConnection();

    // Purga de ventas eliminadas cuyo plazo de recuperacion ya vencio: el
    // borrado suave se vuelve definitivo. Los detalles, abonos y auditoria se
    // van en cascada (FK ON DELETE CASCADE). Se corre al abrir el historial,
    // que es el punto por el que siempre se pasa.
    // MINUTOS_RECUPERACION es una constante entera del propio codigo (no entra
    // nada del usuario), por eso se interpola directo: INTERVAL no admite
    // parametros con prepares reales.
    $pdo->exec(
        'DELETE FROM ventas
          WHERE eliminada_en IS NOT NULL
            AND eliminada_en < (NOW() - INTERVAL ' . MINUTOS_RECUPERACION . ' MINUTE)'
    );

    // ---------- Detalle de una venta ----------
    if ($accion === 'detalle') {
        $ventaId = (int) ($_GET['id'] ?? 0);

        $stmt = $pdo->prepare(
            'SELECT v.id, v.cliente, v.cliente_id, v.fecha, v.total, v.tipo_pago, v.estado_pago,
                    v.credito_aplicado, v.fecha_vencimiento, v.entregada_en, v.usuario_id,
                    CONCAT(u.nombre, " ", COALESCE(u.apellido, "")) AS vendedor,
                    u.numero_empleado
               FROM ventas v
               JOIN usuarios u ON u.id = v.usuario_id
              WHERE v.id = :id'
        );
        $stmt->execute(['id' => $ventaId]);
        $venta = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$venta) {
            http_response_code(404);
            echo json_encode(['error' => 'Venta no encontrada']);
            exit;
        }

        $stmt = $pdo->prepare(
            'SELECT c.nombre AS casa, c.codigo_casa, d.codigo_interno_producto, d.nombre_producto,
                    d.tipo_precio, d.precio_aplicado, d.cantidad, d.subtotal
               FROM detalle_venta d
               JOIN casas c ON c.id = d.casa_id
              WHERE d.venta_id = :id
              ORDER BY d.id'
        );
        $stmt->execute(['id' => $ventaId]);
        $venta['items'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // El detalle guarda el codigo interno, pero en pantalla se ocupa el del
        // proveedor: se busca en la tabla de cada casa.
        $catalogo = catalogoDeProductos(
            $pdo, array_column($venta['items'], 'codigo_interno_producto'), ['codigo_proveedor']
        );

        foreach ($venta['items'] as &$item) {
            $item['casa'] = etiquetaCasa($item['codigo_casa']);

            // Si el producto ya no esta en el catalogo, queda el codigo interno.
            $item['codigo_proveedor'] = $catalogo[$item['codigo_interno_producto']]['codigo_proveedor'] ?? null;
        }
        unset($item);

        // El estado (cobrado / saldo / devolucion) aplica igual a contado y credito.
        $stmt = $pdo->prepare(
            'SELECT total_abonado, credito_aplicado, saldo_pendiente, devolucion
               FROM vista_estado_ventas WHERE venta_id = :id'
        );
        $stmt->execute(['id' => $ventaId]);
        $venta['saldo'] = $stmt->fetch(PDO::FETCH_ASSOC);

        $venta['abonos'] = [];

        if ($venta['tipo_pago'] === 'credito') {
            $stmt = $pdo->prepare(
                'SELECT p.fecha_pago, p.monto, p.nota,
                        CONCAT(u.nombre, " ", COALESCE(u.apellido, "")) AS recibio
                   FROM pagos_credito p
                   JOIN usuarios u ON u.id = p.usuario_id
                  WHERE p.venta_id = :id
                  ORDER BY p.fecha_pago'
            );
            $stmt->execute(['id' => $ventaId]);
            $venta['abonos'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        $stmt = $pdo->prepare(
            'SELECT a.campo, a.valor_anterior, a.valor_nuevo, a.fecha_cambio,
                    CONCAT(u.nombre, " ", COALESCE(u.apellido, "")) AS modifico,
                    u.numero_empleado
               FROM auditoria_ventas a
               JOIN usuarios u ON u.id = a.usuario_id
              WHERE a.venta_id = :id
              ORDER BY a.fecha_cambio DESC, a.id DESC'
        );
        $stmt->execute(['id' => $ventaId]);
        $venta['auditoria'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Todos ven todas las ventas; editar/eliminar solo el admin o el dueño.
        $venta['puede_editar']   = $esAdmin || ((int) $venta['usuario_id'] === $usuarioId);
        $venta['puede_eliminar'] = $venta['puede_editar'];

        echo json_encode(['ok' => true, 'venta' => $venta], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ---------- Listado con filtros ----------
    $desde = $_GET['desde'] ?? date('Y-m-d');
    $hasta = $_GET['hasta'] ?? $desde;

    if (!DateTime::createFromFormat('Y-m-d', $desde) || !DateTime::createFromFormat('Y-m-d', $hasta)) {
        http_response_code(400);
        echo json_encode(['error' => 'Fechas no validas']);
        exit;
    }

    if ($desde > $hasta) {
        [$desde, $hasta] = [$hasta, $desde];
    }

    // Filtro opcional por vendedor: 0 o vacio = todos.
    $filtroUsuario = isset($_GET['usuario']) ? (int) $_GET['usuario'] : 0;
    $condicionUsuario = $filtroUsuario > 0 ? ' AND v.usuario_id = :usuario' : '';

    // ---------- Resumen de productos vendidos en el periodo ----------
    // La consulta vive en app/helpers/resumen_productos.php porque la comparten
    // esta pantalla y la hoja imprimible del mismo resumen.
    if ($accion === 'productos') {
        $datos = resumenProductosVendidos($pdo, $desde, $hasta, $filtroUsuario);

        echo json_encode([
            'ok'        => true,
            'desde'     => $desde,
            'hasta'     => $hasta,
            'productos' => $datos['productos'],
            'resumen'   => $datos['resumen'],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $stmt = $pdo->prepare(
        'SELECT v.id, v.cliente, v.fecha, v.total, v.monto_cobrado, v.credito_aplicado, v.tipo_pago,
                v.estado_pago, v.entregada_en, v.usuario_id,
                GREATEST(v.monto_cobrado + v.credito_aplicado - v.total, 0) AS devolucion,
                CONCAT(u.nombre, " ", COALESCE(u.apellido, "")) AS vendedor,
                u.numero_empleado,
                (SELECT COUNT(*) FROM detalle_venta d WHERE d.venta_id = v.id) AS piezas
           FROM ventas v
           JOIN usuarios u ON u.id = v.usuario_id
          WHERE v.eliminada_en IS NULL
            AND DATE(v.fecha) BETWEEN :desde AND :hasta' . $condicionUsuario . '
          ORDER BY v.fecha DESC, v.id DESC'
    );

    $parametros = ['desde' => $desde, 'hasta' => $hasta];
    if ($filtroUsuario > 0) {
        $parametros['usuario'] = $filtroUsuario;
    }

    $stmt->execute($parametros);
    $ventas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $totalPeriodo = 0.0;
    $devoluciones = 0.0;

    foreach ($ventas as &$venta) {
        $venta['puede_editar']   = $esAdmin || ((int) $venta['usuario_id'] === $usuarioId);
        $venta['puede_eliminar'] = $venta['puede_editar'];
        $totalPeriodo += (float) $venta['total'];
        $devoluciones += (float) $venta['devolucion'];
    }
    unset($venta);

    // ---------- Ventas eliminadas todavia recuperables ----------
    // Salen aparte del listado y de cualquier filtro de fecha: mientras dure el
    // plazo, el vendedor (o el admin) las tiene siempre a la mano para deshacer
    // el borrado. Un vendedor solo ve las suyas; el admin, todas.
    $sqlRecuperables =
        'SELECT v.id, v.cliente, v.fecha, v.total, v.usuario_id,
                CONCAT(u.nombre, " ", COALESCE(u.apellido, "")) AS vendedor,
                u.numero_empleado,
                TIMESTAMPDIFF(SECOND, NOW(),
                    v.eliminada_en + INTERVAL ' . MINUTOS_RECUPERACION . ' MINUTE) AS segundos_restantes
           FROM ventas v
           JOIN usuarios u ON u.id = v.usuario_id
          WHERE v.eliminada_en IS NOT NULL'
        . ($esAdmin ? '' : ' AND v.usuario_id = :usuario')
        . ' ORDER BY v.eliminada_en DESC';

    $stmt = $pdo->prepare($sqlRecuperables);
    $stmt->execute($esAdmin ? [] : ['usuario' => $usuarioId]);

    // Por si la purga aun no corre para alguna: solo devolvemos las que de verdad
    // siguen dentro del plazo (segundos_restantes > 0).
    $recuperables = array_values(array_filter(
        $stmt->fetchAll(PDO::FETCH_ASSOC),
        fn($r) => (int) $r['segundos_restantes'] > 0
    ));

    // Lista de vendedores para llenar el selector del filtro.
    $vendedores = $pdo->query(
        'SELECT u.id, CONCAT(u.nombre, " ", COALESCE(u.apellido, "")) AS nombre, u.numero_empleado
           FROM usuarios u
          WHERE EXISTS (SELECT 1 FROM ventas v WHERE v.usuario_id = u.id)
          ORDER BY u.nombre'
    )->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'ok'           => true,
        'desde'        => $desde,
        'hasta'        => $hasta,
        'usuario'      => $filtroUsuario,
        'vendedores'   => $vendedores,
        'total'        => number_format($totalPeriodo, 2, '.', ''),
        'devoluciones' => number_format($devoluciones, 2, '.', ''),
        'ventas'       => $ventas,
        'recuperables' => $recuperables,
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    error_log($e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Error al consultar el historial']);
}
