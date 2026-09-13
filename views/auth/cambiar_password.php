<?php
session_start();
require_once __DIR__ . '/../partials/assets.php';

if (!isset($_SESSION['cambio_password_id'])) {
    header('Location: login.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cambiar Contraseña - Comercializadora GA-BE</title>
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

        .link-cancelar {
            display: block;
            margin-top: 1rem;
            text-align: center;
            font-size: 0.85rem;
            color: #4a5568;
        }
    </style>
</head>
<body>

    <div class="circle-container">
        <div class="brand-content">
            <img src="../../public/img/GA-BE_logo_blanco.png" alt="Logo Comercializadora GA-BE" class="brand-logo">
            <h1 class="brand-title">COMERCIALIZADORA GA-BE</h1>
        </div>
    </div>

    <div class="login-wrapper">
        <div class="login-card">
            <div class="login-header">
                <h2>Cambiar Contraseña</h2>
                <p>Por seguridad, elige una contraseña nueva antes de continuar</p>
            </div>

            <?php if (isset($_GET['error'])): ?>
                <div class="alert-error">
                    <?php
                        switch ($_GET['error']) {
                            case 'corta':
                                echo 'La contraseña debe tener al menos 6 caracteres.';
                                break;
                            case 'no_coincide':
                                echo 'Las contraseñas no coinciden.';
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

            <form action="../../app/controllers/CambiarPasswordController.php" method="POST">
                <div class="form-group">
                    <label for="password">Nueva contraseña</label>
                    <input type="password" id="password" name="password" required minlength="6" placeholder="Mínimo 6 caracteres" autocomplete="new-password">
                </div>

                <div class="form-group">
                    <label for="confirmar">Confirmar contraseña</label>
                    <input type="password" id="confirmar" name="confirmar" required minlength="6" placeholder="Repite la contraseña" autocomplete="new-password">
                </div>

                <button type="submit" class="btn-submit">Guardar y entrar</button>
            </form>

            <a href="../../app/controllers/LogoutController.php" class="link-cancelar">Cancelar y volver al inicio de sesión</a>
        </div>
    </div>

</body>
</html>
