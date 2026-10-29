/**
 * Panel de pedidos del catálogo por atender, en la sección "En línea".
 *
 * Es lo ÚNICO propio de esa pantalla: la lista de ventas, los filtros y todo lo
 * demás los pone historial.js, que esa vista carga con window.SOLO_EN_LINEA
 * para que traiga solo las ventas nacidas de un pedido.
 *
 * Un pedido deja de salir aquí cuando se cobra (queda ligado a una venta) o
 * cuando se descarta. Si esa venta se elimina, el pedido vuelve a aparecer
 * como pendiente: así no se pierde lo que el cliente mandó.
 */
document.addEventListener('DOMContentLoaded', () => {
    const RUTA_PEDIDOS = '../../app/controllers/PedidoLineaController.php';

    const cajaPedidos = document.getElementById('pedidos-linea');
    const aviso       = document.getElementById('aviso-historial');

    if (!cajaPedidos) return;

    let pedidos = [];
    let temporizadorAviso = null;

    function esc(texto) {
        const div = document.createElement('div');
        div.textContent = texto === null || texto === undefined ? '' : texto;

        // innerHTML escapa < > &, pero NO las comillas, y esto se mete dentro de
        // atributos (data-folio="…"): un nombre con comillas cortaría el atributo.
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

    /** Se reusa el aviso de la pantalla; si no estuviera, no se pierde el mensaje. */
    function mostrarAviso(texto, tipo, segundos = 5) {
        if (!aviso) {
            alert(texto);
            return;
        }

        clearTimeout(temporizadorAviso);
        aviso.textContent = texto;
        aviso.className = 'aviso aviso-' + tipo;
        aviso.hidden = false;

        if (segundos > 0) {
            temporizadorAviso = setTimeout(() => { aviso.hidden = true; }, segundos * 1000);
        }
    }

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

    // historial.js la llama cada vez que reconstruye la lista de ventas: al
    // eliminar o recuperar una venta, su pedido cambia de estado.
    window.recargarPedidosLinea = cargarPedidos;

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

    cargarPedidos();
});
