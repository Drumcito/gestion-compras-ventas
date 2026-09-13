<?php
session_start();
require_once __DIR__ . '/../../config/conexionBD.php';

// Solo llega aquí quien acaba de iniciar sesión con una contraseña que debe
// cambiar (AuthController deja cambio_password_id en la sesión).
if (!isset($_SESSION['cambio_password_id'])) {
    header('Location: ../../views/auth/login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../../views/auth/cambiar_password.php');
    exit;
}

// Mismo trim que el login, para que la contraseña guardada sea la que se teclea al entrar.
$password  = trim($_POST['password'] ?? '');
$confirmar = trim($_POST['confirmar'] ?? '');

if (mb_strlen($password) < 6) {
    header('Location: ../../views/auth/cambiar_password.php?error=corta');
    exit;
}
if ($password !== $confirmar) {
    header('Location: ../../views/auth/cambiar_password.php?error=no_coincide');
    exit;
}

try {
    $pdo = Database::getConnection();

    $stmt = $pdo->prepare('SELECT id, numero_empleado, nombre, apellido, rol FROM usuarios WHERE id = :id AND activo = 1 AND debe_cambiar_password = 1 LIMIT 1');
    $stmt->execute(['id' => (int) $_SESSION['cambio_password_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        $_SESSION = [];
        header('Location: ../../views/auth/login.php');
        exit;
    }

    $stmt = $pdo->prepare('UPDATE usuarios SET password = :password, debe_cambiar_password = 0 WHERE id = :id');
    $stmt->execute([
        'password' => password_hash($password, PASSWORD_BCRYPT),
        'id'       => $user['id'],
    ]);

    // Contraseña cambiada: ahora sí se abre la sesión completa.
    session_regenerate_id(true);
    $_SESSION = [];

    $_SESSION['user_id'] = $user['id'];
    $_SESSION['num_empleado'] = $user['numero_empleado'];
    $_SESSION['user_name'] = $user['nombre'] . ' ' . $user['apellido'];
    $_SESSION['user_role'] = $user['rol'];

    header('Location: ../../views/dashboard/index.php');
    exit;

} catch (PDOException $e) {
    error_log($e->getMessage());
    header('Location: ../../views/auth/cambiar_password.php?error=server');
    exit;
}
