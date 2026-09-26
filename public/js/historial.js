document.addEventListener('DOMContentLoaded', () => {
    const lista        = document.getElementById('lista-ventas');
    if (!lista) return;

    const inputDesde   = document.getElementById('desde');
    const inputHasta   = document.getElementById('hasta');
    const btnFiltrar   = document.getElementById('btn-filtrar');
    const btnExportar  = document.getElementById('btn-exportar');
    const btnProductos = document.getElementById('btn-productos');
    const filtroUsuario = document.getElementById('filtro-usuario');
    // Solo los chips de rango: hay otros elementos con la clase .chip (como el
    // boton de descargar) que no deben comportarse como filtro de fechas.
    const chips        = document.querySelectorAll('.chip[data-rango]');
    const resumen      = document.getElementById('resumen-periodo');
    const aviso        = document.getElementById('aviso-historial');
    const modal        = document.getElementById('modal-detalle');
    const contenido    = document.getElementById('contenido-detalle');
    const btnCerrar    = document.getElementById('btn-cerrar-detalle');
    const barraSeleccion = document.getElementById('barra-seleccion');

    const money = (n) => '$' + Number(n).toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    const iso = (fecha) => {
        const f = new Date(fecha.getTime() - fecha.getTimezoneOffset() * 60000);
        return f.toISOString().slice(0, 10);
    };

    function mostrarAviso(texto, tipo) {
        aviso.textContent = texto;
        aviso.className = 'aviso aviso-' + tipo;
        aviso.hidden = false;
    }

    // Escapa lo que viene de la base (nombre de cliente, producto) antes de
    // meterlo al HTML.
    /**
     * El codigo que se muestra es el del proveedor: es el que viene impreso en
     * el catalogo y el que ocupa la gente. El interno (BNS03-02737) es de la
     * base de datos; solo sale cuando la pieza no trae codigo de proveedor.
     */
    const codigoVisible = (p) =>
        p.codigo_proveedor || p.codigo_interno_producto || p.codigo_interno;

    function esc(texto) {
        const div = document.createElement('div');
        div.textContent = texto === null || texto === undefined ? '' : texto;
        return div.innerHTML;
    }

    function fechaLegible(sql) {
        const [f, h] = sql.split(' ');
        const [a, m, d] = f.split('-');
        return `${d}/${m}/${a} ${h ? h.slice(0, 5) : ''}`.trim();
    }

    // ---------- Rangos rapidos ----------
    function rangoDe(tipo) {
        const hoy = new Date();

        if (tipo === 'hoy')  return [iso(hoy), iso(hoy)];

        if (tipo === 'ayer') {
            const ayer = new Date(hoy);
            ayer.setDate(hoy.getDate() - 1);
            return [iso(ayer), iso(ayer)];
        }

        if (tipo === 'semana') {
            const lunes = new Date(hoy);
            // getDay(): 0 = domingo, por eso el domingo retrocede 6 dias.
            const diff = hoy.getDay() === 0 ? 6 : hoy.getDay() - 1;
            lunes.setDate(hoy.getDate() - diff);
            return [iso(lunes), iso(hoy)];
        }

        if (tipo === 'mes') {
            return [iso(new Date(hoy.getFullYear(), hoy.getMonth(), 1)), iso(hoy)];
        }

        if (tipo === 'anio') {
            return [iso(new Date(hoy.getFullYear(), 0, 1)), iso(hoy)];
        }

        return [iso(hoy), iso(hoy)];
    }

    // ---------- Listado ----------
    async function cargar(desde, hasta) {
        lista.innerHTML = '<p class="venta-vacia">Cargando...</p>';
        aviso.hidden = true;

        try {
            const url = '../../app/controllers/HistorialController.php'
                + '?accion=listar&desde=' + desde + '&hasta=' + hasta
                + '&usuario=' + (filtroUsuario.value || 0);
            const respuesta = await fetch(url);

            if (respuesta.status === 401) {
                mostrarAviso('Tu sesión expiró. Vuelve a iniciar sesión.', 'error');
                lista.innerHTML = '';
                return;
            }

            const datos = await respuesta.json();

            if (!datos.ok) {
                mostrarAviso(datos.error || 'No se pudo cargar el historial.', 'error');
                lista.innerHTML = '';
                return;
            }

            pintarVentas(datos);

        } catch (e) {
            mostrarAviso('Error de conexión al cargar el historial.', 'error');
            lista.innerHTML = '';
        }
    }

    function llenarVendedores(vendedores, seleccionado) {
        const actual = String(seleccionado || 0);
        filtroUsuario.innerHTML = '<option value="0">Todos</option>' +
            vendedores.map((v) =>
                '<option value="' + v.id + '"' + (String(v.id) === actual ? ' selected' : '') + '>' +
                esc(v.nombre.trim()) + ' (' + esc(v.numero_empleado) + ')</option>'
            ).join('');
    }

    function pintarVentas(datos) {
        lista.innerHTML = '';
        // Cambiar de filtro descarta lo que estuviera seleccionado.
        barraSeleccion.hidden = true;
        llenarVendedores(datos.vendedores || [], datos.usuario);

        const rango = datos.desde === datos.hasta
            ? fechaLegible(datos.desde)
            : fechaLegible(datos.desde) + ' al ' + fechaLegible(datos.hasta);

        const devoluciones = Number(datos.devoluciones || 0) > 0
            ? '<span class="resumen-devolucion">Devoluciones: ' + money(datos.devoluciones) + '</span>'
            : '';

        resumen.innerHTML =
            '<span>' + datos.ventas.length + ' venta' + (datos.ventas.length === 1 ? '' : 's') +
            ' · ' + rango + '</span>' +
            devoluciones +
            '<strong>Total: ' + money(datos.total) + '</strong>';

        if (datos.ventas.length === 0) {
            lista.innerHTML = '<p class="venta-vacia">No hay ventas en este periodo.</p>';
            return;
        }

        datos.ventas.forEach((v) => {
            // <div> y no <button>: dentro de un boton, Firefox y Safari no
            // respetan el layout de los hijos y la fila se ve amontonada.
            const fila = document.createElement('div');
            fila.className = 'venta-fila';
            fila.dataset.id = v.id;
            fila.setAttribute('role', 'button');
            fila.tabIndex = 0;

            const etiquetaPago = v.estado_pago === 'devolucion'
                ? '<span class="etiqueta etiqueta-devolucion">Devolución ' + money(v.devolucion) + '</span>'
                : (v.tipo_pago === 'credito'
                    ? '<span class="etiqueta etiqueta-' + v.estado_pago + '">Crédito · ' + v.estado_pago + '</span>'
                    : '<span class="etiqueta etiqueta-pagado">Contado</span>');

            fila.innerHTML =
                '<label class="venta-elegir" title="Seleccionar para imprimir">' +
                    '<input type="checkbox" class="chk-venta" value="' + v.id + '"></label>' +
                '<div class="venta-fila-datos">' +
                    '<p class="venta-fila-cliente">' + (v.cliente ? esc(v.cliente) : 'Sin cliente') +
                        (v.puede_editar ? '<i class="ph ph-pencil-simple icono-editable" title="Puedes editar esta venta"></i>' : '') +
                    '</p>' +
                    '<p class="venta-fila-meta">#' + v.id + ' · ' + fechaLegible(v.fecha) +
                        ' · ' + esc(v.vendedor) + ' (' + esc(v.numero_empleado) + ')' +
                        ' · ' + v.piezas + ' pieza' + (Number(v.piezas) === 1 ? '' : 's') + '</p>' +
                '</div>' +
                '<div class="venta-fila-derecha">' + etiquetaPago +
                    '<span class="venta-fila-total">' + money(v.total) + '</span>' +
                '</div>';

            lista.appendChild(fila);
        });
    }

    // ---------- Resumen de productos del periodo ----------
    async function verProductos() {
        const desde = inputDesde.value;
        const hasta = inputHasta.value || desde;

        if (!desde) {
            mostrarAviso('Elige un rango de fechas primero.', 'error');
            return;
        }

        contenido.innerHTML = '<p class="venta-vacia">Cargando...</p>';
        modal.hidden = false;

        try {
            const url = '../../app/controllers/HistorialController.php'
                + '?accion=productos&desde=' + desde + '&hasta=' + hasta
                + '&usuario=' + (filtroUsuario.value || 0);

            const respuesta = await fetch(url);

            if (respuesta.status === 401) {
                contenido.innerHTML = '<p class="aviso aviso-error">Tu sesión expiró.</p>';
                return;
            }

            const datos = await respuesta.json();

            if (!datos.ok) {
                contenido.innerHTML = '<p class="aviso aviso-error">' +
                    esc(datos.error || 'No se pudo cargar el resumen') + '</p>';
                return;
            }

            pintarProductos(datos);

        } catch (e) {
            contenido.innerHTML = '<p class="aviso aviso-error">Error de conexión.</p>';
        }
    }

    function pintarProductos(datos) {
        const r = datos.resumen;

        const rango = datos.desde === datos.hasta
            ? fechaLegible(datos.desde)
            : fechaLegible(datos.desde) + ' al ' + fechaLegible(datos.hasta);

        const encabezado =
            '<h2 class="detalle-titulo">Productos vendidos</h2>' +
            '<p class="detalle-sub">' + rango +
                (filtroUsuario.value > 0
                    ? ' · ' + esc(filtroUsuario.options[filtroUsuario.selectedIndex].text)
                    : ' · todos los vendedores') + '</p>';

        if (r.distintos === 0) {
            contenido.innerHTML = encabezado +
                '<p class="venta-vacia">No se vendió ningún producto en este periodo.</p>';
            return;
        }

        // Tarjetas de totales, arriba: la respuesta rápida a "cuánto se movió".
        const totales =
            '<div class="prod-totales">' +
                '<div class="prod-dato"><span>' + r.piezas.toLocaleString('es-MX') + '</span>piezas vendidas</div>' +
                '<div class="prod-dato"><span>' + r.distintos.toLocaleString('es-MX') + '</span>productos distintos</div>' +
                '<div class="prod-dato prod-dato-importe"><span>' + money(r.importe) + '</span>importe total</div>' +
            '</div>';

        // Los productos vienen ordenados de más a menos vendido; al agruparlos
        // por casa cada bloque conserva ese orden.
        const porCasa = {};
        datos.productos.forEach((p) => {
            (porCasa[p.codigo_casa] = porCasa[p.codigo_casa] || []).push(p);
        });

        // Se recorre resumen.por_casa para respetar el orden de casas (la que
        // más vendió primero) y tener sus totales a la mano.
        const bloques = r.por_casa.map((c) => {
            const filas = (porCasa[c.codigo_casa] || []).map((p) =>
                '<tr>' +
                    '<td>' + esc(p.nombre_producto) +
                        '<span class="detalle-sub">' +
                        '<span class="codigo-prod">' + esc(codigoVisible(p)) + '</span>' +
                        ' · en ' + p.ventas + ' venta' + (Number(p.ventas) === 1 ? '' : 's') + '</span></td>' +
                    '<td class="num"><strong>' + Number(p.piezas).toLocaleString('es-MX') + '</strong></td>' +
                    '<td class="num">' + money(p.importe) + '</td>' +
                '</tr>'
            ).join('');

            return '<div class="prod-bloque">' +
                '<div class="prod-bloque-titulo">' +
                    '<span class="res-casa casa-' + esc(c.codigo_casa) + '">' + esc(c.casa) + '</span>' +
                    '<span class="prod-casa-cifras">' +
                        '<strong>' + Number(c.piezas).toLocaleString('es-MX') + '</strong> piezas · ' +
                        c.productos + ' producto' + (c.productos === 1 ? '' : 's') + ' · ' +
                        money(c.importe) +
                    '</span>' +
                '</div>' +
                '<table class="tabla-detalle">' +
                    '<thead><tr><th>Producto</th>' +
                    '<th class="num">Piezas</th><th class="num">Importe</th></tr></thead>' +
                    '<tbody>' + filas + '</tbody>' +
                '</table>' +
            '</div>';
        }).join('');

        // Misma información, en hoja carta vertical: el navegador la guarda como
        // PDF desde su propio diálogo de impresión.
        const acciones =
            '<div class="detalle-acciones">' +
                '<button type="button" class="chip" id="btn-productos-pdf">' +
                    '<i class="ph ph-file-pdf" aria-hidden="true"></i> PDF de todo' +
                '</button>' +
                '<button type="button" class="chip" id="btn-productos-pdf-casa">' +
                    '<i class="ph ph-files" aria-hidden="true"></i> PDF, una hoja por casa' +
                '</button>' +
            '</div>';

        contenido.innerHTML = encabezado + acciones + totales + bloques +
            '<p class="detalle-sub">Casas y productos ordenados de más vendido a menos.</p>';
    }

    /**
     * Abre la hoja imprimible del resumen con los filtros de la pantalla.
     *
     * @param porCasa true = cada casa arranca en hoja nueva, para repartir el
     *                reporte por proveedor; false = todo corrido.
     */
    function abrirResumenPdf(porCasa) {
        const desde = inputDesde.value;
        const hasta = inputHasta.value || desde;

        if (!desde) {
            mostrarAviso('Elige un rango de fechas antes de generar el PDF.', 'error');
            return;
        }

        window.open(
            'productos.php?desde=' + desde + '&hasta=' + hasta +
            '&usuario=' + (filtroUsuario.value || 0) +
            (porCasa ? '&porcasa=1' : ''),
            '_blank'
        );
    }

    // ---------- Detalle ----------
    async function verDetalle(id) {
        contenido.innerHTML = '<p class="venta-vacia">Cargando...</p>';
        modal.hidden = false;

        try {
            const respuesta = await fetch(
                '../../app/controllers/HistorialController.php?accion=detalle&id=' + id
            );
            const datos = await respuesta.json();

            if (!datos.ok) {
                contenido.innerHTML = '<p class="aviso aviso-error">' +
                    esc(datos.error || 'No se pudo cargar el detalle') + '</p>';
                return;
            }

            pintarDetalle(datos.venta);

        } catch (e) {
            contenido.innerHTML = '<p class="aviso aviso-error">Error de conexión.</p>';
        }
    }

    function pintarDetalle(v) {
        const filas = v.items.map((i) =>
            '<tr>' +
                '<td>' + esc(i.nombre_producto) +
                    '<span class="detalle-sub">' + esc(i.casa) +
                    ' · <span class="codigo-prod">' + esc(codigoVisible(i)) + '</span></span></td>' +
                '<td>' + i.tipo_precio + '</td>' +
                '<td class="num">' + money(i.precio_aplicado) + '</td>' +
                '<td class="num">' + i.cantidad + '</td>' +
                '<td class="num">' + money(i.subtotal) + '</td>' +
                // Cambiar precios es solo del administrador.
                (window.ES_ADMIN
                    ? '<td><button type="button" class="chip btn-precio" ' +
                        'data-codigo="' + i.codigo_interno_producto + '" ' +
                        'data-venta="' + v.id + '" ' +
                        'title="Cambiar el precio de este producto en el catálogo">Editar precio</button></td>'
                    : '<td></td>') +
            '</tr>'
        ).join('');

        // La devolucion aplica igual a contado y a credito: el cliente pago mas
        // de lo que quedo costando la venta.
        const devolucion = Number(v.saldo ? v.saldo.devolucion : 0);

        const bloqueDevolucion = devolucion > 0
            ? '<div class="detalle-devolucion">' +
                '<h3>Devolución pendiente</h3>' +
                '<p>Venta: <strong>' + money(v.total) + '</strong> · ' +
                   'Cobrado: <strong>' + money(v.saldo.total_abonado) + '</strong> · ' +
                   'Se le debe al cliente: <strong>' + money(devolucion) + '</strong></p>' +
              '</div>'
            : '';

        // Saldo a favor que se usó como descuento en esta venta (aplica a contado
        // y a crédito).
        const creditoAplicado = Number(v.credito_aplicado || 0);
        const bloqueSaldoAplicado = creditoAplicado > 0
            ? '<div class="detalle-saldo-aplicado">' +
                '<p>Saldo a favor aplicado: <strong>-' + money(creditoAplicado) + '</strong> · ' +
                   'A pagar: <strong>' + money(Math.max(Number(v.total) - creditoAplicado, 0)) + '</strong></p>' +
              '</div>'
            : '';

        let credito = '';

        if (v.tipo_pago === 'credito') {
            const abonos = v.abonos.length === 0
                ? '<p class="detalle-sub">Sin abonos registrados.</p>'
                : '<ul class="lista-abonos">' + v.abonos.map((a) =>
                    '<li>' + fechaLegible(a.fecha_pago) + ' — <strong>' + money(a.monto) + '</strong>' +
                    ' · recibió ' + esc(a.recibio) + (a.nota ? ' · ' + esc(a.nota) : '') + '</li>'
                  ).join('') + '</ul>';

            // Solo el vendedor dueño o un admin pueden ir agregando abonos.
            const formAbono = v.puede_editar
                ? '<div class="abono-form">' +
                    '<label class="detalle-sub" for="abono-monto">Registrar abono</label>' +
                    '<div class="abono-campos">' +
                        '<input type="number" id="abono-monto" class="form-control" min="0.01" step="0.01" placeholder="Monto">' +
                        '<input type="text" id="abono-nota" class="form-control" maxlength="255" placeholder="Nota (opcional)">' +
                        '<button type="button" class="btn-save" id="btn-abonar" data-id="' + v.id + '">Abonar</button>' +
                    '</div>' +
                    '<div id="abono-aviso" class="aviso" hidden></div>' +
                  '</div>'
                : '';

            credito =
                '<div class="detalle-credito">' +
                    '<h3>Crédito</h3>' +
                    '<p>Abonado: <strong>' + money(v.saldo ? v.saldo.total_abonado : 0) + '</strong> · ' +
                       'Saldo pendiente: <strong>' + money(v.saldo ? v.saldo.saldo_pendiente : v.total) + '</strong>' +
                       (v.fecha_vencimiento ? ' · Vence: ' + fechaLegible(v.fecha_vencimiento) : '') + '</p>' +
                    abonos +
                    formAbono +
                '</div>';
        }

        const botonEditar = v.puede_editar
            ? '<button type="button" class="btn-save" id="btn-editar-venta" data-id="' + v.id + '">Editar venta</button>'
            : '<p class="detalle-sub">Solo el vendedor que la hizo o un administrador pueden editarla.</p>';

        const auditoria = (v.auditoria && v.auditoria.length > 0)
            ? '<div class="detalle-auditoria">' +
                '<h3>Cambios realizados</h3>' +
                '<ul class="lista-auditoria">' + v.auditoria.map((a) =>
                    '<li><span class="aud-campo">' + esc(a.campo) + '</span>: ' +
                    '<span class="aud-antes">' + (a.valor_anterior === null ? '—' : esc(a.valor_anterior)) + '</span>' +
                    ' → <span class="aud-despues">' + (a.valor_nuevo === null ? '—' : esc(a.valor_nuevo)) + '</span>' +
                    '<span class="detalle-sub">' + fechaLegible(a.fecha_cambio) + ' · ' +
                        esc(a.modifico) + ' (' + esc(a.numero_empleado) + ')</span></li>'
                ).join('') + '</ul></div>'
            : '';

        contenido.innerHTML =
            '<h2 class="detalle-titulo">Venta #' + v.id + '</h2>' +
            '<div class="detalle-cabecera">' +
                '<p><strong>Cliente:</strong> ' + (v.cliente ? esc(v.cliente) : 'Sin cliente') + '</p>' +
                '<p><strong>Fecha:</strong> ' + fechaLegible(v.fecha) + '</p>' +
                '<p><strong>Vendedor:</strong> ' + esc(v.vendedor) + ' (' + esc(v.numero_empleado) + ')</p>' +
                '<p><strong>Pago:</strong> ' + v.tipo_pago + ' · ' + v.estado_pago + '</p>' +
            '</div>' +
            '<table class="tabla-detalle">' +
                '<thead><tr><th>Producto</th><th>Precio</th><th class="num">C/U</th>' +
                '<th class="num">Cant.</th><th class="num">Subtotal</th><th></th></tr></thead>' +
                '<tbody>' + filas + '</tbody>' +
                '<tfoot><tr><td colspan="4" class="num"><strong>Total</strong></td>' +
                '<td class="num"><strong>' + money(v.total) + '</strong></td><td></td></tr></tfoot>' +
            '</table>' +
            bloqueSaldoAplicado +
            bloqueDevolucion +
            credito +
            auditoria +
            '<div class="detalle-acciones">' +
                '<button type="button" class="chip btn-imprimir" data-id="' + v.id + '">' +
                    '<i class="ph ph-printer"></i> Imprimir nota</button>' +
                '<button type="button" class="chip btn-enviar-nota" data-id="' + v.id + '">' +
                    '<i class="ph ph-paper-plane-tilt"></i> Enviar nota</button>' +
                botonEditar +
            '</div>';
    }

    // ---------- Nota imprimible ----------
    // La captura vive en public/js/nota_datos.js porque es la misma que usa la
    // pantalla de Venta: avisa si al cliente le faltan datos y deja guardarlos.
    let ventaDeLaNota = null;

    function abrirImpresion(v) {
        ventaDeLaNota = v.id;

        NotaDatos.abrir({
            ventaId:    v.id,
            cliente:    v.cliente || '',
            clienteId:  Number(v.cliente_id) || 0,
            contenedor: contenido,
            rutaApp:    '../../app/controllers/',
            rutaNota:   '../ventas/nota.php',
            // Igual que antes: al salir de la captura se vuelve al detalle de la
            // venta. La nota se abre en otra pestaña, así que el modal no estorba.
            alCerrar:   () => verDetalle(ventaDeLaNota),
        });
    }

    // ---------- Precio del catalogo ----------
    // Cambia el precio del producto para las ventas futuras. Las ventas ya
    // hechas conservan el precio con el que se cobraron (por eso el detalle
    // guarda una copia), asi que esta venta no cambia de total.
    let ventaDelPrecio = null;

    async function abrirPrecio(codigo, ventaId) {
        ventaDelPrecio = ventaId;
        contenido.innerHTML = '<p class="venta-vacia">Cargando precio actual...</p>';

        try {
            const respuesta = await fetch(
                '../../app/controllers/PrecioController.php?accion=consultar&codigo=' + encodeURIComponent(codigo)
            );
            const datos = await respuesta.json();

            if (!datos.ok) {
                contenido.innerHTML = '<p class="aviso aviso-error">' +
                    esc(datos.error || 'No se pudo cargar el producto') + '</p>';
                return;
            }

            pintarFormularioPrecio(datos.producto);

        } catch (e) {
            contenido.innerHTML = '<p class="aviso aviso-error">Error de conexión.</p>';
        }
    }

    function pintarFormularioPrecio(p) {
        const valor = (n) => (n === null ? '' : Number(n).toFixed(2));
        const actual = (n) => (n === null ? 'sin precio' : money(n));

        contenido.innerHTML =
            '<h2 class="detalle-titulo">Editar precio</h2>' +
            '<div class="detalle-cabecera">' +
                '<p><strong>' + esc(p.nombre) + '</strong></p>' +
                '<p class="detalle-sub"><span class="codigo-prod">' + esc(codigoVisible(p)) + '</span>' +
                    (p.marca ? ' · ' + esc(p.marca) : '') + '</p>' +
            '</div>' +
            '<p class="aviso aviso-info">Esto cambia el precio del producto en el catálogo, ' +
                'para las <strong>ventas futuras</strong>. Las ventas ya registradas conservan ' +
                'el precio con el que se cobraron.</p>' +
            '<div class="form-group">' +
                '<label for="precio-mayoreo">Precio bruto <span class="detalle-sub">' +
                    '(actual: ' + actual(p.precio_mayoreo) + ')</span></label>' +
                '<input type="number" id="precio-mayoreo" class="form-control" ' +
                       'min="0" step="0.01" value="' + valor(p.precio_mayoreo) + '">' +
            '</div>' +
            '<p class="detalle-sub">El precio de venta (neto) se calcula solo, ' +
                'sumándole el porcentaje de la casa al bruto.</p>' +
            '<div id="precio-aviso" class="aviso" hidden></div>' +
            '<div class="detalle-acciones">' +
                '<button type="button" class="chip" id="btn-cancelar-precio">Cancelar</button>' +
                '<button type="button" class="btn-save" id="btn-guardar-precio" ' +
                    'data-codigo="' + p.codigo_interno + '">Guardar precio</button>' +
            '</div>';
    }

    async function guardarPrecio(codigo) {
        const avisoPrecio = document.getElementById('precio-aviso');
        const boton = document.getElementById('btn-guardar-precio');

        boton.disabled = true;
        boton.textContent = 'Guardando...';

        try {
            const respuesta = await fetch('../../app/controllers/PrecioController.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    codigo:         codigo,
                    precio_mayoreo: document.getElementById('precio-mayoreo').value,
                }),
            });
            const datos = await respuesta.json();

            if (!datos.ok) {
                avisoPrecio.textContent = datos.error || 'No se pudo guardar.';
                avisoPrecio.className = 'aviso aviso-error';
                avisoPrecio.hidden = false;
                return;
            }

            if (datos.cambios === 0) {
                mostrarAviso(datos.mensaje, 'ok');
            } else {
                const d = datos.mayoreo;
                const antes = d.antes === null ? 'sin precio' : money(d.antes);
                mostrarAviso(
                    'Precio de ' + datos.nombre + ' actualizado. Bruto: ' +
                    antes + ' → ' + money(d.despues) + '.',
                    'ok'
                );
            }

            verDetalle(ventaDelPrecio);

        } catch (e) {
            avisoPrecio.textContent = 'Error de conexión.';
            avisoPrecio.className = 'aviso aviso-error';
            avisoPrecio.hidden = false;
        } finally {
            boton.disabled = false;
            boton.textContent = 'Guardar precio';
        }
    }

    // ---------- Abonos (crédito) ----------
    async function guardarAbono(id) {
        const avisoAbono = document.getElementById('abono-aviso');
        const boton      = document.getElementById('btn-abonar');
        const montoEl    = document.getElementById('abono-monto');
        const notaEl     = document.getElementById('abono-nota');
        const monto      = parseFloat(montoEl.value);

        const fallo = (texto) => {
            avisoAbono.textContent = texto;
            avisoAbono.className = 'aviso aviso-error';
            avisoAbono.hidden = false;
        };

        if (isNaN(monto) || monto <= 0) {
            fallo('Escribe un monto mayor a cero.');
            return;
        }

        boton.disabled = true;
        boton.textContent = 'Guardando...';

        try {
            const respuesta = await fetch('../../app/controllers/AbonoController.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ venta_id: Number(id), monto: monto, nota: notaEl.value.trim() }),
            });
            const datos = await respuesta.json();

            if (!datos.ok) {
                fallo(datos.error || 'No se pudo registrar el abono.');
                return;
            }

            mostrarAviso(datos.mensaje || 'Abono registrado.',
                         Number(datos.devolucion) > 0 ? 'devolucion' : 'ok');
            verDetalle(Number(id));   // Refresca el detalle con el nuevo abono y estado.

        } catch (e) {
            fallo('Error de conexión.');
        } finally {
            boton.disabled = false;
            boton.textContent = 'Abonar';
        }
    }

    // ---------- Enviar nota (WhatsApp / correo) ----------
    let notaEnviar = null;

    async function abrirEnviarNota(id) {
        contenido.innerHTML = '<p class="venta-vacia">Preparando...</p>';
        modal.hidden = false;

        try {
            const datos = await (await fetch(
                '../../app/controllers/EnviarNotaController.php?accion=preparar&id=' + encodeURIComponent(id)
            )).json();

            if (!datos.ok) {
                contenido.innerHTML = '<p class="aviso aviso-error">' +
                    esc(datos.error || 'No se pudo preparar la nota') + '</p>';
                return;
            }
            notaEnviar = datos;
            pintarEnviarNota(datos);
        } catch (e) {
            contenido.innerHTML = '<p class="aviso aviso-error">Error de conexión.</p>';
        }
    }

    function pintarEnviarNota(d) {
        const avisoLiga = d.ligado ? '' :
            '<p class="detalle-sub">Esta venta no está ligada a un cliente del catálogo; escribe el teléfono o el correo a mano.</p>';
        const avisoSmtp = d.smtp_ok ? '' :
            '<p class="detalle-sub">El envío por correo aún no está configurado (falta el SMTP). Mientras tanto puedes usar WhatsApp.</p>';

        contenido.innerHTML =
            '<h2 class="detalle-titulo">Enviar nota #' + d.venta_id + '</h2>' +
            avisoLiga +
            '<p class="detalle-sub">Vista previa: ' +
                '<a href="' + esc(d.pdf_url) + '" target="_blank" rel="noopener">abrir el PDF</a></p>' +
            '<div class="form-group">' +
                '<label for="env-tel">WhatsApp (teléfono del cliente):</label>' +
                '<input type="text" id="env-tel" class="form-control" maxlength="20" ' +
                       'value="' + esc(d.telefono || '') + '" placeholder="10 dígitos">' +
            '</div>' +
            '<button type="button" class="btn-save btn-wa" id="btn-wa-enviar">' +
                '<i class="ph ph-whatsapp-logo"></i> Enviar por WhatsApp</button>' +
            '<hr class="env-sep">' +
            '<div class="form-group">' +
                '<label for="env-email">Correo del cliente:</label>' +
                '<input type="email" id="env-email" class="form-control" maxlength="150" ' +
                       'value="' + esc(d.email || '') + '" placeholder="cliente@correo.com">' +
            '</div>' +
            '<button type="button" class="btn-save" id="btn-correo-enviar">Enviar por correo</button>' +
            avisoSmtp +
            '<div id="env-aviso" class="aviso" hidden></div>' +
            '<div class="detalle-acciones">' +
                '<button type="button" class="chip" id="btn-volver-detalle" data-id="' + d.venta_id + '">Volver</button>' +
            '</div>';
    }

    function enviarWhatsApp() {
        const avisoEnv = document.getElementById('env-aviso');
        let digitos = String(document.getElementById('env-tel').value).replace(/\D/g, '');

        if (digitos === '') {
            avisoEnv.textContent = 'Escribe el teléfono del cliente.';
            avisoEnv.className = 'aviso aviso-error';
            avisoEnv.hidden = false;
            return;
        }

        // Números de 10 dígitos se asumen de México (lada 52). wa.me pide el
        // número internacional sin el signo +.
        if (digitos.length === 10) digitos = '52' + digitos;

        const mensaje = 'Hola, aquí está tu nota de compra #' + notaEnviar.venta_id +
                        ' de Comercializadora GA-BE: ' + notaEnviar.pdf_url;

        window.open('https://wa.me/' + digitos + '?text=' + encodeURIComponent(mensaje), '_blank');
    }

    async function enviarNotaCorreo() {
        const avisoEnv = document.getElementById('env-aviso');
        const boton    = document.getElementById('btn-correo-enviar');
        const email    = document.getElementById('env-email').value.trim();

        boton.disabled = true;
        boton.textContent = 'Enviando...';

        try {
            const datos = await (await fetch(
                '../../app/controllers/EnviarNotaController.php?accion=correo',
                {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ venta_id: notaEnviar.venta_id, email: email }),
                }
            )).json();

            avisoEnv.textContent = datos.ok ? datos.mensaje : (datos.error || 'No se pudo enviar.');
            avisoEnv.className = 'aviso ' + (datos.ok ? 'aviso-ok' : 'aviso-error');
            avisoEnv.hidden = false;
        } catch (e) {
            avisoEnv.textContent = 'Error de conexión.';
            avisoEnv.className = 'aviso aviso-error';
            avisoEnv.hidden = false;
        } finally {
            boton.disabled = false;
            boton.textContent = 'Enviar por correo';
        }
    }

    // ---------- Edicion ----------
    let ventaEditando = null;

    // Buscador para agregar productos a una venta que se está editando. Usa el
    // mismo controlador que la pantalla de Venta, buscando en todas las casas
    // (cada resultado ya trae la suya).
    let resultadosEdicion  = [];
    let temporizadorEdicion = null;

    // ---------- Cliente de la venta que se edita ----------
    // Mismo comportamiento que en la pantalla de Venta: el nombre se puede
    // teclear libre, y ademas se puede ligar a un cliente del catalogo. Teclear
    // encima rompe la liga, porque ya no se sabe a quien se refiere.
    let clientesEdicion     = [];
    let temporizadorCliente = null;

    function textoLigaCliente() {
        return ventaEditando && ventaEditando.cliente_id
            ? 'Ligada a un cliente registrado. Escribe encima para desligarla.'
            : 'Sin cliente del catálogo: la venta solo guarda el nombre escrito.';
    }

    function refrescarLigaCliente() {
        const p = document.getElementById('edit-cliente-liga');
        if (p) p.textContent = textoLigaCliente();
    }

    async function buscarClientesEdicion(termino) {
        const cont = document.getElementById('edit-resultados-cliente');
        if (!cont) return;

        try {
            const respuesta = await fetch(
                '../../app/controllers/ClienteController.php?accion=buscar&q=' + encodeURIComponent(termino)
            );

            if (respuesta.status === 401) {
                mostrarAviso('Tu sesión expiró. Vuelve a iniciar sesión.', 'error');
                return;
            }

            const datos = await respuesta.json();
            clientesEdicion = datos.ok && Array.isArray(datos.clientes) ? datos.clientes : [];
            pintarClientesEdicion();

        } catch (e) {
            mostrarAviso('No se pudieron cargar los clientes. Revisa tu conexión.', 'error');
        }
    }

    function pintarClientesEdicion() {
        const cont = document.getElementById('edit-resultados-cliente');
        if (!cont) return;

        if (clientesEdicion.length === 0) {
            cont.innerHTML = '<p class="sin-resultados">Sin clientes que coincidan</p>';
            cont.hidden = false;
            return;
        }

        cont.innerHTML = clientesEdicion.map((c, idx) => {
            const persona = [c.nombres, c.apellido_paterno, c.apellido_materno].filter(Boolean).join(' ');
            // El comercio es lo que identifica la venta; si no hay, va la persona.
            const titulo = c.nombre_comercio || persona;
            const meta = [c.nombre_comercio ? persona : '', c.telefono,
                          c.dia_visita ? 'Visita: ' + c.dia_visita : ''].filter(Boolean).join(' · ');

            return '<div class="search-result-item edit-cliente-item" role="button" tabindex="0" ' +
                        'data-idx="' + idx + '">' +
                '<span class="res-nombre">' + esc(titulo) + '</span>' +
                (meta ? '<span class="res-meta">' + esc(meta) + '</span>' : '') +
            '</div>';
        }).join('');

        cont.hidden = false;
        cont.scrollTop = 0;
    }

    function elegirClienteEdicion(indice) {
        const c = clientesEdicion[indice];
        if (!c || !ventaEditando) return;

        const persona = [c.nombres, c.apellido_paterno, c.apellido_materno].filter(Boolean).join(' ');
        const titulo  = c.nombre_comercio || persona;

        ventaEditando.cliente    = titulo;
        ventaEditando.cliente_id = Number(c.id);

        const campo = document.getElementById('edit-cliente');
        if (campo) campo.value = titulo;

        document.getElementById('edit-resultados-cliente').hidden = true;
        refrescarLigaCliente();
    }

    async function buscarProductoEdicion(termino) {
        const cont = document.getElementById('edit-resultados');
        if (!cont) return;

        if (termino.length < 2) {
            cont.hidden = true;
            return;
        }

        try {
            const respuesta = await fetch(
                '../../app/controllers/ProductoController.php?casa=TODAS&q=' + encodeURIComponent(termino)
            );
            if (respuesta.status === 401) {
                mostrarAviso('Tu sesión expiró. Vuelve a iniciar sesión.', 'error');
                return;
            }
            const productos = await respuesta.json();
            resultadosEdicion = Array.isArray(productos) ? productos : [];
            pintarResultadosEdicion();
        } catch (e) {
            mostrarAviso('No se pudo buscar. Revisa tu conexión.', 'error');
        }
    }

    function pintarResultadosEdicion() {
        const cont = document.getElementById('edit-resultados');
        if (!cont) return;

        if (resultadosEdicion.length === 0) {
            cont.innerHTML = '<p class="sin-resultados">Sin coincidencias</p>';
            cont.hidden = false;
            return;
        }

        cont.innerHTML = resultadosEdicion.map((p, idx) => {
            const claseCasa = 'casa-' + String(p.codigo_casa || '').replace(/[^A-Za-z0-9_-]/g, '');
            const precio = (p.precio_neto !== null && p.precio_neto !== undefined)
                ? money(Number(p.precio_neto)) : 'Sin precio';

            return '<div class="search-result-item edit-result-item" role="button" tabindex="0" data-idx="' + idx + '">' +
                '<span class="res-nombre">' + esc(p.nombre) +
                    '<span class="res-casa ' + claseCasa + '">' + esc(p.nombre_casa) + '</span></span>' +
                '<span class="res-meta"><span class="codigo-prod">' + esc(p.codigo_proveedor || p.codigo_interno) + '</span>' +
                    (p.marca ? ' · ' + esc(p.marca) : '') + '</span>' +
                '<span class="res-precio">' + precio + '</span></div>';
        }).join('');

        cont.hidden = false;
        cont.scrollTop = 0;
    }

    function agregarItemEdicion(p) {
        if (!p) return;

        if (p.precio_neto === null || p.precio_neto === undefined) {
            const a = document.getElementById('edit-aviso');
            a.textContent = 'Ese producto no tiene precio cargado, no se puede agregar.';
            a.className = 'aviso aviso-error';
            a.hidden = false;
            return;
        }

        const ya = ventaEditando.items.find((i) => i.codigo_interno === p.codigo_interno);
        if (ya) {
            ya.cantidad += 1;
        } else {
            ventaEditando.items.push({
                casa_nombre:      p.nombre_casa,
                casa_codigo:      p.codigo_casa,
                codigo_interno:   p.codigo_interno,
                codigo_proveedor: p.codigo_proveedor,
                nombre:           p.nombre,
                tipo_precio:      'neto',
                precio:           Number(p.precio_neto),
                cantidad:         1,
            });
        }

        resultadosEdicion = [];
        pintarEdicion();   // Vuelve a dibujar el modal (y limpia el buscador).
    }

    function abrirEdicion(v) {
        ventaEditando = {
            id:        v.id,
            cliente:   v.cliente || '',
            cliente_id: Number(v.cliente_id) || 0,
            tipo_pago: v.tipo_pago,
            fecha_vencimiento: v.fecha_vencimiento || '',
            items: v.items.map((i) => ({
                casa_nombre:    i.casa,
                casa_codigo:    i.codigo_casa,
                codigo_interno: i.codigo_interno_producto,
                // El interno es la llave con la que se guarda; el del proveedor
                // es el que se ve en pantalla.
                codigo_proveedor: i.codigo_proveedor,
                nombre:         i.nombre_producto,
                tipo_precio:    i.tipo_precio,
                precio:         Number(i.precio_aplicado),
                cantidad:       Number(i.cantidad),
            })),
            cobrado: v.saldo ? Number(v.saldo.total_abonado) : 0,
        };

        pintarEdicion();
    }

    function pintarEdicion() {
        const v = ventaEditando;

        const filas = v.items.map((i, indice) =>
            '<tr>' +
                '<td>' + esc(i.nombre) +
                    '<span class="detalle-sub">' + esc(i.casa_nombre) +
                    ' · <span class="codigo-prod">' + esc(codigoVisible(i)) + '</span></span></td>' +
                '<td class="num">' + money(i.precio) + '</td>' +
                '<td class="num">' +
                    '<input type="number" class="input-qty edit-cantidad" data-i="' + indice + '" ' +
                           'value="' + i.cantidad + '" min="1" step="1">' +
                '</td>' +
                '<td class="num">' + money(i.precio * i.cantidad) + '</td>' +
                '<td><button type="button" class="btn-remove edit-quitar" data-i="' + indice + '" ' +
                    'title="Quitar pieza"><i class="ph ph-minus"></i></button></td>' +
            '</tr>'
        ).join('');

        const total = v.items.reduce((suma, i) => suma + i.precio * i.cantidad, 0);

        // Aviso en vivo: si el total baja de lo ya cobrado, se genera devolucion.
        let avisoAbonos = '';

        if (v.cobrado > 0) {
            avisoAbonos = total < v.cobrado
                ? '<p class="aviso aviso-devolucion">El cliente ya pagó ' + money(v.cobrado) +
                  '. Con este total se le deberá devolver <strong>' + money(v.cobrado - total) +
                  '</strong>.</p>'
                : '<p class="detalle-sub">El cliente lleva pagado ' + money(v.cobrado) +
                  ' de esta venta.</p>';
        }

        contenido.innerHTML =
            '<h2 class="detalle-titulo">Editando venta #' + v.id + '</h2>' +
            '<div class="form-group">' +
                '<label for="edit-cliente">Cliente:</label>' +
                '<div class="search-container">' +
                    '<input type="text" id="edit-cliente" class="form-control" maxlength="150" ' +
                           'autocomplete="off" placeholder="Escribe nombre, apellido o comercio…" ' +
                           'value="' + esc(v.cliente) + '">' +
                    '<button type="button" id="edit-lista-clientes" class="cliente-lista-btn" ' +
                            'title="Ver lista de clientes" aria-label="Ver lista de clientes">' +
                        '<i class="ph ph-list-bullets"></i>' +
                    '</button>' +
                '</div>' +
                '<div id="edit-resultados-cliente" class="search-results" hidden></div>' +
                '<p class="detalle-sub" id="edit-cliente-liga">' + textoLigaCliente() + '</p>' +
            '</div>' +
            '<table class="tabla-detalle">' +
                '<thead><tr><th>Producto</th><th class="num">C/U</th><th class="num">Cant.</th>' +
                '<th class="num">Subtotal</th><th></th></tr></thead>' +
                '<tbody>' + filas + '</tbody>' +
                '<tfoot><tr><td colspan="3" class="num"><strong>Total</strong></td>' +
                '<td class="num"><strong id="edit-total">' + money(total) + '</strong></td><td></td></tr></tfoot>' +
            '</table>' +
            '<div class="form-group">' +
                '<label for="edit-buscar-producto">Agregar producto:</label>' +
                '<div class="search-container">' +
                    '<input type="text" id="edit-buscar-producto" class="form-control" ' +
                           'placeholder="Buscar por nombre o código..." autocomplete="off">' +
                    '<i class="ph ph-magnifying-glass search-icon" aria-hidden="true"></i>' +
                '</div>' +
                '<div id="edit-resultados" class="search-results" hidden></div>' +
            '</div>' +
            '<div class="form-group">' +
                '<label for="edit-tipo-pago">Tipo de pago:</label>' +
                '<select id="edit-tipo-pago" class="form-control">' +
                    '<option value="contado"' + (v.tipo_pago === 'contado' ? ' selected' : '') + '>Contado</option>' +
                    '<option value="credito"' + (v.tipo_pago === 'credito' ? ' selected' : '') + '>Crédito</option>' +
                '</select>' +
            '</div>' +
            '<div class="form-group" id="edit-campo-venc"' + (v.tipo_pago === 'credito' ? '' : ' hidden') + '>' +
                '<label for="edit-vencimiento">Fecha de vencimiento:</label>' +
                '<input type="date" id="edit-vencimiento" class="form-control" value="' + v.fecha_vencimiento + '">' +
            '</div>' +
            avisoAbonos +
            '<div id="edit-aviso" class="aviso" hidden></div>' +
            '<div class="detalle-acciones">' +
                '<button type="button" class="chip" id="btn-cancelar-edicion">Cancelar</button>' +
                '<button type="button" class="btn-save" id="btn-guardar-edicion">Guardar cambios</button>' +
            '</div>';
    }

    async function guardarEdicion() {
        const v = ventaEditando;
        const avisoEdit = document.getElementById('edit-aviso');
        const boton = document.getElementById('btn-guardar-edicion');

        boton.disabled = true;
        boton.textContent = 'Guardando...';

        const cuerpo = {
            venta_id:  v.id,
            cliente:    document.getElementById('edit-cliente').value.trim(),
            cliente_id: v.cliente_id || 0,
            tipo_pago: document.getElementById('edit-tipo-pago').value,
            fecha_vencimiento: document.getElementById('edit-vencimiento')?.value || null,
            items: v.items.map((i) => ({
                casa:           i.casa_codigo || i.codigo_interno.split('-')[0],
                codigo_interno: i.codigo_interno,
                tipo_precio:    i.tipo_precio,
                cantidad:       i.cantidad,
            })),
        };

        try {
            const respuesta = await fetch('../../app/controllers/EditarVentaController.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(cuerpo),
            });
            const datos = await respuesta.json();

            if (datos.ok) {
                modal.hidden = true;
                ventaEditando = null;
                const extra = Number(datos.devolucion) > 0
                    ? ' Se le debe devolver ' + money(datos.devolucion) + ' al cliente.'
                    : '';

                mostrarAviso(
                    datos.cambios === 0
                        ? 'No hubo cambios que guardar.'
                        : 'Venta #' + datos.venta_id + ' actualizada (' + datos.cambios + ' cambio' +
                          (datos.cambios === 1 ? '' : 's') + '). Nuevo total ' + money(datos.total) + '.' + extra,
                    Number(datos.devolucion) > 0 ? 'devolucion' : 'ok'
                );
                cargar(inputDesde.value, inputHasta.value || inputDesde.value);
            } else {
                avisoEdit.textContent = datos.error || 'No se pudo guardar.';
                avisoEdit.className = 'aviso aviso-error';
                avisoEdit.hidden = false;
            }
        } catch (e) {
            avisoEdit.textContent = 'Error de conexión al guardar.';
            avisoEdit.className = 'aviso aviso-error';
            avisoEdit.hidden = false;
        } finally {
            boton.disabled = false;
            boton.textContent = 'Guardar cambios';
        }
    }

    // ---------- Eventos ----------
    chips.forEach((chip) => {
        chip.addEventListener('click', () => {
            chips.forEach((c) => c.classList.remove('chip-activo'));
            chip.classList.add('chip-activo');

            const [desde, hasta] = rangoDe(chip.dataset.rango);
            inputDesde.value = desde;
            inputHasta.value = hasta;
            cargar(desde, hasta);
        });
    });

    btnFiltrar.addEventListener('click', () => {
        const desde = inputDesde.value;
        const hasta = inputHasta.value || desde;

        if (!desde) {
            mostrarAviso('Elige al menos la fecha "Desde".', 'error');
            return;
        }

        chips.forEach((c) => c.classList.remove('chip-activo'));
        cargar(desde, hasta);
    });

    // La descarga es una navegacion normal: el navegador la resuelve como
    // archivo adjunto y se lleva la cookie de sesion. Usa exactamente los
    // mismos filtros que la pantalla tiene puestos.
    btnExportar.addEventListener('click', () => {
        const desde = inputDesde.value;
        const hasta = inputHasta.value || desde;

        if (!desde) {
            mostrarAviso('Elige un rango de fechas antes de descargar.', 'error');
            return;
        }

        window.location = '../../app/controllers/ExportarHistorialController.php'
            + '?desde=' + desde
            + '&hasta=' + hasta
            + '&usuario=' + (filtroUsuario.value || 0);
    });

    filtroUsuario.addEventListener('change', () => {
        cargar(inputDesde.value, inputHasta.value || inputDesde.value);
    });

    lista.addEventListener('click', (e) => {
        // La casilla y su etiqueta seleccionan; no deben abrir el detalle.
        if (e.target.closest('.venta-elegir')) return;

        const fila = e.target.closest('.venta-fila');
        if (fila) verDetalle(fila.dataset.id);
    });

    lista.addEventListener('change', (e) => {
        if (e.target.classList.contains('chk-venta')) actualizarSeleccion();
    });

    // ---------- Impresion de varias notas ----------
    function seleccionadas() {
        return [...lista.querySelectorAll('.chk-venta:checked')].map((c) => c.value);
    }

    function actualizarSeleccion() {
        const n = seleccionadas().length;
        barraSeleccion.hidden = n === 0;

        if (n > 0) {
            const hojas = Math.ceil(n / 2);
            document.getElementById('cuenta-seleccion').textContent =
                n + ' venta' + (n === 1 ? '' : 's') + ' · ' +
                hojas + ' hoja' + (hojas === 1 ? '' : 's');
        }
    }

    lista.addEventListener('keydown', (e) => {
        if (e.key !== 'Enter' && e.key !== ' ') return;
        const fila = e.target.closest('.venta-fila');
        if (fila) {
            e.preventDefault();
            verDetalle(fila.dataset.id);
        }
    });

    contenido.addEventListener('click', async (e) => {
        // closest: el click puede caer en el icono de adentro del boton.
        if (e.target.closest('#btn-productos-pdf')) {
            abrirResumenPdf(false);
            return;
        }

        if (e.target.closest('#btn-productos-pdf-casa')) {
            abrirResumenPdf(true);
            return;
        }

        const itemCliente = e.target.closest('.edit-cliente-item');
        if (itemCliente) {
            elegirClienteEdicion(Number(itemCliente.dataset.idx));
            return;
        }

        // El boton de la lista muestra el catalogo completo, sin escribir nada.
        if (e.target.closest('#edit-lista-clientes')) {
            buscarClientesEdicion('');
            return;
        }

        // Un clic fuera cierra el desplegable de clientes.
        if (!e.target.closest('#edit-cliente, #edit-resultados-cliente, #edit-lista-clientes')) {
            const lista = document.getElementById('edit-resultados-cliente');
            if (lista) lista.hidden = true;
        }

        const botonPrecio = e.target.closest('.btn-precio');
        if (botonPrecio) {
            abrirPrecio(botonPrecio.dataset.codigo, botonPrecio.dataset.venta);
            return;
        }

        if (e.target.id === 'btn-cancelar-precio') {
            verDetalle(ventaDelPrecio);
            return;
        }

        if (e.target.id === 'btn-guardar-precio') {
            guardarPrecio(e.target.dataset.codigo);
            return;
        }

        const botonEnviar = e.target.closest('.btn-enviar-nota');
        if (botonEnviar) {
            abrirEnviarNota(botonEnviar.dataset.id);
            return;
        }

        if (e.target.closest('#btn-wa-enviar')) {
            enviarWhatsApp();
            return;
        }

        if (e.target.closest('#btn-correo-enviar')) {
            enviarNotaCorreo();
            return;
        }

        if (e.target.id === 'btn-volver-detalle') {
            verDetalle(e.target.dataset.id);
            return;
        }

        const botonImprimir = e.target.closest('.btn-imprimir');
        if (botonImprimir) {
            const respuesta = await fetch(
                '../../app/controllers/HistorialController.php?accion=detalle&id=' + botonImprimir.dataset.id
            );
            const datos = await respuesta.json();
            if (datos.ok) abrirImpresion(datos.venta);
            return;
        }

        if (e.target.id === 'btn-editar-venta') {
            const respuesta = await fetch(
                '../../app/controllers/HistorialController.php?accion=detalle&id=' + e.target.dataset.id
            );
            const datos = await respuesta.json();
            if (datos.ok) abrirEdicion(datos.venta);
            return;
        }

        if (e.target.id === 'btn-cancelar-edicion') {
            verDetalle(ventaEditando.id);
            return;
        }

        if (e.target.id === 'btn-guardar-edicion') {
            guardarEdicion();
            return;
        }

        const botonAbonar = e.target.closest('#btn-abonar');
        if (botonAbonar) {
            guardarAbono(botonAbonar.dataset.id);
            return;
        }

        const resItem = e.target.closest('.edit-result-item');
        if (resItem) {
            agregarItemEdicion(resultadosEdicion[Number(resItem.dataset.idx)]);
            return;
        }

        const quitar = e.target.closest('.edit-quitar');
        if (quitar) {
            if (ventaEditando.items.length === 1) {
                const a = document.getElementById('edit-aviso');
                a.textContent = 'La venta debe conservar al menos una pieza.';
                a.className = 'aviso aviso-error';
                a.hidden = false;
                return;
            }
            ventaEditando.items.splice(Number(quitar.dataset.i), 1);
            pintarEdicion();
        }
    });

    contenido.addEventListener('input', (e) => {
        // El nombre se guarda en el estado a cada tecla: pintarEdicion() se
        // vuelve a ejecutar al cambiar una cantidad y si no, se perderia.
        if (e.target.id === 'edit-cliente' && ventaEditando) {
            ventaEditando.cliente = e.target.value;

            if (ventaEditando.cliente_id) {
                ventaEditando.cliente_id = 0;
                refrescarLigaCliente();
            }

            clearTimeout(temporizadorCliente);
            const termino = e.target.value.trim();

            // Con una sola letra ya se busca: el catalogo de clientes es chico.
            if (termino === '') {
                document.getElementById('edit-resultados-cliente').hidden = true;
            } else {
                temporizadorCliente = setTimeout(() => buscarClientesEdicion(termino), 250);
            }
            return;
        }

        if (e.target.id === 'edit-buscar-producto') {
            clearTimeout(temporizadorEdicion);
            const termino = e.target.value.trim();
            temporizadorEdicion = setTimeout(() => buscarProductoEdicion(termino), 300);
        }
    });

    contenido.addEventListener('keydown', (e) => {
        const itemCliente = e.target.closest('.edit-cliente-item');
        if (itemCliente && (e.key === 'Enter' || e.key === ' ')) {
            e.preventDefault();
            elegirClienteEdicion(Number(itemCliente.dataset.idx));
            return;
        }

        const resItem = e.target.closest('.edit-result-item');
        if (resItem && (e.key === 'Enter' || e.key === ' ')) {
            e.preventDefault();
            agregarItemEdicion(resultadosEdicion[Number(resItem.dataset.idx)]);
        }
    });

    contenido.addEventListener('change', (e) => {
        if (e.target.classList.contains('edit-cantidad')) {
            const cantidad = parseInt(e.target.value, 10);
            ventaEditando.items[e.target.dataset.i].cantidad =
                (isNaN(cantidad) || cantidad < 1) ? 1 : cantidad;
            pintarEdicion();
        }

        if (e.target.id === 'edit-tipo-pago') {
            const campo = document.getElementById('edit-campo-venc');
            if (campo) campo.hidden = e.target.value !== 'credito';
        }
    });

    document.getElementById('btn-limpiar-seleccion').addEventListener('click', () => {
        lista.querySelectorAll('.chk-venta:checked').forEach((c) => { c.checked = false; });
        actualizarSeleccion();
    });

    document.getElementById('btn-imprimir-seleccion').addEventListener('click', () => {
        const ids = seleccionadas();
        if (ids.length === 0) return;

        // Cada nota sale con lo que su cliente tenga guardado. Antes de imprimir
        // se revisa a quiénes les falta algún dato: si nadie debe nada, imprime
        // de frente sin estorbar.
        NotaDatos.abrirVarias({
            ids:        ids,
            contenedor: contenido,
            rutaApp:    '../../app/controllers/',
            rutaNota:   '../ventas/nota.php',
            mostrar:    () => { modal.hidden = false; },
            alCerrar:   () => { modal.hidden = true; },
        });
    });

    btnProductos.addEventListener('click', verProductos);

    btnCerrar.addEventListener('click', () => { modal.hidden = true; });

    modal.addEventListener('click', (e) => {
        if (e.target === modal) modal.hidden = true;
    });

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') modal.hidden = true;
    });

    // Arranca en el dia de hoy.
    const [hoyDesde, hoyHasta] = rangoDe('hoy');
    inputDesde.value = hoyDesde;
    inputHasta.value = hoyHasta;
    cargar(hoyDesde, hoyHasta);
});
