<?php
/**
 * Aviso de Privacidad Integral del catalogo en linea.
 *
 * Los datos del responsable, el correo de contacto, la version y la fecha salen
 * de config/catalogo.php (legal.*), los mismos que usan los Terminos, el
 * formulario y la constancia que se guarda de cada contacto: asi no puede haber
 * dos versiones distintas del mismo dato.
 *
 * Si cambias este texto, sube legal.version y legal.fecha en la config.
 */

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/layout.php';

$cfg   = configCatalogo();
$legal = $cfg['legal'];

catalogoCabecera('Aviso de Privacidad', 'Aviso de Privacidad Integral de ' . $legal['responsable'] . '.');
?>
    <main id="contenido" class="legal">
        <h1>Aviso de Privacidad Integral</h1>
        <p class="vigencia">Versión <?= e($legal['version']) ?> · Última actualización: <?= e($legal['fecha']) ?></p>

        <p>
            En cumplimiento de la Ley Federal de Protección de Datos Personales en Posesión de los Particulares
            y demás normativa aplicable (en adelante, "la Ley"), te informamos cómo tratamos los datos personales
            que nos proporcionas a través de este sitio.
        </p>

        <h2>1. Responsable</h2>
        <div class="caja">
            <p><strong>Responsable:</strong> <?= datoLegal('responsable', 'nombre o razón social') ?></p>
            <p><strong>Domicilio:</strong> <?= datoLegal('domicilio', 'domicilio completo del responsable') ?></p>
            <p><strong>Contacto para privacidad:</strong> <?= datoLegal('correo_datos', 'correo para datos personales') ?></p>
        </div>

        <h2>2. Datos personales que recabamos</h2>
        <p>A través del formulario de contacto de este sitio recabamos únicamente:</p>
        <ul>
            <li>Nombre (de la persona o del negocio que representa).</li>
            <li>Número de teléfono y/o correo electrónico (al menos uno de los dos).</li>
            <li>Tu elección sobre recibir ofertas y novedades (casilla opcional).</li>
        </ul>
        <p>
            Como constancia de tu consentimiento y para prevenir el uso abusivo del formulario, registramos además
            la fecha y hora en que lo enviaste, la versión de este aviso que aceptaste, la dirección IP y el tipo de
            navegador desde el que lo enviaste.
        </p>
        <p>
            <strong>No recabamos datos personales sensibles</strong> ni datos financieros o patrimoniales a través de
            este sitio. Este sitio no realiza ventas ni cobros en línea.
        </p>

        <h2>3. Finalidades necesarias (primarias)</h2>
        <p>Usamos tus datos para lo siguiente, que es necesario para atender lo que nos pides:</p>
        <ol>
            <li>Atender tu solicitud de información.</li>
            <li>Enviarte nuestro catálogo de productos (incluido en formato PDF), listas de precios y la información
                que solicitaste, por correo electrónico y/o WhatsApp, según el medio que nos proporcionaste.</li>
            <li>Dar seguimiento a tus consultas, cotizaciones y pedidos.</li>
            <li>Conservar la constancia de tu consentimiento y prevenir el uso abusivo del formulario.</li>
        </ol>

        <h2>4. Finalidades opcionales (secundarias)</h2>
        <p>
            Solo si marcas la casilla correspondiente en el formulario, usaremos tus datos para enviarte
            <strong>ofertas, promociones y novedades</strong> por WhatsApp y/o correo electrónico.
        </p>
        <p>
            Si no marcas esa casilla, no te enviaremos ofertas ni promociones, y eso no afecta la atención de tu
            solicitud. Si la marcaste y cambias de opinión, puedes pedir que dejemos de enviártelas en cualquier
            momento, como se explica en el punto 8.
        </p>

        <h2>5. Medios de envío</h2>
        <p>
            Las comunicaciones se envían por correo electrónico y/o por WhatsApp. WhatsApp es un servicio de un
            tercero; al recibir mensajes por ese medio también aplican sus propios términos y políticas de privacidad.
            Los mensajes pueden generar cargos por uso de datos según tu plan con tu compañía telefónica.
        </p>

        <h2>6. Con quién compartimos tus datos</h2>
        <p>
            <strong>No vendemos, rentamos ni transferimos tus datos personales a terceros</strong> para que los usen
            para sus propios fines, salvo en los casos en que la Ley lo permite sin tu consentimiento (por ejemplo, por
            requerimiento de una autoridad competente).
        </p>
        <p>
            Para operar este sitio y hacer los envíos nos apoyamos en proveedores que tratan los datos únicamente por
            cuenta nuestra y bajo nuestras instrucciones (encargados): el proveedor de alojamiento del sitio y de la base
            de datos, el proveedor de correo electrónico y, cuando se use, el proveedor del servicio de mensajería de
            WhatsApp. Estos proveedores no pueden usar tus datos para fines propios.
        </p>

        <h2>7. Cuánto tiempo conservamos tus datos</h2>
        <p>
            Conservamos tus datos mientras sigas interesado en recibir nuestra información y no nos pidas la baja.
            Cuando la pidas, dejamos de usarlos para enviarte comunicaciones y, una vez cumplidos los plazos que
            establece la Ley, los bloqueamos y suprimimos.
        </p>

        <h2>8. Baja de comunicaciones y revocación del consentimiento</h2>
        <p>Puedes dejar de recibir nuestras comunicaciones o revocar tu consentimiento en cualquier momento:</p>
        <ul>
            <li>Respondiendo <strong>BAJA</strong> a cualquiera de nuestros mensajes de WhatsApp o correos, o</li>
            <li>Escribiendo a <?= datoLegal('correo_datos', 'correo para datos personales') ?> con el asunto
                "Baja" o "Revocación del consentimiento".</li>
        </ul>
        <p>Registraremos tu baja y no te enviaremos más comunicaciones del tipo que nos indiques.</p>

        <h2>9. Derechos ARCO</h2>
        <p>
            Tienes derecho a <strong>Acceder</strong> a tus datos personales, <strong>Rectificarlos</strong> si son
            inexactos o incompletos, <strong>Cancelarlos</strong> y <strong>Oponerte</strong> a su uso para fines
            específicos (derechos ARCO). Para ejercerlos, envía tu solicitud a
            <?= datoLegal('correo_datos', 'correo para datos personales') ?> con:
        </p>
        <ol>
            <li>Tu nombre y el medio por el que quieres recibir la respuesta.</li>
            <li>Un documento que acredite tu identidad o, en su caso, la representación de quien actúa en tu nombre.</li>
            <li>La descripción clara de los datos y del derecho que quieres ejercer.</li>
            <li>Cualquier dato que nos ayude a localizarte (por ejemplo, el teléfono o correo que registraste).</li>
        </ol>
        <p>
            Te responderemos por el medio que nos indiques dentro de los plazos que establece la Ley. El ejercicio de
            estos derechos es gratuito; solo podrían cobrarse, en su caso, los gastos justificados de envío o de
            reproducción de documentos.
        </p>

        <h2>10. Cookies y tecnologías de rastreo</h2>
        <p>
            Este sitio <strong>no utiliza cookies</strong> ni herramientas de analítica o publicidad que rastreen tu
            navegación. Solo guarda, en tu propio navegador, tu preferencia de modo claro u oscuro; ese dato no se nos
            envía y puedes borrarlo limpiando los datos del sitio en tu navegador.
        </p>

        <h2>11. Menores de edad</h2>
        <p>
            Este sitio está dirigido a negocios y a personas mayores de edad. No recabamos intencionalmente datos de
            menores de edad.
        </p>

        <h2>12. Seguridad</h2>
        <p>
            Aplicamos medidas de seguridad administrativas, técnicas y físicas razonables para proteger tus datos
            contra daño, pérdida, alteración, destrucción o uso, acceso o tratamiento no autorizado.
        </p>

        <h2>13. Cambios a este aviso</h2>
        <p>
            Cualquier cambio a este aviso se publicará en esta misma página, con su número de versión y fecha de
            actualización. Si el cambio implica nuevas finalidades que requieran tu consentimiento, te lo pediremos
            antes de usar tus datos para ellas.
        </p>

        <h2>14. Autoridad</h2>
        <p>
            Si consideras que tu derecho a la protección de datos personales ha sido vulnerado, puedes acudir ante la
            autoridad competente en materia de protección de datos personales.
        </p>

        <p class="vigencia">
            Al enviar el formulario de contacto y marcar la casilla de aceptación, manifiestas que leíste y aceptas este
            Aviso de Privacidad (versión <?= e($legal['version']) ?>).
        </p>
    </main>
<?php
catalogoPie();
