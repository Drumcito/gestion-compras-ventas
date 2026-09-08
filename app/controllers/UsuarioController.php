<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Sesion expirada']);
    exit;
}

// Toda esta seccion es exclusiva del administrador. La validacion vive aqui,
// no solo en el menu: ocultar el boton no protege nada.
if (($_SESSION['user_role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Solo un administrador puede gestionar usuarios']);
    exit;
}

require_once __DIR__ . '/../../config/conexionBD.php';

$usuarioActual = (int) $_SESSION['user_id'];
$accion = $_GET['accion'] ?? 'listar';

try {
    $pdo = Database::getConnection();

    // ---------- Listado ----------
    if ($accion === 'listar') {
        $usuarios = $pdo->query(
            'SELECT u.id, u.nombre, u.apellido, u.numero_empleado, u.numero_telefono,
                    u.rol, u.activo, u.creado_en,
                    (SELECT COUNT(*) FROM ventas v WHERE v.usuario_id = u.id) AS ventas
               FROM usuarios u
              ORDER BY u.rol, u.nombre'
        )->fetchAll(PDO::FETCH_ASSOC);

        foreach ($usuarios as &$u) {
            $u['es_usuario_actual'] = ((int) $u['id'] === $usuarioActual);
        }
        unset($u);

        echo json_encode(['ok' => true, 'usuarios' => $usuarios], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'Metodo no permitido']);
        exit;
    }

    $datos = json_decode(file_get_contents('php://input'), true);

    // ---------- Alta ----------
    if ($accion === 'crear') {
        $nombre    = trim($datos['nombre'] ?? '');
        $apellido  = trim($datos['apellido'] ?? '');
        $numero    = trim($datos['numero_empleado'] ?? '');
        $telefono  = trim($datos['numero_telefono'] ?? '');
        $rol       = $datos['rol'] ?? 'vendedor';
        $password  = (string) ($datos['password'] ?? '');

        if ($nombre === '' || $numero === '') {
            throw new RuntimeException('El nombre y el número de empleado son obligatorios');
        }
        if (!in_array($rol, ['admin', 'vendedor'], true)) {
            throw new RuntimeException('Rol no valido');
        }
        if (mb_strlen($password) < 6) {
            throw new RuntimeException('La contraseña debe tener al menos 6 caracteres');
        }

        $stmt = $pdo->prepare('SELECT id FROM usuarios WHERE numero_empleado = :num');
        $stmt->execute(['num' => $numero]);
        if ($stmt->fetch()) {
            throw new RuntimeException("El número de empleado {$numero} ya está registrado");
        }

        $stmt = $pdo->prepare(
            'INSERT INTO usuarios (nombre, apellido, numero_empleado, numero_telefono, rol, password)
             VALUES (:nombre, :apellido, :numero, :telefono, :rol, :password)'
        );
        $stmt->execute([
            'nombre'   => $nombre,
            'apellido' => $apellido !== '' ? $apellido : null,
            'numero'   => $numero,
            'telefono' => $telefono !== '' ? $telefono : null,
            'rol'      => $rol,
            'password' => password_hash($password, PASSWORD_BCRYPT),
        ]);

        echo json_encode(['ok' => true, 'mensaje' => "Usuario {$numero} creado"]);
        exit;
    }

    // ---------- Edicion ----------
    if ($accion === 'editar') {
        $id       = (int) ($datos['id'] ?? 0);
        $nombre   = trim($datos['nombre'] ?? '');
        $apellido = trim($datos['apellido'] ?? '');
        $numero   = trim($datos['numero_empleado'] ?? '');
        $telefono = trim($datos['numero_telefono'] ?? '');
        $rol      = $datos['rol'] ?? 'vendedor';
        $activo   = !empty($datos['activo']);
        $password = (string) ($datos['password'] ?? '');

        $stmt = $pdo->prepare('SELECT * FROM usuarios WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$usuario) {
            throw new RuntimeException('Usuario no encontrado');
        }
        if ($nombre === '' || $numero === '') {
            throw new RuntimeException('El nombre y el número de empleado son obligatorios');
        }
        if (!in_array($rol, ['admin', 'vendedor'], true)) {
            throw new RuntimeException('Rol no valido');
        }

        // Quitarse a uno mismo el rol de admin (o desactivarse) deja el sistema
        // sin quien lo administre desde esa sesion.
        if ($id === $usuarioActual && ($rol !== 'admin' || !$activo)) {
            throw new RuntimeException('No puedes quitarte a ti mismo el acceso de administrador');
        }

        if ($usuario['rol'] === 'admin' && ($rol !== 'admin' || !$activo)) {
            $otros = (int) $pdo->query(
                "SELECT COUNT(*) FROM usuarios WHERE rol = 'admin' AND activo = 1"
            )->fetchColumn();

            if ($otros <= 1) {
                throw new RuntimeException('Debe quedar al menos un administrador activo');
            }
        }

        $stmt = $pdo->prepare('SELECT id FROM usuarios WHERE numero_empleado = :num AND id <> :id');
        $stmt->execute(['num' => $numero, 'id' => $id]);
        if ($stmt->fetch()) {
            throw new RuntimeException("El número de empleado {$numero} ya está registrado");
        }

        $sql = 'UPDATE usuarios
                   SET nombre = :nombre, apellido = :apellido, numero_empleado = :numero,
                       numero_telefono = :telefono, rol = :rol, activo = :activo';
        $parametros = [
            'nombre'   => $nombre,
            'apellido' => $apellido !== '' ? $apellido : null,
            'numero'   => $numero,
            'telefono' => $telefono !== '' ? $telefono : null,
            'rol'      => $rol,
            'activo'   => $activo ? 1 : 0,
            'id'       => $id,
        ];

        // La contraseña solo se toca si el admin escribió una nueva.
        if ($password !== '') {
            if (mb_strlen($password) < 6) {
                throw new RuntimeException('La contraseña debe tener al menos 6 caracteres');
            }
            $sql .= ', password = :password';
            $parametros['password'] = password_hash($password, PASSWORD_BCRYPT);
        }

        $sql .= ' WHERE id = :id';

        $pdo->prepare($sql)->execute($parametros);

        echo json_encode(['ok' => true, 'mensaje' => 'Usuario actualizado']);
        exit;
    }

    // ---------- Baja ----------
    if ($accion === 'eliminar') {
        $id = (int) ($datos['id'] ?? 0);

        if ($id === $usuarioActual) {
            throw new RuntimeException('No puedes eliminar tu propio usuario');
        }

        $stmt = $pdo->prepare('SELECT rol, activo, numero_empleado FROM usuarios WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$usuario) {
            throw new RuntimeException('Usuario no encontrado');
        }

        if ($usuario['rol'] === 'admin' && $usuario['activo']) {
            $otros = (int) $pdo->query(
                "SELECT COUNT(*) FROM usuarios WHERE rol = 'admin' AND activo = 1"
            )->fetchColumn();

            if ($otros <= 1) {
                throw new RuntimeException('Debe quedar al menos un administrador activo');
            }
        }

        // Un usuario con movimientos no se borra: sus ventas, abonos y cambios
        // de precio quedarian sin autor. En ese caso se desactiva.
        $stmt = $pdo->prepare(
            'SELECT (SELECT COUNT(*) FROM ventas WHERE usuario_id = :id1)
                  + (SELECT COUNT(*) FROM pagos_credito WHERE usuario_id = :id2)
                  + (SELECT COUNT(*) FROM historial_precios WHERE usuario_id = :id3)
                  + (SELECT COUNT(*) FROM auditoria_ventas WHERE usuario_id = :id4)'
        );
        $stmt->execute(['id1' => $id, 'id2' => $id, 'id3' => $id, 'id4' => $id]);
        $movimientos = (int) $stmt->fetchColumn();

        if ($movimientos > 0) {
            $pdo->prepare('UPDATE usuarios SET activo = 0 WHERE id = :id')->execute(['id' => $id]);

            echo json_encode([
                'ok'       => true,
                'mensaje'  => "{$usuario['numero_empleado']} tiene {$movimientos} movimientos registrados, " .
                              'así que se desactivó en lugar de borrarse (ya no puede entrar al sistema, ' .
                              'pero su historial se conserva)',
                'desactivado' => true,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $pdo->prepare('DELETE FROM usuarios WHERE id = :id')->execute(['id' => $id]);

        echo json_encode(['ok' => true, 'mensaje' => "Usuario {$usuario['numero_empleado']} eliminado"]);
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
    echo json_encode(['ok' => false, 'error' => 'Error al procesar la solicitud']);
}
