<?php
session_start();
require_once __DIR__ . '/../../config/conexionBD.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $numEmpleado = trim($_POST['numero_empleado'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if (empty($numEmpleado) || empty($password)) {
        header('Location: ../../views/auth/login.php?error=empty');
        exit;
    }

    try {
        $pdo = Database::getConnection();

        // activo = 1: un usuario dado de baja no debe poder entrar.
        $stmt = $pdo->prepare('SELECT id, numero_empleado, nombre, apellido, rol, password FROM usuarios WHERE numero_empleado = :num_empleado AND activo = 1 LIMIT 1');
        $stmt->execute(['num_empleado' => $numEmpleado]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && password_verify($password, $user['password'])) {
            session_regenerate_id(true);

            $_SESSION['user_id'] = $user['id'];
            $_SESSION['num_empleado'] = $user['numero_empleado'];
            $_SESSION['user_name'] = $user['nombre'] . ' ' . $user['apellido'];
            $_SESSION['user_role'] = $user['rol'];

            header('Location: ../../views/dashboard/index.php');
            exit;
        } else {
            header('Location: ../../views/auth/login.php?error=invalid');
            exit;
        }

    } catch (PDOException $e) {
        error_log($e->getMessage());
        header('Location: ../../views/auth/login.php?error=server');
        exit;
    }
}