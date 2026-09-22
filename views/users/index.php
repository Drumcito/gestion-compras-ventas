<?php
session_start();
require_once __DIR__ . '/../partials/assets.php';

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

    <link rel="stylesheet" href="<?= recurso('css/style.css') ?>">
    <script src="https://unpkg.com/@phosphor-icons/web"></script>
</head>
<body class="dashboard-body">

    <?php $seccionActiva = 'usuarios'; include __DIR__ . '/../partials/menu.php'; ?>

    <main class="main-content">
        <h1 class="page-title">Usuarios</h1>

        <div class="segmentado" role="tablist" aria-label="Secciones de usuarios" id="tabs-usuarios">
            <button type="button" class="segmento segmento-activo" role="tab"
                    aria-selected="true" data-panel="panel-usuarios">Usuarios</button>
            <button type="button" class="segmento" role="tab"
                    aria-selected="false" data-panel="panel-clientes">Clientes</button>
        </div>

        <!-- Panel: usuarios del sistema (vendedores / admin) -->
        <section class="card-form card-historial" id="panel-usuarios">
            <div class="filtros">
                <button type="button" class="btn-save" id="btn-nuevo">
                    <i class="ph ph-plus"></i> Nuevo usuario
                </button>
            </div>

            <div id="aviso-usuarios" class="aviso" hidden></div>
            <div id="lista-usuarios" class="lista-ventas"></div>
        </section>

        <!-- Panel: clientes (comercios que se visitan) -->
        <section class="card-form card-historial" id="panel-clientes" hidden>
            <div class="filtros">
                <button type="button" class="btn-save" id="btn-nuevo-cliente">
                    <i class="ph ph-plus"></i> Dar de alta cliente
                </button>
            </div>

            <div id="aviso-clientes" class="aviso" hidden></div>
            <div id="lista-clientes" class="lista-ventas"></div>
        </section>
    </main>

    <!-- Alta / edición de usuario -->
    <div id="modal-usuario" class="modal" hidden>
        <div class="modal-caja">
            <button type="button" class="modal-cerrar" id="btn-cerrar-usuario" title="Cerrar">
                <i class="ph ph-x"></i>
            </button>
            <div id="contenido-usuario"></div>
        </div>
    </div>

    <!-- Alta / edición de cliente -->
    <div id="modal-cliente" class="modal" hidden>
        <div class="modal-caja">
            <button type="button" class="modal-cerrar" id="btn-cerrar-cliente" title="Cerrar">
                <i class="ph ph-x"></i>
            </button>
            <div id="contenido-cliente"></div>
        </div>
    </div>

    <script src="<?= recurso('js/usuarios.js') ?>"></script>
    <script src="<?= recurso('js/clientes.js') ?>"></script>
    <script>
        // Cambio de pestaña Usuarios / Clientes. Simple: muestra un panel y
        // oculta el otro; cada panel carga sus datos por su cuenta.
        (function () {
            const tabs = document.getElementById('tabs-usuarios');
            if (!tabs) return;

            tabs.addEventListener('click', (e) => {
                const boton = e.target.closest('.segmento');
                if (!boton) return;

                tabs.querySelectorAll('.segmento').forEach((b) => {
                    const activo = b === boton;
                    b.classList.toggle('segmento-activo', activo);
                    b.setAttribute('aria-selected', activo ? 'true' : 'false');
                    document.getElementById(b.dataset.panel).hidden = !activo;
                });
            });
        })();
    </script>
</body>
</html>
