<?php
/**
 * Catalogo completo en JSON para el sitio publico.
 *
 * Se genera desde la BD y se guarda en cache/ unos minutos (config:
 * sitio.cache_minutos): todos los visitantes reciben el mismo archivo y la BD
 * solo trabaja una vez por periodo. Un cambio de precio en Inventario se ve en
 * el sitio a mas tardar al vencer la cache.
 *
 * Formato (compacto, para que pese poco):
 *   { "generado": "...", "productos": [[codigo, nombre, marca, categoria,
 *     precio, antes|null, descuento, imagen 0/1], ...], "vendidos": [codigos] }
 */

require_once __DIR__ . '/../inc/bootstrap.php';

$cfg     = configCatalogo();
$minutos = max(1, (int) $cfg['sitio']['cache_minutos']);
$dirCache = __DIR__ . '/../cache';
$archivo  = $dirCache . '/productos.json';

if (!is_file($archivo) || filemtime($archivo) < time() - $minutos * 60) {
    try {
        $pdo = catalogoBd();

        // Los "mas vendidos" del PDF, para el filtro rapido del mismo nombre.
        $vendidos = array_column(
            obtenerMasVendidos($pdo, (int) $cfg['vendidos']['dias'], (int) $cfg['vendidos']['limite'], $cfg['vendidos']['criterio']),
            'codigo'
        );

        $json = json_encode([
            'generado'  => date('c'),
            'productos' => obtenerCatalogoPublico($pdo, (int) $cfg['ofertas']['dias']),
            'vendidos'  => $vendidos,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

        if (!is_dir($dirCache)) {
            mkdir($dirCache, 0755, true);
        }
        // Se escribe aparte y se renombra: nadie lee un archivo a medio escribir.
        $temporal = $archivo . '.' . getmypid() . '.tmp';
        file_put_contents($temporal, $json);
        rename($temporal, $archivo);

    } catch (Throwable $e) {
        error_log('Catalogo publico: ' . $e->getMessage());
        // Si la BD falla pero hay una copia vieja, se sirve esa: mejor precios de
        // hace un rato que un catalogo vacio.
        if (!is_file($archivo)) {
            http_response_code(503);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => 'El catálogo no está disponible en este momento.']);
            exit;
        }
    }
}

$etag = '"' . md5_file($archivo) . '"';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=' . ($minutos * 60));
header('ETag: ' . $etag);

if (trim($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
    http_response_code(304);
    exit;
}

// Comprimido si el navegador lo acepta (~2.2 MB -> ~450 KB).
if (!ini_get('zlib.output_compression') && str_contains($_SERVER['HTTP_ACCEPT_ENCODING'] ?? '', 'gzip')) {
    ob_start('ob_gzhandler');
}
readfile($archivo);
