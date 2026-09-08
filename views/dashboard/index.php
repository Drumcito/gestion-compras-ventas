<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit;
}

require_once __DIR__ . '/../../config/conexionBD.php';

$nombreUsuario = $_SESSION['user_name'] ?? 'Usuario';
$rolUsuario = ucfirst($_SESSION['user_role'] ?? 'Rol');

$casas = Database::getConnection()
    ->query('SELECT codigo_casa, nombre FROM casas WHERE activo = 1 ORDER BY codigo_casa')
    ->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Venta - Comercializadora GA-BE</title>
    
    <!-- CSS Modular -->
    <link rel="stylesheet" href="../../public/css/style.css">
    
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
                    <input type="text" id="cliente" name="cliente" class="form-control"
                           maxlength="150" placeholder="Ej. Juan Pérez o Tlapalería X">
                </div>

                <!-- Proveedor (Casa) -->
                <div class="form-group">
                    <label for="proveedor">Proveedor (Casa):</label>
                    <select id="proveedor" name="proveedor" class="form-control">
                        <option value="TODAS">Todas las casas (buscar en todo el catálogo)</option>
                        <?php foreach ($casas as $casa): ?>
                            <option value="<?= htmlspecialchars($casa['codigo_casa'], ENT_QUOTES, 'UTF-8') ?>">
                                <?= htmlspecialchars($casa['nombre'], ENT_QUOTES, 'UTF-8') ?>
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
                        <i class="ph ph-magnifying-glass search-icon"></i>
                    </div>
                    <div id="resultados" class="search-results" hidden></div>
                </div>

                <!-- Productos agregados (dinámico) -->
                <div id="lista-items" class="venta-items">
                    <p class="venta-vacia">Aún no has agregado piezas a esta venta.</p>
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


    <script src="../../public/js/venta.js"></script>
</body>
</html>