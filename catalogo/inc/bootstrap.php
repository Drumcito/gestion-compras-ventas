<?php
/**
 * Arranque comun del catalogo en linea (carpeta catalogo/).
 *
 * Esta carpeta es la raiz del subdominio (catalogo.ga-be.net): todo lo que esta
 * fuera de ella (config/, app/, la BD) no se ve desde internet, pero aqui se
 * reutiliza tal cual. Asi el catalogo publico y el sistema interno leen los
 * mismos productos, precios, imagenes y configuracion.
 *
 * El sitio publico no inicia sesion ni pone cookies: el aviso de privacidad lo
 * dice, y debe seguir siendo cierto.
 */

require_once __DIR__ . '/../../app/helpers/catalogo_ofertas.php';   // configCatalogo(), casas.php
require_once __DIR__ . '/../../app/helpers/correo.php';             // correo + secreto para firmar

date_default_timezone_set('America/Mexico_City');

/** Escapa texto para HTML. */
function e($texto): string
{
    return htmlspecialchars((string) $texto, ENT_QUOTES, 'UTF-8');
}

/** Conexion a la BD, solo cuando una pagina la necesita. */
function catalogoBd(): PDO
{
    require_once __DIR__ . '/../../config/conexionBD.php';
    return Database::getConnection();
}

/** Solo los digitos de un telefono, con lada de Mexico para WhatsApp (wa.me/52...). */
function numeroWhatsapp(string $telefono): string
{
    $digitos = preg_replace('/\D+/', '', $telefono);
    return strlen($digitos) === 10 ? '52' . $digitos : $digitos;
}

/** Enlace de WhatsApp con mensaje prellenado, o '' si no hay numero configurado. */
function enlaceWhatsapp(string $mensaje = ''): string
{
    $numero = numeroWhatsapp((string) configCatalogo()['whatsapp']);
    if ($numero === '') {
        return '';
    }
    return 'https://wa.me/' . $numero . ($mensaje !== '' ? '?text=' . rawurlencode($mensaje) : '');
}

/**
 * Un dato legal de config/catalogo.php, escapado. Si esta vacio sale marcado
 * como PENDIENTE (en rojo), para que no se publique un aviso incompleto sin
 * darse cuenta.
 */
function datoLegal(string $clave, string $queFalta): string
{
    $valor = trim((string) (configCatalogo()['legal'][$clave] ?? ''));
    return $valor !== ''
        ? e($valor)
        : '<mark class="pendiente">PENDIENTE: ' . e($queFalta) . '</mark>';
}

/** Frase sobre el IVA, segun legal.iva en la config. */
function fraseIva(): string
{
    switch (configCatalogo()['legal']['iva'] ?? '') {
        case 'incluido':
            return 'Los precios publicados ya incluyen IVA.';
        case 'mas_iva':
            return 'Los precios publicados no incluyen IVA; el impuesto se agrega al momento de facturar.';
        default:
            return '<mark class="pendiente">PENDIENTE: indicar si los precios incluyen IVA (legal.iva en config/catalogo.php)</mark>';
    }
}

/**
 * Ficha anti-robots del formulario: hora en que se dibujo la pagina, firmada.
 * Un robot que manda el formulario al instante, o reusa una ficha vieja o
 * inventada, se rechaza sin pedirle nada al visitante (ni captcha ni cookies).
 */
function fichaFormulario(?int $momento = null): string
{
    $momento = $momento ?? time();
    return $momento . '.' . substr(hash_hmac('sha256', 'contacto-' . $momento, notasSecret()), 0, 20);
}

function fichaValida(string $ficha, int $minimoSegundos = 3, int $maximoSegundos = 7200): bool
{
    if (!preg_match('/^(\d{10})\.([a-f0-9]{20})$/', $ficha, $m)) {
        return false;
    }
    $edad = time() - (int) $m[1];
    return hash_equals(fichaFormulario((int) $m[1]), $ficha)
        && $edad >= $minimoSegundos && $edad <= $maximoSegundos;
}

/** Version de un archivo de assets/ para que el navegador no use uno viejo. */
function asset(string $ruta): string
{
    $archivo = __DIR__ . '/../assets/' . $ruta;
    return 'assets/' . $ruta . (is_file($archivo) ? '?v=' . filemtime($archivo) : '');
}
