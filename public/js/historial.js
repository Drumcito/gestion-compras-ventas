document.addEventListener('DOMContentLoaded', () => {
    const lista        = document.getElementById('lista-ventas');
    if (!lista) return;

    const inputDesde   = document.getElementById('desde');
    const inputHasta   = document.getElementById('hasta');
    const btnFiltrar   = document.getElementById('btn-filtrar');
    const filtroUsuario = document.getElementById('filtro-usuario');
    const chips        = document.querySelectorAll('.chip');
    const resumen      = document.getElementById('resumen-periodo');
    const aviso        = document.getElementById('aviso-historial');
    const modal        = document.getElementById('modal-detalle');
    const contenido    = document.getElementById('contenido-detalle');
    const btnCerrar    = document.getElementById('btn-cerrar-detalle');

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
                '<div class="venta-fila-datos">' +
                    '<p class="venta-fila-cliente">' + (v.cliente ? esc(v.cliente) : 'Sin cliente') +
                        (v.puede_editar ? '<i class="ph ph-pencil-simple icono-editable" title="Puedes editar esta venta"></i>' : '') +
                    '</p>' +
                    '<p class="venta-fila-meta">#' + v.id + ' · ' + fechaLegible(v.fecha) +
                        ' · ' + esc(v.vendedor) + ' (' + esc(v.numero_empleado) + ')' +
                        ' · ' + v.piezas + ' pieza' + (v.piezas === '1' ? '' : 's') + '</p>' +
                '</div>' +
                '<div class="venta-fila-derecha">' + etiquetaPago +
                    '<span class="venta-fila-total">' + money(v.total) + '</span>' +
                '</div>';

            lista.appendChild(fila);
        });
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
                    '<span class="detalle-sub">' + esc(i.casa) + ' · ' + i.codigo_interno_producto + '</span></td>' +
                '<td>' + i.tipo_precio + '</td>' +
                '<td class="num">' + money(i.precio_aplicado) + '</td>' +
                '<td class="num">' + i.cantidad + '</td>' +
                '<td class="num">' + money(i.subtotal) + '</td>' +
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

        let credito = '';

        if (v.tipo_pago === 'credito') {
            const abonos = v.abonos.length === 0
                ? '<p class="detalle-sub">Sin abonos registrados.</p>'
                : '<ul class="lista-abonos">' + v.abonos.map((a) =>
                    '<li>' + fechaLegible(a.fecha_pago) + ' — <strong>' + money(a.monto) + '</strong>' +
                    ' · recibió ' + esc(a.recibio) + (a.nota ? ' · ' + esc(a.nota) : '') + '</li>'
                  ).join('') + '</ul>';

            credito =
                '<div class="detalle-credito">' +
                    '<h3>Crédito</h3>' +
                    '<p>Abonado: <strong>' + money(v.saldo ? v.saldo.total_abonado : 0) + '</strong> · ' +
                       'Saldo pendiente: <strong>' + money(v.saldo ? v.saldo.saldo_pendiente : v.total) + '</strong>' +
                       (v.fecha_vencimiento ? ' · Vence: ' + fechaLegible(v.fecha_vencimiento) : '') + '</p>' +
                    abonos +
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
                '<th class="num">Cant.</th><th class="num">Subtotal</th></tr></thead>' +
                '<tbody>' + filas + '</tbody>' +
                '<tfoot><tr><td colspan="4" class="num"><strong>Total</strong></td>' +
                '<td class="num"><strong>' + money(v.total) + '</strong></td></tr></tfoot>' +
            '</table>' +
            bloqueDevolucion +
            credito +
            auditoria +
            '<div class="detalle-acciones">' + botonEditar + '</div>';
    }

    // ---------- Edicion ----------
    let ventaEditando = null;

    function abrirEdicion(v) {
        ventaEditando = {
            id:        v.id,
            cliente:   v.cliente || '',
            tipo_pago: v.tipo_pago,
            fecha_vencimiento: v.fecha_vencimiento || '',
            items: v.items.map((i) => ({
                casa_nombre:    i.casa,
                casa_codigo:    i.codigo_casa,
                codigo_interno: i.codigo_interno_producto,
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
                    '<span class="detalle-sub">' + esc(i.casa_nombre) + ' · ' + i.codigo_interno + '</span></td>' +
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
                '<input type="text" id="edit-cliente" class="form-control" maxlength="150" ' +
                       'value="' + esc(v.cliente) + '">' +
            '</div>' +
            '<table class="tabla-detalle">' +
                '<thead><tr><th>Producto</th><th class="num">C/U</th><th class="num">Cant.</th>' +
                '<th class="num">Subtotal</th><th></th></tr></thead>' +
                '<tbody>' + filas + '</tbody>' +
                '<tfoot><tr><td colspan="3" class="num"><strong>Total</strong></td>' +
                '<td class="num"><strong id="edit-total">' + money(total) + '</strong></td><td></td></tr></tfoot>' +
            '</table>' +
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
            cliente:   document.getElementById('edit-cliente').value.trim(),
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

    filtroUsuario.addEventListener('change', () => {
        cargar(inputDesde.value, inputHasta.value || inputDesde.value);
    });

    lista.addEventListener('click', (e) => {
        const fila = e.target.closest('.venta-fila');
        if (fila) verDetalle(fila.dataset.id);
    });

    lista.addEventListener('keydown', (e) => {
        if (e.key !== 'Enter' && e.key !== ' ') return;
        const fila = e.target.closest('.venta-fila');
        if (fila) {
            e.preventDefault();
            verDetalle(fila.dataset.id);
        }
    });

    contenido.addEventListener('click', async (e) => {
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
