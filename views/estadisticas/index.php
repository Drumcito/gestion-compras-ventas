<?php
session_start();
require_once __DIR__ . '/../partials/assets.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit;
}

require_once __DIR__ . '/../../config/conexionBD.php';

$casas = Database::getConnection()
    ->query('SELECT codigo_casa, nombre FROM casas WHERE activo = 1 ORDER BY codigo_casa')
    ->fetchAll(PDO::FETCH_ASSOC);

/**
 * Una tarjeta de grafica con su tabla gemela: la tabla muestra los mismos
 * datos para quien no puede (o no quiere) leer la grafica.
 */
function tarjetaGrafica(string $id, string $titulo, string $subtitulo = '', string $extraCabecera = ''): void
{
    ?>
    <section class="dash-card" id="card-<?= $id ?>">
        <header class="dash-card-cabecera">
            <div>
                <h2 class="dash-card-titulo"><?= $titulo ?></h2>
                <?php if ($subtitulo !== ''): ?>
                    <p class="dash-card-sub" id="sub-<?= $id ?>"><?= $subtitulo ?></p>
                <?php endif; ?>
            </div>
            <div class="dash-card-acciones">
                <?= $extraCabecera ?>
                <button type="button" class="chip chip-mini btn-tabla" data-card="<?= $id ?>"
                        aria-pressed="false" title="Ver los datos como tabla">
                    <i class="ph ph-table"></i> Tabla
                </button>
            </div>
        </header>
        <div class="dash-leyenda" id="leyenda-<?= $id ?>"></div>
        <div class="dash-grafica" id="grafica-<?= $id ?>">
            <canvas id="canvas-<?= $id ?>"></canvas>
            <p class="dash-vacio" hidden></p>
        </div>
        <div class="dash-tabla" id="tabla-<?= $id ?>" hidden></div>
    </section>
    <?php
}

$toggleMetrica = function (string $grupo): string {
    return '<div class="segmentado" role="group" aria-label="Medir por" data-grupo="' . $grupo . '">'
        . '<button type="button" class="segmento segmento-activo" data-metrica="piezas" aria-pressed="true">Piezas</button>'
        . '<button type="button" class="segmento" data-metrica="importe" aria-pressed="false">Importe</button>'
        . '</div>';
};
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Comercializadora GA-BE</title>

    <link rel="stylesheet" href="<?= recurso('css/style.css') ?>">
    <script src="https://unpkg.com/@phosphor-icons/web"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
</head>
<body class="dashboard-body">

    <?php $seccionActiva = 'dashboard'; include __DIR__ . '/../partials/menu.php'; ?>


    <!-- CONTENIDO PRINCIPAL -->
    <main class="main-content main-dashboard">
        <h1 class="page-title">Dashboard</h1>

        <!-- Filtros: una sola fila que manda sobre todas las graficas -->
        <div class="card-form dash-filtros">
            <div class="filtros-rapidos">
                <button type="button" class="chip chip-activo" data-rango="hoy">Hoy</button>
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
                    <label for="filtro-casa">Casa:</label>
                    <select id="filtro-casa" class="form-control">
                        <option value="TODAS">Todas</option>
                        <?php foreach ($casas as $casa): ?>
                            <option value="<?= htmlspecialchars($casa['codigo_casa'], ENT_QUOTES, 'UTF-8') ?>">
                                <?= htmlspecialchars($casa['nombre'], ENT_QUOTES, 'UTF-8') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="button" class="btn-save btn-filtrar" id="btn-filtrar">Filtrar</button>
            </div>

            <p class="dash-periodo" id="dash-periodo"></p>
        </div>

        <div id="aviso-dashboard" class="aviso dash-aviso" hidden></div>

        <div class="dash-contenido" id="dash-contenido">
            <!-- Indicadores -->
            <div class="kpis" id="kpis"></div>

            <div class="dash-grid">
                <?php tarjetaGrafica('ventas', 'Ventas en el tiempo', 'Importe vendido'); ?>
                <?php tarjetaGrafica('casas', 'Ventas por casa', 'Piezas vendidas de cada casa', $toggleMetrica('casas')); ?>
                <?php tarjetaGrafica('productos', 'Productos más vendidos', 'Top 10', $toggleMetrica('productos')); ?>
                <?php tarjetaGrafica('precios', 'Cambios de precio', 'Cada precio (menudeo y mayoreo) cuenta por separado'); ?>
            </div>

            <!-- Tablas de detalle -->
            <div class="dash-grid dash-grid-tablas">
                <section class="dash-card dash-card-ancha">
                    <header class="dash-card-cabecera">
                        <div>
                            <h2 class="dash-card-titulo">Productos con más cambios de precio</h2>
                            <p class="dash-card-sub">Cuántas veces subió o bajó cada uno en el periodo</p>
                        </div>
                    </header>
                    <div id="tabla-cambios-precio"></div>
                </section>

                <section class="dash-card">
                    <header class="dash-card-cabecera">
                        <div>
                            <h2 class="dash-card-titulo">Mejores clientes</h2>
                            <p class="dash-card-sub">Por importe comprado (ventas con nombre de cliente)</p>
                        </div>
                    </header>
                    <div id="tabla-clientes"></div>
                </section>

                <section class="dash-card">
                    <header class="dash-card-cabecera">
                        <div>
                            <h2 class="dash-card-titulo">Ventas por vendedor</h2>
                            <p class="dash-card-sub">Por importe vendido</p>
                        </div>
                    </header>
                    <div id="tabla-vendedores"></div>
                </section>
            </div>
        </div>
    </main>


    <script src="<?= recurso('js/estadisticas.js') ?>"></script>
</body>
</html>
