<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Sesion expirada, vuelve a iniciar sesion']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Metodo no permitido']);
    exit;
}

require_once __DIR__ . '/../../config/conexionBD.php';
require_once __DIR__ . '/../helpers/casas.php';

// El mapa casa -> tabla sale de la base (ver app/helpers/casas.php), asi que una
// casa creada desde Inventario queda disponible sin tocar codigo.
$tablasCasa = tablasCasa();

$usuarioId = (int) $_SESSION['user_id'];
$esAdmin   = ($_SESSION['user_role'] ?? '') === 'admin';

$datos            = json_decode(file_get_contents('php://input'), true);
$ventaId          = (int) ($datos['venta_id'] ?? 0);
$cliente          = trim($datos['cliente'] ?? '');
$tipoPago         = $datos['tipo_pago'] ?? 'contado';
$fechaVencimiento = $datos['fecha_vencimiento'] ?? null;
$items            = $datos['items'] ?? [];

// Cliente del catalogo. 0 = la venta queda solo con el nombre tecleado, sin
// ligarse a ningun cliente registrado.
$clienteIdNuevo = max(0, (int) ($datos['cliente_id'] ?? 0));

/** Como se llama un cliente registrado, para dejarlo legible en la auditoria. */
function nombreCliente(PDO $pdo, int $clienteId): ?string
{
    if ($clienteId <= 0) {
        return null;
    }

    $stmt = $pdo->prepare(
        "SELECT COALESCE(NULLIF(nombre_comercio, ''),
                         TRIM(CONCAT_WS(' ', nombres, apellido_paterno, apellido_materno))) AS nombre
           FROM clientes WHERE id = :id"
    );
    $stmt->execute(['id' => $clienteId]);

    $nombre = $stmt->fetchColumn();

    return $nombre === false ? null : (string) $nombre;
}

try {
    $pdo = Database::getConnection();

    $stmt = $pdo->prepare('SELECT * FROM ventas WHERE id = :id');
    $stmt->execute(['id' => $ventaId]);
    $venta = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$venta) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Venta no encontrada']);
        exit;
    }

    // Un vendedor solo edita sus propias ventas; el admin edita todas.
    if (!$esAdmin && (int) $venta['usuario_id'] !== $usuarioId) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Solo puedes editar las ventas que tu hiciste']);
        exit;
    }

    if (!in_array($tipoPago, ['contado', 'credito'], true)) {
        throw new RuntimeException('Tipo de pago no valido');
    }

    if (!is_array($items) || count($items) === 0) {
        throw new RuntimeException('La venta debe conservar al menos una pieza');
    }

    // Lo ya cubierto al cliente: el efectivo cobrado mas el saldo a favor que se
    // le aplico a esta venta (ese saldo cuenta como pagado).
    $cobrado         = (float) $venta['monto_cobrado'];
    $creditoAplicado = (float) ($venta['credito_aplicado'] ?? 0);
    $efectivo        = $cobrado + $creditoAplicado;

    // ---------- Cliente registrado ----------
    $clienteIdAnterior = (int) ($venta['cliente_id'] ?? 0);
    $cambioDeCliente   = $clienteIdNuevo !== $clienteIdAnterior;

    if ($cambioDeCliente) {
        // El saldo a favor no se guarda: se calcula sumando las ventas de cada
        // cliente (ver app/helpers/clientes.php). Mover a otro cliente una venta
        // que ya gasto saldo le regresaria ese gasto al anterior y se lo cargaria
        // al nuevo, descuadrando a los dos.
        if ($creditoAplicado > 0) {
            throw new RuntimeException(
                'Esta venta ya descontó saldo a favor del cliente, por eso no se le puede '
                . 'cambiar el cliente. Primero habría que deshacer ese descuento.'
            );
        }

        if ($clienteIdNuevo > 0 && nombreCliente($pdo, $clienteIdNuevo) === null) {
            throw new RuntimeException('El cliente que elegiste ya no existe');
        }
    }

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM pagos_credito WHERE venta_id = :id');
    $stmt->execute(['id' => $ventaId]);
    $tieneAbonos = (int) $stmt->fetchColumn() > 0;

    if ($tipoPago === 'contado' && $tieneAbonos) {
        throw new RuntimeException(
            'Esta venta ya tiene abonos registrados, no se puede cambiar a contado'
        );
    }

    $casas = $pdo->query('SELECT id, codigo_casa FROM casas')->fetchAll(PDO::FETCH_KEY_PAIR);
    $casaIdPorCodigo = array_flip($casas);

    // Los precios se releen de la base, igual que al crear la venta.
    $lineas = [];
    $total  = 0.0;

    foreach ($items as $item) {
        $casa       = $item['casa'] ?? '';
        $codigo     = $item['codigo_interno'] ?? '';
        $cantidad   = (int) ($item['cantidad'] ?? 0);

        if (!isset($tablasCasa[$casa]) || !isset($casaIdPorCodigo[$casa])) {
            throw new RuntimeException('Casa no valida en uno de los productos');
        }
        if ($cantidad < 1) {
            throw new RuntimeException('La cantidad debe ser al menos 1');
        }

        $stmt = $pdo->prepare(
            "SELECT nombre, precio_mayoreo
               FROM {$tablasCasa[$casa]}
              WHERE codigo_interno = :codigo AND activo = 1
              LIMIT 1"
        );
        $stmt->execute(['codigo' => $codigo]);
        $producto = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$producto) {
            throw new RuntimeException("El producto {$codigo} ya no esta disponible");
        }

        // Precio neto: bruto (mayoreo) + porcentaje de la casa, a 2 decimales.
        $precio = netoDe($producto['precio_mayoreo'], porcentajeCasa($casa));

        if ($precio === null) {
            throw new RuntimeException("{$producto['nombre']} no tiene precio cargado");
        }

        $lineas[$codigo] = [
            'casa_id'    => $casaIdPorCodigo[$casa],
            'codigo'     => $codigo,
            'nombre'     => $producto['nombre'],
            'tipoPrecio' => 'neto',
            'precio'     => $precio,
            'cantidad'   => $cantidad,
        ];

        $total += $precio * $cantidad;
    }

    // ---------- Comparar contra lo que habia, para la auditoria ----------
    $stmt = $pdo->prepare(
        'SELECT codigo_interno_producto, nombre_producto, tipo_precio, precio_aplicado, cantidad
           FROM detalle_venta WHERE venta_id = :id'
    );
    $stmt->execute(['id' => $ventaId]);

    $anteriores = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $fila) {
        $anteriores[$fila['codigo_interno_producto']] = $fila;
    }

    $cambios = [];

    $clienteAnterior = $venta['cliente'] ?? '';
    $clienteNuevo    = $cliente !== '' ? $cliente : null;

    if ((string) $clienteAnterior !== (string) $clienteNuevo) {
        $cambios[] = ['cliente', $clienteAnterior, $clienteNuevo];
    }

    // Se audita con los nombres, no con los ids: asi el historial se lee.
    if ($cambioDeCliente) {
        $cambios[] = [
            'cliente registrado',
            nombreCliente($pdo, $clienteIdAnterior) ?? 'sin cliente del catálogo',
            nombreCliente($pdo, $clienteIdNuevo) ?? 'sin cliente del catálogo',
        ];
    }

    if ($venta['tipo_pago'] !== $tipoPago) {
        $cambios[] = ['tipo_pago', $venta['tipo_pago'], $tipoPago];
    }

    $vencAnterior = $venta['fecha_vencimiento'];
    $vencNuevo    = ($tipoPago === 'credito' && $fechaVencimiento) ? $fechaVencimiento : null;

    if ((string) $vencAnterior !== (string) $vencNuevo) {
        $cambios[] = ['fecha_vencimiento', $vencAnterior, $vencNuevo];
    }

    foreach ($lineas as $codigo => $linea) {
        if (!isset($anteriores[$codigo])) {
            $cambios[] = ['pieza agregada', null, $linea['nombre'] . ' x' . $linea['cantidad']];
            continue;
        }

        $antes = $anteriores[$codigo];

        if ((int) $antes['cantidad'] !== $linea['cantidad']) {
            $cambios[] = ['cantidad · ' . $codigo, $antes['cantidad'], $linea['cantidad']];
        }
        if ($antes['tipo_precio'] !== $linea['tipoPrecio']) {
            $cambios[] = ['tipo de precio · ' . $codigo, $antes['tipo_precio'], $linea['tipoPrecio']];
        }
        if ((float) $antes['precio_aplicado'] !== $linea['precio']) {
            $cambios[] = ['precio · ' . $codigo, $antes['precio_aplicado'], $linea['precio']];
        }
    }

    foreach ($anteriores as $codigo => $antes) {
        if (!isset($lineas[$codigo])) {
            $cambios[] = ['pieza eliminada', $antes['nombre_producto'] . ' x' . $antes['cantidad'], null];
        }
    }

    if ((float) $venta['total'] !== $total) {
        $cambios[] = ['total', $venta['total'], number_format($total, 2, '.', '')];
    }

    if (count($cambios) === 0) {
        echo json_encode(['ok' => true, 'venta_id' => $ventaId, 'cambios' => 0,
                          'mensaje' => 'No hubo cambios que guardar']);
        exit;
    }

    // ---------- Guardar ----------
    $pdo->beginTransaction();

    // El estado se recalcula contra el total nuevo, contando el efectivo cobrado
    // mas el saldo a favor aplicado. Si eso supera el total nuevo (p. ej. se quito
    // mercancia), la venta se cierra en 'devolucion' y la diferencia es lo que el
    // negocio le debe al cliente.
    if ($efectivo > $total) {
        $estadoPago = 'devolucion';
    } elseif ($efectivo >= $total) {
        $estadoPago = 'pagado';
    } elseif ($efectivo > 0) {
        $estadoPago = 'parcial';
    } else {
        $estadoPago = 'pendiente';
    }

    $stmt = $pdo->prepare(
        'UPDATE ventas
            SET cliente = :cliente, cliente_id = :cliente_id, total = :total,
                tipo_pago = :tipo_pago, estado_pago = :estado_pago,
                fecha_vencimiento = :fecha_vencimiento
          WHERE id = :id'
    );
    $stmt->execute([
        'cliente'           => $clienteNuevo,
        'cliente_id'        => $clienteIdNuevo > 0 ? $clienteIdNuevo : null,
        'total'             => $total,
        'tipo_pago'         => $tipoPago,
        'estado_pago'       => $estadoPago,
        'fecha_vencimiento' => $vencNuevo,
        'id'                => $ventaId,
    ]);

    $pdo->prepare('DELETE FROM detalle_venta WHERE venta_id = :id')->execute(['id' => $ventaId]);

    $stmt = $pdo->prepare(
        'INSERT INTO detalle_venta
            (venta_id, casa_id, codigo_interno_producto, nombre_producto, tipo_precio, precio_aplicado, cantidad)
         VALUES (:venta_id, :casa_id, :codigo, :nombre, :tipo_precio, :precio, :cantidad)'
    );

    foreach ($lineas as $linea) {
        $stmt->execute([
            'venta_id'    => $ventaId,
            'casa_id'     => $linea['casa_id'],
            'codigo'      => $linea['codigo'],
            'nombre'      => $linea['nombre'],
            'tipo_precio' => $linea['tipoPrecio'],
            'precio'      => $linea['precio'],
            'cantidad'    => $linea['cantidad'],
        ]);
    }

    $stmt = $pdo->prepare(
        'INSERT INTO auditoria_ventas (venta_id, usuario_id, campo, valor_anterior, valor_nuevo)
         VALUES (:venta_id, :usuario_id, :campo, :anterior, :nuevo)'
    );

    foreach ($cambios as [$campo, $anterior, $nuevo]) {
        $stmt->execute([
            'venta_id'   => $ventaId,
            'usuario_id' => $usuarioId,
            'campo'      => mb_substr($campo, 0, 80),
            'anterior'   => $anterior === null ? null : mb_substr((string) $anterior, 0, 255),
            'nuevo'      => $nuevo === null ? null : mb_substr((string) $nuevo, 0, 255),
        ]);
    }

    $pdo->commit();

    echo json_encode([
        'ok'          => true,
        'venta_id'    => $ventaId,
        'total'       => number_format($total, 2, '.', ''),
        'estado_pago' => $estadoPago,
        'cobrado'     => number_format($cobrado, 2, '.', ''),
        'saldo'       => number_format(max($total - $efectivo, 0), 2, '.', ''),
        'devolucion'  => number_format(max($efectivo - $total, 0), 2, '.', ''),
        'cambios'     => count($cambios),
    ]);

} catch (RuntimeException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);

} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log($e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'No se pudo guardar la edicion']);
}
