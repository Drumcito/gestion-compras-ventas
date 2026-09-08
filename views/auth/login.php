<?php require_once __DIR__ . '/../partials/assets.php'; ?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Iniciar Sesión - Comercializadora GA-BE</title>
    <!-- Vinculación del CSS modular -->
    <link rel="stylesheet" href="<?= recurso('css/style.css') ?>">
    <style>
        .alert-error {
            background-color: #fee2e2;
            color: #dc2626;
            border: 1px solid #fca5a5;
            padding: 0.75rem 1rem;
            border-radius: 8px;
            font-size: 0.85rem;
            margin-bottom: 1.25rem;
            text-align: center;
            font-weight: 500;
        }
    </style>
</head>
<body>

    <!-- Elemento decorativo adaptativo (Semicírculo en PC / Círculo pequeño en Móvil) -->
    <div class="circle-container">
        <div class="brand-content">
            <img src="../../public/img/GA-BE_logo_blanco.png" alt="Logo Comercializadora GA-BE" class="brand-logo">
            <h1 class="brand-title">COMERCIALIZADORA GA-BE</h1>
        </div>
    </div>

    <!-- Contenedor del Formulario de Inicio de Sesión -->
    <div class="login-wrapper">
        <div class="login-card">
            <div class="login-header">
                <h2>Iniciar Sesión</h2>
                <p>Ingresa tus credenciales para acceder</p>
            </div>

            <!-- Bloque de Notificaciones de Error -->
            <?php if (isset($_GET['error'])): ?>
                <div class="alert-error">
                    <?php 
                        switch ($_GET['error']) {
                            case 'empty':
                                echo 'Por favor, llena todos los campos.';
                                break;
                            case 'invalid':
                                echo 'Usuario o contraseña incorrectos.';
                                break;
                            case 'server':
                                echo 'Error de conexión. Inténtalo más tarde.';
                                break;
                            default:
                                echo 'Ocurrió un error inesperado.';
                        }
                    ?>
                </div>
            <?php endif; ?>

            <form action="../../app/controllers/AuthController.php" method="POST">
                <div class="form-group">
                    <label for="numero_empleado">Usuario / N° de Empleado</label>
                    <input type="text" id="numero_empleado" name="numero_empleado" required placeholder="Ej. EMP-001" autocomplete="username">
                </div>

                <div class="form-group">
                    <label for="password">Contraseña</label>
                    <input type="password" id="password" name="password" required placeholder="••••••••" autocomplete="current-password">
                </div>

                <button type="submit" class="btn-submit">Ingresar</button>
            </form>
        </div>
    </div>

</body>
</html>