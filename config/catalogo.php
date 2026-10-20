<?php
/**
 * Datos del catalogo PDF (mas vendidos + ofertas) y del catalogo en linea.
 *
 * A diferencia de credenciales.*.php y smtp.*.php, este archivo SI se versiona:
 * no trae nada secreto, solo lo que se imprime en el volante. Cambia aqui el
 * telefono, el correo o la direccion del subdominio y el PDF se actualiza solo.
 */

return [
    'negocio'   => 'COMERCIALIZADORA GA-BE',

    // ---------- Pagina 1: mas vendidos ----------
    // Los productos que mas se vendieron en los ultimos N dias (ventas no
    // eliminadas). criterio: 'ventas' = en cuantas ventas distintas aparece (no
    // lo infla un solo pedido grande); 'piezas' = total de piezas vendidas.
    'vendidos' => [
        'titulo'    => 'MÁS VENDIDOS',
        'subtitulo' => 'TOP 10 DEL MES',
        'leyenda'   => '¡LOS FAVORITOS DE NUESTROS CLIENTES!',
        'dias'      => 30,
        'limite'    => 10,
        'criterio'  => 'ventas',
    ],

    // ---------- Pagina 2: ofertas ----------
    // Se compara el precio de hoy contra el que tenia el producto hace N dias
    // (el primer cambio registrado dentro de la ventana). Entran los que bajaron,
    // del mayor al menor descuento.
    'ofertas' => [
        'titulo'    => 'TOP 5 OFERTAS',
        'subtitulo' => '& PRECIOS A LA BAJA',
        'leyenda'   => '¡OFERTAS VÁLIDAS POR TIEMPO LIMITADO!',
        'dias'      => 30,
        'limite'    => 5,
    ],

    // ---------- Pie (contacto) ----------
    // Un dato vacio no se dibuja: el pie no deja huecos.
    'telefono'  => '55 2804 8722',
    'whatsapp'  => '55 2804 8722',
    'correo'    => 'comercializadoragabe@ga-be.net',

    // ---------- Catalogo en linea (subdominio) ----------
    // Es lo que lleva el codigo QR y el boton del pie. El sitio vive en la
    // carpeta catalogo/ del proyecto: el subdominio apunta ahi.
    'url_catalogo' => 'https://catalogo.ga-be.net',

    'sitio' => [
        // A donde llegan los avisos del formulario de contacto del pie.
        'correo_avisos'  => 'comercializadoragabe@ga-be.net',
        // Cada cuanto se regenera la lista de productos (precios, altas, bajas).
        'cache_minutos'  => 10,
        // Marcas que salen como filtro rapido (las de mas productos).
        'marcas_rapidas' => 10,
    ],

    // ---------- Datos legales (aviso de privacidad y terminos) ----------
    // Lo que se imprime en catalogo/aviso-de-privacidad.php y terminos.php. Un
    // dato vacio sale marcado en ROJO como "PENDIENTE": hay que llenarlo antes
    // de publicar el sitio. Si cambias el texto de los avisos, sube la version
    // y la fecha: cada contacto guarda la version que acepto.
    'legal' => [
        // Nombre o razon social tal como aparece ante el SAT.
        'responsable'  => 'COMERCIALIZADORA GA-BE',
        // Domicilio completo (calle, numero, colonia, municipio/alcaldia, estado, C.P.).
        'domicilio'    => '',
        // Correo para ejercer derechos ARCO, revocar el consentimiento o pedir la baja.
        'correo_datos' => 'comercializadoragabe@ga-be.net',
        // 'incluido' = los precios publicados ya incluyen IVA; 'mas_iva' = no lo
        // incluyen. Debe coincidir con lo que realmente se cobra.
        'iva'          => '',
        'version'      => '1.0',
        'fecha'        => '19 de octubre de 2026',
    ],

    // ---------- Imagenes de producto ----------
    // Una imagen por producto, nombrada con su codigo interno:
    //   public/img/productos/BNS01-00001.jpg   (o .png)
    // Estandar: cuadrada 1:1, 800 x 800 px, fondo blanco o transparente, el
    // producto centrado con ~5% de margen, JPG o PNG no entrelazado (FPDF no lee
    // WebP). Si no hay imagen se dibuja un recuadro del mismo tamano.
    'dir_imagenes' => __DIR__ . '/../public/img/productos',
];
