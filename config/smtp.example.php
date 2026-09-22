<?php
/**
 * Configuracion de correo (SMTP) para enviar la nota de venta.
 *
 * Copia este archivo como "smtp.local.php" en tu maquina y como "smtp.prod.php"
 * en el servidor, y cambia SOLO los valores por los reales. Estos archivos
 * .local/.prod estan en .gitignore: nunca se suben al repositorio.
 *
 * Para Gmail/Outlook necesitas una "contrasena de aplicacion" (no tu contrasena
 * normal): se genera en la configuracion de seguridad de tu cuenta.
 */

return [
    // ---- Servidor SMTP ----
    'smtp_host'   => 'smtp.gmail.com',      // Gmail: smtp.gmail.com · Outlook: smtp.office365.com
    'smtp_port'   => 587,                    // 587 con TLS, o 465 con SSL
    'smtp_secure' => 'tls',                  // 'tls' (puerto 587) o 'ssl' (puerto 465)
    'smtp_user'   => 'tucorreo@gmail.com',   // <-- CAMBIAR
    'smtp_pass'   => 'CONTRASENA_DE_APP',    // <-- CAMBIAR (contrasena de aplicacion)

    // ---- Remitente que vera el cliente ----
    'from_email'  => 'tucorreo@gmail.com',   // <-- CAMBIAR (normalmente igual a smtp_user)
    'from_name'   => 'Comercializadora GA-BE',

    // ---- Enlaces publicos del PDF ----
    // Texto largo y secreto: firma los enlaces del PDF para que no se puedan
    // adivinar. Cambialo por cualquier cadena larga y no lo compartas.
    'notas_secret' => 'CAMBIA-ESTO-por-un-texto-largo-y-unico',

    // URL base publica del sistema (ej. https://tudominio.com/GA-BE). Dejalo
    // vacio para que se detecte solo a partir de la peticion.
    'base_url'     => '',
];
