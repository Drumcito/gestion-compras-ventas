<?php
/**
 * Cabecera y pie compartidos por las paginas del catalogo en linea.
 *
 * El pie lleva el formulario de contacto, los datos del negocio, los enlaces a
 * los avisos legales y la leyenda de las imagenes, en TODAS las paginas.
 */

/**
 * @param string $titulo      <title> y encabezado
 * @param string $descripcion meta description
 * @param string $pagina      'catalogo' | 'legal' (la de catalogo trae buscador)
 */
function catalogoCabecera(string $titulo, string $descripcion, string $pagina = 'legal'): void
{
    $cfg = configCatalogo();
    $whatsapp = enlaceWhatsapp('Hola, vi su catálogo en línea y quiero información.');
    ?>
<!DOCTYPE html>
<html lang="es-MX">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($titulo) ?> · <?= e($cfg['negocio']) ?></title>
    <meta name="description" content="<?= e($descripcion) ?>">
    <meta name="theme-color" content="#c97500">
    <link rel="icon" type="image/png" href="<?= e(asset('logo.png')) ?>">
    <script>
        // Tema antes de pintar, para que no "parpadee" en claro al abrir en oscuro.
        // Sin eleccion guardada, manda el sistema (CSS: prefers-color-scheme).
        try {
            var t = localStorage.getItem('tema');
            if (t === 'claro' || t === 'oscuro') document.documentElement.setAttribute('data-tema', t);
        } catch (e) {}
    </script>
    <link rel="stylesheet" href="<?= e(asset('catalogo.css')) ?>">
</head>
<body class="pagina-<?= e($pagina) ?>">
    <a class="saltar" href="#contenido">Saltar al contenido</a>

    <header class="barra">
        <div class="barra-interior">
            <a class="marca" href="./" aria-label="Inicio del catálogo">
                <img src="<?= e(asset('logo.png')) ?>" alt="" width="44" height="44">
                <span>
                    <strong><?= e($cfg['negocio']) ?></strong>
                    <small>Catálogo de productos</small>
                </span>
            </a>

            <div class="barra-acciones">
                <?php if ($whatsapp !== ''): ?>
                    <a class="boton-wa" href="<?= e($whatsapp) ?>" target="_blank" rel="noopener">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2a10 10 0 0 0-8.6 15.1L2 22l5-1.3A10 10 0 1 0 12 2Zm0 18.2a8.2 8.2 0 0 1-4.2-1.2l-.3-.2-3 .8.8-2.9-.2-.3A8.2 8.2 0 1 1 12 20.2Zm4.5-6.1c-.2-.1-1.5-.7-1.7-.8-.2-.1-.4-.1-.6.1l-.8 1c-.1.2-.3.2-.5.1a6.7 6.7 0 0 1-3.3-2.9c-.2-.4.2-.4.7-1.3.1-.2 0-.3 0-.4l-.8-1.8c-.2-.5-.4-.4-.6-.4h-.5a1 1 0 0 0-.7.3 3 3 0 0 0-.9 2.2 5.1 5.1 0 0 0 1.1 2.7 11.7 11.7 0 0 0 4.5 4c1.7.7 2.3.8 3.2.6.5-.1 1.5-.6 1.7-1.2.2-.6.2-1.1.2-1.2-.1-.1-.3-.2-.5-.3Z"/></svg>
                        <span>WhatsApp</span>
                    </a>
                <?php endif; ?>
                <?php if ($pagina === 'catalogo' && $whatsapp !== ''): ?>
                    <button type="button" class="boton-carrito" id="abrir-carrito" aria-label="Abrir carrito" aria-haspopup="dialog">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="9" cy="20" r="1.4"/><circle cx="18" cy="20" r="1.4"/><path d="M2 3h2.8l2.5 12a1.6 1.6 0 0 0 1.6 1.3h8.7a1.6 1.6 0 0 0 1.6-1.2L21 7.5H5.7"/></svg>
                        <span class="contador-carrito" id="contador-carrito" hidden>0</span>
                    </button>
                <?php endif; ?>
                <button type="button" class="boton-tema" id="boton-tema" aria-label="Cambiar a modo oscuro">
                    <svg class="icono-luna" viewBox="0 0 24 24" aria-hidden="true"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8Z"/></svg>
                    <svg class="icono-sol" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="4.5"/><path d="M12 1.5v3M12 19.5v3M1.5 12h3M19.5 12h3M4.6 4.6l2.1 2.1M17.3 17.3l2.1 2.1M4.6 19.4l2.1-2.1M17.3 6.7l2.1-2.1"/></svg>
                </button>
            </div>
        </div>
    </header>
    <?php
}

function catalogoPie(): void
{
    $cfg = configCatalogo();
    $legal = $cfg['legal'];
    ?>
    <footer class="pie" id="contacto">
        <div class="pie-interior">
            <section class="pie-formulario" aria-labelledby="titulo-contacto">
                <h2 id="titulo-contacto">¿Quieres recibir nuestro catálogo y ofertas?</h2>
                <p class="pie-sub">Déjanos tus datos y te enviamos la información por correo o WhatsApp.</p>

                <form id="form-contacto" class="formulario" action="api/contacto.php" method="post" novalidate>
                    <input type="hidden" name="ficha" value="<?= e(fichaFormulario()) ?>">
                    <!-- Trampa para robots: una persona no ve este campo y lo deja vacío. -->
                    <div class="trampa" aria-hidden="true">
                        <label for="sitio_web">No llenes este campo</label>
                        <input type="text" id="sitio_web" name="sitio_web" tabindex="-1" autocomplete="off">
                    </div>

                    <div class="campo">
                        <label for="c-nombre">Nombre o negocio <span aria-hidden="true">*</span></label>
                        <input type="text" id="c-nombre" name="nombre" maxlength="120" required
                               autocomplete="organization" placeholder="Ej. Ferretería López o Juan López">
                    </div>

                    <div class="campos-dobles">
                        <div class="campo">
                            <label for="c-telefono">Teléfono / WhatsApp</label>
                            <input type="tel" id="c-telefono" name="telefono" maxlength="20" inputmode="tel"
                                   autocomplete="tel" placeholder="10 dígitos">
                        </div>
                        <div class="campo">
                            <label for="c-correo">Correo electrónico</label>
                            <input type="email" id="c-correo" name="correo" maxlength="150"
                                   autocomplete="email" placeholder="tucorreo@ejemplo.com">
                        </div>
                    </div>
                    <p class="ayuda" id="ayuda-contacto">Escribe al menos uno: teléfono o correo.</p>

                    <label class="casilla">
                        <input type="checkbox" name="acepta_aviso" value="1" required>
                        <span>
                            He leído y acepto el <a href="aviso-de-privacidad.php" target="_blank">Aviso de Privacidad</a>
                            y los <a href="terminos.php" target="_blank">Términos y Condiciones</a>. <span aria-hidden="true">*</span>
                        </span>
                    </label>

                    <label class="casilla">
                        <input type="checkbox" name="acepta_promociones" value="1">
                        <span>Además, quiero recibir ofertas y novedades por WhatsApp y/o correo (opcional).</span>
                    </label>

                    <p class="aviso-corto">
                        <?= e($legal['responsable']) ?> usará tus datos para enviarte la información que solicitas
                        y, solo si marcas la casilla opcional, ofertas y novedades. Puedes pedir la baja cuando
                        quieras respondiendo <strong>BAJA</strong> o escribiendo a <?= e($legal['correo_datos']) ?>.
                        Más detalles en el <a href="aviso-de-privacidad.php">Aviso de Privacidad</a>.
                    </p>

                    <button type="submit" class="boton-primario">Quiero recibir información</button>
                    <p class="mensaje-form" id="mensaje-form" role="status" aria-live="polite"></p>
                </form>
            </section>

            <section class="pie-datos" aria-label="Datos de contacto">
                <img src="<?= e(asset('logo.png')) ?>" alt="" width="64" height="64" class="pie-logo">
                <p class="pie-negocio"><?= e($cfg['negocio']) ?></p>
                <ul class="pie-lista">
                    <?php if ($cfg['telefono'] !== ''): ?>
                        <li>Tel. <a href="tel:+52<?= e(preg_replace('/\D+/', '', $cfg['telefono'])) ?>"><?= e($cfg['telefono']) ?></a></li>
                    <?php endif; ?>
                    <?php if (enlaceWhatsapp() !== ''): ?>
                        <li>WhatsApp <a href="<?= e(enlaceWhatsapp()) ?>" target="_blank" rel="noopener"><?= e($cfg['whatsapp']) ?></a></li>
                    <?php endif; ?>
                    <?php if ($cfg['correo'] !== ''): ?>
                        <li><a href="mailto:<?= e($cfg['correo']) ?>"><?= e($cfg['correo']) ?></a></li>
                    <?php endif; ?>
                </ul>
                <nav class="pie-legal" aria-label="Avisos legales">
                    <a href="aviso-de-privacidad.php">Aviso de Privacidad</a>
                    <a href="terminos.php">Términos y Condiciones</a>
                </nav>
            </section>
        </div>

        <div class="pie-leyenda">
            <p>
                Las imágenes de los productos son ilustrativas y de uso referencial: no son de nuestra autoría y
                pertenecen a sus respectivos titulares. El producto real puede variar en color, empaque o presentación.
                Las marcas mencionadas son propiedad de sus dueños y se usan solo para identificar los productos.
                Precios en pesos mexicanos, sujetos a cambio sin previo aviso y a disponibilidad. <?= fraseIva() ?>
            </p>
            <p>© <?= date('Y') ?> <?= e($legal['responsable']) ?>. Aviso de Privacidad versión <?= e($legal['version']) ?>.</p>
        </div>
    </footer>

    <script src="<?= e(asset('comun.js')) ?>" defer></script>
</body>
</html>
    <?php
}
