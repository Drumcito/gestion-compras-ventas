<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Sesion expirada']);
    exit;
}

require_once __DIR__ . '/../../config/conexionBD.php';
require_once __DIR__ . '/../helpers/correo.php';

$accion = $_GET['accion'] ?? 'preparar';

/** Carga la venta con los datos del cliente ligado (si tiene). */
function cargarVenta(PDO $pdo, int $ventaId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT v.id, v.usuario_id, v.cliente, v.cliente_id, v.fecha, v.total, v.credito_aplicado, v.tipo_pago,
                CONCAT(u.nombre, " ", COALESCE(u.apellido, "")) AS vendedor,
                c.telefono AS cliente_telefono, c.email AS cliente_email,
                c.nombre_comercio AS cliente_comercio,
                c.direccion AS cliente_direccion, c.codigo_postal AS cliente_cp
           FROM ventas v
           JOIN usuarios u ON u.id = v.usuario_id
           LEFT JOIN clientes c ON c.id = v.cliente_id
          WHERE v.id = :id'
    );
    $stmt->execute(['id' => $ventaId]);
    $venta = $stmt->fetch(PDO::FETCH_ASSOC);

    return $venta ?: null;
}

/**
 * Un vendedor solo puede enviar la nota de sus propias ventas; el admin, la de
 * todas. Corta el paso en el servidor, no solo en el boton de la pantalla.
 */
function verificarPropietario(array $venta): void
{
    $esAdmin = ($_SESSION['user_role'] ?? '') === 'admin';
    if (!$esAdmin && (int) $venta['usuario_id'] !== (int) $_SESSION['user_id']) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Solo puedes enviar las notas de tus ventas']);
        exit;
    }
}

try {
    $pdo = Database::getConnection();

    // ---------- Datos para armar los botones (enlace, telefono, correo) ----------
    if ($accion === 'preparar') {
        $ventaId = (int) ($_GET['id'] ?? 0);
        $venta   = cargarVenta($pdo, $ventaId);

        if (!$venta) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'Venta no encontrada']);
            exit;
        }

        verificarPropietario($venta);

        echo json_encode([
            'ok'         => true,
            'venta_id'   => $ventaId,
            'pdf_url'    => urlPdfNota($ventaId),
            'telefono'   => $venta['cliente_telefono'] ?? '',
            'email'      => $venta['cliente_email'] ?? '',
            'ligado'     => $venta['cliente_id'] !== null,
            'cliente'    => $venta['cliente_comercio'] ?: ($venta['cliente'] ?: ''),
            'smtp_ok'    => smtpConfigurado(),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'Metodo no permitido']);
        exit;
    }

    $datos = json_decode(file_get_contents('php://input'), true) ?: [];

    // ---------- Enviar por correo (PDF adjunto) ----------
    if ($accion === 'correo') {
        $ventaId = (int) ($datos['venta_id'] ?? 0);
        $para    = trim((string) ($datos['email'] ?? ''));

        if (!filter_var($para, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Escribe un correo válido para enviar la nota');
        }

        $venta = cargarVenta($pdo, $ventaId);
        if (!$venta) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'Venta no encontrada']);
            exit;
        }

        verificarPropietario($venta);

        $stmt = $pdo->prepare(
            'SELECT nombre_producto, precio_aplicado, cantidad, subtotal
               FROM detalle_venta WHERE venta_id = :id ORDER BY id'
        );
        $stmt->execute(['id' => $ventaId]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        require_once __DIR__ . '/../helpers/nota_pdf.php';
        $pdf = construirNotaPdf($venta, $items);

        $nombreCliente = $venta['cliente_comercio'] ?: ($venta['cliente'] ?: 'cliente');

        $cuerpo = '<p>Hola,</p>'
                . '<p>Adjuntamos la nota de tu compra <strong>#' . (int) $ventaId . '</strong> en '
                . 'Comercializadora GA-BE.</p>'
                . '<p>Gracias por tu preferencia.</p>';

        enviarNotaPorCorreo(
            $para,
            'Nota de venta #' . $ventaId . ' - Comercializadora GA-BE',
            $cuerpo,
            $pdf,
            'nota-' . $ventaId . '.pdf'
        );

        echo json_encode([
            'ok'      => true,
            'mensaje' => 'Nota enviada por correo a ' . $para,
        ], JSON_UNESCAPED_UNICODE);
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
    echo json_encode(['ok' => false, 'error' => 'No se pudo procesar la solicitud']);
}
