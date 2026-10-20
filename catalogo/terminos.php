<?php
/**
 * Terminos y Condiciones de uso del catalogo en linea.
 *
 * Mismos datos que el Aviso de Privacidad (config/catalogo.php, legal.*). La
 * definicion de "precio antes" usa la misma ventana de dias que el calculo real
 * (ofertas.dias): si cambia uno, cambia el otro.
 *
 * Si cambias este texto, sube legal.version y legal.fecha en la config.
 */

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/layout.php';

$cfg   = configCatalogo();
$legal = $cfg['legal'];
$dias  = (int) $cfg['ofertas']['dias'];

catalogoCabecera('Términos y Condiciones', 'Términos y Condiciones de uso del catálogo en línea de ' . $legal['responsable'] . '.');
?>
    <main id="contenido" class="legal">
        <h1>Términos y Condiciones de uso</h1>
        <p class="vigencia">Versión <?= e($legal['version']) ?> · Última actualización: <?= e($legal['fecha']) ?></p>

        <h2>1. Aceptación</h2>
        <p>
            Estos términos regulan el uso de este catálogo en línea, operado por
            <?= datoLegal('responsable', 'nombre o razón social') ?> (en adelante, "nosotros"). Al usar el sitio
            aceptas estos términos; si no estás de acuerdo con ellos, te pedimos no utilizarlo.
        </p>

        <h2>2. Qué es este sitio</h2>
        <p>
            Es un <strong>catálogo informativo</strong> de los productos que comercializamos. <strong>No es una
            tienda en línea:</strong> en este sitio no se realizan compras, pagos ni cobros. Los pedidos y cotizaciones
            se acuerdan directamente con nosotros por WhatsApp, teléfono o correo electrónico.
        </p>

        <h2>3. Precios, ofertas y disponibilidad</h2>
        <ul>
            <li>Los precios están expresados en pesos mexicanos (MXN). <?= fraseIva() ?></li>
            <li>Los precios y la disponibilidad pueden cambiar sin previo aviso. La lista de precios del sitio se
                actualiza periódicamente a partir de nuestro inventario.</li>
            <li>El precio "antes" que se muestra junto a una oferta es el precio que tuvo ese mismo producto en este
                catálogo dentro de los últimos <?= $dias ?> días, y el porcentaje de descuento se calcula contra ese
                precio, redondeado hacia abajo.</li>
            <li>El precio, la existencia y las condiciones de cada pedido (forma de pago, entrega, mínimos de compra
                y facturación) se confirman al momento de hacer el pedido. Lo confirmado en ese momento es lo que
                aplica.</li>
            <li>Si detectamos un error evidente en un precio o en una descripción, te lo haremos saber antes de
                confirmar tu pedido para que decidas si continúas.</li>
        </ul>

        <h2>4. Imágenes y descripciones</h2>
        <p>
            Las imágenes de los productos son <strong>ilustrativas y de uso referencial</strong>. No son de nuestra
            autoría: pertenecen a sus respectivos titulares (fabricantes, marcas o sus licenciatarios) y se muestran
            únicamente para ayudarte a identificar el producto. El producto que recibas puede variar en color,
            empaque, acabado o presentación respecto de la imagen. Cuando la imagen y la descripción no coincidan,
            prevalece la descripción y lo que se confirme al hacer el pedido.
        </p>

        <h2>5. Marcas</h2>
        <p>
            Las marcas, nombres comerciales y logotipos de terceros que aparecen en el sitio son propiedad de sus
            respectivos titulares y se mencionan solo para identificar los productos. Su mención no implica, por sí
            misma, patrocinio, afiliación ni respaldo de esos titulares hacia nosotros.
        </p>

        <h2>6. Retiro de contenido</h2>
        <p>
            Si eres titular de derechos sobre alguna imagen o contenido y consideras que su uso en este sitio no es
            adecuado, escríbenos a <?= datoLegal('correo_datos', 'correo de contacto') ?> indicando el contenido y
            acreditando tu titularidad. Lo revisaremos y, en su caso, lo retiraremos a la brevedad.
        </p>

        <h2>7. Formulario de contacto y comunicaciones</h2>
        <ul>
            <li>Al enviar el formulario aceptas recibir la información que solicitaste (catálogo de productos,
                incluido en formato PDF, y listas de precios) por correo electrónico y/o WhatsApp, según los datos que
                nos proporcionaste.</li>
            <li>Solo te enviaremos ofertas, promociones y novedades si marcaste la casilla opcional para ello.</li>
            <li>Puedes dejar de recibir nuestras comunicaciones en cualquier momento respondiendo
                <strong>BAJA</strong> a cualquier mensaje o escribiendo a
                <?= datoLegal('correo_datos', 'correo de contacto') ?>.</li>
            <li>El tratamiento de tus datos se rige por nuestro
                <a href="aviso-de-privacidad.php">Aviso de Privacidad</a>.</li>
        </ul>

        <h2>8. Uso permitido</h2>
        <p>Al usar el sitio te comprometes a no:</p>
        <ul>
            <li>Extraer de forma masiva o automatizada su contenido, ni reproducirlo con fines comerciales sin nuestra
                autorización.</li>
            <li>Intentar afectar su funcionamiento, su seguridad o la de sus usuarios.</li>
            <li>Enviar a través del formulario datos falsos o de otra persona sin su autorización.</li>
        </ul>

        <h2>9. Propiedad intelectual</h2>
        <p>
            El diseño del sitio, sus textos propios, la organización del catálogo y nuestro nombre y logotipo nos
            pertenecen. Las imágenes y marcas de terceros se rigen por lo indicado en los puntos 4 y 5.
        </p>

        <h2>10. Responsabilidad</h2>
        <p>
            Procuramos que la información del sitio sea exacta y esté actualizada, pero puede contener errores u
            omisiones involuntarios, y el sitio puede no estar disponible en algún momento. Nada de lo establecido en
            estos términos limita los derechos que te otorga la Ley Federal de Protección al Consumidor cuando te
            sea aplicable.
        </p>

        <h2>11. Cambios a estos términos</h2>
        <p>
            Podemos actualizar estos términos. La versión vigente es la publicada en esta página, con su número de
            versión y fecha de actualización.
        </p>

        <h2>12. Legislación aplicable</h2>
        <p>
            Estos términos se rigen por las leyes federales de los Estados Unidos Mexicanos. Para cualquier
            controversia, las partes se someten a los tribunales competentes correspondientes al domicilio del
            responsable (<?= datoLegal('domicilio', 'domicilio del responsable') ?>), sin perjuicio de la competencia
            de la Procuraduría Federal del Consumidor cuando corresponda.
        </p>

        <h2>13. Contacto</h2>
        <div class="caja">
            <p><strong><?= datoLegal('responsable', 'nombre o razón social') ?></strong></p>
            <?php if ($cfg['telefono'] !== ''): ?><p>Teléfono: <?= e($cfg['telefono']) ?></p><?php endif; ?>
            <?php if ($cfg['whatsapp'] !== ''): ?><p>WhatsApp: <?= e($cfg['whatsapp']) ?></p><?php endif; ?>
            <p>Correo: <?= datoLegal('correo_datos', 'correo de contacto') ?></p>
        </div>
    </main>
<?php
catalogoPie();
