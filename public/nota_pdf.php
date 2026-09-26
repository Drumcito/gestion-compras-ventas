<?php
/**
 * Nota de venta en PDF, con enlace publico firmado.
 *
 * A diferencia de views/ventas/nota.php (que exige sesion), este endpoint NO
 * pide iniciar sesion: es el enlace que se le manda al cliente por WhatsApp o
 * correo. En su lugar, el acceso se controla con un token que firma el id de la
 * venta (ver app/helpers/correo.php). Sin el token correcto, no entrega nada.
 */

require_once __DIR__ . '/../config/conexionBD.php';
require_once __DIR__ . '/../app/helpers/correo.php';
require_once __DIR__ . '/../app/helpers/nota_pdf.php';

$ventaId = (int) ($_GET['id'] ?? 0);
$token   = (string) ($_GET['t'] ?? '');

if ($ventaId <= 0 || !tokenNotaValido($ventaId, $token)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Enlace no valido.');
}

try {
    $pdo = Database::getConnection();

    $stmt = $pdo->prepare(
        'SELECT v.id, v.cliente, v.fecha, v.total, v.credito_aplicado, v.tipo_pago,
                CONCAT(u.nombre, " ", COALESCE(u.apellido, "")) AS vendedor,
                c.direccion AS cliente_direccion, c.codigo_postal AS cliente_cp,
                c.telefono  AS cliente_telefono
           FROM ventas v
           JOIN usuarios u ON u.id = v.usuario_id
           LEFT JOIN clientes c ON c.id = v.cliente_id
          WHERE v.id = :id'
    );
    $stmt->execute(['id' => $ventaId]);
    $venta = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$venta) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        exit('Venta no encontrada.');
    }

    $stmt = $pdo->prepare(
        'SELECT nombre_producto, precio_aplicado, cantidad, subtotal
           FROM detalle_venta WHERE venta_id = :id ORDER BY id'
    );
    $stmt->execute(['id' => $ventaId]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $pdf = construirNotaPdf($venta, $items);

    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="nota-' . $ventaId . '.pdf"');
    header('Content-Length: ' . strlen($pdf));
    header('Cache-Control: private, max-age=0, must-revalidate');
    echo $pdf;

} catch (PDOException $e) {
    error_log($e->getMessage());
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    exit('No se pudo generar la nota.');
}
