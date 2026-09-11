<?php
/**
 * Menú lateral (escritorio / tablet horizontal) y, para celular / tablet
 * vertical, una cabecera con el usuario arriba y un menú inferior. Se incluye
 * desde cada vista definiendo antes:
 *
 *   $seccionActiva = 'venta' | 'dashboard' | 'historial' | 'usuarios' | 'inventario';
 *
 * La opción "Usuarios" solo se dibuja para el rol admin. El control real está
 * en UsuarioController y en la propia vista: esto es únicamente la interfaz.
 */

$seccionActiva  = $seccionActiva ?? '';
$nombreUsuario  = $_SESSION['user_name'] ?? 'Usuario';
$rolUsuario     = ucfirst($_SESSION['user_role'] ?? 'Rol');
$esAdministrador = ($_SESSION['user_role'] ?? '') === 'admin';

$opciones = [
    ['clave' => 'venta',      'texto' => 'Venta',      'icono' => 'ph-currency-dollar-simple', 'url' => '../dashboard/index.php'],
    ['clave' => 'dashboard',  'texto' => 'Dashboard',  'icono' => 'ph-chart-line-up',          'url' => '../estadisticas/index.php'],
    ['clave' => 'historial',  'texto' => 'Historial',  'icono' => 'ph-clock-counter-clockwise','url' => '../historial/index.php'],
    ['clave' => 'usuarios',   'texto' => 'Usuarios',   'icono' => 'ph-user-circle',            'url' => '../users/index.php', 'soloAdmin' => true],
    ['clave' => 'inventario', 'texto' => 'Inventario', 'icono' => 'ph-shopping-cart',          'url' => '#'],
];

$visibles = array_filter($opciones, function ($o) use ($esAdministrador) {
    return empty($o['soloAdmin']) || $esAdministrador;
});

$nombreSeguro = htmlspecialchars($nombreUsuario, ENT_QUOTES, 'UTF-8');
$rolSeguro    = htmlspecialchars($rolUsuario, ENT_QUOTES, 'UTF-8');
$urlSalir     = '../../app/controllers/LogoutController.php';
?>
<aside class="sidebar">
    <div class="user-profile">
        <img src="../../public/img/GA-BE_logo_blanco.png" alt="Logo Comercializadora GA-BE" class="brand-logo">
        <h3><?= $nombreSeguro ?></h3>
        <p><?= $rolSeguro ?></p>
    </div>

    <ul class="sidebar-menu">
        <?php foreach ($visibles as $o): ?>
            <?php $activa = $o['clave'] === $seccionActiva; ?>
            <li<?= $activa ? ' class="active"' : '' ?>>
                <a href="<?= $o['url'] ?>"<?= $activa ? ' aria-current="page"' : '' ?>>
                    <i class="ph <?= $o['icono'] ?>"></i> <?= $o['texto'] ?>
                </a>
            </li>
        <?php endforeach; ?>
        <li class="menu-salir">
            <a href="<?= $urlSalir ?>"><i class="ph ph-sign-out"></i> Cerrar Sesión</a>
        </li>
    </ul>
</aside>

<!-- Solo celular / tablet vertical: quién tiene la sesión abierta. -->
<header class="mobile-header">
    <div class="mobile-header-info">
        <h3><?= $nombreSeguro ?></h3>
        <p><?= $rolSeguro ?></p>
    </div>
    <div class="mobile-header-logo">
        <img src="../../public/img/GA-BE_logo_blanco.png" alt="Logo Comercializadora GA-BE">
    </div>
</header>

<nav class="bottom-nav">
    <?php foreach ($visibles as $o): ?>
        <?php $activa = $o['clave'] === $seccionActiva; ?>
        <a href="<?= $o['url'] ?>"<?= $activa ? ' class="active" aria-current="page"' : '' ?>
           title="<?= $o['texto'] ?>" aria-label="<?= $o['texto'] ?>"><i class="ph <?= $o['icono'] ?>"></i></a>
    <?php endforeach; ?>
    <a href="<?= $urlSalir ?>" class="bottom-nav-salir" title="Salir" aria-label="Cerrar sesión">
        <i class="ph ph-sign-out"></i>
    </a>
</nav>
