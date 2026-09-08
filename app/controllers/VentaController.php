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

$tablasCasa = [
    'BNS01' => 'productos_casa1',
    'BNS02' => 'productos_casa2',
    'BNS03' => 'productos_casa3',
    'BNS04' => 'productos_casa4',
];

$datos = json_decode(file_get_contents('php://input'), true);

$cliente          = trim($datos['cliente'] ?? '');
$tipoPago         = $datos['tipo_pago'] ?? 'contado';
$fechaVencimiento = $datos['fecha_vencimiento'] ?? null;
$pagoInicial      = (float) ($datos['pago_inicial'] ?? 0);
$items            = $datos['items'] ?? [];

if (!in_array($tipoPago, ['contado', 'credito'], true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Tipo de pago no valido']);
    exit;
}

if (!is_array($items) || count($items) === 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Agrega al menos un producto a la venta']);
    exit;
}

try {
    $pdo = Database::getConnection();

    // Ids de las casas, para guardarlos en cada linea del detalle.
    $casas = $pdo->query('SELECT id, codigo_casa FROM casas')->fetchAll(PDO::FETCH_KEY_PAIR);
    $casaIdPorCodigo = array_flip($casas);

    // Los precios NO se toman de lo que manda el navegador: se releen de la base
    // para que nadie pueda mandar un precio alterado desde el cliente.
    $lineas = [];
    $total  = 0.0;

    foreach ($items as $item) {
        $casa       = $item['casa'] ?? '';
        $codigo     = $item['codigo_interno'] ?? '';
        $tipoPrecio = $item['tipo_precio'] ?? 'menudeo';
        $cantidad   = (int) ($item['cantidad'] ?? 0);

        if (!isset($tablasCasa[$casa]) || !isset($casaIdPorCodigo[$casa])) {
            throw new RuntimeException('Casa no valida en uno de los productos');
        }
        if (!in_array($tipoPrecio, ['mayoreo', 'menudeo'], true)) {
            throw new RuntimeException('Tipo de precio no valido');
        }
        if ($cantidad < 1) {
            throw new RuntimeException('La cantidad debe ser al menos 1');
        }

        $stmt = $pdo->prepare(
            "SELECT nombre, precio_mayoreo, precio_menudeo
               FROM {$tablasCasa[$casa]}
              WHERE codigo_interno = :codigo AND activo = 1
              LIMIT 1"
        );
        $stmt->execute(['codigo' => $codigo]);
        $producto = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$producto) {
            throw new RuntimeException("El producto {$codigo} ya no esta disponible");
        }

        $precio = $tipoPrecio === 'mayoreo' ? $producto['precio_mayoreo'] : $producto['precio_menudeo'];

        if ($precio === null) {
            throw new RuntimeException("{$producto['nombre']} no tiene precio de {$tipoPrecio}");
        }

        $lineas[] = [
            'casa_id'    => $casaIdPorCodigo[$casa],
            'codigo'     => $codigo,
            'nombre'     => $producto['nombre'],
            'tipoPrecio' => $tipoPrecio,
            'precio'     => (float) $precio,
            'cantidad'   => $cantidad,
        ];

        $total += (float) $precio * $cantidad;
    }

    if ($tipoPago === 'credito' && $pagoInicial > $total) {
        throw new RuntimeException('El pago inicial no puede ser mayor al total');
    }

    $pdo->beginTransaction();

    $estadoPago = $tipoPago === 'contado' ? 'pagado' : 'pendiente';

    // En contado el cliente paga todo al momento; en credito lo cobrado lo va
    // acumulando el trigger de pagos_credito conforme entran los abonos.
    $cobrado = $tipoPago === 'contado' ? $total : 0.0;

    $stmt = $pdo->prepare(
        'INSERT INTO ventas (usuario_id, cliente, total, monto_cobrado, tipo_pago, estado_pago, fecha_vencimiento)
         VALUES (:usuario_id, :cliente, :total, :monto_cobrado, :tipo_pago, :estado_pago, :fecha_vencimiento)'
    );
    $stmt->execute([
        'usuario_id'        => $_SESSION['user_id'],
        'cliente'           => $cliente !== '' ? $cliente : null,
        'total'             => $total,
        'monto_cobrado'     => $cobrado,
        'tipo_pago'         => $tipoPago,
        'estado_pago'       => $estadoPago,
        'fecha_vencimiento' => ($tipoPago === 'credito' && $fechaVencimiento) ? $fechaVencimiento : null,
    ]);

    $ventaId = (int) $pdo->lastInsertId();

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

    // El abono inicial es opcional y puede ser 0; el trigger de pagos_credito
    // recalcula solo el estado de la venta (pendiente / parcial / pagado).
    if ($tipoPago === 'credito' && $pagoInicial > 0) {
        $stmt = $pdo->prepare(
            'INSERT INTO pagos_credito (venta_id, monto, usuario_id, nota)
             VALUES (:venta_id, :monto, :usuario_id, :nota)'
        );
        $stmt->execute([
            'venta_id'   => $ventaId,
            'monto'      => $pagoInicial,
            'usuario_id' => $_SESSION['user_id'],
            'nota'       => 'Pago inicial',
        ]);
    }

    $pdo->commit();

    echo json_encode([
        'ok'       => true,
        'venta_id' => $ventaId,
        'total'    => number_format($total, 2, '.', ''),
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
    echo json_encode(['ok' => false, 'error' => 'No se pudo guardar la venta']);
}
