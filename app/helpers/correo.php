<?php
/**
 * Correo y enlaces de la nota.
 *
 * La configuracion real (SMTP + secreto) vive en config/smtp.local.php o
 * config/smtp.prod.php (fuera del repositorio). Aqui solo se lee y se usa.
 */

/** Carga la config de correo, o null si todavia no se ha creado el archivo. */
function configCorreo(): ?array
{
    static $cache = false;

    if ($cache !== false) {
        return $cache;
    }

    foreach (['smtp.local.php', 'smtp.prod.php'] as $archivo) {
        $ruta = __DIR__ . '/../../config/' . $archivo;
        if (is_file($ruta)) {
            $cfg = require $ruta;
            return $cache = (is_array($cfg) ? $cfg : null);
        }
    }

    return $cache = null;
}

/**
 * ¿El SMTP ya tiene datos reales? Sirve para avisar en vez de intentar enviar
 * con los valores de ejemplo y fallar de forma confusa.
 */
function smtpConfigurado(): bool
{
    $cfg = configCorreo();

    if ($cfg === null) {
        return false;
    }

    $user = trim((string) ($cfg['smtp_user'] ?? ''));
    $pass = (string) ($cfg['smtp_pass'] ?? '');

    return $user !== '' && $user !== 'tucorreo@gmail.com'
        && $pass !== '' && $pass !== 'CONTRASENA_DE_APP';
}

/**
 * Secreto para firmar los enlaces del PDF. Si no hay config, usa un respaldo
 * fijo (el enlace igual funciona dentro del mismo servidor; conviene poner uno
 * propio en la config para produccion).
 */
function notasSecret(): string
{
    $cfg = configCorreo();
    $secreto = trim((string) ($cfg['notas_secret'] ?? ''));

    return $secreto !== '' ? $secreto : 'gabe-notas-respaldo-cambia-esto';
}

/** Token que va en el enlace publico del PDF de una venta. */
function tokenNota(int $ventaId): string
{
    return substr(hash_hmac('sha256', 'nota-' . $ventaId, notasSecret()), 0, 24);
}

/** Compara en tiempo constante el token recibido contra el esperado. */
function tokenNotaValido(int $ventaId, string $token): bool
{
    return hash_equals(tokenNota($ventaId), $token);
}

/**
 * URL base publica del sistema (ej. https://dominio.com/GA-BE). Sale de la
 * config si esta puesta; si no, se arma a partir de la peticion actual.
 */
function urlBase(): string
{
    $cfg  = configCorreo();
    $base = trim((string) ($cfg['base_url'] ?? ''));

    if ($base !== '') {
        return rtrim($base, '/');
    }

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';

    // SCRIPT_NAME es algo como /GA-BE/app/controllers/EnviarNotaController.php;
    // la raiz del sistema son dos carpetas arriba.
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    $raiz   = preg_replace('#/app/controllers/[^/]*$#', '', $script);
    $raiz   = $raiz === $script ? '' : $raiz;   // por si no coincidio el patron

    return $scheme . '://' . $host . $raiz;
}

/** Enlace publico al PDF de una venta (abre sin iniciar sesion, va firmado). */
function urlPdfNota(int $ventaId): string
{
    return urlBase() . '/public/nota_pdf.php?id=' . $ventaId . '&t=' . tokenNota($ventaId);
}

/**
 * Envia la nota por correo con el PDF adjunto. Lanza RuntimeException con un
 * mensaje claro si el SMTP no esta configurado o si el envio falla.
 */
function enviarNotaPorCorreo(string $para, string $asunto, string $cuerpoHtml, string $pdf, string $nombrePdf): void
{
    if (!smtpConfigurado()) {
        throw new RuntimeException(
            'El correo todavia no esta configurado. Pon los datos de tu cuenta en config/smtp.local.php (o smtp.prod.php).'
        );
    }

    $cfg = configCorreo();

    require_once __DIR__ . '/../../lib/PHPMailer/Exception.php';
    require_once __DIR__ . '/../../lib/PHPMailer/PHPMailer.php';
    require_once __DIR__ . '/../../lib/PHPMailer/SMTP.php';

    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host       = (string) $cfg['smtp_host'];
        $mail->SMTPAuth   = true;
        $mail->Username   = (string) $cfg['smtp_user'];
        $mail->Password   = (string) $cfg['smtp_pass'];
        $mail->Port       = (int) $cfg['smtp_port'];
        $mail->CharSet    = 'UTF-8';

        $secure = (string) ($cfg['smtp_secure'] ?? 'tls');
        $mail->SMTPSecure = $secure === 'ssl'
            ? \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS
            : \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;

        $mail->setFrom((string) $cfg['from_email'], (string) ($cfg['from_name'] ?? 'GA-BE'));
        $mail->addAddress($para);

        $mail->addStringAttachment($pdf, $nombrePdf, 'base64', 'application/pdf');

        $mail->isHTML(true);
        $mail->Subject = $asunto;
        $mail->Body    = $cuerpoHtml;
        $mail->AltBody = strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $cuerpoHtml));

        $mail->send();

    } catch (\PHPMailer\PHPMailer\Exception $e) {
        error_log('Correo nota: ' . $mail->ErrorInfo);
        throw new RuntimeException('No se pudo enviar el correo: ' . $mail->ErrorInfo);
    }
}
