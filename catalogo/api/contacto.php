<?php
/**
 * Formulario de contacto del pie del catalogo.
 *
 * Guarda al contacto en catalogo_contactos con la constancia de su
 * consentimiento (version del aviso, fecha, IP) y avisa por correo al negocio.
 * Si el correo falla o el SMTP no esta configurado, el contacto igual queda
 * guardado: lo importante es no perderlo.
 *
 * Responde JSON: { ok: true, mensaje } o { ok: false, error, campo? }.
 *
 * Defensas, sin captcha ni cookies: campo trampa, ficha firmada con la hora
 * (ni instantaneo ni viejo), mismo origen y maximo 5 envios por hora por IP.
 */

require_once __DIR__ . '/../inc/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function responder(int $codigo, array $datos): void
{
    http_response_code($codigo);
    echo json_encode($datos, JSON_UNESCAPED_UNICODE);
    exit;
}

function fallo(string $error, ?string $campo = null, int $codigo = 422): void
{
    responder($codigo, ['ok' => false, 'error' => $error] + ($campo ? ['campo' => $campo] : []));
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fallo('Método no permitido', null, 405);
}

// Mismo origen: el formulario solo se manda desde este sitio.
$origen = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origen !== '' && parse_url($origen, PHP_URL_HOST) !== parse_url('//' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST)) {
    fallo('Origen no permitido', null, 403);
}

$entrada = $_POST;

// Robot: lleno la trampa o la ficha no cuadra. Se le contesta "ok" para no
// darle pistas, pero no se guarda nada.
if (trim((string) ($entrada['sitio_web'] ?? '')) !== '') {
    responder(200, ['ok' => true, 'mensaje' => '¡Gracias! Te contactaremos pronto.']);
}
if (!fichaValida((string) ($entrada['ficha'] ?? ''))) {
    // Ficha vieja (pagina abierta por horas), inventada o enviada al instante.
    fallo('No pudimos validar el envío. Recarga la página e intenta de nuevo.');
}

// ---------- Validacion ----------
$nombre = trim(preg_replace('/\s+/u', ' ', (string) ($entrada['nombre'] ?? '')));
$correo = mb_strtolower(trim((string) ($entrada['correo'] ?? '')));
$telefono = preg_replace('/\D+/', '', (string) ($entrada['telefono'] ?? ''));

if (mb_strlen($nombre) < 2 || mb_strlen($nombre) > 120) {
    fallo('Escribe tu nombre o el de tu negocio.', 'nombre');
}

// Se acepta con lada de pais (+52 / 52) o con el 1 viejo de celular (521...).
if ($telefono !== '') {
    $telefono = preg_replace('/^(52)?1?(?=\d{10}$)/', '', $telefono);
    if (strlen($telefono) !== 10) {
        fallo('El teléfono debe tener 10 dígitos.', 'telefono');
    }
}

if ($correo !== '' && (mb_strlen($correo) > 150 || !filter_var($correo, FILTER_VALIDATE_EMAIL))) {
    fallo('Revisa el correo electrónico.', 'correo');
}

if ($telefono === '' && $correo === '') {
    fallo('Escribe al menos tu teléfono o tu correo.', 'telefono');
}

if (($entrada['acepta_aviso'] ?? '') !== '1') {
    fallo('Para enviarte información necesitamos que aceptes el Aviso de Privacidad y los Términos.', 'acepta_aviso');
}

$promociones = ($entrada['acepta_promociones'] ?? '') === '1' ? 1 : 0;
$version     = (string) configCatalogo()['legal']['version'];
$ip          = substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
$agente      = mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);

try {
    $pdo = catalogoBd();

    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM catalogo_contactos
          WHERE ip = :ip AND actualizado_en >= NOW() - INTERVAL 1 HOUR'
    );
    $stmt->execute(['ip' => $ip]);
    if ((int) $stmt->fetchColumn() >= 5) {
        fallo('Recibimos varios registros desde tu conexión. Intenta más tarde o escríbenos por WhatsApp.', null, 429);
    }

    // Misma persona (mismo telefono o correo): se actualiza su fila. Volver a
    // registrarse es volver a dar el consentimiento, asi que se quita la baja.
    $stmt = $pdo->prepare(
        "SELECT id FROM catalogo_contactos
          WHERE (:tel1 <> '' AND telefono = :tel2) OR (:cor1 <> '' AND correo = :cor2)
          ORDER BY id LIMIT 1"
    );
    $stmt->execute(['tel1' => $telefono, 'tel2' => $telefono, 'cor1' => $correo, 'cor2' => $correo]);
    $existente = $stmt->fetchColumn();

    $datos = [
        'nombre'   => $nombre,
        'telefono' => $telefono !== '' ? $telefono : null,
        'correo'   => $correo !== '' ? $correo : null,
        'promo'    => $promociones,
        'version'  => $version,
        'ip'       => $ip,
        'agente'   => $agente,
    ];

    if ($existente) {
        $stmt = $pdo->prepare(
            'UPDATE catalogo_contactos
                SET nombre = :nombre,
                    telefono = COALESCE(:telefono, telefono),
                    correo = COALESCE(:correo, correo),
                    acepta_promociones = :promo, aviso_version = :version,
                    aceptado_en = NOW(), ip = :ip, user_agent = :agente, baja_en = NULL
              WHERE id = :id'
        );
        $stmt->execute($datos + ['id' => (int) $existente]);
    } else {
        $stmt = $pdo->prepare(
            'INSERT INTO catalogo_contactos
                    (nombre, telefono, correo, acepta_promociones, aviso_version, aceptado_en, ip, user_agent)
             VALUES (:nombre, :telefono, :correo, :promo, :version, NOW(), :ip, :agente)'
        );
        $stmt->execute($datos);
    }

} catch (PDOException $e) {
    error_log('Contacto catalogo: ' . $e->getMessage());
    fallo('No pudimos guardar tus datos. Intenta de nuevo en unos minutos o escríbenos por WhatsApp.', null, 500);
}

// ---------- Aviso al negocio (si falla, el contacto ya quedo guardado) ----------
$destino = trim((string) configCatalogo()['sitio']['correo_avisos']);
if ($destino !== '' && smtpConfigurado()) {
    $filas = [
        'Nombre / negocio' => $nombre,
        'Teléfono'         => $telefono ?: '—',
        'Correo'           => $correo ?: '—',
        'Ofertas'          => $promociones ? 'Sí, acepta ofertas y novedades' : 'No (solo lo que pidió)',
        'Aviso aceptado'   => 'Versión ' . $version . ', ' . date('d/m/Y H:i'),
        'Registro'         => $existente ? 'Ya estaba registrado (datos actualizados)' : 'Nuevo',
    ];
    $html = '<h2 style="font-family:sans-serif">Nuevo contacto desde el catálogo en línea</h2>'
          . '<table style="font-family:sans-serif;border-collapse:collapse">';
    foreach ($filas as $k => $v) {
        $html .= '<tr><td style="padding:4px 12px 4px 0;color:#666">' . e($k) . '</td><td style="padding:4px 0"><b>' . e($v) . '</b></td></tr>';
    }
    $html .= '</table>';

    try {
        enviarCorreoSimple($destino, 'Nuevo contacto del catálogo: ' . $nombre, $html, $correo ?: null);
    } catch (RuntimeException $e) {
        error_log('Contacto catalogo, aviso por correo: ' . $e->getMessage());
    }
}

responder(200, ['ok' => true, 'mensaje' => '¡Listo, ' . $nombre . '! Te enviaremos la información muy pronto.']);
