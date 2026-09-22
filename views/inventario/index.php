<?php
session_start();
require_once __DIR__ . '/../partials/assets.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit;
}

$nombreUsuario = $_SESSION['user_name'] ?? 'Usuario';
$rolUsuario = ucfirst($_SESSION['user_role'] ?? 'Rol');
$esAdmin = ($_SESSION['user_role'] ?? '') === 'admin';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventario - Comercializadora GA-BE</title>

    <link rel="stylesheet" href="<?= recurso('css/style.css') ?>">
    <script src="https://unpkg.com/@phosphor-icons/web"></script>
</head>
<body class="dashboard-body">

    <?php $seccionActiva = 'inventario'; include __DIR__ . '/../partials/menu.php'; ?>

    <main class="main-content">
        <h1 class="page-title">Inventario</h1>

        <div class="card-form card-historial">
            <!-- Casas -->
            <div class="filtros">
                <div class="casas-linea">
                    <div id="lista-casas" class="casas-tabs"></div>

                    <?php if ($esAdmin): ?>
                        <button type="button" class="chip chip-nuevo" id="btn-nueva-casa">
                            <i class="ph ph-plus" aria-hidden="true"></i> Nueva casa
                        </button>
                        <button type="button" class="chip" id="btn-editar-casa">
                            <i class="ph ph-pencil-simple" aria-hidden="true"></i> Editar casa
                        </button>
                    <?php endif; ?>
                </div>

                <div class="filtros-rango">
                    <div class="search-container inventario-buscador">
                        <input type="text" id="buscar-producto" class="form-control"
                               placeholder="Buscar por nombre o código..." autocomplete="off">
                        <i class="ph ph-magnifying-glass search-icon"></i>
                    </div>

                    <?php if ($esAdmin): ?>
                        <button type="button" class="chip chip-nuevo" id="btn-agregar-productos">
                            <i class="ph ph-package" aria-hidden="true"></i> Agregar productos
                        </button>
                    <?php endif; ?>
                </div>
            </div>

            <div id="casa-neto-info" class="casa-neto-info" hidden></div>
            <div id="resumen-inventario" class="resumen-periodo"></div>
            <div id="aviso-inventario" class="aviso" hidden></div>

            <div id="lista-productos" class="lista-ventas"></div>

            <div id="paginacion" class="paginacion" hidden>
                <button type="button" class="chip" id="btn-anterior">Anterior</button>
                <span id="info-pagina"></span>
                <button type="button" class="chip" id="btn-siguiente">Siguiente</button>
            </div>
        </div>
    </main>

    <!-- Casa nueva / alta de productos (solo admin) -->
    <div id="modal-casa" class="modal" hidden>
        <div class="modal-caja modal-caja-ancha">
            <button type="button" class="modal-cerrar" id="btn-cerrar-casa" title="Cerrar">
                <i class="ph ph-x"></i>
            </button>
            <div id="contenido-casa"></div>
        </div>
    </div>

    <!-- Edición de precio -->
    <div id="modal-inventario" class="modal" hidden>
        <div class="modal-caja">
            <button type="button" class="modal-cerrar" id="btn-cerrar-inventario" title="Cerrar">
                <i class="ph ph-x"></i>
            </button>
            <div id="contenido-inventario"></div>
        </div>
    </div>

    <script>window.ES_ADMIN = <?php echo $esAdmin ? 'true' : 'false'; ?>;</script>
    <script src="<?= recurso('js/inventario.js') ?>"></script>
</body>
</html>
