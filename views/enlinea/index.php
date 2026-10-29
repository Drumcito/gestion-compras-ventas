<?php
/**
 * Pedidos y ventas que llegaron por el catálogo en línea.
 *
 * Son el mismo flujo visto en dos momentos:
 *
 *   - Arriba, los pedidos POR ATENDER: lo que mandó un cliente desde el
 *     catálogo y todavía nadie cobró. De ahí se pasan a Venta o se descartan.
 *   - Abajo, las ventas que YA salieron de un pedido, con su folio.
 *
 * La ven los dos roles, pero con distinto alcance: el admin todas las ventas,
 * el vendedor solo las que él atendió. El control real está en
 * PedidoLineaController; esto es nada más la pantalla.
 */
session_start();
require_once __DIR__ . '/../partials/assets.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit;
}

$esAdmin = ($_SESSION['user_role'] ?? '') === 'admin';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ventas en línea - Comercializadora GA-BE</title>

    <link rel="stylesheet" href="<?= recurso('css/style.css') ?>">
    <script src="https://unpkg.com/@phosphor-icons/web"></script>
</head>
<body class="dashboard-body">

    <?php $seccionActiva = 'enlinea'; include __DIR__ . '/../partials/menu.php'; ?>

    <main class="main-content">
        <h1 class="page-title">En línea</h1>

        <div id="aviso" class="aviso" hidden></div>

        <!-- Pedidos del catálogo que nadie ha cobrado todavía -->
        <div id="pedidos-linea" class="pedidos-linea" hidden></div>

        <div class="card-form card-historial">
            <div class="filtros">
                <div class="filtros-busqueda">
                    <div class="search-container">
                        <input type="text" id="buscar-enlinea" class="form-control"
                               placeholder="Buscar por folio, cliente o número de venta…"
                               autocomplete="off">
                        <i class="ph ph-magnifying-glass search-icon"></i>
                    </div>
                </div>
            </div>

            <div id="resumen-enlinea" class="resumen-periodo"></div>
            <div id="lista-enlinea" class="lista-ventas"></div>
        </div>
    </main>

    <!-- Detalle de una venta -->
    <div id="modal-detalle" class="modal" hidden>
        <div class="modal-caja">
            <button type="button" class="modal-cerrar" id="btn-cerrar-detalle" title="Cerrar">
                <i class="ph ph-x"></i>
            </button>
            <div id="contenido-detalle"></div>
        </div>
    </div>

    <script>
        window.ES_ADMIN   = <?php echo $esAdmin ? 'true' : 'false'; ?>;
        // Cargar un pedido en Venta pasa por el borrador del navegador, que va
        // por usuario (ver venta.js).
        window.USUARIO_ID = <?php echo (int) ($_SESSION['user_id'] ?? 0); ?>;
    </script>
    <script src="<?= recurso('js/enlinea.js') ?>"></script>
</body>
</html>
