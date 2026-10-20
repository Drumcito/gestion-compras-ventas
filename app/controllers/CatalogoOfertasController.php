<?php
/**
 * Catalogo PDF de dos hojas: 1) top 10 mas vendidos, 2) top 5 ofertas (los que
 * mas bajaron de precio). Cantidades, ventanas y textos: config/catalogo.php.
 *
 *   ?dias=30     ventana de las dos hojas (si no, la de cada una en la config)
 *   ?muestra=1   vista previa con productos y precios "Antes" inventados, para
 *                revisar el diseno sin datos. El PDF lo dice en grande.
 *   ?descargar=1 descarga el archivo en vez de abrirlo en el navegador
 *
 * Una hoja sin datos (p. ej. ninguna bajada de precio en la ventana) se omite;
 * si las dos salen vacias se avisa en pantalla.
 *
 * Reservado al admin, como todo lo que toca precios (ver PrecioController).
 */

session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../views/auth/login.php');
    exit;
}

if (($_SESSION['user_role'] ?? '') !== 'admin') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Solo un administrador puede generar el catalogo.');
}

require_once __DIR__ . '/../../config/conexionBD.php';
require_once __DIR__ . '/../helpers/catalogo_ofertas.php';
require_once __DIR__ . '/../helpers/catalogo_pdf.php';

$cfg      = configCatalogo();
$vendidos = $cfg['vendidos'];
$ofertas  = $cfg['ofertas'];
$muestra  = ($_GET['muestra'] ?? '') === '1';

if (isset($_GET['dias'])) {
    $vendidos['dias'] = $ofertas['dias'] = max(1, min(365, (int) $_GET['dias']));
}

try {
    $pdo = Database::getConnection();

    if ($muestra) {
        // Mas vendidos: algunos con descuento, otros sin; ofertas: todos con descuento.
        $topVendidos = obtenerMuestra($pdo, (int) $vendidos['limite'], 0, [0, 15, 0, 0, 25, 0, 0, 10, 0, 0]);
        $topOfertas  = obtenerMuestra($pdo, (int) $ofertas['limite'], 40, [35, 30, 25, 20, 15]);
    } else {
        $topVendidos = obtenerMasVendidos($pdo, (int) $vendidos['dias'], (int) $vendidos['limite'], $vendidos['criterio']);
        $topOfertas  = obtenerTopOfertas($pdo, (int) $ofertas['dias'], (int) $ofertas['limite']);
    }

    $paginas = [];
    if ($topVendidos) {
        $paginas[] = ['tipo' => 'vendidos', 'textos' => $vendidos, 'productos' => $topVendidos];
    }
    if ($topOfertas) {
        $paginas[] = ['tipo' => 'ofertas', 'textos' => $ofertas, 'productos' => $topOfertas];
    }

    if (!$paginas) {
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html lang="es"><meta charset="UTF-8">'
           . '<title>Catálogo</title>'
           . '<body style="font-family:sans-serif;max-width:34rem;margin:3rem auto;padding:0 1rem;line-height:1.5">'
           . '<h2>Sin productos que mostrar</h2>'
           . '<p>No hubo ventas ni bajadas de precio en la ventana configurada '
           . '(' . (int) $vendidos['dias'] . ' días para más vendidos, '
           . (int) $ofertas['dias'] . ' para ofertas).</p>'
           . '<p><a href="?dias=90">Buscar en los últimos 90 días</a> · '
           . '<a href="?muestra=1">Ver vista previa del diseño</a></p>'
           . '</body></html>';
        exit;
    }

    $pdf = construirCatalogoPdf($paginas, $cfg, $muestra);

} catch (Throwable $e) {
    error_log('Catalogo PDF: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    exit('No se pudo generar el catalogo.');
}

$nombre = 'catalogo-ga-be-' . date('Y-m-d') . ($muestra ? '-MUESTRA' : '') . '.pdf';
$modo   = ($_GET['descargar'] ?? '') === '1' ? 'attachment' : 'inline';

header('Content-Type: application/pdf');
header('Content-Disposition: ' . $modo . '; filename="' . $nombre . '"');
header('Content-Length: ' . strlen($pdf));
header('Cache-Control: private, max-age=0, must-revalidate');
echo $pdf;
