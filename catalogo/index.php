<?php
/**
 * Catalogo en linea: todos los productos activos del inventario, con busqueda
 * en tiempo real y filtros rapidos (ofertas, marcas, categoria).
 *
 * La pagina solo trae el "esqueleto"; los productos llegan de api/productos.php
 * y la busqueda/filtrado corre en el navegador (assets/catalogo.js): con ~28 mil
 * productos (unos 450 KB comprimidos) cada tecla filtra al instante, sin ir y
 * venir al servidor.
 */

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/layout.php';

$cfg = configCatalogo();

header('Content-Type: text/html; charset=utf-8');

catalogoCabecera(
    'Catálogo de productos',
    'Catálogo de ferretería, plomería, herramienta y más de ' . $cfg['negocio'] . ': busca por nombre, código o marca.',
    'catalogo'
);
?>
    <main id="contenido" class="catalogo" data-whatsapp="<?= e(numeroWhatsapp((string) $cfg['whatsapp'])) ?>"
          data-marcas-rapidas="<?= (int) $cfg['sitio']['marcas_rapidas'] ?>">
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

        <div class="rejilla" id="rejilla" aria-busy="true">
            <?php for ($i = 0; $i < 8; $i++): ?>
                <div class="tarjeta esqueleto" aria-hidden="true"></div>
            <?php endfor; ?>
        </div>
        <div id="centinela" aria-hidden="true"></div>

        <noscript>
            <p class="sin-js">Para buscar en el catálogo activa JavaScript, o escríbenos y te lo enviamos.</p>
        </noscript>
    </main>

    <script src="<?= e(asset('catalogo.js')) ?>" defer></script>
<?php
catalogoPie();
