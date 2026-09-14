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
    let casas        = [];

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

    // ---------- Casa nueva y alta de productos (solo admin) ----------
    const RUTA_CASA = '../../app/controllers/CasaController.php';

    const modalCasa     = document.getElementById('modal-casa');
    const contenidoCasa = document.getElementById('contenido-casa');
    const btnCerrarCasa = document.getElementById('btn-cerrar-casa');
    const btnNuevaCasa  = document.getElementById('btn-nueva-casa');
    const btnAltaProd   = document.getElementById('btn-agregar-productos');

    // Orden de las columnas al pegar; el 'false' marca las que son opcionales.
    const COLUMNAS = [
        ['Código del proveedor', false],
        ['Nombre',              true],
        ['Marca',               false],
        ['Categoría',           false],
        ['Precio menudeo',      true],
        ['Precio mayoreo',      false],
    ];

    // Ejemplo de cómo deben verse las celdas en Excel. El tercer renglón deja ver
    // que las columnas opcionales pueden quedar vacías sin romper el orden.
    const EJEMPLO = [
        ['FP-200', 'PINZA DE PRESIÓN 10"',  'TRUPER', 'HERRAMIENTA', '245.00',   '1980.00'],
        ['FP-201', 'CINTA MÉTRICA 5 M',     'TRUPER', 'MEDICIÓN',    '$89.50',   ''],
        ['',       'MARTILLO DE UÑA 16 OZ', '',       '',            '150',      ''],
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

            const menudeo = aPrecioJS(celdas[4]);

            if (menudeo.error) {
                anotarError('el precio de menudeo ' + menudeo.error);
                return;
            }
            if (menudeo.valor === null) {
                anotarError('falta el precio de menudeo');
                return;
            }

            const mayoreo = aPrecioJS(celdas[5]);

            if (mayoreo.error) {
                anotarError('el precio de mayoreo ' + mayoreo.error);
                return;
            }

            salida.validas.push({
                linea:            numero,
                codigo_proveedor: (celdas[0] || '').trim(),
                nombre:           nombre,
                marca:            (celdas[2] || '').trim(),
                categoria:        (celdas[3] || '').trim(),
                precio_menudeo:   menudeo.valor,
                precio_mayoreo:   mayoreo.valor,
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
                '<th class="num">Menudeo</th><th class="num">Mayoreo</th>' +
                '</tr></thead><tbody>' +
                validas.slice(0, 8).map((f) =>
                    '<tr><td>' + esc(f.codigo_proveedor || '—') + '</td>' +
                    '<td>' + esc(f.nombre) + '</td>' +
                    '<td>' + esc(f.marca || '—') + '</td>' +
                    '<td class="num">' + money(f.precio_menudeo) + '</td>' +
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
                    nombre:   document.getElementById('casa-nombre').value,
                    etiqueta: document.getElementById('casa-etiqueta').value,
                    orden:    document.getElementById('casa-orden').value,
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
            '<div class="alta-dos">' +
                '<div class="form-group">' +
                    '<label for="p-menudeo">Precio menudeo</label>' +
                    '<input type="number" id="p-menudeo" class="form-control" min="0" step="0.01">' +
                '</div>' +
                '<div class="form-group">' +
                    '<label for="p-mayoreo">Precio mayoreo <span class="detalle-sub">(opcional)</span></label>' +
                    '<input type="number" id="p-mayoreo" class="form-control" min="0" step="0.01">' +
                '</div>' +
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
            precio_menudeo:   document.getElementById('p-menudeo').value,
            precio_mayoreo:   document.getElementById('p-mayoreo').value,
        };

        const datos = await enviarProductos([producto], false, boton, 'Guardar producto');

        if (!datos) return;

        agregadosEnModal++;

        // Se limpia lo que cambia de producto a producto; marca y categoría se
        // quedan porque normalmente se capturan varios seguidos de la misma.
        ['p-nombre', 'p-codigo', 'p-menudeo', 'p-mayoreo'].forEach((id) => {
            document.getElementById(id).value = '';
        });

        document.getElementById('casa-aviso').hidden = true;
        document.getElementById('alta-contador').textContent =
            agregadosEnModal + ' producto(s) agregados en esta tanda. ' +
            etiquetaActual() + ' tiene ' + datos.total.toLocaleString('es-MX') + '.';

        document.getElementById('p-nombre').focus();
    }

    if (btnNuevaCasa) btnNuevaCasa.addEventListener('click', abrirNuevaCasa);
    if (btnAltaProd)  btnAltaProd.addEventListener('click', abrirAltaProductos);

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
        if (e.key !== 'Escape') return;

        modal.hidden = true;
        if (!modalCasa.hidden) cerrarModalCasa();
    });

    cargarCasas();
});
