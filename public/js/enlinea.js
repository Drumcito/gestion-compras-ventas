/**
 * Sección "En línea": pedidos del catálogo y las ventas que salieron de ellos.
 *
 * Arriba, los pedidos POR ATENDER (el mismo panel que antes vivía en Historial,
 * movido aquí para que tengan su propio lugar). Abajo, las ventas que ya se
 * cobraron a partir de un pedido, con su folio.
 *
 * El alcance lo decide el servidor (PedidoLineaController): el admin ve todas
 * las ventas, el vendedor solo las que él atendió.
 */
document.addEventListener('DOMContentLoaded', () => {
    const RUTA_PEDIDOS = '../../app/controllers/PedidoLineaController.php';
    const RUTA_DETALLE = '../../app/controllers/HistorialController.php';

    const aviso        = document.getElementById('aviso');
    const cajaPedidos  = document.getElementById('pedidos-linea');
    const lista        = document.getElementById('lista-enlinea');
    const resumen      = document.getElementById('resumen-enlinea');
    const buscador     = document.getElementById('buscar-enlinea');
    const modal        = document.getElementById('modal-detalle');
    const contenido    = document.getElementById('contenido-detalle');
    const btnCerrar    = document.getElementById('btn-cerrar-detalle');

    let temporizadorAviso = null;

    function esc(texto) {
        const div = document.createElement('div');
        div.textContent = texto === null || texto === undefined ? '' : texto;

        // innerHTML escapa < > &, pero NO las comillas, y esto se mete dentro de
        // atributos (value="…"). Un nombre con comillas cortaría el atributo.
        return div.innerHTML.replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    const money = (n) => '$' + Number(n || 0).toLocaleString('es-MX',
        { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    function fechaLegible(sql) {
        if (!sql) return '';

        // El servidor manda 'YYYY-MM-DD HH:MM:SS'; Safari no lo parsea sin la T.
        const d = new Date(String(sql).replace(' ', 'T'));

        return isNaN(d) ? '' : d.toLocaleString('es-MX',
            { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
    }

    function mostrarAviso(texto, tipo, segundos = 5) {
        clearTimeout(temporizadorAviso);
        aviso.textContent = texto;
        aviso.className = 'aviso aviso-' + tipo;
        aviso.hidden = false;
        if (segundos > 0) {
            temporizadorAviso = setTimeout(() => { aviso.hidden = true; }, segundos * 1000);
        }
    }

    // ---------- Pedidos por atender ----------
    let pedidos = [];

    async function cargarPedidos() {
        try {
            const datos = await (await fetch(RUTA_PEDIDOS + '?accion=pendientes')).json();
            pedidos = datos.ok ? datos.pedidos : [];
        } catch (e) {
            pedidos = [];
        }
        pintarPedidos();
    }

    function pintarPedidos() {
        if (pedidos.length === 0) {
            cajaPedidos.hidden = true;
            cajaPedidos.innerHTML = '';
            return;
        }

        const tarjetas = pedidos.map((p) => {
            const productos = p.items.map((i) =>
                '<li><span class="pedido-cant">' + i.cantidad + ' ×</span> ' + esc(i.nombre) +
                    ' <span class="pedido-precio">' + money(i.precio) + '</span></li>'
            ).join('');

            return '<div class="pedido-linea">' +
                '<div class="pedido-linea-cabeza">' +
                    '<div class="pedido-linea-datos">' +
                        '<span class="pedido-linea-cliente">' + (p.nombre ? esc(p.nombre) : 'Sin nombre') + '</span>' +
                        '<span class="pedido-linea-meta">Folio <strong>' + esc(p.folio) + '</strong> · ' +
                            fechaLegible(p.creado_en) + ' · ' +
                            p.items.length + ' producto' + (p.items.length === 1 ? '' : 's') + ' · ' +
                            p.piezas + ' pieza' + (Number(p.piezas) === 1 ? '' : 's') + '</span>' +
                    '</div>' +
                    '<span class="pedido-linea-total">' + money(p.total) + '</span>' +
                '</div>' +
                '<details class="pedido-linea-productos"><summary>Ver productos</summary>' +
                    '<ul>' + productos + '</ul></details>' +
                '<div class="pedido-linea-acciones">' +
                    '<button type="button" class="chip btn-descartar-pedido" data-id="' + p.id + '" ' +
                        'data-folio="' + esc(p.folio) + '">Descartar</button>' +
                    '<button type="button" class="btn-save btn-cargar-pedido" data-id="' + p.id + '">' +
                        '<i class="ph ph-shopping-cart"></i> Cargar en venta</button>' +
                '</div>' +
            '</div>';
        }).join('');

        cajaPedidos.innerHTML =
            '<div class="pedidos-linea-titulo"><i class="ph ph-globe"></i> Pedidos en línea por atender · ' +
                pedidos.length + '</div>' +
            '<p class="pedidos-linea-ayuda">Llegaron por WhatsApp desde el catálogo; búscalos ahí por su folio. ' +
                '«Cargar en venta» los pasa a la pantalla de Venta para cobrarlos y sacar la nota.</p>' +
            tarjetas;
        cajaPedidos.hidden = false;
    }

    /**
     * Pasa el pedido a la pantalla de Venta como si fuera una venta a medias (el
     * mismo borrador que venta.js guarda en el navegador), con el precio de hoy.
     * Ahí se revisa, se elige la forma de pago y se guarda.
     */
    async function cargarPedidoEnVenta(id) {
        let datos;

        try {
            datos = await (await fetch(RUTA_PEDIDOS + '?accion=cargar&id=' + encodeURIComponent(id))).json();
        } catch (e) {
            mostrarAviso('Error de conexión al cargar el pedido.', 'error');
            return;
        }

        if (!datos.ok) {
            mostrarAviso(datos.error || 'No se pudo cargar el pedido.', 'error');
            cargarPedidos();
            return;
        }

        const pedido = datos.pedido;

        if (pedido.items.length === 0) {
            mostrarAviso('Ninguno de los productos del pedido ' + pedido.folio +
                ' sigue en el catálogo. Atiéndelo por WhatsApp o descártalo.', 'error');
            return;
        }

        const clave = 'gabe:venta:' + (window.USUARIO_ID || 0);

        // No pisar una venta que alguien dejó a medias sin preguntar.
        try {
            const actual = JSON.parse(localStorage.getItem(clave) || 'null');
            const otra = actual && Array.isArray(actual.items) && actual.items.length > 0 &&
                !(actual.pedido_linea && String(actual.pedido_linea.id) === String(pedido.id));

            if (otra && !confirm('Tienes una venta a medias en la pantalla de Venta. ' +
                    '¿Reemplazarla por el pedido ' + pedido.folio + '?')) {
                return;
            }

            localStorage.setItem(clave, JSON.stringify({
                guardado_en:     Date.now(),
                items:           pedido.items,
                cliente:         pedido.nombre || '',
                cliente_sel:     { id: null, nombre: '', saldo: 0 },
                aplicar_saldo:   true,
                guardar_cliente: false,
                tipo_pago:       'contado',
                pago_inicial:    '',
                vencimiento:     '',
                pedido_linea:    { id: pedido.id, folio: pedido.folio, nombre: pedido.nombre || '' },
                pedido_recien_cargado: true,
                pedido_fuera:    pedido.fuera,
            }));
        } catch (e) {
            mostrarAviso('El navegador no deja guardar el pedido para pasarlo a Venta.', 'error');
            return;
        }

        window.location.href = '../dashboard/index.php';
    }

    async function descartarPedido(id, folio) {
        if (!confirm('¿Descartar el pedido en línea ' + folio + '? Ya no saldrá como pendiente ' +
                '(úsalo para pedidos repetidos, de prueba o que el cliente canceló).')) {
            return;
        }

        try {
            const datos = await (await fetch(RUTA_PEDIDOS, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ accion: 'descartar', id: id }),
            })).json();

            mostrarAviso(datos.ok ? 'Pedido ' + folio + ' descartado.' : (datos.error || 'No se pudo descartar.'),
                datos.ok ? 'ok' : 'error');
        } catch (e) {
            mostrarAviso('Error de conexión al descartar el pedido.', 'error');
        }

        cargarPedidos();
    }

    // ---------- Ventas que salieron de un pedido ----------
    let ventas = [];

    async function cargarVentas() {
        lista.innerHTML = '<p class="venta-vacia">Cargando…</p>';

        try {
            const datos = await (await fetch(RUTA_PEDIDOS + '?accion=ventas')).json();

            if (!datos.ok) {
                lista.innerHTML = '';
                mostrarAviso(datos.error || 'No se pudieron cargar las ventas en línea.', 'error');
                return;
            }

            ventas = datos.ventas || [];

        } catch (e) {
            lista.innerHTML = '';
            mostrarAviso('Error de conexión.', 'error');
            return;
        }

        pintarVentas();
    }

    function pintarVentas() {
        const termino = buscador.value.trim().toLowerCase();

        const visibles = termino === ''
            ? ventas
            : ventas.filter((v) =>
                String(v.folio || '').toLowerCase().includes(termino) ||
                String(v.cliente || '').toLowerCase().includes(termino) ||
                String(v.nombre_pedido || '').toLowerCase().includes(termino) ||
                String(v.venta_id).includes(termino));

        const suma = visibles.reduce((t, v) => t + Number(v.total), 0);

        resumen.textContent = visibles.length === 0
            ? (ventas.length === 0
                ? 'Todavía no hay ventas que hayan salido del catálogo.'
                : 'Ninguna venta coincide con la búsqueda.')
            : visibles.length + ' venta' + (visibles.length === 1 ? '' : 's') +
              ' en línea · ' + money(suma);

        lista.innerHTML = '';

        visibles.forEach((v) => {
            const fila = document.createElement('div');
            fila.className = 'venta-fila venta-en-linea';
            fila.dataset.id = v.venta_id;
            fila.tabIndex = 0;

            fila.innerHTML =
                '<div class="venta-fila-datos">' +
                    '<p class="venta-fila-cliente">' + (v.cliente ? esc(v.cliente) : 'Sin cliente') +
                        '<span class="etiqueta-en-linea"><i class="ph ph-globe"></i> ' +
                            esc(v.folio) + '</span></p>' +
                    '<p class="venta-fila-meta">Venta #' + v.venta_id + ' · ' + fechaLegible(v.fecha) +
                        ' · ' + v.renglones + ' producto' + (Number(v.renglones) === 1 ? '' : 's') +
                        ' · ' + v.piezas + ' pieza' + (Number(v.piezas) === 1 ? '' : 's') +
                        (v.vendedor ? ' · ' + esc(v.vendedor.trim()) : '') + '</p>' +
                    '<p class="venta-fila-meta">Pedido del ' + fechaLegible(v.pedido_en) +
                        (v.nombre_pedido ? ' · lo mandó ' + esc(v.nombre_pedido) : '') + '</p>' +
                '</div>' +
                '<div class="venta-fila-derecha">' +
                    '<span class="venta-fila-total">' + money(v.total) + '</span>' +
                    '<span class="venta-fila-meta">' + esc(v.tipo_pago) + ' · ' + esc(v.estado_pago) + '</span>' +
                '</div>';

            lista.appendChild(fila);
        });
    }

    /** El detalle es el mismo de Historial: se reusa su controlador. */
    async function verDetalle(id) {
        contenido.innerHTML = '<p class="venta-vacia">Cargando…</p>';
        modal.hidden = false;

        try {
            const datos = await (await fetch(RUTA_DETALLE + '?accion=detalle&id=' + encodeURIComponent(id))).json();

            if (!datos.ok) {
                contenido.innerHTML = '<p class="aviso aviso-error">' +
                    esc(datos.error || 'No se pudo cargar la venta') + '</p>';
                return;
            }

            const v = datos.venta;

            const filas = v.items.map((i) =>
                '<tr>' +
                    '<td>' + esc(i.nombre_producto) + '</td>' +
                    '<td class="num">' + money(i.precio_aplicado) + '</td>' +
                    '<td class="num">' + i.cantidad + '</td>' +
                    '<td class="num">' + money(i.subtotal) + '</td>' +
                '</tr>'
            ).join('');

            contenido.innerHTML =
                '<h2 class="detalle-titulo">Venta #' + v.id + '</h2>' +
                '<div class="detalle-cabecera">' +
                    '<p><strong>' + (v.cliente ? esc(v.cliente) : 'Sin cliente') + '</strong></p>' +
                    '<p class="detalle-sub">' + fechaLegible(v.fecha) +
                        (v.pedido_folio ? ' · pedido en línea ' + esc(v.pedido_folio) : '') + '</p>' +
                '</div>' +
                '<table class="tabla-detalle">' +
                    '<thead><tr><th>Producto</th><th class="num">C/U</th>' +
                        '<th class="num">Cant.</th><th class="num">Subtotal</th></tr></thead>' +
                    '<tbody>' + filas + '</tbody>' +
                '</table>' +
                '<p class="detalle-total">Total: <strong>' + money(v.total) + '</strong></p>' +
                '<p class="detalle-sub">Para cambiarla o imprimir su nota, ábrela en Historial.</p>';

        } catch (e) {
            contenido.innerHTML = '<p class="aviso aviso-error">Error de conexión.</p>';
        }
    }

    // ---------- Eventos ----------
    cajaPedidos.addEventListener('click', (e) => {
        const cargarBtn = e.target.closest('.btn-cargar-pedido');
        if (cargarBtn) {
            cargarBtn.disabled = true;
            cargarPedidoEnVenta(cargarBtn.dataset.id).finally(() => { cargarBtn.disabled = false; });
            return;
        }

        const descartarBtn = e.target.closest('.btn-descartar-pedido');
        if (descartarBtn) descartarPedido(descartarBtn.dataset.id, descartarBtn.dataset.folio);
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

    buscador.addEventListener('input', pintarVentas);

    btnCerrar.addEventListener('click', () => { modal.hidden = true; });
    modal.addEventListener('click', (e) => { if (e.target === modal) modal.hidden = true; });
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape') modal.hidden = true; });

    cargarPedidos();
    cargarVentas();
});
