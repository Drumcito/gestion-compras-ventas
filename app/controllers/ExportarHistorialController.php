<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Sesion expirada, vuelve a iniciar sesion.');
}

require_once __DIR__ . '/../../config/conexionBD.php';
require_once __DIR__ . '/../helpers/ExcelSimple.php';

$desde = $_GET['desde'] ?? date('Y-m-d');
$hasta = $_GET['hasta'] ?? $desde;

if (!DateTime::createFromFormat('Y-m-d', $desde) || !DateTime::createFromFormat('Y-m-d', $hasta)) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Fechas no validas.');
}

if ($desde > $hasta) {
    [$desde, $hasta] = [$hasta, $desde];
}

// Mismos filtros que la pantalla: rango de fechas y vendedor (0 = todos).
$filtroUsuario = isset($_GET['usuario']) ? (int) $_GET['usuario'] : 0;
$condicion     = $filtroUsuario > 0 ? ' AND v.usuario_id = :usuario' : '';

$parametros = ['desde' => $desde, 'hasta' => $hasta];
if ($filtroUsuario > 0) {
    $parametros['usuario'] = $filtroUsuario;
}

try {
    $pdo = Database::getConnection();

    // ---------- Hoja 1: una fila por venta ----------
    $stmt = $pdo->prepare(
        'SELECT v.id, v.fecha, v.cliente, v.total, v.monto_cobrado, v.tipo_pago,
                v.estado_pago, v.fecha_vencimiento,
                GREATEST(v.total - v.monto_cobrado, 0) AS saldo_pendiente,
                GREATEST(v.monto_cobrado - v.total, 0) AS devolucion,
                CONCAT(u.nombre, " ", COALESCE(u.apellido, "")) AS vendedor,
                u.numero_empleado,
                (SELECT COUNT(*) FROM detalle_venta d WHERE d.venta_id = v.id) AS piezas
           FROM ventas v
           JOIN usuarios u ON u.id = v.usuario_id
          WHERE DATE(v.fecha) BETWEEN :desde AND :hasta' . $condicion . '
          ORDER BY v.fecha DESC, v.id DESC'
    );
    $stmt->execute($parametros);
    $ventas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ---------- Hoja 2: una fila por producto vendido ----------
    $stmt = $pdo->prepare(
        'SELECT v.id AS venta_id, v.fecha, v.cliente, v.tipo_pago,
                CONCAT(u.nombre, " ", COALESCE(u.apellido, "")) AS vendedor,
                u.numero_empleado,
                c.nombre AS casa, d.codigo_interno_producto, d.nombre_producto,
                d.tipo_precio, d.precio_aplicado, d.cantidad, d.subtotal
           FROM detalle_venta d
           JOIN ventas v  ON v.id = d.venta_id
           JOIN usuarios u ON u.id = v.usuario_id
           JOIN casas c   ON c.id = d.casa_id
          WHERE DATE(v.fecha) BETWEEN :desde AND :hasta' . $condicion . '
          ORDER BY v.fecha DESC, v.id DESC, d.id'
    );
    $stmt->execute($parametros);
    $detalle = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log($e->getMessage());
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    exit('No se pudo generar el archivo.');
}

$partirFecha = function (string $fechaHora): array {
    [$fecha, $hora] = array_pad(explode(' ', $fechaHora), 2, '');
    return [$fecha, substr($hora, 0, 5)];
};

// ---------- Hoja de ventas ----------
$filasVentas = [];
$sumaTotal = $sumaCobrado = $sumaSaldo = $sumaDevolucion = 0.0;

foreach ($ventas as $v) {
    [$fecha, $hora] = $partirFecha($v['fecha']);

    $filasVentas[] = [
        (int) $v['id'],
        $fecha,
        $hora,
        $v['cliente'] ?? 'Sin cliente',
        trim($v['vendedor']),
        $v['numero_empleado'],
        (int) $v['piezas'],
        ucfirst($v['tipo_pago']),
        ucfirst($v['estado_pago']),
        (float) $v['total'],
        (float) $v['monto_cobrado'],
        (float) $v['saldo_pendiente'],
        (float) $v['devolucion'],
        $v['fecha_vencimiento'] ?? '',
    ];

    $sumaTotal      += (float) $v['total'];
    $sumaCobrado    += (float) $v['monto_cobrado'];
    $sumaSaldo      += (float) $v['saldo_pendiente'];
    $sumaDevolucion += (float) $v['devolucion'];
}

$filasVentas[] = ['', '', '', 'TOTALES (' . count($ventas) . ' ventas)', '', '', '', '', '',
                  $sumaTotal, $sumaCobrado, $sumaSaldo, $sumaDevolucion, ''];

// ---------- Hoja de detalle ----------
$filasDetalle = [];
$sumaPiezas = 0;
$sumaImporte = 0.0;

foreach ($detalle as $d) {
    [$fecha, $hora] = $partirFecha($d['fecha']);

    $filasDetalle[] = [
        (int) $d['venta_id'],
        $fecha,
        $hora,
        $d['cliente'] ?? 'Sin cliente',
        trim($d['vendedor']),
        $d['numero_empleado'],
        $d['casa'],
        $d['codigo_interno_producto'],
        $d['nombre_producto'],
        ucfirst($d['tipo_precio']),
        (float) $d['precio_aplicado'],
        (int) $d['cantidad'],
        (float) $d['subtotal'],
        ucfirst($d['tipo_pago']),
    ];

    $sumaPiezas  += (int) $d['cantidad'];
    $sumaImporte += (float) $d['subtotal'];
}

$filasDetalle[] = ['', '', '', '', '', '', '', '', 'TOTALES (' . count($detalle) . ' renglones)', '',
                   '', $sumaPiezas, $sumaImporte, ''];

$excel = new ExcelSimple();

$excel->agregarHoja(
    'Ventas',
    ['Folio', 'Fecha', 'Hora', 'Cliente', 'Vendedor', 'No. empleado', 'Piezas',
     'Tipo de pago', 'Estado', 'Total', 'Cobrado', 'Saldo pendiente', 'Devolución', 'Vence'],
    $filasVentas,
    [8, 12, 8, 28, 22, 14, 8, 14, 14, 13, 13, 15, 13, 12]
);

$excel->agregarHoja(
    'Detalle de productos',
    ['Folio', 'Fecha', 'Hora', 'Cliente', 'Vendedor', 'No. empleado', 'Casa',
     'Código', 'Producto', 'Precio', 'P. unitario', 'Cantidad', 'Importe', 'Tipo de pago'],
    $filasDetalle,
    [8, 12, 8, 24, 22, 14, 18, 15, 46, 11, 13, 10, 13, 14]
);

$nombre = $desde === $hasta
    ? "ventas_{$desde}.xlsx"
    : "ventas_{$desde}_a_{$hasta}.xlsx";

$excel->descargar($nombre);
