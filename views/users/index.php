<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit;
}

// Barrera real: un vendedor que teclee la URL a mano tampoco entra.
if (($_SESSION['user_role'] ?? '') !== 'admin') {
    header('Location: ../dashboard/index.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Usuarios - Comercializadora GA-BE</title>

    <link rel="stylesheet" href="../../public/css/style.css">
    <script src="https://unpkg.com/@phosphor-icons/web"></script>
</head>
<body class="dashboard-body">

    <?php $seccionActiva = 'usuarios'; include __DIR__ . '/../partials/menu.php'; ?>

    <main class="main-content">
        <h1 class="page-title">Usuarios</h1>

        <div class="card-form card-historial">
            <div class="filtros">
                <button type="button" class="btn-save" id="btn-nuevo">
                    <i class="ph ph-plus"></i> Nuevo usuario
                </button>
            </div>

            <div id="aviso-usuarios" class="aviso" hidden></div>
            <div id="lista-usuarios" class="lista-ventas"></div>
        </div>
    </main>

    <!-- Alta / edición -->
    <div id="modal-usuario" class="modal" hidden>
        <div class="modal-caja">
            <button type="button" class="modal-cerrar" id="btn-cerrar-usuario" title="Cerrar">
                <i class="ph ph-x"></i>
            </button>
            <div id="contenido-usuario"></div>
        </div>
    </div>

    <script src="../../public/js/usuarios.js"></script>
</body>
</html>
