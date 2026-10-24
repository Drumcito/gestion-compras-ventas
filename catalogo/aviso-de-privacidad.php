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
            En cumplimiento con lo dispuesto por la <strong>Ley Federal de Protección de Datos Personales en Posesión de los Particulares</strong>,
            su Reglamento y los Lineamientos del Aviso de Privacidad (en adelante, la "Ley"), <?= datoLegal('responsable', 'nombre o razón social') ?>
            pone a su disposición el presente Aviso de Privacidad Integral, con el objetivo de informarle de manera transparente
            sobre las características y finalidades del tratamiento al que serán sometidos sus datos personales recabados a través de este sitio web.
        </p>

        <h2>1. Identidad y Domicilio del Responsable</h2>
        <div class="caja">
            <p><strong>Responsable:</strong> <?= datoLegal('responsable', 'nombre o razón social') ?></p>
            <p><strong>Domicilio:</strong> <?= datoLegal('domicilio', 'domicilio completo del responsable') ?></p>
            <p><strong>Contacto para privacidad:</strong> <?= datoLegal('correo_datos', 'correo para datos personales') ?></p>
        </div>

        <h2>2. Datos Personales Recabados</h2>
        <p>Para la adecuada atención de sus solicitudes mediante nuestro formulario de contacto y nuestro carrito de pedidos, recabamos las siguientes categorías de datos personales de identificación y contacto:</p>
        <ul>
            <li><strong>Nombre completo</strong> (de la persona física o del representante comercial/negocio).</li>
            <li><strong>Medios de contacto:</strong> Número telefónico (móvil o fijo) y/o dirección de correo electrónico.</li>
            <li><strong>Preferencia de comunicaciones comerciales:</strong> Manifestación expresa para la recepción de promociones y novedades.</li>
        </ul>
        <p>
            <strong>Pedidos desde el carrito:</strong> Cuando usted envía su carrito mediante el botón "Enviar pedido por WhatsApp", registramos el folio del pedido, los productos y cantidades seleccionados con su precio, y el nombre o negocio que, de manera opcional, haya escrito. El carrito no solicita teléfono ni correo: el número de WhatsApp desde el que nos escriba lo recibimos a través de dicha aplicación al iniciar la conversación.
        </p>
        <p>
            <strong>Datos de trazabilidad y seguridad:</strong> Con fines de validación del consentimiento y prevención de fraudes o envíos abusivos mediante herramientas automatizadas (bots), registramos de forma automática, tanto en el formulario como en los pedidos del carrito, la fecha y hora de envío, la versión del aviso de privacidad vigente, la dirección IP de origen y las características del agente de usuario (navegador y sistema operativo).
        </p>
        <p>
            <strong>Manifestación sobre datos sensibles y financieros:</strong> Hacemos constar que a través de este sitio web <strong>no se recaban datos personales sensibles</strong>, ni datos de carácter patrimonial o financiero. Esta plataforma no efectúa transacciones comerciales directas ni procesamiento de pagos en línea.
        </p>

        <h2>3. Finalidades Primarias (Necesarias para la Relación Jurídica)</h2>
        <p>Los datos personales recabados serán tratados para las siguientes finalidades primarias y estrictamente necesarias:</p>
        <ol>
            <li>Gestionar, procesar y responder a sus solicitudes de información, cotizaciones y atención al cliente.</li>
            <li>Registrar los pedidos enviados desde el carrito, identificarlos por su folio, confirmar existencias y, en su caso, formalizar la venta y emitir la nota correspondiente.</li>
            <li>Remitir catálogos de productos (incluidos documentos en formato PDF), listas de precios vigentes y la información requerida, a través de correo electrónico o la plataforma WhatsApp, según el canal facilitado por el titular.</li>
            <li>Brindar seguimiento comercial a sus consultas, cotizaciones y pedidos en trámite.</li>
            <li>Mantener la trazabilidad técnica, acreditar el otorgamiento del consentimiento y garantizar la seguridad del sitio web y de sus formularios.</li>
        </ol>

        <h2>4. Finalidades Secundarias (Accesorias u Opcionales)</h2>
        <p>
            De manera accesoria, y <strong>únicamente si usted otorga su consentimiento previo y expreso</strong> mediante la marcación de la casilla correspondiente en nuestro formulario, utilizaremos sus datos de contacto para:
        </p>
        <ul>
            <li>El envío de comunicaciones publicitarias, ofertas comerciales, promociones exclusivas y boletines informativos mediante correo electrónico y/o mensajes de WhatsApp.</li>
        </ul>
        <p>
            <strong>Mecanismo de negativa:</strong> La negativa para el uso de sus datos personales para estas finalidades secundarias no será motivo para que le neguemos la atención a sus solicitudes principales o de cotización. En caso de haber otorgado su consentimiento, podrá revocarlo en cualquier momento conforme a los mecanismos descritos en la sección 8 de este aviso.
        </p>

        <h2>5. Canales de Comunicación y Enlaces a Terceros</h2>
        <p>
            Las comunicaciones derivadas de este sitio se canalizan mediante correo electrónico o la aplicación de mensajería instantánea WhatsApp. Se hace del conocimiento del titular que WhatsApp es un servicio prestado por un tercero independiente (Meta Platforms, Inc.), por lo que el uso de dicha plataforma se rige adicionalmente por sus propias condiciones de servicio y políticas de privacidad. Las descargas de archivos adjuntos o recepción de mensajes pueden consumir datos móviles según el plan tarifario contratado con su proveedor de telefonía.
        </p>

        <h2>6. Transferencias y Remisiones de Datos</h2>
        <p>
            <?= datoLegal('responsable', 'El responsable') ?> <strong>no vende, arrienda, comercializa ni transfiere</strong> sus datos personales a terceros con fines publicitarios o ajenos a la relación jurídica, salvo en las excepciones legalmente previstas en el artículo 37 de la Ley (como requerimientos formales y motivados de autoridades administrativas o judiciales competentes).
        </p>
        <p>
            <strong>Remisiones a encargados:</strong> Para la correcta operación de la infraestructura tecnológica, nos apoyamos en terceros prestadores de servicios que actúan en calidad de <strong>Encargados</strong> (proveedores de alojamiento web y bases de datos, plataformas de gestión de correos electrónicos y pasarelas de mensajería). Dichos prestadores procesan sus datos únicamente bajo nuestras instrucciones, obligándose mediante cláusulas de confidencialidad y sin facultad para utilizarlos en beneficio propio.
        </p>

        <h2>7. Conservación y Bloqueo de Datos</h2>
        <p>
            Sus datos personales se conservarán durante el tiempo estrictamente indispensable para dar trámite a su solicitud y dar seguimiento comercial, o bien hasta que manifieste su voluntad de revocar el consentimiento o ejercer su derecho de cancelación.
        </p>
        <p>
            Concluido dicho periodo o formulada la solicitud correspondiente, sus datos pasarán a un estado de <strong>bloqueo</strong> durante los plazos de prescripción legalmente previstos por la normativa civil, mercantil o fiscal, procediéndose posteriormente a su <strong>supresión definitiva</strong>.
        </p>

        <h2>8. Revocación del Consentimiento y Limitación del Uso</h2>
        <p>Usted puede revocar en todo momento el consentimiento otorgado para el tratamiento de sus datos o limitar su uso para finalidades secundarias a través de cualquiera de los siguientes canales:</p>
        <ul>
            <li><strong>Vía digital inmediata:</strong> Respondiendo con la palabra <strong>"BAJA"</strong> a cualquiera de nuestros mensajes por WhatsApp o correos electrónicos promocionales.</li>
            <li><strong>Vía correo electrónico:</strong> Enviando una solicitud expresa a <?= datoLegal('correo_datos', 'correo para datos personales') ?> con el asunto <em>"Baja de Comunicaciones"</em> o <em>"Revocación de Consentimiento"</em>.</li>
        </ul>

        <h2>9. Medios y Procedimiento para el Ejercicio de Derechos ARCO</h2>
        <p>
            Usted, como titular de los datos personales (o su representante legal debidamente acreditado), tiene derecho a <strong>Acceder</strong> a sus datos y conocer las condiciones de su tratamiento; solicitar su <strong>Rectificación</strong> si resultan inexactos o incompletos; demandar su <strong>Cancelación</strong> de nuestros registros; u <strong>Oponerse</strong> al tratamiento para fines específicos (Derechos ARCO).
        </p>
        <p>
            Para el ejercicio de estos derechos, deberá remitir su solicitud al correo electrónico <?= datoLegal('correo_datos', 'correo para datos personales') ?>, acompañada de la siguiente información y documentación:
        </p>
        <ol>
            <li><strong>Identificación del titular:</strong> Nombre completo del titular y medio para comunicarle la respuesta (correo electrónico preferente).</li>
            <li><strong>Acreditación de identidad:</strong> Copia legible de identificación oficial vigente (INE, Pasaporte) o, en su caso, instrumento notarial o carta poder firmada ante dos testigos que acredite la representación legal.</li>
            <li><strong>Descripción clara:</strong> Indicación puntual del derecho ARCO que desea ejercer y los datos personales objeto de la solicitud.</li>
            <li><strong>Datos adicionales:</strong> Teléfono o correo electrónico originalmente proporcionado en el formulario, o el folio del pedido enviado desde el carrito, para agilizar su localización en nuestros sistemas.</li>
        </ol>
        <p>
            <strong>Plazos de atención:</strong> Se dará respuesta en un plazo no mayor a <strong>veinte (20) días hábiles</strong>, contados a partir de la recepción efectiva de la solicitud completa. De resultar procedente, se hará efectiva dentro de los <strong>quince (15) días hábiles</strong> siguientes a la fecha de comunicación de la respuesta. El ejercicio de estos derechos es gratuito, reservándose únicamente el cobro de los costos justificados de reproducción de documentos o cargos de envío de ser el caso.
        </p>

        <h2>10. Uso de Cookies y Almacenamiento Local</h2>
        <p>
            Este sitio web <strong>no emplea cookies de seguimiento publicitario ni herramientas analíticas de terceros</strong> destinadas a perfilar su comportamiento en línea. Únicamente se utiliza el almacenamiento local de su navegador (<em>Local Storage</em>) para guardar:
        </p>
        <ul>
            <li>La preferencia estética de visualización (modo claro/modo oscuro).</li>
            <li>El contenido de su carrito: los productos y cantidades que agregue, el nombre o negocio que opcionalmente escriba y el folio del pedido, para que no se pierdan al recargar o cerrar la página.</li>
        </ul>
        <p>
            Dicha información reside exclusivamente en su dispositivo y solo se nos envía cuando usted pulsa "Enviar pedido por WhatsApp" (ver sección 2). Puede eliminarla en cualquier momento con el botón "Vaciar carrito" o desde las opciones de configuración y limpieza de su navegador.
        </p>

        <h2>11. Protección de Menores de Edad</h2>
        <p>
            Nuestros servicios y sitio web están dirigidos exclusivamente a personas con capacidad jurídica para contratar (mayores de 18 años) y al sector empresarial. No recabamos de manera deliberada información de personas menores de edad. De detectarse el registro involuntario de un menor, se procederá a la cancelación inmediata de sus registros.
        </p>

        <h2>12. Medidas de Seguridad</h2>
        <p>
            Para garantizar la integridad, disponibilidad y confidencialidad de sus datos personales, <?= datoLegal('responsable', 'el responsable') ?> implementa medidas de seguridad técnicas, físicas y administrativas razonables y proporcionales contra pérdida, alteración, destrucción o acceso no autorizado.
        </p>

        <h2>13. Modificaciones al Aviso de Privacidad</h2>
        <p>
            El presente Aviso de Privacidad puede sufrir modificaciones o actualizaciones derivadas de cambios normativos, necesidades comerciales o actualizaciones de nuestros procesos. Toda modificación estará disponible para consulta directa en este mismo apartado web, especificando la fecha de última actualización y el número de versión. Si los cambios involucran finalidades que requieran un nuevo consentimiento, este le será solicitado oportunamente.
        </p>

        <h2>14. Autoridad Garante</h2>
        <p>
            Si usted considera que su derecho a la protección de datos personales ha sido lesionado por alguna conducta de nuestra parte, o presume alguna infracción a las disposiciones de la Ley, puede interponer su inconformidad ante el <strong>Instituto Nacional de Transparencia, Acceso a la Información y Protección de Datos Personales (INAI)</strong> (<a href="https://home.inai.org.mx/" target="_blank" rel="noopener noreferrer">www.inai.org.mx</a>).
        </p>

        <p class="vigencia">
            Al enviar el formulario de contacto y marcar la casilla de aceptación, o al enviar un pedido desde el carrito, usted manifiesta expresamente haber leído, comprendido y aceptado los términos estipulados en el presente Aviso de Privacidad (versión <?= e($legal['version']) ?>).
        </p>
    </main>
<?php
catalogoPie();