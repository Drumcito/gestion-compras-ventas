<?php
/**
 * Catalogo en linea: todos los productos activos del inventario, con busqueda
 * en tiempo real y filtros rapidos (ofertas, marcas, categoria).
 *
 * La pagina solo trae el "esqueleto"; los productos llegan de api/productos.php
 * y la busqueda/filtrado corre en el navegador (assets/catalogo.js): con ~28 mil
 * productos (unos 450 KB comprimidos) cada tecla filtra al instante, sin ir y
 * venir al servidor. Los resultados se ven por paginas (sitio.por_pagina).
 *
 * Carrito: sin cuenta ni sesion. Se guarda como JSON en el navegador del
 * visitante (localStorage) y se manda completo en un solo mensaje de WhatsApp.
 */

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/layout.php';

$cfg = configCatalogo();
$whatsapp = numeroWhatsapp((string) $cfg['whatsapp']);

header('Content-Type: text/html; charset=utf-8');

catalogoCabecera(
    'Catálogo de productos',
    'Catálogo de ferretería, plomería, herramienta y más de ' . $cfg['negocio'] . ': busca por nombre, código o marca.',
    'catalogo'
);
?>
    <main id="contenido" class="catalogo" data-whatsapp="<?= e($whatsapp) ?>"
          data-marcas-rapidas="<?= (int) $cfg['sitio']['marcas_rapidas'] ?>"
          data-por-pagina="<?= (int) $cfg['sitio']['por_pagina'] ?>"
          data-carrito-max="<?= (int) ($cfg['sitio']['carrito_max'] ?? 20) ?>">
        <section class="buscador" aria-label="Buscar y filtrar">
            <div class="buscador-interior">
                <div class="caja-busqueda">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/></svg>
                    <label class="solo-lector" for="busqueda">Buscar productos</label>
                    <input type="search" id="busqueda" placeholder="Busca por nombre, código o marca…"
                           autocomplete="off" spellcheck="false" enterkeyhint="search">
                    <button type="button" id="limpiar-busqueda" class="limpiar" aria-label="Borrar búsqueda" hidden>×</button>
                </div>

                <div class="filtros-rapidos" id="filtros-rapidos" role="group" aria-label="Filtros rápidos">
                    <!-- Los llena catalogo.js con las marcas que mas productos tienen -->
                </div>

                <div class="filtros-extra">
                    <label>
                        <span class="solo-lector">Marca</span>
                        <select id="filtro-marca"><option value="">Todas las marcas</option></select>
                    </label>
                    <label>
                        <span class="solo-lector">Categoría</span>
                        <select id="filtro-categoria"><option value="">Todas las categorías</option></select>
                    </label>
                    <label>
                        <span class="solo-lector">Ordenar</span>
                        <select id="orden">
                            <option value="relevancia">Más relevantes</option>
                            <option value="nombre">Nombre (A–Z)</option>
                            <option value="precio-asc">Precio: menor a mayor</option>
                            <option value="precio-desc">Precio: mayor a menor</option>
                            <option value="descuento">Mayor descuento</option>
                        </select>
                    </label>
                </div>
            </div>
        </section>

        <div class="resultados-info">
            <p id="conteo" role="status" aria-live="polite">Cargando catálogo…</p>
            <button type="button" id="quitar-filtros" class="enlace" hidden>Quitar filtros</button>
        </div>
        <p class="nota-precios">Precios con IVA incluido, sujetos a cambio sin previo aviso.</p>

        <div class="rejilla" id="rejilla" aria-busy="true">
            <?php for ($i = 0; $i < (int) $cfg['sitio']['por_pagina']; $i++): ?>
                <div class="tarjeta esqueleto" aria-hidden="true"></div>
            <?php endfor; ?>
        </div>
        <nav class="paginacion" id="paginacion" aria-label="Páginas de resultados"></nav>

        <noscript>
            <p class="sin-js">Para buscar en el catálogo activa JavaScript, o escríbenos y te lo enviamos.</p>
        </noscript>
    </main>

    <?php if ($whatsapp !== ''): ?>
        <!-- Carrito: lo llena catalogo.js con lo guardado en este navegador. -->
        <button type="button" class="carrito-flotante" id="carrito-flotante" aria-haspopup="dialog" hidden>
            <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="9" cy="20" r="1.4"/><circle cx="18" cy="20" r="1.4"/><path d="M2 3h2.8l2.5 12a1.6 1.6 0 0 0 1.6 1.3h8.7a1.6 1.6 0 0 0 1.6-1.2L21 7.5H5.7"/></svg>
            <span id="carrito-flotante-texto">Ver carrito</span>
        </button>

        <dialog class="carrito" id="carrito" aria-labelledby="carrito-titulo">
            <div class="carrito-cabeza">
                <h2 id="carrito-titulo">Mi carrito</h2>
                <button type="button" class="carrito-cerrar" id="carrito-cerrar" aria-label="Cerrar carrito">×</button>
            </div>

            <ul class="carrito-lista" id="carrito-lista"></ul>

            <div class="carrito-pie" id="carrito-pie" hidden>
                <p class="carrito-resumen" id="carrito-resumen"></p>
                <div class="campo-carrito">
                    <label for="carrito-nombre">Tu nombre o negocio <small>(opcional)</small></label>
                    <input type="text" id="carrito-nombre" maxlength="80" autocomplete="organization"
                           placeholder="Ej. Ferretería López">
                </div>
                <a class="pedir carrito-enviar" id="carrito-enviar" target="_blank" rel="noopener" href="#">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2a10 10 0 0 0-8.6 15.1L2 22l5-1.3A10 10 0 1 0 12 2Zm4.5 12.1c-.2-.1-1.5-.7-1.7-.8-.2-.1-.4-.1-.6.1l-.8 1c-.1.2-.3.2-.5.1a6.7 6.7 0 0 1-3.3-2.9c-.2-.4.2-.4.7-1.3.1-.2 0-.3 0-.4l-.8-1.8c-.2-.5-.4-.4-.6-.4h-.5a1 1 0 0 0-.7.3 3 3 0 0 0-.9 2.2 5.1 5.1 0 0 0 1.1 2.7 11.7 11.7 0 0 0 4.5 4c1.7.7 2.3.8 3.2.6.5-.1 1.5-.6 1.7-1.2.2-.6.2-1.1.2-1.2-.1-.1-.3-.2-.5-.3Z"/></svg>
                    Enviar pedido por WhatsApp
                </a>
                <p class="carrito-nota">
                    Precios finales con IVA incluido, sujetos a cambio sin previo aviso y a disponibilidad:
                    te confirmamos existencias por WhatsApp. Tu carrito se guarda solo en este navegador.
                </p>
                <button type="button" class="enlace carrito-vaciar" id="carrito-vaciar">Vaciar carrito</button>
            </div>
        </dialog>

        <p class="aviso-carrito" id="aviso-carrito" role="status" aria-live="polite"></p>
    <?php endif; ?>

    <script src="<?= e(asset('catalogo.js')) ?>" defer></script>
<?php
catalogoPie();
