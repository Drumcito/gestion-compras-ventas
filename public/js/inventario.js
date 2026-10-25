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
    const RUTA_EDITAR = '../../app/controllers/EditarProductoController.php';
    const RUTA_PCT    = '../../app/controllers/PorcentajeProductoController.php';
    const RUTA_IMG    = '../../app/controllers/ImagenProductoController.php';

    // Las imagenes se ven por el mismo img.php del catalogo en linea: con la
    // version en la direccion el navegador las guarda y, al cambiarla, pide la nueva.
    const urlImagen = (codigo, version, miniatura) => '../../catalogo/img.php?c=' +
        encodeURIComponent(codigo) + (miniatura ? '&t=400' : '') + '&v=' + version;

    // Mismo cálculo que netoDe() en el servidor: bruto + %, a 2 decimales.
    const netoJS = (bruto, pct) => (bruto === null || bruto === '' || isNaN(parseFloat(bruto)))
        ? null
        : Math.round(parseFloat(bruto) * (1 + Number(pct) / 100) * 100) / 100;

    const pctTexto = (n) => Number(n).toLocaleString('es-MX', { maximumFractionDigits: 2 });

    let casaActual   = null;
    let paginaActual = 1;
    let totalPaginas = 1;
    let casas        = [];

    const money = (n) => n === null || n === ''
        ? '—'
        : '$' + Number(n).toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    /**
     * El codigo que se muestra es el del proveedor; el interno (BNS0X-#####) es
     * de la base y no se ocupa en el mostrador. Solo sale si la pieza no trae
     * codigo de proveedor.
     */
    const codigoVisible = (p) => p.codigo_proveedor || p.codigo_interno;

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

    /** '2026-10-06 17:48:38' -> '06/10/2026'. Cadena vacia si no hay fecha. */
    function fechaCorta(iso) {
        if (!iso) return '';

        // El servidor manda 'YYYY-MM-DD HH:MM:SS'; Safari no lo parsea sin la T.
        const d = new Date(String(iso).replace(' ', 'T'));

        return isNaN(d) ? '' : d.toLocaleDateString('es-MX',
            { day: '2-digit', month: '2-digit', year: 'numeric' });
    }

    /** Flecha de subida o bajada entre dos precios. '' si no hay con qué comparar. */
    function flechaPrecio(antes, ahora) {
        if (antes === null || antes === undefined || ahora === null || ahora === undefined) {
            return '';
        }

        const a = Number(antes), b = Number(ahora);

        if (a === b) return '';

        return b > a
            ? ' <span class="precio-sube" title="Subió">▲</span>'
            : ' <span class="precio-baja" title="Bajó">▼</span>';
    }

    /**
     * Desde cuándo rige el precio que se está viendo, y de cuánto venía.
     *
     * Si al producto nunca se le movió el precio no hay nada en el historial, y
     * entonces lo que vale es desde cuándo está en el catálogo: trae el mismo
     * precio con el que entró.
     *
     * Devuelve HTML (lleva la flecha), así que lo que venga de la base ya va
     * escapado o es un número.
     */
    function htmlPrecioDesde(p) {
        if (!p.ultimo_cambio_precio) {
            const alta = fechaCorta(p.precio_desde);

            return alta ? 'Sin cambios desde el alta · ' + esc(alta) : '';
        }

        let texto = 'Precio cambiado el ' + esc(fechaCorta(p.ultimo_cambio_precio));

        // El historial guarda el BRUTO, que es justo el que se edita aquí.
        if (p.precio_anterior !== null && p.precio_anterior !== undefined) {
            texto += ' · antes ' + esc(money(p.precio_anterior)) + ' bruto' +
                     flechaPrecio(p.precio_anterior, p.precio_mayoreo);
        }

        return texto;
    }

    // ---------- Casas ----------
    /**
     * Vuelve a pintar las pestañas de casa. Se llama también después de crear una
     * casa o de agregar productos (cambia el conteo), así que respeta la casa que
     * estaba viéndose: sin esto, cada alta te regresaba a la primera.
     */
    async function cargarCasas(codigoPreferido) {
        try {
            const datos = await (await fetch(RUTA_INV + '?accion=casas')).json();

            if (!datos.ok) {
                mostrarAviso(datos.error || 'No se pudieron cargar las casas.', 'error', 0);
                return;
            }

            casas = datos.casas;

            const existe = (codigo) => datos.casas.some((c) => c.codigo_casa === codigo);

            const elegida = existe(codigoPreferido) ? codigoPreferido
                          : existe(casaActual)      ? casaActual
                          : (datos.casas[0] || {}).codigo_casa;

            listaCasas.innerHTML = datos.casas.map((c) =>
                '<button type="button" class="chip casa-tab' +
                    (c.codigo_casa === elegida ? ' chip-activo' : '') + '" ' +
                    'data-casa="' + c.codigo_casa + '">' +
                    esc(c.etiqueta) +
                    '<span class="casa-conteo">' + Number(c.productos).toLocaleString('es-MX') + '</span>' +
                '</button>'
            ).join('');

            if (elegida) {
                const cambioDeCasa = elegida !== casaActual;
                casaActual = elegida;
                if (cambioDeCasa) paginaActual = 1;
                cargarProductos();
            }
        } catch (e) {
            mostrarAviso('Error de conexión.', 'error', 0);
        }
    }

    /** Etiqueta de la casa que se está viendo, para los títulos y los avisos. */
    function etiquetaActual() {
        const casa = casas.find((c) => c.codigo_casa === casaActual);
        return casa ? casa.etiqueta : casaActual;
    }

    // ---------- Productos ----------
    async function cargarProductos() {
        pintarCasaInfo();
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
            // Una casa recién creada está vacía: conviene decir cómo llenarla en
            // lugar de dejar el mismo mensaje que una búsqueda sin resultados.
            const vacia = buscador.value.trim() === '';

            listaProductos.innerHTML = '<p class="venta-vacia">' +
                (vacia
                    ? 'Esta casa todavía no tiene productos.' +
                      (window.ES_ADMIN ? ' Usa <strong>Agregar productos</strong> para cargar su catálogo.' : '')
                    : 'No hay productos que coincidan.') +
                '</p>';
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

            // Bruto (lo que se captura) y neto (lo que se cobra) con su porcentaje.
            // El porcentaje se resalta cuando es propio del producto, distinto al
            // de la casa.
            const precios =
                '<span class="precio-etiqueta">Bruto <strong>' + money(p.precio_mayoreo) + '</strong></span>' +
                '<span class="precio-etiqueta">Neto <strong>' + money(p.precio_neto) + '</strong> ' +
                    '<span class="pct-etiqueta' + (p.porcentaje_propio ? ' pct-propio' : '') + '" ' +
                        'title="' + (p.porcentaje_propio
                            ? 'Porcentaje propio de este producto'
                            : 'Porcentaje de la casa') + '">' +
                        pctTexto(p.porcentaje_neto) + '%</span></span>';

            const desde = htmlPrecioDesde(p);

            fila.innerHTML =
                (window.ES_ADMIN ? botonMiniatura(p.codigo_interno, p.imagen) : '') +
                '<div class="venta-fila-datos">' +
                    '<p class="venta-fila-cliente">' + esc(p.nombre) + '</p>' +
                    '<p class="venta-fila-meta">' +
                        '<span class="codigo-prod">' + esc(codigoVisible(p)) + '</span>' +
                        (p.marca ? ' · ' + esc(p.marca) : '') +
                        (p.categoria ? ' · ' + esc(p.categoria) : '') + '</p>' +
                '</div>' +
                '<div class="venta-fila-derecha">' +
                    '<div class="precios-producto">' + precios +
                        (desde ? '<span class="precio-fecha">' + desde + '</span>' : '') +
                    '</div>' +
                    (window.ES_ADMIN
                        ? '<div class="producto-acciones">' +
                              '<span class="chip btn-editar-porcentaje" ' +
                                  'data-codigo="' + esc(p.codigo_interno) + '" ' +
                                  'title="Cambiar solo el porcentaje de este producto">Editar %</span>' +
                              '<span class="chip btn-editar-precio">Editar</span>' +
                              '<button type="button" class="btn-borrar-prod" ' +
                                  'title="Borrar producto" aria-label="Borrar producto" ' +
                                  'data-codigo="' + esc(p.codigo_interno) + '" ' +
                                  'data-nombre="' + esc(p.nombre) + '">' +
                                  '<i class="ph ph-x" aria-hidden="true"></i>' +
                              '</button>' +
                          '</div>'
                        : '') +
                '</div>';

            listaProductos.appendChild(fila);
        });

        paginacion.hidden = datos.paginas <= 1;
        infoPagina.textContent = 'Página ' + datos.pagina + ' de ' + datos.paginas;
        btnAnterior.disabled = datos.pagina <= 1;
        btnSiguiente.disabled = datos.pagina >= datos.paginas;
    }

    // ---------- Imagen del producto ----------
    // Las imagenes son archivos (public/img/productos/), no van en la base. El
    // navegador las deja cuadradas, con fondo blanco y en 800 y 400 px antes de
    // subirlas: asi pesan poco y no hace falta GD en el servidor.

    function botonMiniatura(codigo, version) {
        return '<button type="button" class="prod-miniatura btn-imagen' + (version ? '' : ' sin-imagen') + '" ' +
            'data-codigo="' + esc(codigo) + '" ' +
            'title="' + (version ? 'Cambiar la imagen' : 'Agregar imagen') + '" ' +
            'aria-label="' + (version ? 'Cambiar la imagen' : 'Agregar imagen') + '">' +
            (version
                ? '<img src="' + urlImagen(codigo, version, true) + '" alt="" loading="lazy" width="48" height="48">'
                : '<i class="ph ph-camera-plus" aria-hidden="true"></i>') +
            '</button>';
    }

    function cargarImagenDeArchivo(archivo) {
        return new Promise((resolver, rechazar) => {
            const url = URL.createObjectURL(archivo);
            const img = new Image();
            img.onload  = () => { URL.revokeObjectURL(url); resolver(img); };
            img.onerror = () => {
                URL.revokeObjectURL(url);
                rechazar(new Error('No se pudo leer «' + archivo.name + '». Usa JPG, PNG o WebP ' +
                    '(las fotos HEIC del iPhone hay que exportarlas como JPG).'));
            };
            img.src = url;
        });
    }

    function lienzo(ancho, alto) {
        const c = document.createElement('canvas');
        c.width = ancho;
        c.height = alto;
        return c;
    }

    /**
     * Cuadrado de lado x lado, fondo blanco y el producto centrado ocupando el
     * 90% (el estandar de public/img/productos/README.md). Una foto grande se
     * reduce a la mitad varias veces antes del tamaño final: de un jalon queda
     * "pixeleada".
     */
    function cuadrado(img, lado) {
        const ancho = img.naturalWidth || img.width;
        const alto  = img.naturalHeight || img.height;
        const escala = Math.min(lado * 0.9 / ancho, lado * 0.9 / alto);
        const destAncho = Math.max(1, Math.round(ancho * escala));
        const destAlto  = Math.max(1, Math.round(alto * escala));

        let fuente = img;
        let fAncho = ancho;
        let fAlto = alto;
        while (fAncho / 2 > destAncho && fAlto / 2 > destAlto) {
            const mitad = lienzo(Math.round(fAncho / 2), Math.round(fAlto / 2));
            const ctx = mitad.getContext('2d');
            ctx.imageSmoothingQuality = 'high';
            ctx.drawImage(fuente, 0, 0, mitad.width, mitad.height);
            fuente = mitad;
            fAncho = mitad.width;
            fAlto = mitad.height;
        }

        const final = lienzo(lado, lado);
        const ctx = final.getContext('2d');
        ctx.fillStyle = '#ffffff';    // un PNG transparente queda sobre blanco
        ctx.fillRect(0, 0, lado, lado);
        ctx.imageSmoothingQuality = 'high';
        ctx.drawImage(fuente, Math.round((lado - destAncho) / 2), Math.round((lado - destAlto) / 2), destAncho, destAlto);
        return final;
    }

    const aBlob = (canvas, calidad) => new Promise((resolver, rechazar) => {
        canvas.toBlob((b) => (b ? resolver(b) : rechazar(new Error('No se pudo preparar la imagen.'))),
            'image/jpeg', calidad);
    });

    /** La foto, lista para subir: { grande, chica, vista, original: [ancho, alto] }. */
    async function prepararImagen(archivo) {
        if (!archivo || !/^image\//.test(archivo.type || 'image/')) {
            throw new Error('«' + (archivo ? archivo.name : '') + '» no es una imagen.');
        }
        const img    = await cargarImagenDeArchivo(archivo);
        const grande = cuadrado(img, 800);
        const chica  = lienzo(400, 400);
        const ctx = chica.getContext('2d');
        ctx.imageSmoothingQuality = 'high';
        ctx.drawImage(grande, 0, 0, 400, 400);

        const blobGrande = await aBlob(grande, 0.85);
        return {
            grande:   blobGrande,
            chica:    await aBlob(chica, 0.82),
            vista:    URL.createObjectURL(blobGrande),
            original: [img.naturalWidth, img.naturalHeight],
        };
    }

    async function subirImagen(codigo, lista) {
        const datos = new FormData();
        datos.append('accion', 'subir');
        datos.append('codigo', codigo);
        datos.append('grande', lista.grande, 'grande.jpg');
        datos.append('chica', lista.chica, 'chica.jpg');

        const respuesta = await fetch(RUTA_IMG, { method: 'POST', body: datos });
        return respuesta.json();
    }

    /** Refleja en la lista la imagen nueva (o que se quito) sin recargar. */
    function actualizarMiniatura(codigo, version) {
        const fila = [...listaProductos.querySelectorAll('.producto-fila')]
            .find((f) => f.dataset.codigo.toUpperCase() === String(codigo).toUpperCase());
        const boton = fila && fila.querySelector('.prod-miniatura');
        if (boton) boton.outerHTML = botonMiniatura(fila.dataset.codigo, version);
    }

    let imagenLista  = null;   // foto preparada y aun sin guardar
    let imagenCodigo = null;   // producto abierto en la ventana de imagen

    function soltarVistaPrevia() {
        if (imagenLista) URL.revokeObjectURL(imagenLista.vista);
        imagenLista = null;
    }

    function pesoTexto(bytes) {
        return bytes >= 1048576
            ? (bytes / 1048576).toLocaleString('es-MX', { maximumFractionDigits: 1 }) + ' MB'
            : Math.round(bytes / 1024) + ' KB';
    }

    async function abrirImagen(codigo) {
        soltarVistaPrevia();
        imagenCodigo = null;
        contenido.innerHTML = '<p class="venta-vacia">Cargando...</p>';
        modal.hidden = false;

        try {
            const datos = await (await fetch(RUTA_IMG + '?accion=info&codigo=' + encodeURIComponent(codigo))).json();
            if (!datos.ok) {
                contenido.innerHTML = '<p class="aviso aviso-error">' + esc(datos.error || 'No se pudo cargar') + '</p>';
                return;
            }
            imagenCodigo = datos.codigo;
            pintarImagen(datos);
        } catch (e) {
            contenido.innerHTML = '<p class="aviso aviso-error">Error de conexión.</p>';
        }
    }

    function pintarImagen(d) {
        const meta = !d.version
            ? 'Este producto todavía no tiene imagen.'
            : (d.datos
                ? 'Subida por ' + esc((d.datos.subida_por || 'un usuario borrado').trim()) + ' el ' +
                  esc(String(d.datos.subida_en).slice(0, 10).split('-').reverse().join('/')) +
                  ' · ' + d.datos.ancho + ' × ' + d.datos.alto + ' px · ' + pesoTexto(Number(d.datos.peso_bytes))
                : 'Imagen copiada directo al servidor' +
                  (d.miniatura ? '.' : ' (sin miniatura de 400 px: vuelve a subirla para que cargue más rápido).'));

        contenido.innerHTML =
            '<h2 class="detalle-titulo">Imagen del producto</h2>' +
            '<p class="imagen-producto-nombre">' + esc(d.nombre) + ' · <span class="codigo-prod">' + esc(d.codigo) + '</span></p>' +
            '<div class="imagen-editor">' +
                '<div class="imagen-vista' + (d.version ? '' : ' sin-imagen') + '" id="imagen-vista">' +
                    (d.version
                        ? '<img src="' + urlImagen(d.codigo, d.version, false) + '" alt="" width="800" height="800">'
                        : '<i class="ph ph-image" aria-hidden="true"></i>') +
                '</div>' +
                '<div class="imagen-lado">' +
                    '<p class="imagen-meta" id="imagen-meta">' + meta + '</p>' +
                    '<label class="imagen-soltar" id="imagen-soltar">' +
                        '<i class="ph ph-upload-simple" aria-hidden="true"></i>' +
                        '<span>Arrastra una foto aquí, pégala con <kbd>Ctrl</kbd>+<kbd>V</kbd> o ' +
                            '<strong>elige un archivo</strong></span>' +
                        '<input type="file" id="imagen-archivo" accept="image/*" hidden>' +
                    '</label>' +
                    '<p class="imagen-ayuda">Se ajusta sola: queda cuadrada, con fondo blanco y en 800 y 400 px ' +
                        '(unos 100 KB). Mejor si la foto es de 800 px o más.</p>' +
                    '<div id="aviso-imagen" class="aviso" hidden></div>' +
                    '<div class="imagen-botones">' +
                        (d.version
                            ? '<button type="button" class="chip chip-peligro" id="btn-quitar-imagen">' +
                                '<i class="ph ph-trash"></i> Quitar imagen</button>'
                            : '') +
                        '<button type="button" class="btn-save" id="btn-guardar-imagen" disabled>' +
                            '<i class="ph ph-floppy-disk"></i> Guardar imagen</button>' +
                    '</div>' +
                '</div>' +
            '</div>';
    }

    function avisoImagen(texto, tipo) {
        const caja = document.getElementById('aviso-imagen');
        if (!caja) return;
        caja.textContent = texto;
        caja.className = 'aviso aviso-' + tipo;
        caja.hidden = !texto;
    }

    async function elegirImagen(archivo) {
        // La ventana pudo cambiar a otro editor (precio, %): solo con la de imagen.
        if (!imagenCodigo || !archivo || !document.getElementById('imagen-vista')) return;
        avisoImagen('Preparando la imagen…', 'info');

        try {
            const lista = await prepararImagen(archivo);
            soltarVistaPrevia();
            imagenLista = lista;

            const vista = document.getElementById('imagen-vista');
            vista.classList.remove('sin-imagen');
            vista.innerHTML = '<img src="' + lista.vista + '" alt="" width="800" height="800">' +
                '<span class="imagen-sin-guardar">Vista previa · sin guardar</span>';
            document.getElementById('btn-guardar-imagen').disabled = false;

            const [ancho, alto] = lista.original;
            avisoImagen(Math.max(ancho, alto) < 600
                ? 'La foto es pequeña (' + ancho + ' × ' + alto + ' px): se verá algo borrosa en grande.'
                : 'Lista: ' + pesoTexto(lista.grande.size) + '. Revisa la vista previa y guarda.',
                Math.max(ancho, alto) < 600 ? 'alerta' : 'ok');
        } catch (e) {
            avisoImagen(e.message, 'error');
        }
    }

    async function guardarImagen() {
        if (!imagenLista || !imagenCodigo) return;
        const boton = document.getElementById('btn-guardar-imagen');
        boton.disabled = true;
        boton.textContent = 'Guardando...';

        try {
            const datos = await subirImagen(imagenCodigo, imagenLista);
            if (!datos.ok) {
                avisoImagen(datos.error || 'No se pudo guardar la imagen.', 'error');
                boton.disabled = false;
                boton.innerHTML = '<i class="ph ph-floppy-disk"></i> Guardar imagen';
                return;
            }
            soltarVistaPrevia();
            actualizarMiniatura(datos.codigo, datos.version);
            modal.hidden = true;
            mostrarAviso('Imagen guardada: ' + datos.nombre + '. Ya se ve en el catálogo en línea.', 'ok');
        } catch (e) {
            avisoImagen('Error de conexión al subir la imagen.', 'error');
            boton.disabled = false;
            boton.innerHTML = '<i class="ph ph-floppy-disk"></i> Guardar imagen';
        }
    }

    async function quitarImagen() {
        if (!imagenCodigo || !confirm('¿Quitar la imagen de este producto? Deja de verse en el catálogo y en el PDF.')) {
            return;
        }
        const datos = new FormData();
        datos.append('accion', 'quitar');
        datos.append('codigo', imagenCodigo);

        try {
            const r = await (await fetch(RUTA_IMG, { method: 'POST', body: datos })).json();
            if (!r.ok) {
                avisoImagen(r.error || 'No se pudo quitar la imagen.', 'error');
                return;
            }
            actualizarMiniatura(r.codigo, 0);
            modal.hidden = true;
            mostrarAviso('Se quitó la imagen de ' + r.nombre + '.', 'ok');
        } catch (e) {
            avisoImagen('Error de conexión.', 'error');
        }
    }

    // ----- Varias a la vez: cada archivo se llama como el codigo del producto -----
    function abrirSubidaMasiva() {
        soltarVistaPrevia();
        imagenCodigo = null;
        contenido.innerHTML =
            '<h2 class="detalle-titulo">Subir varias imágenes</h2>' +
            '<p class="imagen-ayuda">Nombra cada foto con el <strong>código del producto</strong>: el del proveedor ' +
                '(el que ves en la lista, p. ej. <code>IMP641.jpg</code>) o el interno (<code>BNS03-07092.jpg</code>). ' +
                'Cada una se ajusta sola a 800 y 400 px y reemplaza la que hubiera.</p>' +
            '<label class="imagen-soltar" id="imagen-soltar-varias">' +
                '<i class="ph ph-images" aria-hidden="true"></i>' +
                '<span>Arrastra las fotos aquí o <strong>elígelas</strong></span>' +
                '<input type="file" id="imagen-archivos" accept="image/*" multiple hidden>' +
            '</label>' +
            '<p class="imagen-progreso" id="imagen-progreso" hidden></p>' +
            '<ul class="imagen-resultados" id="imagen-resultados"></ul>';
        modal.hidden = false;
    }

    let subiendoVarias = false;

    async function subirVarias(archivos) {
        if (subiendoVarias || !archivos.length) return;
        subiendoVarias = true;

        const progreso   = document.getElementById('imagen-progreso');
        const resultados = document.getElementById('imagen-resultados');
        resultados.innerHTML = '';
        progreso.hidden = false;
        let bien = 0;

        // Una por una: no saturar al servidor ni la memoria del navegador.
        for (let i = 0; i < archivos.length; i++) {
            const archivo = archivos[i];
            const codigo = archivo.name.replace(/\.[^.]+$/, '').trim();
            progreso.textContent = 'Subiendo ' + (i + 1) + ' de ' + archivos.length + '…';

            const li = document.createElement('li');
            try {
                const lista = await prepararImagen(archivo);
                URL.revokeObjectURL(lista.vista);
                const r = await subirImagen(codigo, lista);
                if (!r.ok) throw new Error(r.error || 'No se pudo subir');

                bien++;
                actualizarMiniatura(r.codigo, r.version);
                li.className = 'imagen-ok';
                li.innerHTML = '<i class="ph ph-check-circle"></i> <strong>' + esc(archivo.name) + '</strong> → ' + esc(r.nombre);
            } catch (e) {
                li.className = 'imagen-error';
                li.innerHTML = '<i class="ph ph-x-circle"></i> <strong>' + esc(archivo.name) + '</strong>: ' + esc(e.message);
            }
            resultados.appendChild(li);
        }

        progreso.textContent = 'Listo: ' + bien + ' de ' + archivos.length + ' imágenes guardadas.';
        subiendoVarias = false;
    }

    // Elegir / arrastrar dentro de la ventana (una o varias, segun cual este abierta).
    contenido.addEventListener('change', (e) => {
        if (e.target.id === 'imagen-archivo') elegirImagen(e.target.files[0]);
        if (e.target.id === 'imagen-archivos') subirVarias([...e.target.files]);
        if (e.target.type === 'file') e.target.value = '';
    });

    contenido.addEventListener('dragover', (e) => {
        const zona = e.target.closest('.imagen-soltar');
        if (!zona) return;
        e.preventDefault();
        zona.classList.add('arrastrando');
    });

    contenido.addEventListener('dragleave', (e) => {
        const zona = e.target.closest('.imagen-soltar');
        if (zona) zona.classList.remove('arrastrando');
    });

    contenido.addEventListener('drop', (e) => {
        const zona = e.target.closest('.imagen-soltar');
        if (!zona) return;
        e.preventDefault();
        zona.classList.remove('arrastrando');
        const archivos = [...e.dataTransfer.files];
        if (zona.id === 'imagen-soltar-varias') subirVarias(archivos);
        else elegirImagen(archivos[0]);
    });

    // Pegar una imagen copiada (de una pagina o captura) con la ventana abierta.
    document.addEventListener('paste', (e) => {
        if (modal.hidden || !imagenCodigo) return;
        const archivo = [...(e.clipboardData ? e.clipboardData.files : [])].find((f) => /^image\//.test(f.type));
        if (archivo) {
            e.preventDefault();
            elegirImagen(archivo);
        }
    });

    const btnSubirImagenes = document.getElementById('btn-subir-imagenes');
    if (btnSubirImagenes) btnSubirImagenes.addEventListener('click', abrirSubidaMasiva);

    // ---------- Editar producto (nombre, código, precio y casa) ----------
    async function abrirEditor(codigo) {
        contenido.innerHTML = '<p class="venta-vacia">Cargando...</p>';
        modal.hidden = false;

        try {
            const datos = await (await fetch(
                RUTA_EDITAR + '?accion=consultar&codigo=' + encodeURIComponent(codigo)
            )).json();

            if (!datos.ok) {
                contenido.innerHTML = '<p class="aviso aviso-error">' +
                    esc(datos.error || 'No se pudo cargar el producto') + '</p>';
                return;
            }

            pintarEditor(datos);

        } catch (e) {
            contenido.innerHTML = '<p class="aviso aviso-error">Error de conexión.</p>';
        }
    }

    function pintarEditor(datos) {
        const p     = datos.producto;
        const casas = datos.casas || [];
        const valor = (n) => (n === null || n === '' ? '' : Number(n).toFixed(2));

        // Porcentaje y etiqueta de cada casa, para calcular el neto en vivo y
        // avisar cuando se elige una casa distinta a la actual.
        const pct  = {};
        const etiq = {};
        casas.forEach((c) => { pct[c.codigo_casa] = Number(c.porcentaje_neto); etiq[c.codigo_casa] = c.etiqueta; });

        const opciones = casas.map((c) =>
            '<option value="' + esc(c.codigo_casa) + '"' +
                (c.codigo_casa === datos.casa_actual ? ' selected' : '') + '>' +
                esc(c.etiqueta) + ' — ' + esc(c.nombre) +
            '</option>'
        ).join('');

        contenido.innerHTML =
            '<h2 class="detalle-titulo">Editar producto</h2>' +
            '<p class="detalle-sub">Código interno: <span class="codigo-prod">' + esc(p.codigo_interno) + '</span>' +
                (datos.ultimo_cambio_precio
                    ? ' · precio cambiado el ' + fechaCorta(datos.ultimo_cambio_precio) +
                      (datos.precio_anterior !== null && datos.precio_anterior !== undefined
                          ? ' · antes ' + money(datos.precio_anterior)
                          : '')
                    : ' · nunca se le ha cambiado el precio') + '</p>' +
            '<div class="form-group">' +
                '<label for="ed-nombre">Nombre / concepto</label>' +
                '<input type="text" id="ed-nombre" class="form-control" maxlength="150" value="' + esc(p.nombre) + '">' +
            '</div>' +
            '<div class="form-group">' +
                '<label for="ed-codigo">Código del proveedor <span class="detalle-sub">(opcional)</span></label>' +
                '<input type="text" id="ed-codigo" class="form-control" maxlength="50" value="' + esc(p.codigo_proveedor || '') + '">' +
            '</div>' +
            '<div class="alta-dos">' +
                '<div class="form-group">' +
                    '<label for="ed-marca">Marca <span class="detalle-sub">(opcional)</span></label>' +
                    '<input type="text" id="ed-marca" class="form-control" maxlength="60" value="' + esc(p.marca || '') + '">' +
                '</div>' +
                '<div class="form-group">' +
                    '<label for="ed-categoria">Categoría <span class="detalle-sub">(opcional)</span></label>' +
                    '<input type="text" id="ed-categoria" class="form-control" maxlength="60" value="' + esc(p.categoria || '') + '">' +
                '</div>' +
            '</div>' +
            '<div class="alta-dos">' +
                '<div class="form-group">' +
                    '<label for="ed-mayoreo">Precio bruto</label>' +
                    '<input type="number" id="ed-mayoreo" class="form-control" min="0" step="0.01" value="' + valor(p.precio_mayoreo) + '">' +
                '</div>' +
                '<div class="form-group">' +
                    '<label for="ed-casa">Casa</label>' +
                    '<select id="ed-casa" class="form-control">' + opciones + '</select>' +
                '</div>' +
            '</div>' +
            '<p id="ed-neto" class="detalle-sub"></p>' +
            '<div id="ed-aviso-mover" class="aviso aviso-info" hidden></div>' +
            '<p class="aviso aviso-info">El precio de venta (neto) se calcula solo, sumándole el porcentaje de la casa. ' +
                'Las ventas ya registradas conservan el nombre, la casa y el precio con el que se cobraron.</p>' +
            '<div id="inv-aviso" class="aviso" hidden></div>' +
            '<div class="detalle-acciones">' +
                '<button type="button" class="chip" id="btn-cancelar-editar">Cancelar</button>' +
                '<button type="button" class="btn-save" id="btn-guardar-editar" ' +
                    'data-codigo="' + esc(p.codigo_interno) + '">Guardar</button>' +
            '</div>';

        const selCasa = document.getElementById('ed-casa');
        const inpPrec = document.getElementById('ed-mayoreo');
        const lineaN  = document.getElementById('ed-neto');
        const avisoM  = document.getElementById('ed-aviso-mover');

        // Recalcula el neto en vivo y avisa cuando se va a mover de casa.
        function refrescar() {
            const casa  = selCasa.value;
            const bruto = parseFloat(inpPrec.value);
            const porc  = pct[casa] || 0;

            lineaN.innerHTML = isNaN(bruto)
                ? 'Precio de venta = bruto + ' + porc + '% de ' + esc(etiq[casa] || casa)
                : 'Precio de venta en <strong>' + esc(etiq[casa] || casa) + '</strong>: ' +
                  money(Math.round(bruto * (1 + porc / 100) * 100) / 100) + ' (bruto + ' + porc + '%)';

            if (casa !== datos.casa_actual) {
                avisoM.hidden = false;
                avisoM.innerHTML = 'Se va a <strong>mover a ' + esc(etiq[casa] || casa) + '</strong>: se crea ahí ' +
                    'con un código interno nuevo y se da de baja en ' + esc(etiq[datos.casa_actual] || datos.casa_actual) +
                    '. Las ventas anteriores no se modifican.';
            } else {
                avisoM.hidden = true;
            }
        }

        selCasa.addEventListener('change', refrescar);
        inpPrec.addEventListener('input', refrescar);
        refrescar();

        document.getElementById('ed-nombre').focus();
    }

    async function guardarProducto(codigo) {
        const avisoForm = document.getElementById('inv-aviso');
        const boton = document.getElementById('btn-guardar-editar');

        boton.disabled = true;
        boton.textContent = 'Guardando...';

        try {
            const datos = await (await fetch(RUTA_EDITAR, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    codigo:           codigo,
                    casa_destino:     document.getElementById('ed-casa').value,
                    nombre:           document.getElementById('ed-nombre').value,
                    codigo_proveedor: document.getElementById('ed-codigo').value,
                    marca:            document.getElementById('ed-marca').value,
                    categoria:        document.getElementById('ed-categoria').value,
                    precio_mayoreo:   document.getElementById('ed-mayoreo').value,
                }),
            })).json();

            if (!datos.ok) {
                avisoForm.textContent = datos.error || 'No se pudo guardar.';
                avisoForm.className = 'aviso aviso-error';
                avisoForm.hidden = false;
                return;
            }

            modal.hidden = true;
            mostrarAviso(datos.mensaje || 'Producto actualizado.', 'ok');

            // Recargar las casas refresca los conteos (importante si se movió) y
            // vuelve a pintar la lista de la casa que se está viendo.
            cargarCasas(casaActual);

        } catch (e) {
            avisoForm.textContent = 'Error de conexión.';
            avisoForm.className = 'aviso aviso-error';
            avisoForm.hidden = false;
        } finally {
            boton.disabled = false;
            boton.textContent = 'Guardar';
        }
    }

    // ---------- Editar solo el porcentaje de un producto (solo admin) ----------
    // Cambia el porcentaje con el que se calcula el neto de ESTE producto, sin
    // tocar a los demás de la casa. Vale para ventas futuras; las ya hechas
    // conservan su precio.
    async function abrirPorcentaje(codigo) {
        contenido.innerHTML = '<p class="venta-vacia">Cargando...</p>';
        modal.hidden = false;

        try {
            const datos = await (await fetch(
                RUTA_PCT + '?accion=consultar&codigo=' + encodeURIComponent(codigo)
            )).json();

            if (!datos.ok) {
                contenido.innerHTML = '<p class="aviso aviso-error">' +
                    esc(datos.error || 'No se pudo cargar el producto') + '</p>';
                return;
            }

            pintarPorcentaje(datos.producto);

        } catch (e) {
            contenido.innerHTML = '<p class="aviso aviso-error">Error de conexión.</p>';
        }
    }

    function pintarPorcentaje(p) {
        const bruto    = p.precio_mayoreo;
        const casaPct  = Number(p.porcentaje_casa);
        const efectivo = Number(p.porcentaje_efectivo);

        contenido.innerHTML =
            '<h2 class="detalle-titulo">Editar porcentaje</h2>' +
            '<div class="detalle-cabecera">' +
                '<p><strong>' + esc(p.nombre) + '</strong></p>' +
                '<p class="detalle-sub"><span class="codigo-prod">' + esc(codigoVisible(p)) + '</span>' +
                    (p.marca ? ' · ' + esc(p.marca) : '') + '</p>' +
            '</div>' +
            '<p class="aviso aviso-info">El precio de venta (neto) sale del bruto más un porcentaje. ' +
                'Aquí lo cambias <strong>solo para este producto</strong>; los demás de ' +
                esc(p.etiqueta_casa) + ' no se tocan. Las ventas ya registradas conservan su precio.</p>' +
            '<p class="detalle-sub">Precio bruto: <strong>' + money(bruto) + '</strong>' +
                (p.ultimo_cambio_precio
                    ? ' · cambiado el ' + fechaCorta(p.ultimo_cambio_precio) +
                      (p.precio_anterior !== null && p.precio_anterior !== undefined
                          ? ' · antes ' + money(p.precio_anterior)
                          : '')
                    : ' · nunca se le ha cambiado') + '</p>' +
            '<p class="pct-actual">Actualmente: <strong>' + pctTexto(efectivo) + '%</strong> → ' +
                '<strong>' + money(netoJS(bruto, efectivo)) + '</strong> ' +
                '<span class="detalle-sub">(' + (p.usa_casa
                    ? 'porcentaje de la casa'
                    : 'porcentaje propio; la casa usa ' + pctTexto(casaPct) + '%') + ')</span></p>' +
            '<div class="form-group">' +
                '<label for="pct-nuevo">Nuevo porcentaje (%)</label>' +
                '<input type="number" id="pct-nuevo" class="form-control" min="0" max="999.99" step="0.01" ' +
                       'value="' + Number(efectivo).toFixed(2) + '">' +
            '</div>' +
            '<p id="pct-nuevo-neto" class="detalle-sub"></p>' +
            '<label class="pct-casilla">' +
                '<input type="checkbox" id="pct-usar-casa"' + (p.usa_casa ? ' checked' : '') + '> ' +
                'Usar el porcentaje de la casa (' + pctTexto(casaPct) + '%)' +
            '</label>' +
            '<div id="pct-aviso" class="aviso" hidden></div>' +
            '<div class="detalle-acciones">' +
                '<button type="button" class="chip" id="btn-cancelar-porcentaje">Cancelar</button>' +
                '<button type="button" class="btn-save" id="btn-guardar-porcentaje" ' +
                    'data-codigo="' + esc(p.codigo_interno) + '">Guardar %</button>' +
            '</div>';

        const inp   = document.getElementById('pct-nuevo');
        const chk   = document.getElementById('pct-usar-casa');
        const linea = document.getElementById('pct-nuevo-neto');

        function refrescar() {
            const pct = chk.checked ? casaPct : parseFloat(inp.value);
            inp.disabled = chk.checked;
            if (chk.checked) inp.value = Number(casaPct).toFixed(2);

            linea.innerHTML = isNaN(pct)
                ? 'Escribe un porcentaje para ver el precio nuevo.'
                : 'Nuevo precio de venta: <strong>' + money(netoJS(bruto, pct)) + '</strong> ' +
                  '(bruto + ' + pctTexto(pct) + '%)';
        }

        chk.addEventListener('change', refrescar);
        inp.addEventListener('input', refrescar);
        refrescar();

        inp.focus();
    }

    async function guardarPorcentaje(codigo) {
        const avisoForm = document.getElementById('pct-aviso');
        const boton     = document.getElementById('btn-guardar-porcentaje');
        const usarCasa  = document.getElementById('pct-usar-casa').checked;

        boton.disabled = true;
        boton.textContent = 'Guardando...';

        try {
            const datos = await (await fetch(RUTA_PCT, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    codigo:     codigo,
                    porcentaje: usarCasa ? null : document.getElementById('pct-nuevo').value,
                }),
            })).json();

            if (!datos.ok) {
                avisoForm.textContent = datos.error || 'No se pudo guardar.';
                avisoForm.className = 'aviso aviso-error';
                avisoForm.hidden = false;
                return;
            }

            modal.hidden = true;

            const detalle = datos.usa_casa
                ? datos.nombre + ' vuelve a usar el ' + pctTexto(datos.porcentaje_casa) + '% de la casa.'
                : datos.nombre + ' ahora usa ' + pctTexto(datos.porcentaje_efectivo) + '%' +
                  (datos.neto !== null ? ' (neto ' + money(datos.neto) + ').' : '.');

            mostrarAviso(datos.cambios === 0 ? datos.mensaje : detalle, 'ok');
            cargarProductos();

        } catch (e) {
            avisoForm.textContent = 'Error de conexión.';
            avisoForm.className = 'aviso aviso-error';
            avisoForm.hidden = false;
        } finally {
            boton.disabled = false;
            boton.textContent = 'Guardar %';
        }
    }

    // ---------- Borrar producto (solo admin) ----------
    const RUTA_CASA = '../../app/controllers/CasaController.php';

    /**
     * Pide confirmar la baja antes de mandarla. Se reutiliza el modal del precio
     * (mismo contenedor y mismo cierre con Escape / clic fuera), solo cambia lo
     * que se pinta dentro.
     */
    function confirmarBorrado(codigo, nombre) {
        contenido.innerHTML =
            '<h2 class="detalle-titulo">Borrar producto</h2>' +
            '<p class="aviso aviso-error">¿Seguro que deseas borrar <strong>"' + esc(nombre) +
                '"</strong> de la casa ' + esc(etiquetaActual()) + '?</p>' +
            '<p class="detalle-sub">El producto deja de aparecer en el inventario y en el ' +
                'buscador de ventas. Las ventas ya registradas no se modifican, y tendr\u00e1s ' +
                '<strong>10 minutos para recuperarlo</strong>.</p>' +
            '<div id="inv-aviso" class="aviso" hidden></div>' +
            '<div class="detalle-acciones">' +
                '<button type="button" class="chip" id="btn-cancelar-borrado">Cancelar</button>' +
                '<button type="button" class="btn-borrar-confirmar" id="btn-confirmar-borrado" ' +
                    'data-codigo="' + esc(codigo) + '">Sí, borrar</button>' +
            '</div>';

        modal.hidden = false;
    }

    async function borrarProducto(codigo) {
        const avisoForm = document.getElementById('inv-aviso');
        const boton = document.getElementById('btn-confirmar-borrado');

        const fallar = (texto) => {
            avisoForm.textContent = texto;
            avisoForm.className = 'aviso aviso-error';
            avisoForm.hidden = false;
            boton.disabled = false;
            boton.textContent = 'Sí, borrar';
        };

        boton.disabled = true;
        boton.textContent = 'Borrando...';

        try {
            const datos = await (await fetch(RUTA_CASA + '?accion=eliminar', {
                method:  'POST',
                headers: { 'Content-Type': 'application/json' },
                body:    JSON.stringify({ codigo: codigo }),
            })).json();

            if (!datos.ok) {
                fallar(datos.error || 'No se pudo borrar el producto.');
                return;
            }

            modal.hidden = true;
            mostrarAviso(datos.mensaje || ('Se borró "' + datos.nombre + '".'), 'ok');

            // El conteo de la casa cambió: recargar las casas refresca las pestañas
            // y vuelve a pintar la lista sin el producto.
            cargarCasas(casaActual);

            // El panel de recuperación se vuelve a leer del servidor en vez de
            // agregar la fila a mano: así el reloj sale de la misma cuenta que
            // usa el servidor para decidir si todavía se puede deshacer.
            cargarRecuperables();

        } catch (e) {
            fallar('Error de conexión.');
        }
    }

    // ---------- Productos recién borrados (10 minutos para deshacer) ----------
    // Mismo trato que las ventas eliminadas en Historial: el servidor dice
    // cuántos segundos le quedan a cada uno, aquí se guarda el instante en que
    // vence y un temporizador redibuja el reloj cada segundo.
    //
    // Pasado el plazo el producto NO se borra de verdad (las ventas guardan su
    // código y el historial de precios depende de la fila): lo único que se
    // acaba es poder regresarlo desde aquí.
    const cajaRecuperables = document.getElementById('recuperables-productos');

    let recuperables          = [];
    let temporizadorRecuperar = null;

    async function cargarRecuperables() {
        try {
            const datos = await (await fetch(RUTA_CASA + '?accion=recuperables')).json();

            if (!datos.ok) return;

            const ahora = Date.now();
            recuperables = (datos.recuperables || []).map((r) => ({
                codigo:  r.codigo_interno,
                nombre:  r.nombre,
                casa:    r.casa,
                fin:     ahora + Number(r.segundos_restantes) * 1000,
            }));

            dibujarRecuperables();

        } catch (e) {
            // Sin conexión no se pinta nada: es un extra, no debe tumbar la pantalla.
        }
    }

    function dibujarRecuperables() {
        clearTimeout(temporizadorRecuperar);

        const ahora = Date.now();
        recuperables = recuperables.filter((r) => r.fin - ahora > 0);

        if (recuperables.length === 0) {
            cajaRecuperables.hidden = true;
            cajaRecuperables.innerHTML = '';
            return;
        }

        const filas = recuperables.map((r) => {
            const seg = Math.max(0, Math.round((r.fin - ahora) / 1000));
            const mm  = String(Math.floor(seg / 60)).padStart(2, '0');
            const ss  = String(seg % 60).padStart(2, '0');

            return '<div class="recuperable-fila">' +
                '<div class="recuperable-datos">' +
                    '<span class="recuperable-cliente">' + esc(r.nombre) + '</span>' +
                    '<span class="recuperable-meta">' + esc(r.casa) + ' · ' + esc(r.codigo) +
                        ' · ya no se puede recuperar en ' +
                        '<strong class="recuperable-reloj">' + mm + ':' + ss + '</strong></span>' +
                '</div>' +
                '<button type="button" class="btn-save btn-recuperar-prod" data-codigo="' +
                    esc(r.codigo) + '">' +
                    '<i class="ph ph-arrow-counter-clockwise"></i> Recuperar</button>' +
            '</div>';
        }).join('');

        cajaRecuperables.innerHTML =
            '<div class="recuperables-titulo">' +
                '<i class="ph ph-trash"></i> Borrados hace poco · recupéralos antes de que venza el plazo</div>' +
            filas;
        cajaRecuperables.hidden = false;

        temporizadorRecuperar = setTimeout(dibujarRecuperables, 1000);
    }

    async function recuperarProducto(codigo, boton) {
        boton.disabled = true;

        try {
            const datos = await (await fetch(RUTA_CASA + '?accion=recuperar', {
                method:  'POST',
                headers: { 'Content-Type': 'application/json' },
                body:    JSON.stringify({ codigo: codigo }),
            })).json();

            if (!datos.ok) {
                mostrarAviso(datos.error || 'No se pudo recuperar el producto.', 'error');
                // Puede haber vencido justo ahora: se relee para que la fila
                // desaparezca en lugar de quedarse con un botón que ya no sirve.
                cargarRecuperables();
                return;
            }

            mostrarAviso(datos.mensaje || ('Se recuperó "' + datos.nombre + '".'), 'ok');

            // Vuelve al inventario de su casa (el conteo cambió) y se repinta el panel.
            cargarCasas(casaActual);
            cargarRecuperables();

        } catch (e) {
            mostrarAviso('Error de conexión.', 'error');
            boton.disabled = false;
        }
    }

    // ---------- Casa nueva y alta de productos (solo admin) ----------

    const modalCasa     = document.getElementById('modal-casa');
    const contenidoCasa = document.getElementById('contenido-casa');
    const btnCerrarCasa = document.getElementById('btn-cerrar-casa');
    const btnNuevaCasa  = document.getElementById('btn-nueva-casa');
    const btnEditarCasa = document.getElementById('btn-editar-casa');
    const btnAltaProd   = document.getElementById('btn-agregar-productos');
    const casaNetoInfo  = document.getElementById('casa-neto-info');

    // Orden de las columnas al pegar; el 'false' marca las que son opcionales.
    const COLUMNAS = [
        ['Código del proveedor', false],
        ['Nombre',              true],
        ['Marca',               false],
        ['Categoría',           false],
        ['Precio bruto',        true],
    ];

    // Ejemplo de cómo deben verse las celdas en Excel. El tercer renglón deja ver
    // que las columnas opcionales pueden quedar vacías sin romper el orden.
    const EJEMPLO = [
        ['FP-200', 'PINZA DE PRESIÓN 10"',  'TRUPER', 'HERRAMIENTA', '245.00'],
        ['FP-201', 'CINTA MÉTRICA 5 M',     'TRUPER', 'MEDICIÓN',    '$89.50'],
        ['',       'MARTILLO DE UÑA 16 OZ', '',       '',            '150'],
    ];

    // Mismo tope que MAX_FILAS en CasaController: más que esto no cabe en un POST.
    const MAX_FILAS = 5000;

    let analisis            = null;   // resultado del último texto pegado
    let temporizadorPegado  = null;
    let agregadosEnModal    = 0;      // altas hechas sin cerrar el modal

    function cerrarModalCasa() {
        modalCasa.hidden = true;

        // Si se dieron de alta productos uno por uno, hay que refrescar conteos.
        if (agregadosEnModal > 0) {
            agregadosEnModal = 0;
            cargarCasas(casaActual);
        }
    }

    function avisoModal(texto, tipo) {
        const caja = document.getElementById('casa-aviso');
        if (!caja) return;

        caja.textContent = texto;
        caja.className = 'aviso aviso-' + tipo;
        caja.hidden = false;
    }

    /**
     * Lee un precio escrito a mano o pegado de Excel: "$1,234.50", "1234,50".
     * Es el mismo criterio que aPrecio() en CasaController, para que la vista
     * previa coincida con lo que va a aceptar el servidor.
     */
    function aPrecioJS(crudo) {
        let t = String(crudo === null || crudo === undefined ? '' : crudo).trim();

        if (t === '' || t === '-' || t === '—') return { valor: null };

        t = t.replace(/MXN/gi, '').replace(/[$\s ]/g, '');

        const coma  = t.indexOf(',') !== -1;
        const punto = t.indexOf('.') !== -1;

        if (coma && punto) t = t.replace(/,/g, '');   // "1,234.50": coma de miles
        else if (coma)     t = t.replace(',', '.');   // "1234,50": coma decimal

        if (t === '' || isNaN(Number(t))) return { error: 'no es un número' };

        const n = Number(t);

        if (n < 0)             return { error: 'no puede ser negativo' };
        if (n > 99999999.99)   return { error: 'es demasiado alto' };

        return { valor: Math.round(n * 100) / 100 };
    }

    /** Convierte el texto pegado en filas revisadas, con el número de línea. */
    function analizarPegado(texto) {
        const salida = { validas: [], errores: [], encabezado: false, sinTabulador: false };

        // Excel copia las celdas separadas por tabulador; sin tabuladores no hay
        // columnas que leer.
        if (texto.indexOf('\t') === -1) {
            salida.sinTabulador = true;
            return salida;
        }

        let primera = true;

        texto.split(/\r?\n/).forEach((linea, i) => {
            const numero = i + 1;

            if (linea.trim() === '') return;

            const celdas = linea.split('\t');

            // Si la primera fila trae los títulos de Excel, se ignora.
            if (primera) {
                primera = false;

                if (/nombre|descripci/i.test(celdas.join(' ')) &&
                    aPrecioJS(celdas[4] || '').valor === undefined) {
                    salida.encabezado = true;
                    return;
                }
            }

            const anotarError = (detalle) => salida.errores.push({
                linea:   numero,
                nombre:  (celdas[1] || celdas[0] || '').trim().slice(0, 60),
                detalle: detalle,
            });

            if (celdas.length < 5) {
                anotarError('trae ' + celdas.length + ' columna(s) y se esperan al menos 5');
                return;
            }

            const nombre = (celdas[1] || '').trim().replace(/\s+/g, ' ');

            if (nombre === '') {
                anotarError('falta el nombre del producto');
                return;
            }

            const bruto = aPrecioJS(celdas[4]);

            if (bruto.error) {
                anotarError('el precio bruto ' + bruto.error);
                return;
            }
            if (bruto.valor === null) {
                anotarError('falta el precio bruto');
                return;
            }

            salida.validas.push({
                linea:            numero,
                codigo_proveedor: (celdas[0] || '').trim(),
                nombre:           nombre,
                marca:            (celdas[2] || '').trim(),
                categoria:        (celdas[3] || '').trim(),
                precio_mayoreo:   bruto.valor,
            });
        });

        return salida;
    }

    function revisarPegado() {
        const area    = document.getElementById('alta-pegado');
        const resumen = document.getElementById('alta-resumen');
        const boton   = document.getElementById('btn-guardar-pegado');

        if (!area || !resumen || !boton) return;

        if (area.value.trim() === '') {
            analisis = null;
            resumen.innerHTML = '';
            boton.disabled = true;
            boton.textContent = 'Guardar productos';
            return;
        }

        analisis = analizarPegado(area.value);

        if (analisis.sinTabulador) {
            resumen.innerHTML = '<p class="aviso aviso-error">Ese texto no trae columnas. ' +
                'Selecciona las celdas en Excel, cópialas con Ctrl+C y pégalas aquí con Ctrl+V.</p>';
            boton.disabled = true;
            return;
        }

        const validas = analisis.validas;
        const errores = analisis.errores;

        let html = '';

        if (validas.length > MAX_FILAS) {
            html += '<p class="aviso aviso-error">Son ' + validas.length.toLocaleString('es-MX') +
                ' filas y el máximo por vez es ' + MAX_FILAS.toLocaleString('es-MX') +
                '. Pégalas en varias tandas.</p>';
            boton.disabled = true;
        }

        html += '<p class="resumen-periodo"><span>' +
            validas.length.toLocaleString('es-MX') + ' producto(s) listos' +
            (errores.length > 0 ? ' · ' + errores.length + ' fila(s) con problema' : '') +
            (analisis.encabezado ? ' · se ignoró el encabezado' : '') +
            '</span></p>';

        if (errores.length > 0) {
            html += '<div class="alta-errores"><p><strong>Estas filas no se van a guardar:</strong></p><ul>' +
                errores.slice(0, 12).map((e) =>
                    '<li>Línea ' + e.linea + (e.nombre ? ' (' + esc(e.nombre) + ')' : '') +
                    ': ' + esc(e.detalle) + '</li>'
                ).join('') +
                (errores.length > 12 ? '<li>y ' + (errores.length - 12) + ' más…</li>' : '') +
                '</ul></div>';
        }

        if (validas.length > 0) {
            html += '<div class="tabla-scroll"><table class="tabla-detalle"><thead><tr>' +
                '<th>Código</th><th>Producto</th><th>Marca</th>' +
                '<th class="num">Bruto</th>' +
                '</tr></thead><tbody>' +
                validas.slice(0, 8).map((f) =>
                    '<tr><td>' + esc(f.codigo_proveedor || '—') + '</td>' +
                    '<td>' + esc(f.nombre) + '</td>' +
                    '<td>' + esc(f.marca || '—') + '</td>' +
                    '<td class="num">' + money(f.precio_mayoreo) + '</td></tr>'
                ).join('') +
                '</tbody></table></div>' +
                (validas.length > 8
                    ? '<p class="detalle-sub">Vista previa de las primeras 8 de ' +
                      validas.length.toLocaleString('es-MX') + '.</p>'
                    : '');
        }

        resumen.innerHTML = html;

        if (validas.length > 0 && validas.length <= MAX_FILAS) {
            boton.disabled = false;
            boton.textContent = errores.length > 0
                ? 'Guardar las ' + validas.length.toLocaleString('es-MX') + ' filas válidas'
                : 'Guardar ' + validas.length.toLocaleString('es-MX') + ' producto(s)';
        } else if (validas.length === 0) {
            boton.disabled = true;
            boton.textContent = 'Guardar productos';
        }
    }

    /** Manda las filas al servidor, que vuelve a revisarlas antes de guardar. */
    async function enviarProductos(productos, forzar, boton, etiquetaBoton) {
        boton.disabled = true;
        boton.textContent = 'Guardando...';

        try {
            const datos = await (await fetch(RUTA_CASA + '?accion=agregar', {
                method:  'POST',
                headers: { 'Content-Type': 'application/json' },
                body:    JSON.stringify({ casa: casaActual, productos: productos, forzar: forzar }),
            })).json();

            if (!datos.ok) {
                avisoModal(datos.error || 'No se pudo guardar.', 'error');
                return null;
            }

            // El servidor encontró problemas que la vista previa no marcó.
            if (!datos.guardado) {
                avisoModal(
                    'Revisa ' + datos.errores.length + ' fila(s): ' +
                    datos.errores.slice(0, 3).map((e) => 'línea ' + e.linea + ', ' + e.detalle).join('; '),
                    'error'
                );
                return null;
            }

            return datos;

        } catch (e) {
            avisoModal('Error de conexión.', 'error');
            return null;

        } finally {
            boton.disabled = false;
            boton.textContent = etiquetaBoton;
        }
    }

    // ---------- Formulario de casa nueva ----------
    async function abrirNuevaCasa() {
        contenidoCasa.innerHTML = '<p class="venta-vacia">Cargando...</p>';
        modalCasa.hidden = false;

        let datos;

        try {
            datos = await (await fetch(RUTA_CASA + '?accion=nueva')).json();
        } catch (e) {
            contenidoCasa.innerHTML = '<p class="aviso aviso-error">Error de conexión.</p>';
            return;
        }

        if (!datos.ok) {
            contenidoCasa.innerHTML = '<p class="aviso aviso-error">' +
                esc(datos.error || 'No se pudo preparar la casa nueva') + '</p>';
            return;
        }

        contenidoCasa.innerHTML =
            '<h2 class="detalle-titulo">Nueva casa</h2>' +
            '<p class="aviso aviso-info">Se va a crear con el código interno <strong>' +
                esc(datos.codigo_casa) + '</strong>. Ese código es el que llevarán sus productos (' +
                esc(datos.codigo_casa) + '-00001) y no se puede cambiar después.</p>' +
            '<div class="form-group">' +
                '<label for="casa-nombre">Nombre del proveedor</label>' +
                '<input type="text" id="casa-nombre" class="form-control" maxlength="100" ' +
                       'placeholder="Ej. Ferretería del Centro">' +
            '</div>' +
            '<div class="alta-dos">' +
                '<div class="form-group">' +
                    '<label for="casa-etiqueta">Etiqueta corta</label>' +
                    '<input type="text" id="casa-etiqueta" class="form-control" maxlength="30" ' +
                           'value="' + esc(datos.etiqueta_sugerida) + '" placeholder="Ej. C5-FC">' +
                '</div>' +
                '<div class="form-group">' +
                    '<label for="casa-orden">Orden en pantalla</label>' +
                    '<input type="number" id="casa-orden" class="form-control" min="1" max="999" ' +
                           'value="' + Number(datos.orden_sugerido) + '">' +
                '</div>' +
            '</div>' +
            '<p class="detalle-sub">La etiqueta es lo que se ve en Ventas, Inventario, Estadísticas ' +
                'y el Excel del historial. El orden decide en qué lugar aparece la casa en las listas.</p>' +
            '<div class="form-group">' +
                '<label for="casa-porcentaje">Porcentaje para el neto (%)</label>' +
                '<input type="number" id="casa-porcentaje" class="form-control" min="0" max="999.99" step="0.01" ' +
                       'value="' + Number(datos.porcentaje_sugerido) + '">' +
                '<span class="detalle-sub">El precio de venta se calcula sumándole este porcentaje al bruto.</span>' +
            '</div>' +
            '<div id="casa-aviso" class="aviso" hidden></div>' +
            '<div class="detalle-acciones">' +
                '<button type="button" class="chip" id="btn-cancelar-casa">Cancelar</button>' +
                '<button type="button" class="btn-save" id="btn-guardar-casa">Crear casa</button>' +
            '</div>';

        document.getElementById('casa-nombre').focus();
    }

    async function guardarCasa() {
        const boton = document.getElementById('btn-guardar-casa');

        boton.disabled = true;
        boton.textContent = 'Creando...';

        try {
            const datos = await (await fetch(RUTA_CASA + '?accion=crear', {
                method:  'POST',
                headers: { 'Content-Type': 'application/json' },
                body:    JSON.stringify({
                    nombre:          document.getElementById('casa-nombre').value,
                    etiqueta:        document.getElementById('casa-etiqueta').value,
                    orden:           document.getElementById('casa-orden').value,
                    porcentaje_neto: document.getElementById('casa-porcentaje').value,
                }),
            })).json();

            if (!datos.ok) {
                avisoModal(datos.error || 'No se pudo crear la casa.', 'error');
                return;
            }

            mostrarAviso(
                'Casa ' + datos.etiqueta + ' (' + datos.nombre + ') creada con el código ' +
                datos.codigo_casa + '.' + (datos.avisos.length > 0 ? ' ' + datos.avisos.join(' ') : ''),
                datos.avisos.length > 0 ? 'info' : 'ok',
                datos.avisos.length > 0 ? 0 : 6
            );

            // Nace vacía, así que lo siguiente es cargarle el catálogo.
            await cargarCasas(datos.codigo_casa);
            abrirAltaProductos();

        } catch (e) {
            avisoModal('Error de conexión.', 'error');

        } finally {
            boton.disabled = false;
            boton.textContent = 'Crear casa';
        }
    }

    // ---------- Editar casa (nombre y porcentaje del neto) ----------
    function casaObj() {
        return casas.find((c) => c.codigo_casa === casaActual) || null;
    }

    /** Línea que recuerda con qué porcentaje se calcula el neto de la casa activa. */
    function pintarCasaInfo() {
        if (!casaNetoInfo) return;

        const casa = casaObj();
        if (!casa || casa.porcentaje_neto === undefined) {
            casaNetoInfo.hidden = true;
            return;
        }

        const pct = Number(casa.porcentaje_neto).toLocaleString('es-MX', { maximumFractionDigits: 2 });
        casaNetoInfo.hidden = false;
        casaNetoInfo.innerHTML =
            '<i class="ph ph-tag" aria-hidden="true"></i> Precio de venta = bruto + <strong>' + pct + '%</strong>';
    }

    function abrirEditarCasa() {
        const casa = casaObj();
        if (!casa) {
            mostrarAviso('Primero elige una casa.', 'error');
            return;
        }

        modalCasa.hidden = false;
        contenidoCasa.innerHTML =
            '<h2 class="detalle-titulo">Editar casa ' + esc(casa.etiqueta) + '</h2>' +
            '<div class="form-group">' +
                '<label for="casa-ed-nombre">Nombre del proveedor</label>' +
                '<input type="text" id="casa-ed-nombre" class="form-control" maxlength="100" value="' + esc(casa.nombre) + '">' +
            '</div>' +
            '<div class="form-group">' +
                '<label for="casa-ed-porcentaje">Porcentaje para el neto (%)</label>' +
                '<input type="number" id="casa-ed-porcentaje" class="form-control" min="0" max="999.99" step="0.01" ' +
                       'value="' + Number(casa.porcentaje_neto) + '">' +
                '<span class="detalle-sub">Al cambiarlo, todos los precios de venta de esta casa se recalculan solos.</span>' +
            '</div>' +
            '<div id="casa-aviso" class="aviso" hidden></div>' +
            '<div class="detalle-acciones">' +
                '<button type="button" class="chip" id="btn-cancelar-casa">Cancelar</button>' +
                '<button type="button" class="btn-save" id="btn-guardar-edicion-casa" ' +
                    'data-casa="' + casa.codigo_casa + '">Guardar</button>' +
            '</div>';

        document.getElementById('casa-ed-nombre').focus();
    }

    async function guardarEdicionCasa(codigo) {
        const boton = document.getElementById('btn-guardar-edicion-casa');

        boton.disabled = true;
        boton.textContent = 'Guardando...';

        try {
            const datos = await (await fetch(RUTA_CASA + '?accion=editar', {
                method:  'POST',
                headers: { 'Content-Type': 'application/json' },
                body:    JSON.stringify({
                    codigo_casa:     codigo,
                    nombre:          document.getElementById('casa-ed-nombre').value,
                    porcentaje_neto: document.getElementById('casa-ed-porcentaje').value,
                }),
            })).json();

            if (!datos.ok) {
                avisoModal(datos.error || 'No se pudo guardar la casa.', 'error');
                return;
            }

            modalCasa.hidden = true;
            mostrarAviso(
                'Casa actualizada.' + (datos.avisos && datos.avisos.length ? ' ' + datos.avisos.join(' ') : ''),
                datos.avisos && datos.avisos.length ? 'info' : 'ok',
                datos.avisos && datos.avisos.length ? 0 : 6
            );

            // Recargar refresca el % de la info y los precios que se ven.
            await cargarCasas(codigo);

        } catch (e) {
            avisoModal('Error de conexión.', 'error');
        } finally {
            boton.disabled = false;
            boton.textContent = 'Guardar';
        }
    }

    // ---------- Alta de productos ----------
    function abrirAltaProductos() {
        if (!casaActual) return;

        analisis = null;
        agregadosEnModal = 0;

        contenidoCasa.innerHTML =
            '<h2 class="detalle-titulo">Agregar productos a ' + esc(etiquetaActual()) + '</h2>' +
            '<div class="casas-tabs" id="alta-pestanas">' +
                '<button type="button" class="chip chip-activo" data-pestana="pegar">Pegar desde Excel</button>' +
                '<button type="button" class="chip" data-pestana="uno">Uno por uno</button>' +
            '</div>' +
            '<div id="alta-cuerpo"></div>' +
            '<div id="casa-aviso" class="aviso" hidden></div>';

        modalCasa.hidden = false;
        pintarPegar();
    }

    function pintarPegar() {
        document.getElementById('alta-cuerpo').innerHTML =
            '<p class="aviso aviso-info">Copia las filas en Excel y pégalas aquí. Las columnas tienen ' +
                'que ir en este orden; si incluyes el encabezado, se ignora solo.</p>' +
            '<p class="alta-ejemplo-titulo">Así se debe ver en tu Excel:</p>' +
            '<div class="tabla-scroll"><table class="tabla-detalle tabla-ejemplo"><thead><tr>' +
                COLUMNAS.map(([nombre, obligatoria]) =>
                    '<th>' + nombre +
                    (obligatoria ? '' : '<span class="detalle-sub">opcional</span>') + '</th>'
                ).join('') +
            '</tr></thead><tbody>' +
                EJEMPLO.map((fila) =>
                    '<tr>' + fila.map((celda) =>
                        '<td>' + (celda === '' ? '&nbsp;' : esc(celda)) + '</td>'
                    ).join('') + '</tr>'
                ).join('') +
            '</tbody></table></div>' +
            '<p class="detalle-sub alta-ejemplo-pie">Las celdas opcionales pueden ir vacías (como en el ' +
                'tercer renglón), pero no cambies el orden de las columnas. Los precios aceptan signo de ' +
                'pesos y comas: <strong>$1,450.00</strong>, <strong>89,50</strong> y <strong>150</strong> ' +
                'se leen igual de bien.</p>' +
            '<div class="form-group">' +
                '<label for="alta-pegado">Filas pegadas</label>' +
                '<textarea id="alta-pegado" class="form-control" rows="7" ' +
                          'placeholder="Pega aquí las celdas copiadas de Excel"></textarea>' +
            '</div>' +
            '<div id="alta-resumen"></div>' +
            '<div class="detalle-acciones">' +
                '<button type="button" class="chip" id="btn-cancelar-casa">Cancelar</button>' +
                '<button type="button" class="btn-save" id="btn-guardar-pegado" disabled>Guardar productos</button>' +
            '</div>';

        const area = document.getElementById('alta-pegado');

        area.addEventListener('input', () => {
            clearTimeout(temporizadorPegado);
            temporizadorPegado = setTimeout(revisarPegado, 250);
        });

        area.focus();
    }

    async function guardarPegado() {
        if (!analisis || analisis.validas.length === 0) return;

        const boton = document.getElementById('btn-guardar-pegado');
        const etiquetaPrevia = boton.textContent;

        const datos = await enviarProductos(
            analisis.validas, analisis.errores.length > 0, boton, etiquetaPrevia
        );

        if (!datos) return;

        modalCasa.hidden = true;
        agregadosEnModal = 0;

        mostrarAviso(
            'Se agregaron ' + datos.guardados.toLocaleString('es-MX') + ' producto(s) a ' +
            datos.etiqueta + '. Su catálogo quedó en ' + datos.total.toLocaleString('es-MX') + '.',
            'ok', 8
        );

        cargarCasas(casaActual);
    }

    function pintarUno() {
        document.getElementById('alta-cuerpo').innerHTML =
            '<div class="form-group">' +
                '<label for="p-nombre">Nombre del producto</label>' +
                '<input type="text" id="p-nombre" class="form-control" maxlength="150">' +
            '</div>' +
            '<div class="form-group">' +
                '<label for="p-codigo">Código del proveedor <span class="detalle-sub">(opcional)</span></label>' +
                '<input type="text" id="p-codigo" class="form-control" maxlength="50">' +
            '</div>' +
            '<div class="alta-dos">' +
                '<div class="form-group">' +
                    '<label for="p-marca">Marca <span class="detalle-sub">(opcional)</span></label>' +
                    '<input type="text" id="p-marca" class="form-control" maxlength="60">' +
                '</div>' +
                '<div class="form-group">' +
                    '<label for="p-categoria">Categoría <span class="detalle-sub">(opcional)</span></label>' +
                    '<input type="text" id="p-categoria" class="form-control" maxlength="60">' +
                '</div>' +
            '</div>' +
            '<div class="form-group">' +
                '<label for="p-mayoreo">Precio bruto</label>' +
                '<input type="number" id="p-mayoreo" class="form-control" min="0" step="0.01">' +
            '</div>' +
            '<p id="alta-contador" class="detalle-sub"></p>' +
            '<div class="detalle-acciones">' +
                '<button type="button" class="chip" id="btn-cancelar-casa">Cerrar</button>' +
                '<button type="button" class="btn-save" id="btn-guardar-uno">Guardar producto</button>' +
            '</div>';

        document.getElementById('p-nombre').focus();
    }

    async function guardarUno() {
        const boton = document.getElementById('btn-guardar-uno');

        const producto = {
            linea:            1,
            codigo_proveedor: document.getElementById('p-codigo').value,
            nombre:           document.getElementById('p-nombre').value,
            marca:            document.getElementById('p-marca').value,
            categoria:        document.getElementById('p-categoria').value,
            precio_mayoreo:   document.getElementById('p-mayoreo').value,
        };

        const datos = await enviarProductos([producto], false, boton, 'Guardar producto');

        if (!datos) return;

        agregadosEnModal++;

        // Se limpia lo que cambia de producto a producto; marca y categoría se
        // quedan porque normalmente se capturan varios seguidos de la misma.
        ['p-nombre', 'p-codigo', 'p-mayoreo'].forEach((id) => {
            document.getElementById(id).value = '';
        });

        document.getElementById('casa-aviso').hidden = true;
        document.getElementById('alta-contador').textContent =
            agregadosEnModal + ' producto(s) agregados en esta tanda. ' +
            etiquetaActual() + ' tiene ' + datos.total.toLocaleString('es-MX') + '.';

        document.getElementById('p-nombre').focus();
    }

    if (btnNuevaCasa)  btnNuevaCasa.addEventListener('click', abrirNuevaCasa);
    if (btnEditarCasa) btnEditarCasa.addEventListener('click', abrirEditarCasa);
    if (btnAltaProd)   btnAltaProd.addEventListener('click', abrirAltaProductos);

    if (btnCerrarCasa) {
        btnCerrarCasa.addEventListener('click', cerrarModalCasa);

        modalCasa.addEventListener('click', (e) => {
            if (e.target === modalCasa) cerrarModalCasa();
        });

        contenidoCasa.addEventListener('click', (e) => {
            const pestana = e.target.closest('[data-pestana]');

            if (pestana) {
                document.querySelectorAll('#alta-pestanas .chip')
                    .forEach((p) => p.classList.remove('chip-activo'));
                pestana.classList.add('chip-activo');

                document.getElementById('casa-aviso').hidden = true;

                if (pestana.dataset.pestana === 'pegar') pintarPegar();
                else pintarUno();
                return;
            }

            if (e.target.id === 'btn-cancelar-casa')  cerrarModalCasa();
            if (e.target.id === 'btn-guardar-casa')   guardarCasa();
            if (e.target.id === 'btn-guardar-pegado') guardarPegado();
            if (e.target.id === 'btn-guardar-uno')    guardarUno();

            const btnEd = e.target.closest('#btn-guardar-edicion-casa');
            if (btnEd) guardarEdicionCasa(btnEd.dataset.casa);
        });
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

        // La "x" vive dentro de la fila, así que hay que atenderla antes de que el
        // clic caiga en la fila y abra el editor de precio.
        const borrar = e.target.closest('.btn-borrar-prod');
        if (borrar) {
            e.stopPropagation();
            confirmarBorrado(borrar.dataset.codigo, borrar.dataset.nombre);
            return;
        }

        const imagen = e.target.closest('.btn-imagen');
        if (imagen) {
            e.stopPropagation();
            abrirImagen(imagen.dataset.codigo);
            return;
        }

        // "Editar %" abre solo el editor de porcentaje; sin esto, el clic caería
        // en la fila y abriría el editor completo del producto.
        const editarPct = e.target.closest('.btn-editar-porcentaje');
        if (editarPct) {
            e.stopPropagation();
            abrirPorcentaje(editarPct.dataset.codigo);
            return;
        }

        const fila = e.target.closest('.producto-fila');
        if (fila) abrirEditor(fila.dataset.codigo);
    });

    listaProductos.addEventListener('keydown', (e) => {
        if (!window.ES_ADMIN) return;
        if (e.key !== 'Enter' && e.key !== ' ') return;
        // El botón de borrar se activa solo con Enter/Espacio; que no dispare
        // además la apertura del editor.
        if (e.target.closest('.btn-borrar-prod, .btn-imagen')) return;
        const fila = e.target.closest('.producto-fila');
        if (fila) { e.preventDefault(); abrirEditor(fila.dataset.codigo); }
    });

    contenido.addEventListener('click', (e) => {
        if (e.target.id === 'btn-cancelar-editar') modal.hidden = true;
        const btnGuardar = e.target.closest('#btn-guardar-editar');
        if (btnGuardar) guardarProducto(btnGuardar.dataset.codigo);

        if (e.target.id === 'btn-cancelar-porcentaje') modal.hidden = true;
        const btnGuardarPct = e.target.closest('#btn-guardar-porcentaje');
        if (btnGuardarPct) guardarPorcentaje(btnGuardarPct.dataset.codigo);

        if (e.target.id === 'btn-cancelar-borrado') modal.hidden = true;
        const btnBorrar = e.target.closest('#btn-confirmar-borrado');
        if (btnBorrar) borrarProducto(btnBorrar.dataset.codigo);

        if (e.target.closest('#btn-guardar-imagen')) guardarImagen();
        if (e.target.closest('#btn-quitar-imagen')) quitarImagen();
    });

    cajaRecuperables.addEventListener('click', (e) => {
        const boton = e.target.closest('.btn-recuperar-prod');
        if (boton) recuperarProducto(boton.dataset.codigo, boton);
    });

    btnCerrar.addEventListener('click', () => { modal.hidden = true; });

    modal.addEventListener('click', (e) => {
        if (e.target === modal) modal.hidden = true;
    });

    document.addEventListener('keydown', (e) => {
        if (e.key !== 'Escape') return;

        modal.hidden = true;
        if (!modalCasa.hidden) cerrarModalCasa();
    });

    cargarCasas();

    // Si se borró algo y se recargó la página, el plazo sigue corriendo: el panel
    // tiene que aparecer solo, sin depender de que el borrado haya sido en esta
    // misma visita.
    cargarRecuperables();
});
