<?php
/**
 * Menú lateral (escritorio / tablet horizontal) y menú inferior (celular /
 * tablet vertical). Se incluye desde cada vista definiendo antes:
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
    ['clave' => 'dashboard',  'texto' => 'Dashboard',  'icono' => 'ph-chart-line-up',          'url' => '#'],
    ['clave' => 'historial',  'texto' => 'Historial',  'icono' => 'ph-clock-counter-clockwise','url' => '../historial/index.php'],
    ['clave' => 'usuarios',   'texto' => 'Usuarios',   'icono' => 'ph-user-circle',            'url' => '../users/index.php', 'soloAdmin' => true],
    ['clave' => 'inventario', 'texto' => 'Inventario', 'icono' => 'ph-shopping-cart',          'url' => '#'],
];

$visibles = array_filter($opciones, function ($o) use ($esAdministrador) {
    return empty($o['soloAdmin']) || $esAdministrador;
});
?>
<aside class="sidebar">
    <div class="user-profile">
        <img src="../../public/img/GA-BE_logo_blanco.png" alt="Logo Comercializadora GA-BE" class="brand-logo">
        <h3><?= htmlspecialchars($nombreUsuario, ENT_QUOTES, 'UTF-8') ?></h3>
        <p><?= htmlspecialchars($rolUsuario, ENT_QUOTES, 'UTF-8') ?></p>
    </div>

    <ul class="sidebar-menu">
        <?php foreach ($visibles as $o): ?>
            <li<?= $o['clave'] === $seccionActiva ? ' class="active"' : '' ?>>
                <a href="<?= $o['url'] ?>"><i class="ph <?= $o['icono'] ?>"></i> <?= $o['texto'] ?></a>
            </li>
        <?php endforeach; ?>
        <li style="margin-top: 2rem;">
            <a href="../../app/controllers/LogoutController.php" style="color: #ffcccc;">
                <i class="ph ph-sign-out"></i> Cerrar Sesión
            </a>
        </li>
    </ul>
</aside>

<nav class="bottom-nav">
    <?php foreach ($visibles as $o): ?>
        <a href="<?= $o['url'] ?>"<?= $o['clave'] === $seccionActiva ? ' class="active"' : '' ?>
           title="<?= $o['texto'] ?>"><i class="ph <?= $o['icono'] ?>"></i></a>
    <?php endforeach; ?>
    <a href="../../app/controllers/LogoutController.php" title="Salir" style="color: #ffaaaa;">
        <i class="ph ph-sign-out"></i>
    </a>
</nav>
