document.addEventListener('DOMContentLoaded', () => {
    const listaCasas     = document.getElementById('lista-casas');
    if (!listaCasas) return;

    const listaProductos = document.getElementById('lista-productos');
    const buscador       = document.getElementById('buscar-producto');
    const resumen        = document.getElementById('resumen-inventario');
    const aviso          = document.getElementById('aviso-inventario');
    const paginacion     = document.getElementById('paginacion');
    const infoPagina     = document.getElementById('info-pagina');
    const btnAnterior    = document.getElementById('btn-anterior');
    const btnSiguiente   = document.getElementById('btn-siguiente');
    const modal          = document.getElementById('modal-inventario');
    const contenido      = document.getElementById('contenido-inventario');
    const btnCerrar      = document.getElementById('btn-cerrar-inventario');

    const RUTA_INV    = '../../app/controllers/InventarioController.php';
    const RUTA_PRECIO = '../../app/controllers/PrecioController.php';

    let casaActual   = null;
    let paginaActual = 1;
    let totalPaginas = 1;

    const money = (n) => n === null || n === ''
        ? '—'
        : '$' + Number(n).toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    function esc(texto) {
        const div = document.createElement('div');
        div.textContent = texto === null || texto === undefined ? '' : texto;
        return div.innerHTML;
    }

    let temporizadorAviso = null;

    function mostrarAviso(texto, tipo, segundos = 5) {
        clearTimeout(temporizadorAviso);
        aviso.textContent = texto;
        aviso.className = 'aviso aviso-' + tipo;
        aviso.hidden = false;
        if (segundos > 0) temporizadorAviso = setTimeout(() => { aviso.hidden = true; }, segundos * 1000);
    }

    // ---------- Casas ----------
    async function cargarCasas() {
        try {
            const datos = await (await fetch(RUTA_INV + '?accion=casas')).json();

            if (!datos.ok) {
                mostrarAviso(datos.error || 'No se pudieron cargar las casas.', 'error', 0);
                return;
            }

            listaCasas.innerHTML = datos.casas.map((c, i) =>
                '<button type="button" class="chip casa-tab' + (i === 0 ? ' chip-activo' : '') + '" ' +
                    'data-casa="' + c.codigo_casa + '">' +
                    esc(c.etiqueta) +
                    '<span class="casa-conteo">' + Number(c.productos).toLocaleString('es-MX') + '</span>' +
                '</button>'
            ).join('');

            if (datos.casas.length > 0) {
                casaActual = datos.casas[0].codigo_casa;
                cargarProductos();
            }
        } catch (e) {
            mostrarAviso('Error de conexión.', 'error', 0);
        }
    }

    // ---------- Productos ----------
    async function cargarProductos() {
        listaProductos.innerHTML = '<p class="venta-vacia">Cargando...</p>';
        paginacion.hidden = true;

        const url = RUTA_INV
            + '?casa=' + encodeURIComponent(casaActual)
            + '&pagina=' + paginaActual
            + '&q=' + encodeURIComponent(buscador.value.trim());

        try {
            const respuesta = await fetch(url);

            if (respuesta.status === 401) {
                mostrarAviso('Tu sesión expiró. Vuelve a iniciar sesión.', 'error', 0);
                listaProductos.innerHTML = '';
                return;
            }

            const datos = await respuesta.json();

            if (!datos.ok) {
                mostrarAviso(datos.error || 'No se pudo cargar el inventario.', 'error', 0);
                listaProductos.innerHTML = '';
                return;
            }

            pintarProductos(datos);

        } catch (e) {
            mostrarAviso('Error de conexión.', 'error', 0);
            listaProductos.innerHTML = '';
        }
    }

    function pintarProductos(datos) {
        totalPaginas = datos.paginas;

        const desde = (datos.pagina - 1) * datos.por_pagina + 1;
        const hasta = Math.min(datos.pagina * datos.por_pagina, datos.total);

        resumen.innerHTML = datos.total === 0
            ? '<span>Sin resultados</span>'
            : '<span>Mostrando ' + desde + '–' + hasta + ' de ' +
              datos.total.toLocaleString('es-MX') + ' productos</span>';

        if (datos.total === 0) {
            listaProductos.innerHTML = '<p class="venta-vacia">No hay productos que coincidan.</p>';
            return;
        }

        listaProductos.innerHTML = '';

        datos.productos.forEach((p) => {
            // <div> y no <button>: dentro de un boton, Firefox y Safari no
            // respetan el layout de los hijos.
            const fila = document.createElement('div');
            fila.className = 'venta-fila producto-fila' + (window.ES_ADMIN ? '' : ' fila-no-editable');
            fila.dataset.codigo = p.codigo_interno;

            // Sin permiso para editar precios, la fila es solo informativa.
            if (window.ES_ADMIN) {
                fila.setAttribute('role', 'button');
                fila.tabIndex = 0;
            }

            const precios =
                '<span class="precio-etiqueta">Menudeo <strong>' + money(p.precio_menudeo) + '</strong></span>' +
                '<span class="precio-etiqueta">Mayoreo <strong>' + money(p.precio_mayoreo) + '</strong></span>';

            fila.innerHTML =
                '<div class="venta-fila-datos">' +
                    '<p class="venta-fila-cliente">' + esc(p.nombre) + '</p>' +
                    '<p class="venta-fila-meta">' + p.codigo_interno +
                        ' · ' + esc(p.codigo_proveedor || 's/código') +
                        (p.marca ? ' · ' + esc(p.marca) : '') +
                        (p.categoria ? ' · ' + esc(p.categoria) : '') + '</p>' +
                '</div>' +
                '<div class="venta-fila-derecha">' +
                    '<div class="precios-producto">' + precios + '</div>' +
                    (window.ES_ADMIN ? '<span class="chip btn-editar-precio">Editar precio</span>' : '') +
                '</div>';

            listaProductos.appendChild(fila);
        });

        paginacion.hidden = datos.paginas <= 1;
        infoPagina.textContent = 'Página ' + datos.pagina + ' de ' + datos.paginas;
        btnAnterior.disabled = datos.pagina <= 1;
        btnSiguiente.disabled = datos.pagina >= datos.paginas;
    }

    // ---------- Editar precio ----------
    async function abrirPrecio(codigo) {
        contenido.innerHTML = '<p class="venta-vacia">Cargando...</p>';
        modal.hidden = false;

        try {
            const datos = await (await fetch(
                RUTA_PRECIO + '?accion=consultar&codigo=' + encodeURIComponent(codigo)
            )).json();

            if (!datos.ok) {
                contenido.innerHTML = '<p class="aviso aviso-error">' +
                    esc(datos.error || 'No se pudo cargar el producto') + '</p>';
                return;
            }

            pintarFormulario(datos.producto);

        } catch (e) {
            contenido.innerHTML = '<p class="aviso aviso-error">Error de conexión.</p>';
        }
    }

    function pintarFormulario(p) {
        const valor = (n) => (n === null ? '' : Number(n).toFixed(2));

        contenido.innerHTML =
            '<h2 class="detalle-titulo">Editar precio</h2>' +
            '<div class="detalle-cabecera">' +
                '<p><strong>' + esc(p.nombre) + '</strong></p>' +
                '<p class="detalle-sub">' + p.codigo_interno + ' · ' + esc(p.codigo_proveedor || '') +
                    (p.marca ? ' · ' + esc(p.marca) : '') + '</p>' +
            '</div>' +
            '<p class="aviso aviso-info">El precio nuevo aplica a partir de este momento. ' +
                'Las ventas ya registradas conservan el precio con el que se cobraron.</p>' +
            '<div class="form-group">' +
                '<label for="inv-menudeo">Precio menudeo <span class="detalle-sub">' +
                    '(actual: ' + money(p.precio_menudeo) + ')</span></label>' +
                '<input type="number" id="inv-menudeo" class="form-control" min="0" step="0.01" ' +
                       'value="' + valor(p.precio_menudeo) + '">' +
            '</div>' +
            '<div class="form-group">' +
                '<label for="inv-mayoreo">Precio mayoreo <span class="detalle-sub">' +
                    '(actual: ' + money(p.precio_mayoreo) + ')</span></label>' +
                '<input type="number" id="inv-mayoreo" class="form-control" min="0" step="0.01" ' +
                       'value="' + valor(p.precio_mayoreo) + '">' +
            '</div>' +
            '<p class="detalle-sub">Deja un campo vacío para no tocar ese precio.</p>' +
            '<div id="inv-aviso" class="aviso" hidden></div>' +
            '<div class="detalle-acciones">' +
                '<button type="button" class="chip" id="btn-cancelar-inv">Cancelar</button>' +
                '<button type="button" class="btn-save" id="btn-guardar-inv" ' +
                    'data-codigo="' + p.codigo_interno + '">Guardar precio</button>' +
            '</div>';
    }

    async function guardarPrecio(codigo) {
        const avisoForm = document.getElementById('inv-aviso');
        const boton = document.getElementById('btn-guardar-inv');

        boton.disabled = true;
        boton.textContent = 'Guardando...';

        try {
            const datos = await (await fetch(RUTA_PRECIO, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    codigo:         codigo,
                    precio_menudeo: document.getElementById('inv-menudeo').value,
                    precio_mayoreo: document.getElementById('inv-mayoreo').value,
                }),
            })).json();

            if (!datos.ok) {
                avisoForm.textContent = datos.error || 'No se pudo guardar.';
                avisoForm.className = 'aviso aviso-error';
                avisoForm.hidden = false;
                return;
            }

            modal.hidden = true;

            if (datos.cambios === 0) {
                mostrarAviso(datos.mensaje, 'ok');
            } else {
                const linea = (etiqueta, d) => {
                    if (d.tendencia === 'igual') return '';
                    const antes = d.antes === null ? 'sin precio' : money(d.antes);
                    return ' ' + etiqueta + ': ' + antes + ' → ' + money(d.despues) + '.';
                };
                mostrarAviso(
                    'Precio de ' + datos.nombre + ' actualizado.' +
                    linea('Menudeo', datos.menudeo) + linea('Mayoreo', datos.mayoreo),
                    'ok'
                );
            }

            cargarProductos();

        } catch (e) {
            avisoForm.textContent = 'Error de conexión.';
            avisoForm.className = 'aviso aviso-error';
            avisoForm.hidden = false;
        } finally {
            boton.disabled = false;
            boton.textContent = 'Guardar precio';
        }
    }

    // ---------- Eventos ----------
    listaCasas.addEventListener('click', (e) => {
        const tab = e.target.closest('.casa-tab');
        if (!tab) return;

        listaCasas.querySelectorAll('.casa-tab').forEach((t) => t.classList.remove('chip-activo'));
        tab.classList.add('chip-activo');

        casaActual = tab.dataset.casa;
        paginaActual = 1;
        cargarProductos();
    });

    let temporizadorBusqueda = null;

    buscador.addEventListener('input', () => {
        clearTimeout(temporizadorBusqueda);
        temporizadorBusqueda = setTimeout(() => {
            paginaActual = 1;
            cargarProductos();
        }, 350);
    });

    btnAnterior.addEventListener('click', () => {
        if (paginaActual > 1) { paginaActual--; cargarProductos(); window.scrollTo(0, 0); }
    });

    btnSiguiente.addEventListener('click', () => {
        if (paginaActual < totalPaginas) { paginaActual++; cargarProductos(); window.scrollTo(0, 0); }
    });

    listaProductos.addEventListener('click', (e) => {
        if (!window.ES_ADMIN) return;
        const fila = e.target.closest('.producto-fila');
        if (fila) abrirPrecio(fila.dataset.codigo);
    });

    listaProductos.addEventListener('keydown', (e) => {
        if (!window.ES_ADMIN) return;
        if (e.key !== 'Enter' && e.key !== ' ') return;
        const fila = e.target.closest('.producto-fila');
        if (fila) { e.preventDefault(); abrirPrecio(fila.dataset.codigo); }
    });

    contenido.addEventListener('click', (e) => {
        if (e.target.id === 'btn-cancelar-inv') modal.hidden = true;
        if (e.target.id === 'btn-guardar-inv') guardarPrecio(e.target.dataset.codigo);
    });

    btnCerrar.addEventListener('click', () => { modal.hidden = true; });

    modal.addEventListener('click', (e) => {
        if (e.target === modal) modal.hidden = true;
    });

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') modal.hidden = true;
    });

    cargarCasas();
});
