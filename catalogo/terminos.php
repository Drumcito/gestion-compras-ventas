<?php
/**
 * Términos y Condiciones de uso del catálogo en línea.
 *
 * Mismos datos que el Aviso de Privacidad (config/catalogo.php, legal.*). La
 * definición de "precio antes" usa la misma ventana de días que el cálculo real
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
        <h1>Términos y Condiciones de Uso</h1>
        <p class="vigencia">Versión <?= e($legal['version']) ?> · Última actualización: <?= e($legal['fecha']) ?></p>

        <h2>1. Aceptación de los Términos</h2>
        <p>
            Los presentes Términos y Condiciones regulan el acceso, navegación y uso del catálogo digital operado por
            <?= datoLegal('responsable', 'nombre o razón social') ?> (en lo sucesivo, "el Responsable"). Al acceder, navegar
            o utilizar este sitio web, usted manifiesta su conformidad plena y sin reservas con estos Términos y Condiciones.
            Si no está de acuerdo con alguna de las disposiciones aquí establecidas, deberá abstenerse de utilizar la plataforma.
        </p>

        <h2>2. Naturaleza Informativa del Sitio Web</h2>
        <p>
            Este sitio web constituye un <strong>catálogo digital de carácter estrictamente informativo</strong> y de exhibición
            comercial de los productos distribuidos por el Responsable. <strong>No constituye una plataforma de comercio electrónico
            ni procesa pagos o transacciones directas en línea.</strong> El levantamiento de pedidos, cotizaciones formales,
            confirmaciones de inventario y acuerdos de pago se gestionan de manera directa y personalizada mediante WhatsApp,
            llamada telefónica o correo electrónico institucional.
        </p>

        <h2>3. Precios, Promociones y Disponibilidad</h2>
        <ul>
            <li>
                <strong>Moneda:</strong> Salvo indicación expresa en contrario, todos los precios se presentan en moneda nacional:
                Pesos Mexicanos (MXN). <?= fraseIva() ?>
            </li>
            <li>
                <strong>Vigencia y fluctuación:</strong> Los precios, inventarios y disponibilidad exhibidos están sujetos a cambios
                sin previo aviso debido a la actualización periódica de inventarios y listas de proveedores.
            </li>
            <li>
                <strong>Transparencia en promociones:</strong> Cuando se exhiba un precio de referencia ("precio antes"), este
                corresponderá al precio regular registrado para dicho producto en este catálogo durante los últimos <?= $dias ?> días
                naturales. El porcentaje de descuento se calcula sobre dicha base, redondeado hacia abajo para mayor claridad.
            </li>
            <li>
                <strong>Perfeccionamiento de la operación:</strong> Las condiciones definitivas de cada operación (precio final,
                existencias confirmadas, modalidades de pago, plazos y costos de entrega, mínimos de compra y datos de facturación)
                se pactarán y formalizarán al momento de concertar la orden. Dichas condiciones acordadas individualmente serán las vinculantes.
            </li>
            <li>
                <strong>Errores manifiestos:</strong> En el supuesto de detectarse un error tipográfico, manifiesto o involuntario
                en precios o especificaciones, el Responsable notificará al usuario de forma oportuna y previa a la confirmación de la orden,
                para que este decida si continúa con la adquisición bajo los términos corregidos o desiste de ella.
            </li>
        </ul>

        <h2>4. Imágenes y Descripciones de Productos</h2>
        <p>
            Las fotografías, gráficos y descripciones visuales de los artículos son de carácter <strong>meramente ilustrativo y referencial</strong>.
            Pertenecen a sus respectivos fabricantes o titulares de derechos y se presentan con el exclusivo propósito de facilitar la identificación
            del producto. El artículo suministrado puede presentar variaciones en color, empaque, acabado o presentación respecto de la imagen por causas
            atribuibles a los procesos de fabricación del titular de la marca. En caso de discrepancia técnica entre la imagen exhibida y la descripción
            del producto, prevalecerá lo asentado en la descripción escrita y lo convenido al formalizar la cotización.
        </p>

        <h2>5. Derechos sobre Signos Distintivos y Marcas de Terceros</h2>
        <p>
            Todas las marcas registradas, nombres comerciales, avisos comerciales y logotipos de terceros exhibidos en este sitio son propiedad
            exclusiva de sus respectivos titulares. Su mención en este portal responde a fines puramente nominativos e identificativos conforme a la
            Ley Federal de Protección a la Propiedad Industrial. Dicha exhibición no supone, sugiere ni constituye relación societaria, patrocinio,
            aval o licenciamiento exclusivo entre dichos titulares y <?= datoLegal('responsable', 'el Responsable') ?>.
        </p>

        <h2>6. Procedimiento para el Retiro de Contenidos</h2>
        <p>
            Si usted es titular de derechos de autor, propiedad industrial o derechos conexos sobre algún contenido o imagen incorporada al sitio, y considera
            que su uso contraviene sus derechos o resulta indebido, puede solicitar su remoción enviando una notificación al correo
            <?= datoLegal('correo_datos', 'correo de contacto') ?>. La solicitud deberá incluir la descripción del material controvertido y la
            acreditación correspondiente de titularidad. El Responsable evaluará la solicitud y, de resultar conducente, procederá a su retiro a la brevedad posible.
        </p>

        <h2>7. Formulario de Contacto y Comunicaciones Comerciales</h2>
        <ul>
            <li>
                <strong>Atención a solicitudes:</strong> Al remitir el formulario de contacto, usted autoriza el envío del catálogo de productos
                (incluido en formato PDF), listas de precios vigentes y el seguimiento a su cotización a través del canal elegido (correo electrónico o WhatsApp).
            </li>
            <li>
                <strong>Comunicaciones promocionales:</strong> Únicamente se remitirán ofertas, promociones o novedades informativas si usted otorgó su
                consentimiento expreso mediante la activación de la casilla opcional respectiva.
            </li>
            <li>
                <strong>Revocación:</strong> Podrá cancelar la recepción de mensajes comerciales en cualquier momento respondiendo con la palabra
                <strong>"BAJA"</strong> a los envíos o dirigiendo un correo a <?= datoLegal('correo_datos', 'correo de contacto') ?>.
            </li>
            <li>
                <strong>Privacidad:</strong> El tratamiento de sus datos personales se rige íntegramente por lo dispuesto en nuestro
                <a href="aviso-de-privacidad.php">Aviso de Privacidad Integral</a>.
            </li>
        </ul>

        <h2>8. Condiciones de Uso y Prohibiciones</h2>
        <p>Al utilizar este sitio web, usted se compromete a no:</p>
        <ul>
            <li>Realizar labores de extracción masiva automatizada de datos (<em>web scraping</em>) o reproducción comercial del catálogo sin autorización previa y por escrito del Responsable.</li>
            <li>Ejecutar actos orientados a vulnerar, desestabilizar o comprometer la seguridad perimetral, la disponibilidad o el código fuente del sitio web.</li>
            <li>Proporcionar datos de contacto falsos, inexactos o pertenecientes a terceras personas sin contar con su debida autorización.</li>
        </ul>

        <h2>9. Propiedad Intelectual del Sitio Web</h2>
        <p>
            El diseño de la interfaz gráfica, código fuente, compilación de bases de datos, textos originales, estructura del catálogo y signos distintivos
            de <?= datoLegal('responsable', 'el Responsable') ?> son de su exclusiva titularidad o cuenta con los derechos para su uso, encontrándose protegidos
            por las leyes de propiedad industrial y derechos de autor aplicables. Las imágenes y marcas de terceros se rigen por lo dispuesto en los apartados 4 y 5.
        </p>

        <h2>10. Límite de Responsabilidad</h2>
        <p>
            El Responsable realiza esfuerzos continuos para procurar la exactitud y veracidad de la información exhibida; no obstante, pueden presentarse errores
            u omisiones involuntarios, y el sitio podría experimentar interrupciones técnicas no programadas. Ninguna disposición contenida en estos términos restringe
            los derechos irrenunciables que otorga la <strong>Ley Federal de Protección al Consumidor</strong> en los supuestos en que resulte legalmente aplicable.
        </p>

        <h2>11. Actualizaciones y Modificaciones</h2>
        <p>
            El Responsable se reserva la facultad de actualizar o modificar los presentes Términos y Condiciones en cualquier momento. La versión vigente será la publicada
            en esta misma página, identificada con su respectivo número de versión y fecha de actualización.
        </p>

        <h2>12. Legislación Aplicable y Jurisdicción</h2>
        <p>
            Los presentes Términos y Condiciones se interpretarán conforme a las leyes federales de los Estados Unidos Mexicanos. Para la solución de cualquier controversia,
            las partes se someten expresamente a los juzgados y tribunales competentes correspondientes al domicilio del responsable
            (<?= datoLegal('domicilio', 'domicilio del responsable') ?>), renunciando a cualquier otro fuero que pudiera corresponderles por razón de sus domicilios
            presentes o futuros, sin menoscabo de la competencia que corresponda a la Procuraduría Federal del Consumidor (PROFECO) en el ámbito administrativo.
        </p>

        <h2>13. Canales de Contacto</h2>
        <div class="caja">
            <p><strong><?= datoLegal('responsable', 'nombre o razón social') ?></strong></p>
            <?php if ($cfg['telefono'] !== ''): ?><p>Teléfono: <?= e($cfg['telefono']) ?></p><?php endif; ?>
            <?php if ($cfg['whatsapp'] !== ''): ?><p>WhatsApp: <?= e($cfg['whatsapp']) ?></p><?php endif; ?>
            <p>Correo electrónico: <?= datoLegal('correo_datos', 'correo de contacto') ?></p>
        </div>
    </main>
<?php
catalogoPie();