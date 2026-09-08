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
    <title>Historial de Ventas - Comercializadora GA-BE</title>

    <link rel="stylesheet" href="<?= recurso('css/style.css') ?>">
    <script src="https://unpkg.com/@phosphor-icons/web"></script>
</head>
<body class="dashboard-body">

    <?php $seccionActiva = 'historial'; include __DIR__ . '/../partials/menu.php'; ?>


    <!-- CONTENIDO PRINCIPAL -->
    <main class="main-content">
        <h1 class="page-title">Historial de Ventas</h1>

        <div class="card-form card-historial">
            <!-- Filtros -->
            <div class="filtros">
                <div class="filtros-rapidos">
                    <button type="button" class="chip chip-activo" data-rango="hoy">Hoy</button>
                    <button type="button" class="chip" data-rango="ayer">Ayer</button>
                    <button type="button" class="chip" data-rango="semana">Esta semana</button>
                    <button type="button" class="chip" data-rango="mes">Este mes</button>
                    <button type="button" class="chip" data-rango="anio">Este año</button>
                </div>

                <div class="filtros-rango">
                    <div class="campo-fecha">
                        <label for="desde">Desde:</label>
                        <input type="date" id="desde" class="form-control">
                    </div>
                    <div class="campo-fecha">
                        <label for="hasta">Hasta:</label>
                        <input type="date" id="hasta" class="form-control">
                    </div>
                    <div class="campo-fecha">
                        <label for="filtro-usuario">Vendedor:</label>
                        <select id="filtro-usuario" class="form-control">
                            <option value="0">Todos</option>
                        </select>
                    </div>
                    <button type="button" class="btn-save btn-filtrar" id="btn-filtrar">Filtrar</button>
                </div>
            </div>

            <div id="resumen-periodo" class="resumen-periodo"></div>
            <div id="aviso-historial" class="aviso" hidden></div>

            <!-- Listado de ventas -->
            <div id="lista-ventas" class="lista-ventas"></div>
        </div>
    </main>

    <!-- Detalle de la venta -->
    <div id="modal-detalle" class="modal" hidden>
        <div class="modal-caja">
            <button type="button" class="modal-cerrar" id="btn-cerrar-detalle" title="Cerrar">
                <i class="ph ph-x"></i>
            </button>
            <div id="contenido-detalle"></div>
        </div>
    </div>


    <script>window.ES_ADMIN = <?php echo $esAdmin ? 'true' : 'false'; ?>;</script>
    <script src="<?= recurso('js/historial.js') ?>"></script>
</body>
</html>
