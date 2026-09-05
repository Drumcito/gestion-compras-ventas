<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit;
}

$nombreUsuario = $_SESSION['user_name'] ?? 'Usuario';
$rolUsuario = ucfirst($_SESSION['user_role'] ?? 'Rol');
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

    <!-- SIDEBAR (Pantallas Medianas y Grandes) -->
    <aside class="sidebar">
        <div class="user-profile">
            <!-- LOGO OFICIAL GA-BE -->
            <img src="../../public/img/GA-BE_logo_blanco.png" alt="Logo Comercializadora GA-BE" class="brand-logo">
            <h3><?php echo htmlspecialchars($nombreUsuario); ?></h3>
            <p><?php echo htmlspecialchars($rolUsuario); ?></p>
        </div>

        <ul class="sidebar-menu">
            <li class="active">
                <a href="index.php"><i class="ph ph-currency-dollar-simple"></i> Venta</a>
            </li>
            <li>
                <a href="#"><i class="ph ph-chart-line-up"></i> Dashboard</a>
            </li>
            <li>
                <a href="#"><i class="ph ph-clock-counter-clockwise"></i> Historial</a>
            </li>
            <li>
                <a href="../users/index.php"><i class="ph ph-user-circle"></i> Usuarios</a>
            </li>
            <li>
                <a href="#"><i class="ph ph-shopping-cart"></i> Inventario</a>
            </li>
            <li style="margin-top: 2rem;">
                <a href="../auth/logout.php" style="color: #ffcccc;"><i class="ph ph-sign-out"></i> Cerrar Sesión</a>
            </li>
        </ul>
    </aside>

    <!-- CONTENIDO PRINCIPAL -->
    <main class="main-content">
        <h1 class="page-title">Venta</h1>

        <div class="card-form">
            <form action="#" method="POST">
                <!-- Cliente -->
                <div class="form-group">
                    <label for="cliente">Cliente:</label>
                    <input type="text" id="cliente" name="cliente" class="form-control">
                </div>

                <!-- Proveedor (Casa) -->
                <div class="form-group">
                    <label for="proveedor">Proveedor (Casa):</label>
                    <select id="proveedor" name="proveedor" class="form-control">
                        <option value="Tlapa">Tlapa</option>
                        <option value="Matriz">Matriz</option>
                    </select>
                </div>

                <!-- Piezas / Búsqueda -->
                <div class="form-group">
                    <label for="piezas">Piezas:</label>
                    <div class="search-container">
                        <input type="text" id="piezas" name="piezas" class="form-control" placeholder="Buscar producto...">
                        <i class="ph ph-magnifying-glass search-icon"></i>
                        <button type="button" class="btn-add"><i class="ph ph-plus"></i></button>
                    </div>
                </div>

                <!-- Detalle del Producto -->
                <div class="product-summary-row">
                    <div class="summary-item">
                        <h4>Precio C/U:</h4>
                        <p style="font-size: 1.1rem; font-weight: 500;">$25</p>
                    </div>

                    <div class="summary-item">
                        <h4>Piezas:</h4>
                        <p style="font-weight: 500;">Candado | Phillips, 63mm</p>
                    </div>

                    <div class="summary-item">
                        <h4>Cantidad:</h4>
                        <div class="quantity-control">
                            <input type="number" value="7" class="input-qty" readonly>
                            <button type="button" class="btn-remove"><i class="ph ph-minus"></i></button>
                        </div>
                    </div>
                </div>

                <!-- Footer del Formulario -->
                <div class="card-footer-action">
                    <div class="total-price">
                        Total: &nbsp;&nbsp;$175
                    </div>
                    <button type="submit" class="btn-save">Guardar</button>
                </div>
            </form>
        </div>
    </main>

    <!-- MENÚ INFERIOR MÓVIL (Puros íconos fijados abajo) -->
    <nav class="bottom-nav">
        <a href="index.php" class="active" title="Venta"><i class="ph ph-currency-dollar-simple"></i></a>
        <a href="#" title="Dashboard"><i class="ph ph-chart-line-up"></i></a>
        <a href="#" title="Historial"><i class="ph ph-clock-counter-clockwise"></i></a>
        <a href="../users/index.php" title="Usuarios"><i class="ph ph-user-circle"></i></a>
        <a href="#" title="Inventario"><i class="ph ph-shopping-cart"></i></a>
        <a href="../auth/logout.php" title="Salir" style="color: #ffaaaa;"><i class="ph ph-sign-out"></i></a>
    </nav>

</body>
</html>