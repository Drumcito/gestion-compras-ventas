<?php
/**
 * Imagen de un producto: img.php?c=BNS01-00001[&t=400][&v=1729800000]
 *
 * Las imagenes viven en public/img/productos/ del sistema (la misma carpeta que
 * usa el PDF), fuera de la raiz del subdominio. Este archivo las entrega sin
 * duplicarlas. Solo sirve archivos de esa carpeta cuyo nombre sea un codigo
 * valido (lo resuelve imagenesDisponibles()): no hay forma de pedir otra ruta.
 *
 *   t=400  la miniatura de 400 px (tarjetas). Si el producto no la tiene (la
 *          imagen se copio a mano al servidor), se entrega la grande.
 *   v=...  version de la imagen. Con ella la respuesta se guarda un año sin
 *          volver a preguntar: al cambiar la imagen cambia la version, y con
 *          ella la direccion.
 */

require_once __DIR__ . '/inc/bootstrap.php';

$codigo = (string) ($_GET['c'] ?? '');
$ruta   = preg_match('/^[A-Za-z0-9_-]{1,20}$/', $codigo) ? imagenProducto($codigo) : null;

if ($ruta !== null && ($_GET['t'] ?? '') === '400') {
    $ruta = miniaturaProducto($codigo) ?? $ruta;
}

if ($ruta === null || !is_file($ruta)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Sin imagen');
}

$tipo = preg_match('/\.png$/i', $ruta) ? 'image/png' : 'image/jpeg';
$etag = '"' . filemtime($ruta) . '-' . filesize($ruta) . '"';

header('Content-Type: ' . $tipo);
header('Cache-Control: ' . (isset($_GET['v'])
    ? 'public, max-age=31536000, immutable'
    : 'public, max-age=604800'));    // sin version: una semana
header('ETag: ' . $etag);
header('X-Content-Type-Options: nosniff');

if (trim($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
    http_response_code(304);
    exit;
}

header('Content-Length: ' . filesize($ruta));
readfile($ruta);
