<?php
session_start();
require_once __DIR__ . '/../partials/assets.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit;
}

require_once __DIR__ . '/../../config/conexionBD.php';
require_once __DIR__ . '/../../app/helpers/casas.php';

$nombreUsuario = $_SESSION['user_name'] ?? 'Usuario';
$rolUsuario = ucfirst($_SESSION['user_role'] ?? 'Rol');

$casas = Database::getConnection()
    ->query('SELECT codigo_casa, nombre FROM casas WHERE activo = 1')
    ->fetchAll(PDO::FETCH_ASSOC);

// El orden y la etiqueta visible salen del mapa de app/helpers/casas.php.
$casas = ordenarCasas($casas);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Venta - Comercializadora GA-BE</title>
    
    <!-- CSS Modular -->
    <link rel="stylesheet" href="<?= recurso('css/style.css') ?>">
    
    <!-- Librería de Íconos -->
    <script src="https://unpkg.com/@phosphor-icons/web"></script>
</head>
<body class="dashboard-body">

    <?php $seccionActiva = 'venta'; include __DIR__ . '/../partials/menu.php'; ?>


    <!-- CONTENIDO PRINCIPAL -->
    <main class="main-content">
        <h1 class="page-title">Venta</h1>

        <div class="card-form">
            <form id="form-venta" autocomplete="off">
                <!-- Cliente -->
                <div class="form-group">
                    <label for="cliente">Cliente:</label>
                    <div class="search-container">
                        <input type="text" id="cliente" name="cliente" class="form-control"
                               maxlength="150" autocomplete="off"
                               placeholder="Escribe nombre, apellido o comercio…">
                        <button type="button" id="btn-lista-clientes" class="cliente-lista-btn"
                                title="Ver lista de clientes" aria-label="Ver lista de clientes">
                            <i class="ph ph-list-bullets"></i>
                        </button>
                    </div>
                    <div id="resultados-cliente" class="search-results" hidden></div>
                </div>

                <!-- Proveedor (Casa) -->
                <div class="form-group">
                    <label for="proveedor">Proveedor (Casa):</label>
                    <select id="proveedor" name="proveedor" class="form-control">
                        <option value="TODAS">Todas las casas (buscar en todo el catálogo)</option>
                        <?php foreach ($casas as $casa): ?>
                            <option value="<?= htmlspecialchars($casa['codigo_casa'], ENT_QUOTES, 'UTF-8') ?>">
                                <?= htmlspecialchars($casa['etiqueta'], ENT_QUOTES, 'UTF-8') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Piezas / Búsqueda -->
                <div class="form-group">
                    <label for="piezas">Piezas:</label>
                    <div class="search-container">
                        <input type="text" id="piezas" class="form-control"
                               placeholder="Buscar por nombre o código..." autocomplete="off">
                        <i class="ph ph-magnifying-glass search-icon" aria-hidden="true"></i>
                    </div>
                    <div id="resultados" class="search-results" hidden></div>
                </div>

                <!-- Productos agregados (dinámico) -->
                <div id="lista-items" class="venta-items">
                    <p class="venta-vacia">Aún no has agregado piezas a esta venta.</p>
                </div>

                <!-- Pieza que no está en el catálogo: se captura a mano y va a la casa Otros -->
                <div class="venta-otro">
                    <button type="button" class="btn-agregar-otro" id="btn-agregar-otro"
                            title="Agregar un producto que no está en el catálogo">
                        <i class="ph ph-plus"></i> Nuevo producto
                    </button>
                </div>

                <!-- Tipo de pago -->
                <div class="form-group">
                    <label for="tipo_pago">Tipo de pago:</label>
                    <select id="tipo_pago" name="tipo_pago" class="form-control">
                        <option value="contado">Contado</option>
                        <option value="credito">Crédito</option>
                    </select>
                </div>

                <div id="campos-credito" hidden>
                    <div class="form-group">
                        <label for="pago_inicial">Pago inicial (puede ser 0):</label>
                        <input type="number" id="pago_inicial" class="form-control"
                               min="0" step="0.01" value="0">
                    </div>
                    <div class="form-group">
                        <label for="fecha_vencimiento">Fecha de vencimiento:</label>
                        <input type="date" id="fecha_vencimiento" class="form-control">
                    </div>
                </div>

                <div id="bloque-saldo" class="bloque-saldo" hidden></div>

                <div id="aviso-venta" class="aviso" hidden></div>

                <!-- Footer del Formulario -->
                <div class="card-footer-action">
                    <div class="total-price">
                        Total: &nbsp;&nbsp;<span id="total-venta">$0.00</span>
                    </div>
                    <button type="submit" class="btn-save" id="btn-guardar">Guardar</button>
                </div>
            </form>
        </div>
    </main>

    <!-- Nuevo producto (casa Otros) -->
    <div id="modal-otro" class="modal" hidden>
        <div class="modal-caja">
            <button type="button" class="modal-cerrar" id="btn-cerrar-otro" title="Cerrar">
                <i class="ph ph-x"></i>
            </button>
            <form id="form-otro" autocomplete="off">
                <h2 class="detalle-titulo">Nuevo producto</h2>
                <p class="detalle-sub">Se guarda en la casa <strong>Otros</strong> y se agrega a esta venta.</p>

                <div class="form-group">
                    <label for="otro-nombre">Nombre o descripción del producto:</label>
                    <input type="text" id="otro-nombre" class="form-control" maxlength="150" required>
                </div>

                <div class="form-group">
                    <label for="otro-bruto">Precio antes del <span id="otro-porcentaje">13</span>%:</label>
                    <input type="number" id="otro-bruto" class="form-control" min="0.01" step="0.01" required>
                </div>

                <p class="otro-final">Precio final: <strong id="otro-final">$0.00</strong></p>

                <div id="otro-aviso" class="aviso" hidden></div>

                <div class="detalle-acciones">
                    <button type="button" class="chip" id="btn-cancelar-otro">Cancelar</button>
                    <button type="submit" class="btn-save" id="btn-guardar-otro">Guardar</button>
                </div>
            </form>
        </div>
    </div>


    <script src="<?= recurso('js/venta.js') ?>"></script>
</body>
</html>