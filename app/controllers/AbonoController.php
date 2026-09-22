<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Sesion expirada']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Metodo no permitido']);
    exit;
}

require_once __DIR__ . '/../../config/conexionBD.php';

$usuarioId = (int) $_SESSION['user_id'];
$esAdmin   = ($_SESSION['user_role'] ?? '') === 'admin';

$datos   = json_decode(file_get_contents('php://input'), true);
$ventaId = (int) ($datos['venta_id'] ?? 0);
$monto   = round((float) ($datos['monto'] ?? 0), 2);
$nota    = trim((string) ($datos['nota'] ?? ''));

try {
    $pdo = Database::getConnection();

    $stmt = $pdo->prepare('SELECT id, usuario_id, tipo_pago, total, monto_cobrado, credito_aplicado FROM ventas WHERE id = :id');
    $stmt->execute(['id' => $ventaId]);
    $venta = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$venta) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Venta no encontrada']);
        exit;
    }

    // Un vendedor solo abona a sus propias ventas; el admin a todas (misma regla
    // que editar la venta).
    if (!$esAdmin && (int) $venta['usuario_id'] !== $usuarioId) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Solo puedes registrar abonos en las ventas que tu hiciste']);
        exit;
    }

    if ($venta['tipo_pago'] !== 'credito') {
        throw new RuntimeException('Solo las ventas a credito llevan abonos');
    }
    if ($monto <= 0) {
        throw new RuntimeException('El abono debe ser mayor a cero');
    }
    if ($monto > 99999999.99) {
        throw new RuntimeException('El abono es demasiado alto');
    }

    // Se permite abonar de mas: ese excedente queda como saldo a favor del
    // cliente (si la venta esta ligada a uno). El trigger de pagos_credito
    // recalcula monto_cobrado y el estado (incluido 'devolucion').
    $pdo->prepare(
        'INSERT INTO pagos_credito (venta_id, monto, usuario_id, nota)
         VALUES (:venta_id, :monto, :usuario_id, :nota)'
    )->execute([
        'venta_id'   => $ventaId,
        'monto'      => $monto,
        'usuario_id' => $usuarioId,
        'nota'       => $nota !== '' ? mb_substr($nota, 0, 255) : null,
    ]);

    // Estado ya recalculado por el trigger.
    $stmt = $pdo->prepare(
        'SELECT estado_pago, total, monto_cobrado, credito_aplicado
           FROM ventas WHERE id = :id'
    );
    $stmt->execute(['id' => $ventaId]);
    $v = $stmt->fetch(PDO::FETCH_ASSOC);

    $efectivo   = (float) $v['monto_cobrado'] + (float) $v['credito_aplicado'];
    $saldo      = max((float) $v['total'] - $efectivo, 0);
    $devolucion = max($efectivo - (float) $v['total'], 0);

    echo json_encode([
        'ok'          => true,
        'estado_pago' => $v['estado_pago'],
        'cobrado'     => number_format((float) $v['monto_cobrado'], 2, '.', ''),
        'saldo'       => number_format($saldo, 2, '.', ''),
        'devolucion'  => number_format($devolucion, 2, '.', ''),
        'mensaje'     => $devolucion > 0
            ? 'Abono registrado. El cliente pagó de más: quedan ' .
              number_format($devolucion, 2) . ' de saldo a favor.'
            : 'Abono registrado.',
    ], JSON_UNESCAPED_UNICODE);

} catch (RuntimeException $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    error_log($e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'No se pudo registrar el abono']);
}
