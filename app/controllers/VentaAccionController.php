<?php
/**
 * Acciones sobre una venta ya registrada que no cambian su contenido:
 *
 *   accion=eliminar   Borrado suave. Desactiva la venta (eliminada_en = ahora):
 *                     deja de aparecer y de contar en todos lados, pero sigue en
 *                     la base. Quien la elimina tiene 10 minutos para recuperarla
 *                     (ver accion=recuperar); pasado ese plazo, el historial la
 *                     purga de verdad al cargarse. No se puede eliminar una venta
 *                     de credito que ya tenga abonos.
 *
 *   accion=recuperar  Deshace el borrado suave (eliminada_en = NULL), siempre que
 *                     todavia este dentro de los 10 minutos.
 *
 *   accion=entregar   Marca (o desmarca) la fecha de entrega de la mercancia.
 *
 * Permisos: un vendedor solo actua sobre sus propias ventas; el admin, sobre
 * todas.
 */

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

// Minutos que una venta eliminada sigue siendo recuperable. Debe coincidir con
// la purga del HistorialController.
const MINUTOS_RECUPERACION = 10;

$usuarioId = (int) $_SESSION['user_id'];
$esAdmin   = ($_SESSION['user_role'] ?? '') === 'admin';

$datos   = json_decode(file_get_contents('php://input'), true) ?? [];
$accion  = $datos['accion'] ?? '';
$ventaId = (int) ($datos['venta_id'] ?? 0);

if ($ventaId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Falta la venta']);
    exit;
}

/** Deja constancia en la auditoria de la venta. */
function auditar(PDO $pdo, int $ventaId, int $usuarioId, string $campo, ?string $anterior, ?string $nuevo): void
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

try {
    $pdo = Database::getConnection();

    $stmt = $pdo->prepare(
        'SELECT id, usuario_id, tipo_pago, entregada_en, eliminada_en FROM ventas WHERE id = :id'
    );
    $stmt->execute(['id' => $ventaId]);
    $venta = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$venta) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'La venta ya no existe']);
        exit;
    }

    // Un vendedor solo toca lo suyo; el admin, todo.
    if (!$esAdmin && (int) $venta['usuario_id'] !== $usuarioId) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Solo puedes modificar las ventas que tu hiciste']);
        exit;
    }

    // ---------- Eliminar (borrado suave) ----------
    if ($accion === 'eliminar') {
        if ($venta['eliminada_en'] !== null) {
            echo json_encode(['ok' => true, 'ya' => true, 'mensaje' => 'La venta ya estaba eliminada']);
            exit;
        }

        // Las ventas de credito con abonos no se eliminan: primero habria que
        // revertir los pagos para no descuadrar la cobranza.
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM pagos_credito WHERE venta_id = :id');
        $stmt->execute(['id' => $ventaId]);

        if ((int) $stmt->fetchColumn() > 0) {
            http_response_code(409);
            echo json_encode([
                'ok'    => false,
                'error' => 'Esta venta a crédito ya tiene abonos registrados, no se puede eliminar. '
                         . 'Primero habría que revertir los abonos.',
            ]);
            exit;
        }

        $stmt = $pdo->prepare(
            'UPDATE ventas SET eliminada_en = NOW(), eliminada_por = :usuario WHERE id = :id'
        );
        $stmt->execute(['usuario' => $usuarioId, 'id' => $ventaId]);

        auditar($pdo, $ventaId, $usuarioId, 'venta eliminada', null,
                'recuperable ' . MINUTOS_RECUPERACION . ' min');

        echo json_encode([
            'ok'       => true,
            'venta_id' => $ventaId,
            'segundos' => MINUTOS_RECUPERACION * 60,
            'mensaje'  => 'Venta eliminada. Tienes ' . MINUTOS_RECUPERACION
                        . ' minutos para recuperarla.',
        ]);
        exit;
    }

    // ---------- Recuperar ----------
    if ($accion === 'recuperar') {
        if ($venta['eliminada_en'] === null) {
            echo json_encode(['ok' => true, 'ya' => true, 'mensaje' => 'La venta ya estaba activa']);
            exit;
        }

        // Fuera de plazo ya no se recupera (y de hecho el historial pudo haberla
        // purgado). Se compara en el mismo huso que usa la conexion.
        $vencida = (new DateTime($venta['eliminada_en']))
            ->modify('+' . MINUTOS_RECUPERACION . ' minutes') < new DateTime();

        if ($vencida) {
            http_response_code(410);
            echo json_encode([
                'ok'    => false,
                'error' => 'Ya pasaron los ' . MINUTOS_RECUPERACION
                         . ' minutos: la venta no se puede recuperar.',
            ]);
            exit;
        }

        $stmt = $pdo->prepare(
            'UPDATE ventas SET eliminada_en = NULL, eliminada_por = NULL WHERE id = :id'
        );
        $stmt->execute(['id' => $ventaId]);

        auditar($pdo, $ventaId, $usuarioId, 'venta recuperada', 'eliminada', 'activa');

        echo json_encode(['ok' => true, 'venta_id' => $ventaId, 'mensaje' => 'Venta recuperada.']);
        exit;
    }

    // ---------- Entregar (marcar / desmarcar) ----------
    if ($accion === 'entregar') {
        if ($venta['eliminada_en'] !== null) {
            http_response_code(409);
            echo json_encode(['ok' => false, 'error' => 'No se puede entregar una venta eliminada']);
            exit;
        }

        $fecha = trim((string) ($datos['fecha'] ?? ''));

        // Sin fecha = desmarcar la entrega.
        if ($fecha === '') {
            $stmt = $pdo->prepare('UPDATE ventas SET entregada_en = NULL WHERE id = :id');
            $stmt->execute(['id' => $ventaId]);

            if ($venta['entregada_en'] !== null) {
                auditar($pdo, $ventaId, $usuarioId, 'entrega', $venta['entregada_en'], null);
            }

            echo json_encode([
                'ok'        => true,
                'venta_id'  => $ventaId,
                'entregada' => false,
                'mensaje'   => 'Se quitó la marca de entrega.',
            ]);
            exit;
        }

        $fechaObj = DateTime::createFromFormat('Y-m-d', $fecha);

        if (!$fechaObj || $fechaObj->format('Y-m-d') !== $fecha) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'La fecha de entrega no es válida']);
            exit;
        }

        $stmt = $pdo->prepare('UPDATE ventas SET entregada_en = :fecha WHERE id = :id');
        $stmt->execute(['fecha' => $fecha, 'id' => $ventaId]);

        auditar($pdo, $ventaId, $usuarioId, 'entrega', $venta['entregada_en'], $fecha);

        echo json_encode([
            'ok'           => true,
            'venta_id'     => $ventaId,
            'entregada'    => true,
            'entregada_en' => $fecha,
            'mensaje'      => 'Venta marcada como entregada.',
        ]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Acción no reconocida']);

} catch (PDOException $e) {
    error_log($e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'No se pudo completar la acción']);
}
