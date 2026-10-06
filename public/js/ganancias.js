/**
 * Seccion "Ganancias" del Dashboard (agregado, independiente de estadisticas.js).
 *
 * Reconstruye lo que de verdad se gana: cada producto se vende a su NETO (bruto +
 * un porcentaje) y la ganancia es ese porcentaje. El calculo real vive en el
 * servidor (app/controllers/GananciasController.php); aqui solo se pinta.
 *
 * No modifica nada del tablero: lee los MISMOS filtros (fechas, casa y rangos
 * rapidos) que ya existen y se cuelga de los mismos botones con addEventListener,
 * asi que convive con estadisticas.js sin pisarlo. Solo corre para el admin.
 */
document.addEventListener('DOMContentLoaded', () => {
    // La seccion solo existe para el admin; si no esta en el DOM, no hay nada que hacer.
    const seccion = document.getElementById('ganancias-seccion');
    if (!seccion || window.ES_ADMIN !== true) return;

    const hayGraficas = typeof Chart !== 'undefined';

    // ---------- Controles (los mismos del tablero) ----------
    const inputDesde = document.getElementById('desde');
    const inputHasta = document.getElementById('hasta');
    const filtroCasa = document.getElementById('filtro-casa');
    const btnFiltrar = document.getElementById('btn-filtrar');
    const chips = document.querySelectorAll('.filtros-rapidos .chip');

    // ---------- Nodos propios ----------
    const kpis = document.getElementById('kpis-ganancias');
    const subGrafica = document.getElementById('sub-ganancias');
    const tablaProductos = document.getElementById('tabla-ganancia-productos');
    const tablaCasas = document.getElementById('tabla-ganancia-casas');

    // ---------- Formato ----------
    const money = (n) => '$' + Number(n).toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const entero = (n) => Number(n).toLocaleString('es-MX', { maximumFractionDigits: 0 });
    const pct = (n) => Number(n).toLocaleString('es-MX', { maximumFractionDigits: 1 }) + '%';

    const moneyCorto = (n) => {
        const v = Math.abs(n);
        if (v >= 1000000) return '$' + (n / 1000000).toLocaleString('es-MX', { maximumFractionDigits: 1 }) + 'M';
        if (v >= 1000) return '$' + (n / 1000).toLocaleString('es-MX', { maximumFractionDigits: 1 }) + 'k';
        return '$' + Number(n).toLocaleString('es-MX', { maximumFractionDigits: 0 });
    };

    const iso = (f) => f.getFullYear() + '-' + String(f.getMonth() + 1).padStart(2, '0') + '-' + String(f.getDate()).padStart(2, '0');
    const aFecha = (texto) => { const [a, m, d] = texto.slice(0, 10).split('-').map(Number); return new Date(a, m - 1, d); };

    function esc(texto) {
        return String(texto).replace(/[&<>"']/g, (c) =>
            ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    }
    const codigoVisible = (p) => p.codigo_proveedor || p.codigo;
    const recortar = (t, max) => (t.length <= max ? t : t.slice(0, max - 1).trim() + '…');

    // ---------- Colores (del CSS, igual que el resto del tablero) ----------
    const raiz = document.querySelector('.main-dashboard') || document.documentElement;
    const estilo = getComputedStyle(raiz);
    const estiloRaiz = getComputedStyle(document.documentElement);
    const token = (n) => estilo.getPropertyValue(n).trim();

    const color = {
        ganancia: token('--dash-mejor') || '#006300',
        texto: token('--dash-texto') || '#0b0b0b',
        tenue: token('--dash-tenue') || '#898781',
        reticula: token('--dash-reticula') || '#e1e0d9',
    };
    const colorCasa = (codigo) => estiloRaiz.getPropertyValue('--casa-' + codigo).trim() || color.tenue;

    // ---------- Etiquetas de cubetas de tiempo ----------
    const nombreAgrupacion = { hora: 'hora', dia: 'día', semana: 'semana', mes: 'mes' };

    function etiquetaCubeta(clave, agrupacion, variosAnios, pocosDias) {
        if (agrupacion === 'hora') return clave.slice(11, 13) + ':00';
        if (agrupacion === 'mes') {
            return aFecha(clave + '-01').toLocaleDateString('es-MX',
                variosAnios ? { month: 'short', year: '2-digit' } : { month: 'short' });
        }
        const opciones = pocosDias ? { weekday: 'short', day: 'numeric' } : { day: 'numeric', month: 'short' };
        return aFecha(clave).toLocaleDateString('es-MX', opciones);
    }

    function tituloCubeta(clave, agrupacion) {
        if (agrupacion === 'hora') {
            const h = Number(clave.slice(11, 13));
            return String(h).padStart(2, '0') + ':00 – ' + String(h + 1).padStart(2, '0') + ':00';
        }
        if (agrupacion === 'mes') return aFecha(clave + '-01').toLocaleDateString('es-MX', { month: 'long', year: 'numeric' });
        if (agrupacion === 'semana') {
            const lunes = aFecha(clave);
            const domingo = new Date(lunes); domingo.setDate(lunes.getDate() + 6);
            const f = (d) => d.toLocaleDateString('es-MX', { day: 'numeric', month: 'short' });
            return 'Semana del ' + f(lunes) + ' al ' + f(domingo);
        }
        return aFecha(clave).toLocaleDateString('es-MX', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
    }

    // ---------- Rangos rapidos (mismas reglas que el tablero) ----------
    function rangoDe(tipo) {
        const hoy = new Date();
        if (tipo === 'semana') {
            const lunes = new Date(hoy);
            const diff = hoy.getDay() === 0 ? 6 : hoy.getDay() - 1;
            lunes.setDate(hoy.getDate() - diff);
            return [iso(lunes), iso(hoy)];
        }
        if (tipo === 'mes') return [iso(new Date(hoy.getFullYear(), hoy.getMonth(), 1)), iso(hoy)];
        if (tipo === 'anio') return [iso(new Date(hoy.getFullYear(), 0, 1)), iso(hoy)];
        return [iso(hoy), iso(hoy)];
    }

    // ---------- Tabla (mismo markup que estadisticas.js) ----------
    function tabla(encabezados, filas) {
        if (filas.length === 0) return '<p class="tabla-vacia">Sin datos en este periodo.</p>';
        const clase = (e) => {
            const clases = [e.num ? 'num' : '', e.ancha ? 'col-ancha' : '', e.extra ? 'col-extra' : '']
                .filter(Boolean).join(' ');
            return clases ? ' class="' + clases + '"' : '';
        };
        return '<div class="tabla-scroll"><table class="tabla-dash"><thead><tr>' +
            encabezados.map((e) => '<th' + clase(e) + '>' + e.texto + '</th>').join('') +
            '</tr></thead><tbody>' +
            filas.map((f) => '<tr>' + f.map((celda, i) =>
                '<td' + clase(encabezados[i]) + '>' + celda + '</td>').join('') + '</tr>').join('') +
            '</tbody></table></div>';
    }

    function barra(valor, maximo) {
        const ancho = maximo > 0 ? Math.max(2, Math.round(valor / maximo * 100)) : 0;
        return '<div class="barra-pista"><div class="barra-relleno" style="width:' + ancho + '%;background:' + color.ganancia + '"></div></div>';
    }

    const etiquetaCasaHtml = (codigo, nombre) =>
        '<span class="res-casa casa-' + esc(codigo) + '">' + esc(nombre) + '</span>';

    // ---------- Estado ----------
    let datos = null;
    let rangoActivo = 'hoy';
    let peticion = 0;
    let grafica = null;

    // ---------- Carga ----------
    async function cargar() {
        const desde = inputDesde.value;
        if (!desde) return;
        const hasta = inputHasta.value || desde;
        const numero = ++peticion;

        seccion.classList.add('cargando');

        try {
            const url = '../../app/controllers/GananciasController.php'
                + '?desde=' + encodeURIComponent(desde)
                + '&hasta=' + encodeURIComponent(hasta)
                + '&rango=' + encodeURIComponent(rangoActivo)
                + '&casa=' + encodeURIComponent(filtroCasa ? filtroCasa.value : 'TODAS');
            const respuesta = await fetch(url);

            if (numero !== peticion) return;
            if (!respuesta.ok) { seccion.hidden = respuesta.status === 403; return; }

            const json = await respuesta.json();
            if (!json.ok) return;

            datos = json;
            pintar();
        } catch (e) {
            // La seccion de ganancias es un extra; si falla, el tablero sigue igual.
        } finally {
            if (numero === peticion) seccion.classList.remove('cargando');
        }
    }

    // ---------- Pintado ----------
    function pintar() {
        pintarKpis();
        graficaGanancia();
        tablaPorProducto();
        tablaPorCasa();
    }

    function delta(actual, anterior) {
        if (anterior === 0) {
            return actual === 0
                ? '<span class="kpi-delta igual">Sin cambio</span>'
                : '<span class="kpi-delta igual">Sin datos antes</span>';
        }
        const cambio = (actual - anterior) / anterior * 100;
        if (Math.abs(cambio) < 0.05) return '<span class="kpi-delta igual">Igual</span>';
        const redondeo = Math.abs(cambio) < 10 ? 1 : 0;
        const texto = Math.abs(cambio).toLocaleString('es-MX', { maximumFractionDigits: redondeo }) + '%';
        return cambio > 0
            ? '<span class="kpi-delta mejor"><i class="ph ph-arrow-up"></i>' + texto + '</span>'
            : '<span class="kpi-delta peor"><i class="ph ph-arrow-down"></i>' + texto + '</span>';
    }

    function pintarKpis() {
        const r = datos.resumen;
        const a = datos.anterior;
        const vs = ' vs periodo anterior';

        const kpi = (icono, etiqueta, valor, detalle) =>
            '<div class="kpi">' +
                '<p class="kpi-etiqueta"><i class="ph ' + icono + '"></i>' + etiqueta + '</p>' +
                '<p class="kpi-valor">' + valor + '</p>' +
                '<p class="kpi-detalle">' + detalle + '</p>' +
            '</div>';

        kpis.innerHTML =
            kpi('ph-trend-up', 'Ganancia real', money(r.ganancia),
                delta(r.ganancia, a.ganancia) + vs) +
            kpi('ph-percent', 'Margen sobre la venta', r.neto > 0 ? pct(r.margen) : '—',
                'de cada venta, esto es ganancia') +
            kpi('ph-coins', 'Costo (bruto)', money(r.costo),
                'lo que costó la mercancía vendida') +
            kpi('ph-currency-dollar-simple', 'Neto vendido', money(r.neto),
                'costo ' + money(r.costo) + ' + ganancia ' + money(r.ganancia));
    }

    function graficaGanancia() {
        let serie = datos.serie;

        if (datos.agrupacion === 'hora') {
            const conDato = serie.map((s, i) => (s.ganancia > 0 ? i : -1)).filter((i) => i >= 0);
            const inicio = Math.min(7, conDato.length ? conDato[0] : 7);
            const fin = Math.max(21, conDato.length ? conDato[conDato.length - 1] : 21);
            serie = serie.slice(inicio, fin + 1);
        }

        const variosAnios = datos.desde.slice(0, 4) !== datos.hasta.slice(0, 4);
        const pocosDias = datos.agrupacion === 'dia' && serie.length <= 7;
        const etiquetas = serie.map((s) => etiquetaCubeta(s.clave, datos.agrupacion, variosAnios, pocosDias));

        if (subGrafica) subGrafica.textContent = 'Ganancia por ' + nombreAgrupacion[datos.agrupacion];

        // Tabla gemela (se ve con el boton "Tabla" que ya cablea estadisticas.js).
        const tablaHtml = tabla(
            [{ texto: 'Periodo' }, { texto: 'Neto', num: true }, { texto: 'Ganancia', num: true }],
            serie.filter((s) => s.ganancia > 0 || s.neto > 0).map((s) => [
                esc(tituloCubeta(s.clave, datos.agrupacion)), money(s.neto), money(s.ganancia),
            ])
        );
        const destinoTabla = document.getElementById('tabla-ganancias');
        if (destinoTabla) destinoTabla.innerHTML = tablaHtml;

        const vacio = document.querySelector('#grafica-ganancias .dash-vacio');
        if (vacio) {
            const hay = datos.resumen.ganancia !== 0 || datos.resumen.neto !== 0;
            vacio.textContent = hay ? '' : 'No hay ventas en este periodo.';
            vacio.hidden = hay;
        }

        if (!hayGraficas) return;

        const canvas = document.getElementById('canvas-ganancias');
        if (!canvas) return;
        if (grafica) grafica.destroy();

        grafica = new Chart(canvas, {
            type: 'bar',
            data: {
                labels: etiquetas,
                datasets: [{
                    label: 'Ganancia',
                    data: serie.map((s) => s.ganancia),
                    backgroundColor: color.ganancia,
                    hoverBackgroundColor: '#004a00',
                    borderRadius: 4,
                    borderSkipped: 'start',
                    maxBarThickness: 36,
                    categoryPercentage: 0.85,
                    barPercentage: 0.9,
                }],
            },
            options: {
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                scales: {
                    x: { grid: { display: false }, ticks: { color: color.tenue, maxRotation: 0, autoSkipPadding: 10 } },
                    y: { beginAtZero: true, grid: { color: color.reticula }, ticks: { color: color.tenue, maxTicksLimit: 6, callback: (v) => moneyCorto(v) } },
                },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        displayColors: false,
                        callbacks: {
                            title: (items) => tituloCubeta(serie[items[0].dataIndex].clave, datos.agrupacion),
                            label: (item) => {
                                const s = serie[item.dataIndex];
                                return ['Ganancia: ' + money(s.ganancia), 'Neto vendido: ' + money(s.neto)];
                            },
                        },
                    },
                },
            },
        });
    }

    function tablaPorProducto() {
        const lista = datos.top_productos;
        const maximo = lista.length ? lista[0].ganancia : 0;

        tablaProductos.innerHTML = tabla(
            [{ texto: '#' }, { texto: 'Producto', ancha: true }, { texto: '%', num: true },
             { texto: 'Piezas', num: true }, { texto: 'Neto', num: true }, { texto: 'Ganancia', num: true },
             { texto: '', extra: true }],
            lista.map((p, i) => [
                i + 1,
                esc(recortar(p.nombre, 60)) +
                    '<span class="detalle-sub">' + esc(codigoVisible(p)) + ' · ' + etiquetaCasaHtml(p.codigo_casa, p.casa) + '</span>',
                pct(p.porcentaje),
                entero(p.piezas),
                money(p.neto),
                money(p.ganancia),
                '<div class="barra-celda">' + barra(p.ganancia, maximo) + '</div>',
            ])
        );
    }

    function tablaPorCasa() {
        if (!tablaCasas) return;
        const lista = datos.por_casa;
        const maximo = lista.length ? Math.max(...lista.map((c) => c.ganancia)) : 0;

        tablaCasas.innerHTML = tabla(
            [{ texto: 'Casa', ancha: true }, { texto: 'Margen', num: true }, { texto: 'Ganancia', num: true },
             { texto: '', extra: true }],
            lista.map((c) => [
                etiquetaCasaHtml(c.codigo_casa, c.casa) + '<span class="detalle-sub">neto ' + money(c.neto) + '</span>',
                pct(c.margen),
                money(c.ganancia),
                '<div class="barra-celda">' + barra(c.ganancia, maximo) + '</div>',
            ])
        );
    }

    // ---------- Eventos (aditivos, no reemplazan a estadisticas.js) ----------
    chips.forEach((chip) => {
        chip.addEventListener('click', () => {
            rangoActivo = chip.dataset.rango;
            const [desde, hasta] = rangoDe(rangoActivo);
            // estadisticas.js tambien escribe estos inputs; los fijamos por si
            // nuestro manejador corre primero.
            inputDesde.value = desde;
            inputHasta.value = hasta;
            cargar();
        });
    });

    if (btnFiltrar) {
        btnFiltrar.addEventListener('click', () => {
            if (!inputDesde.value) return;
            rangoActivo = 'personalizado';
            cargar();
        });
    }

    if (filtroCasa) filtroCasa.addEventListener('change', cargar);

    // Arranca en "hoy", igual que el tablero.
    const [hoyDesde, hoyHasta] = rangoDe('hoy');
    if (!inputDesde.value) inputDesde.value = hoyDesde;
    if (!inputHasta.value) inputHasta.value = hoyHasta;
    cargar();
});
