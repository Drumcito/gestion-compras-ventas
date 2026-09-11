document.addEventListener('DOMContentLoaded', () => {
    const contenido  = document.getElementById('dash-contenido');
    if (!contenido) return;

    const raiz        = document.querySelector('.main-dashboard');
    const inputDesde  = document.getElementById('desde');
    const inputHasta  = document.getElementById('hasta');
    const filtroCasa  = document.getElementById('filtro-casa');
    const btnFiltrar  = document.getElementById('btn-filtrar');
    const chips       = document.querySelectorAll('.filtros-rapidos .chip');
    const aviso       = document.getElementById('aviso-dashboard');
    const periodo     = document.getElementById('dash-periodo');
    const kpis        = document.getElementById('kpis');

    const hayGraficas = typeof window.Chart !== 'undefined';

    // ---------- Utilidades ----------
    const money = (n) => '$' + Number(n).toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const entero = (n) => Number(n).toLocaleString('es-MX', { maximumFractionDigits: 0 });

    // Para los ejes: $12k en lugar de $12,000.00.
    const moneyCorto = (n) => {
        const abs = Math.abs(n);
        if (abs >= 1e6) return '$' + (n / 1e6).toLocaleString('es-MX', { maximumFractionDigits: 1 }) + 'M';
        if (abs >= 1e3) return '$' + (n / 1e3).toLocaleString('es-MX', { maximumFractionDigits: 1 }) + 'k';
        return '$' + entero(n);
    };

    const iso = (fecha) => {
        const f = new Date(fecha.getTime() - fecha.getTimezoneOffset() * 60000);
        return f.toISOString().slice(0, 10);
    };

    // 'YYYY-MM-DD' -> Date local (sin que la zona horaria lo mueva de dia).
    const aFecha = (texto) => {
        const [a, m, d] = texto.split('-').map(Number);
        return new Date(a, m - 1, d || 1);
    };

    const fechaCorta = (texto) => {
        const [a, m, d] = texto.split('-');
        return `${d}/${m}/${a}`;
    };

    function esc(texto) {
        const div = document.createElement('div');
        div.textContent = texto === null || texto === undefined ? '' : texto;
        return div.innerHTML;
    }

    function recortar(texto, maximo) {
        return texto.length > maximo ? texto.slice(0, maximo - 1).trimEnd() + '…' : texto;
    }

    function mostrarAviso(texto, tipo) {
        aviso.textContent = texto;
        aviso.className = 'aviso dash-aviso aviso-' + tipo;
        aviso.hidden = false;
    }

    // ---------- Colores (salen del CSS, un solo lugar para cambiarlos) ----------
    const estilo = getComputedStyle(raiz);
    const estiloRaiz = getComputedStyle(document.documentElement);
    const token = (nombre) => estilo.getPropertyValue(nombre).trim();

    const color = {
        texto:    token('--dash-texto'),
        texto2:   token('--dash-texto-2'),
        tenue:    token('--dash-tenue'),
        reticula: token('--dash-reticula'),
        eje:      token('--dash-eje'),
        ventas:   token('--dash-ventas'),
        sube:     token('--dash-sube'),
        baja:     token('--dash-baja'),
        apagado:  '#d6d5cf',
    };

    // El color sigue a la casa, no a su lugar en la lista: Diplomex es azul en
    // todas las graficas aunque cambie el filtro o el orden.
    const colorCasa = (codigo) => estiloRaiz.getPropertyValue('--casa-' + codigo).trim() || color.tenue;

    // En tablet vertical todo el texto de la pagina es mas grande.
    const esTabletVertical = window.matchMedia('(min-width: 769px) and (max-width: 1024px) and (orientation: portrait)');
    const tamLetra = () => (esTabletVertical.matches ? 15 : 12);

    // ---------- Configuracion comun de Chart.js ----------
    const familia = getComputedStyle(document.body).fontFamily;

    if (hayGraficas) {
        Chart.defaults.font.family = familia;
        Chart.defaults.font.size = tamLetra();
        Chart.defaults.color = color.texto2;
        Chart.defaults.borderColor = color.reticula;
        Chart.defaults.animation.duration = 300;
        Chart.defaults.maintainAspectRatio = false;
        Chart.defaults.plugins.legend.display = false; // leyendas propias en HTML

        Object.assign(Chart.defaults.plugins.tooltip, {
            backgroundColor: '#1f2023',
            titleColor: '#ffffff',
            bodyColor: '#e8e8e4',
            padding: 10,
            cornerRadius: 8,
            boxWidth: 10,
            boxHeight: 10,
            boxPadding: 4,
            usePointStyle: false,
        });
    }

    // Numero al final de cada barra horizontal. Se dibuja solo si hay espacio
    // (el espacio lo reserva layout.padding.right).
    const etiquetasBarras = {
        id: 'etiquetasBarras',
        afterDatasetsDraw(chart, args, opciones) {
            if (!opciones || !opciones.formato) return;
            const { ctx } = chart;
            ctx.save();
            ctx.font = `600 ${tamLetra()}px ${familia}`;
            ctx.fillStyle = color.texto;
            ctx.textBaseline = 'middle';
            chart.getDatasetMeta(0).data.forEach((barra, i) => {
                const valor = chart.data.datasets[0].data[i];
                if (!valor) return;
                ctx.fillText(opciones.formato(valor, i), barra.x + 6, barra.y);
            });
            ctx.restore();
        },
    };

    function anchoTexto(textos) {
        const lienzo = anchoTexto.lienzo || (anchoTexto.lienzo = document.createElement('canvas'));
        const ctx = lienzo.getContext('2d');
        ctx.font = `600 ${tamLetra()}px ${familia}`;
        return Math.max(0, ...textos.map((t) => ctx.measureText(t).width));
    }

    const ejeRecesivo = {
        grid: { color: color.reticula, drawTicks: false },
        border: { color: color.eje },
        ticks: { color: color.tenue, padding: 6 },
    };

    // ---------- Estado ----------
    const graficas = {};
    const tablas = {};
    const metricas = { casas: 'piezas', productos: 'piezas' };
    let datos = null;
    let rangoActivo = 'hoy';
    let peticion = 0;

    // ---------- Rangos rapidos ----------
    function rangoDe(tipo) {
        const hoy = new Date();

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

    // ---------- Etiquetas de las cubetas de tiempo ----------
    function etiquetaCubeta(clave, agrupacion, variosAnios, pocosDias) {
        if (agrupacion === 'hora') {
            return clave.slice(11, 13) + ':00';
        }
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
            return `${String(h).padStart(2, '0')}:00 – ${String(h + 1).padStart(2, '0')}:00`;
        }
        if (agrupacion === 'mes') {
            return aFecha(clave + '-01').toLocaleDateString('es-MX', { month: 'long', year: 'numeric' });
        }
        if (agrupacion === 'semana') {
            const lunes = aFecha(clave);
            const domingo = new Date(lunes);
            domingo.setDate(lunes.getDate() + 6);
            const f = (d) => d.toLocaleDateString('es-MX', { day: 'numeric', month: 'short' });
            return `Semana del ${f(lunes)} al ${f(domingo)}`;
        }
        return aFecha(clave).toLocaleDateString('es-MX', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
    }

    const nombreAgrupacion = { hora: 'hora', dia: 'día', semana: 'semana', mes: 'mes' };

    // ---------- Carga ----------
    async function cargar() {
        const desde = inputDesde.value;
        const hasta = inputHasta.value || desde;
        const numero = ++peticion;

        contenido.classList.add('cargando');
        aviso.hidden = true;

        try {
            const url = '../../app/controllers/EstadisticasController.php'
                + '?desde=' + encodeURIComponent(desde)
                + '&hasta=' + encodeURIComponent(hasta)
                + '&rango=' + encodeURIComponent(rangoActivo)
                + '&casa=' + encodeURIComponent(filtroCasa.value);
            const respuesta = await fetch(url);

            // Si el usuario cambio el filtro mientras tanto, esta respuesta ya
            // no sirve: se ignora para no pintar un periodo viejo encima.
            if (numero !== peticion) return;

            if (respuesta.status === 401) {
                mostrarAviso('Tu sesión expiró. Vuelve a iniciar sesión.', 'error');
                return;
            }

            const json = await respuesta.json();

            if (!json.ok) {
                mostrarAviso(json.error || 'No se pudieron cargar las estadísticas.', 'error');
                return;
            }

            datos = json;
            pintarTodo();

        } catch (e) {
            if (numero === peticion) {
                mostrarAviso('Error de conexión al cargar las estadísticas.', 'error');
            }
        } finally {
            if (numero === peticion) contenido.classList.remove('cargando');
        }
    }

    function pintarTodo() {
        pintarPeriodo();
        pintarKpis();
        graficaVentas();
        graficaCasas();
        graficaProductos();
        graficaPrecios();
        tablaCambiosPrecio();
        tablaClientes();
        tablaVendedores();

        if (!hayGraficas) {
            mostrarAviso('No se pudo cargar la librería de gráficas (revisa la conexión a internet). ' +
                'Se muestran los datos en tablas.', 'error');
        }
    }

    // ---------- Periodo ----------
    function pintarPeriodo() {
        const rango = datos.desde === datos.hasta
            ? fechaCorta(datos.desde)
            : 'Del ' + fechaCorta(datos.desde) + ' al ' + fechaCorta(datos.hasta);

        const casa = datos.casa === 'TODAS'
            ? 'todas las casas'
            : (datos.casas.find((c) => c.codigo_casa === datos.casa) || {}).nombre;

        periodo.textContent = rango + ' · ' + casa + ' · gráficas por ' + nombreAgrupacion[datos.agrupacion];
    }

    // ---------- Indicadores ----------
    function delta(actual, anterior) {
        if (anterior === 0) {
            return actual === 0
                ? '<span class="kpi-delta igual">Sin cambio</span>'
                : '<span class="kpi-delta igual">Sin datos antes</span>';
        }
        const cambio = (actual - anterior) / anterior * 100;
        const redondeo = Math.abs(cambio) < 10 ? 1 : 0;
        const texto = Math.abs(cambio).toLocaleString('es-MX', { maximumFractionDigits: redondeo }) + '%';

        if (Math.abs(cambio) < 0.05) return '<span class="kpi-delta igual">Igual</span>';

        return cambio > 0
            ? '<span class="kpi-delta mejor"><i class="ph ph-arrow-up"></i>' + texto + '</span>'
            : '<span class="kpi-delta peor"><i class="ph ph-arrow-down"></i>' + texto + '</span>';
    }

    function pintarKpis() {
        const r = datos.resumen;
        const a = datos.anterior;
        const vs = ' vs ' + (a.desde === a.hasta ? fechaCorta(a.desde) : fechaCorta(a.desde) + ' – ' + fechaCorta(a.hasta));

        const ticket = r.ventas > 0 ? r.total / r.ventas : 0;
        const ticketAnterior = a.ventas > 0 ? a.total / a.ventas : 0;
        const pctContado = r.total > 0 ? Math.round(r.contado / r.total * 100) : 0;
        const p = datos.precios;

        const kpi = (icono, etiqueta, valor, detalle, titulo) =>
            '<div class="kpi"' + (titulo ? ' title="' + esc(titulo) + '"' : '') + '>' +
                '<p class="kpi-etiqueta"><i class="ph ' + icono + '"></i>' + etiqueta + '</p>' +
                '<p class="kpi-valor">' + valor + '</p>' +
                '<p class="kpi-detalle">' + detalle + '</p>' +
            '</div>';

        kpis.innerHTML =
            kpi('ph-currency-dollar-simple', 'Total vendido', money(r.total),
                delta(r.total, a.total) + vs) +
            kpi('ph-receipt', 'Ventas', entero(r.ventas),
                delta(r.ventas, a.ventas) + ' · ticket prom. ' + money(ticket),
                'Ticket promedio antes: ' + money(ticketAnterior)) +
            kpi('ph-package', 'Piezas vendidas', entero(r.piezas),
                delta(r.piezas, a.piezas) + vs) +
            kpi('ph-wallet', 'Contado / crédito', r.total > 0 ? pctContado + '% contado' : '—',
                money(r.contado) + ' contado · ' + money(r.credito) + ' crédito') +
            kpi('ph-hourglass-medium', 'Por cobrar', money(r.por_cobrar),
                r.ventas_con_saldo + ' venta' + (r.ventas_con_saldo === 1 ? '' : 's') + ' a crédito con saldo' +
                (datos.casa !== 'TODAS' ? ' (todas las casas)' : '')) +
            kpi('ph-tag', 'Cambios de precio',
                '<span class="kpi-sube"><i class="ph ph-arrow-up"></i>' + entero(p.subidas) + '</span>' +
                ' &nbsp;<span class="kpi-baja"><i class="ph ph-arrow-down"></i>' + entero(p.bajadas) + '</span>',
                'subidas y bajadas en ' + entero(p.productos) + ' producto' + (p.productos === 1 ? '' : 's'));
    }

    // ---------- Piezas de cada tarjeta ----------
    function nuevaGrafica(id, config) {
        if (graficas[id]) graficas[id].destroy();
        const canvas = document.getElementById('canvas-' + id);
        graficas[id] = new Chart(canvas, config);
    }

    function vacio(id, texto) {
        const caja = document.querySelector('#grafica-' + id + ' .dash-vacio');
        caja.textContent = texto || '';
        caja.hidden = !texto;
    }

    function leyenda(id, items) {
        document.getElementById('leyenda-' + id).innerHTML = items.map((i) =>
            '<span class="leyenda-item"><span class="leyenda-muestra" style="background:' + i.color + '"></span>' +
            esc(i.texto) + '</span>'
        ).join('');
    }

    function describir(id, texto) {
        const canvas = document.getElementById('canvas-' + id);
        canvas.setAttribute('role', 'img');
        canvas.setAttribute('aria-label', texto);
    }

    // encabezados: [{ texto, num (alinear a la derecha), ancha (columna de
    // nombre), extra (se oculta en celular) }]
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

    // Nombre de producto en dos renglones para el eje de la grafica: muchos
    // productos empiezan igual ("ABRAZADERA ACERO...") y con uno solo no se
    // distinguen.
    function dosRenglones(texto, maximo) {
        if (texto.length <= maximo) return texto;
        const corte = texto.lastIndexOf(' ', maximo);
        const fin = corte > maximo * 0.5 ? corte : maximo;
        return [texto.slice(0, fin).trim(), recortar(texto.slice(fin).trim(), maximo)];
    }

    const etiquetaCasa = (codigo, nombre) =>
        '<span class="res-casa casa-' + esc(codigo) + '">' + esc(nombre) + '</span>';

    function refrescarTabla(id) {
        if (tablas[id]) document.getElementById('tabla-' + id).innerHTML = tablas[id]();
    }

    // ---------- Ventas en el tiempo ----------
    function graficaVentas() {
        let serie = datos.serie_ventas;

        // Por hora, se recortan las horas vacias de la madrugada y la noche,
        // pero siempre se deja al menos de 7:00 a 21:00.
        if (datos.agrupacion === 'hora') {
            const conVenta = serie.map((s, i) => (s.total > 0 ? i : -1)).filter((i) => i >= 0);
            const inicio = Math.min(7, conVenta.length ? conVenta[0] : 7);
            const fin = Math.max(21, conVenta.length ? conVenta[conVenta.length - 1] : 21);
            serie = serie.slice(inicio, fin + 1);
        }

        const variosAnios = datos.desde.slice(0, 4) !== datos.hasta.slice(0, 4);
        const pocosDias = datos.agrupacion === 'dia' && serie.length <= 7;
        const etiquetas = serie.map((s) => etiquetaCubeta(s.clave, datos.agrupacion, variosAnios, pocosDias));

        document.getElementById('sub-ventas').textContent =
            'Importe vendido por ' + nombreAgrupacion[datos.agrupacion];

        tablas.ventas = () => tabla(
            [{ texto: 'Periodo' }, { texto: 'Ventas', num: true }, { texto: 'Piezas', num: true }, { texto: 'Importe', num: true }],
            serie.filter((s) => s.total > 0).map((s) => [
                esc(tituloCubeta(s.clave, datos.agrupacion)), entero(s.ventas), entero(s.piezas), money(s.total),
            ])
        );
        refrescarTabla('ventas');

        const mejor = serie.reduce((m, s) => (s.total > m.total ? s : m), { total: 0 });
        describir('ventas', 'Gráfica de barras del importe vendido por ' + nombreAgrupacion[datos.agrupacion] +
            (mejor.total > 0 ? '. El más alto: ' + tituloCubeta(mejor.clave, datos.agrupacion) + ' con ' + money(mejor.total) : ''));

        vacio('ventas', datos.resumen.ventas === 0 ? 'No hay ventas en este periodo.' : '');
        if (!hayGraficas) return;

        nuevaGrafica('ventas', {
            type: 'bar',
            data: {
                labels: etiquetas,
                datasets: [{
                    label: 'Importe',
                    data: serie.map((s) => s.total),
                    backgroundColor: color.ventas,
                    hoverBackgroundColor: '#a86200',
                    borderRadius: 4,
                    borderSkipped: 'start',
                    maxBarThickness: 36,
                    categoryPercentage: 0.85,
                    barPercentage: 0.9,
                }],
            },
            options: {
                interaction: { mode: 'index', intersect: false },
                scales: {
                    x: { ...ejeRecesivo, grid: { display: false }, ticks: { ...ejeRecesivo.ticks, maxRotation: 0, autoSkipPadding: 10 } },
                    y: { ...ejeRecesivo, beginAtZero: true, ticks: { ...ejeRecesivo.ticks, maxTicksLimit: 6, callback: (v) => moneyCorto(v) } },
                },
                plugins: {
                    tooltip: {
                        displayColors: false,
                        callbacks: {
                            title: (items) => tituloCubeta(serie[items[0].dataIndex].clave, datos.agrupacion),
                            label: (item) => {
                                const s = serie[item.dataIndex];
                                return [money(s.total), entero(s.ventas) + ' venta' + (s.ventas === 1 ? '' : 's') +
                                    ' · ' + entero(s.piezas) + ' pieza' + (s.piezas === 1 ? '' : 's')];
                            },
                        },
                    },
                },
            },
        });
    }

    // ---------- Ventas por casa ----------
    function graficaCasas() {
        const metrica = metricas.casas;
        const casas = datos.por_casa;
        const total = casas.reduce((s, c) => s + c[metrica], 0);
        const formato = (v) => (metrica === 'importe' ? moneyCorto(v) : entero(v));
        const porcentaje = (v) => (total > 0 ? Math.round(v / total * 100) : 0) + '%';

        // Con una casa filtrada, esa conserva su color y las demas se apagan:
        // se sigue viendo contra quien se compara.
        const colores = casas.map((c) =>
            (datos.casa === 'TODAS' || datos.casa === c.codigo_casa) ? colorCasa(c.codigo_casa) : color.apagado);

        document.getElementById('sub-casas').textContent =
            (metrica === 'importe' ? 'Importe vendido' : 'Piezas vendidas') + ' de cada casa y su parte del total' +
            (datos.casa !== 'TODAS' ? ' (la casa filtrada, resaltada)' : '');

        tablas.casas = () => tabla(
            [{ texto: 'Casa' }, { texto: 'Ventas', num: true }, { texto: 'Productos', num: true },
             { texto: 'Piezas', num: true }, { texto: 'Importe', num: true }, { texto: 'Parte', num: true }],
            casas.map((c) => [
                etiquetaCasa(c.codigo_casa, c.nombre), entero(c.ventas), entero(c.productos),
                entero(c.piezas), money(c.importe), porcentaje(c[metrica]),
            ])
        );
        refrescarTabla('casas');

        const lider = casas.reduce((m, c) => (c[metrica] > m[metrica] ? c : m), casas[0] || {});
        describir('casas', 'Gráfica de barras de ' + metrica + ' vendidas por casa' +
            (lider && lider[metrica] > 0 ? '. La casa con más: ' + lider.nombre + ' (' + porcentaje(lider[metrica]) + ')' : ''));

        vacio('casas', total === 0 ? 'No hay ventas en este periodo.' : '');
        if (!hayGraficas) return;

        const textos = casas.map((c) => formato(c[metrica]) + ' · ' + porcentaje(c[metrica]));

        nuevaGrafica('casas', {
            type: 'bar',
            plugins: [etiquetasBarras],
            data: {
                labels: casas.map((c) => c.nombre),
                datasets: [{
                    label: metrica === 'importe' ? 'Importe' : 'Piezas',
                    data: casas.map((c) => c[metrica]),
                    backgroundColor: colores,
                    borderRadius: 4,
                    borderSkipped: 'start',
                    maxBarThickness: 34,
                    categoryPercentage: 0.8,
                }],
            },
            options: {
                indexAxis: 'y',
                layout: { padding: { right: anchoTexto(textos) + 12 } },
                interaction: { mode: 'nearest', axis: 'y', intersect: false },
                scales: {
                    x: { ...ejeRecesivo, beginAtZero: true, ticks: { ...ejeRecesivo.ticks, maxTicksLimit: 5, callback: (v) => formato(v) } },
                    y: { ...ejeRecesivo, grid: { display: false }, ticks: { color: color.texto, padding: 6 } },
                },
                plugins: {
                    etiquetasBarras: { formato: (v, i) => textos[i] },
                    tooltip: {
                        callbacks: {
                            label: (item) => {
                                const c = casas[item.dataIndex];
                                return [
                                    ' ' + money(c.importe) + ' · ' + entero(c.piezas) + ' piezas',
                                    ' ' + entero(c.ventas) + ' ventas · ' + entero(c.productos) + ' productos distintos',
                                ];
                            },
                        },
                    },
                },
            },
        });
    }

    // ---------- Productos mas vendidos ----------
    function graficaProductos() {
        const metrica = metricas.productos;
        const lista = metrica === 'importe' ? datos.top_importe : datos.top_piezas;
        const formato = (v) => (metrica === 'importe' ? money(v) : entero(v) + ' pzas');
        const ancho = document.getElementById('grafica-productos').clientWidth;
        const maximo = ancho < 420 ? 16 : (ancho < 620 ? 22 : 30);

        document.getElementById('sub-productos').textContent =
            'Top 10 por ' + (metrica === 'importe' ? 'importe' : 'piezas') + ' · el color indica la casa';

        // Leyenda: solo las casas que aparecen, en el orden fijo de las casas.
        const presentes = datos.casas.filter((c) => lista.some((p) => p.codigo_casa === c.codigo_casa));
        leyenda('productos', presentes.map((c) => ({ color: colorCasa(c.codigo_casa), texto: c.nombre })));

        tablas.productos = () => tabla(
            [{ texto: '#' }, { texto: 'Producto', ancha: true }, { texto: 'Casa' }, { texto: 'Piezas', num: true },
             { texto: 'Importe', num: true }, { texto: 'Ventas', num: true }],
            lista.map((p, i) => [
                i + 1,
                esc(p.nombre) + '<span class="detalle-sub">' + esc(p.codigo) + '</span>',
                etiquetaCasa(p.codigo_casa, p.casa), entero(p.piezas), money(p.importe), entero(p.ventas),
            ])
        );
        refrescarTabla('productos');

        describir('productos', 'Gráfica de barras de los productos más vendidos por ' + metrica +
            (lista[0] ? '. El primero: ' + lista[0].nombre + ' con ' + formato(lista[0][metrica]) : ''));

        vacio('productos', lista.length === 0 ? 'No hay ventas en este periodo.' : '');
        if (!hayGraficas) return;

        const textos = lista.map((p) => formato(p[metrica]));

        nuevaGrafica('productos', {
            type: 'bar',
            plugins: [etiquetasBarras],
            data: {
                labels: lista.map((p) => dosRenglones(p.nombre, maximo)),
                datasets: [{
                    label: metrica === 'importe' ? 'Importe' : 'Piezas',
                    data: lista.map((p) => p[metrica]),
                    backgroundColor: lista.map((p) => colorCasa(p.codigo_casa)),
                    borderRadius: 4,
                    borderSkipped: 'start',
                    maxBarThickness: 22,
                    categoryPercentage: 0.8,
                }],
            },
            options: {
                indexAxis: 'y',
                layout: { padding: { right: anchoTexto(textos) + 12 } },
                interaction: { mode: 'nearest', axis: 'y', intersect: false },
                scales: {
                    x: { ...ejeRecesivo, beginAtZero: true, ticks: { ...ejeRecesivo.ticks, maxTicksLimit: 5,
                        callback: (v) => (metrica === 'importe' ? moneyCorto(v) : entero(v)) } },
                    y: { ...ejeRecesivo, grid: { display: false }, ticks: { color: color.texto, padding: 6, autoSkip: false } },
                },
                plugins: {
                    etiquetasBarras: { formato: (v, i) => textos[i] },
                    tooltip: {
                        callbacks: {
                            title: (items) => lista[items[0].dataIndex].nombre,
                            label: (item) => {
                                const p = lista[item.dataIndex];
                                return [
                                    ' ' + p.casa + ' · ' + p.codigo,
                                    ' ' + entero(p.piezas) + ' piezas · ' + money(p.importe),
                                    ' en ' + entero(p.ventas) + ' venta' + (p.ventas === 1 ? '' : 's'),
                                ];
                            },
                        },
                    },
                },
            },
        });
    }

    // ---------- Cambios de precio en el tiempo ----------
    // Barras divergentes: las subidas hacia arriba y las bajadas hacia abajo,
    // sobre el mismo eje (numero de cambios).
    function graficaPrecios() {
        let serie = datos.precios.serie;
        const p = datos.precios;

        if (datos.agrupacion === 'hora') {
            const conCambio = serie.map((s, i) => (s.subidas + s.bajadas > 0 ? i : -1)).filter((i) => i >= 0);
            const inicio = Math.min(7, conCambio.length ? conCambio[0] : 7);
            const fin = Math.max(21, conCambio.length ? conCambio[conCambio.length - 1] : 21);
            serie = serie.slice(inicio, fin + 1);
        }

        const variosAnios = datos.desde.slice(0, 4) !== datos.hasta.slice(0, 4);
        const pocosDias = datos.agrupacion === 'dia' && serie.length <= 7;

        leyenda('precios', [
            { color: color.sube, texto: '↑ Subió (' + entero(p.subidas) + ')' },
            { color: color.baja, texto: '↓ Bajó (' + entero(p.bajadas) + ')' },
        ]);

        tablas.precios = () => tabla(
            [{ texto: 'Periodo' }, { texto: '↑ Subió', num: true }, { texto: '↓ Bajó', num: true }],
            serie.filter((s) => s.subidas + s.bajadas > 0).map((s) => [
                esc(tituloCubeta(s.clave, datos.agrupacion)), entero(s.subidas), entero(s.bajadas),
            ])
        );
        refrescarTabla('precios');

        describir('precios', 'Gráfica de barras de cambios de precio: ' + p.subidas + ' subidas y ' + p.bajadas + ' bajadas');

        vacio('precios', p.subidas + p.bajadas === 0 ? 'No hubo cambios de precio en este periodo.' : '');
        if (!hayGraficas) return;

        const comun = {
            borderRadius: 4,
            borderSkipped: 'start',
            maxBarThickness: 36,
            categoryPercentage: 0.85,
            barPercentage: 0.9,
        };

        nuevaGrafica('precios', {
            type: 'bar',
            data: {
                labels: serie.map((s) => etiquetaCubeta(s.clave, datos.agrupacion, variosAnios, pocosDias)),
                datasets: [
                    { ...comun, label: 'Subió', data: serie.map((s) => s.subidas), backgroundColor: color.sube },
                    { ...comun, label: 'Bajó', data: serie.map((s) => -s.bajadas), backgroundColor: color.baja },
                ],
            },
            options: {
                interaction: { mode: 'index', intersect: false },
                scales: {
                    x: { ...ejeRecesivo, stacked: true, grid: { display: false }, ticks: { ...ejeRecesivo.ticks, maxRotation: 0, autoSkipPadding: 10 } },
                    y: { ...ejeRecesivo, stacked: true, ticks: { ...ejeRecesivo.ticks, precision: 0, maxTicksLimit: 7, callback: (v) => entero(Math.abs(v)) },
                         grid: { color: (ctx) => (ctx.tick && ctx.tick.value === 0 ? color.eje : color.reticula), drawTicks: false } },
                },
                plugins: {
                    tooltip: {
                        callbacks: {
                            title: (items) => tituloCubeta(serie[items[0].dataIndex].clave, datos.agrupacion),
                            label: (item) => ' ' + item.dataset.label + ': ' + entero(Math.abs(item.raw)) + ' ' +
                                (Math.abs(item.raw) === 1 ? 'vez' : 'veces'),
                        },
                    },
                },
            },
        });
    }

    // ---------- Tablas de detalle ----------
    function tablaCambiosPrecio() {
        const top = datos.precios.top;
        const precio = (n) => (n === null ? '—' : money(n));

        document.getElementById('tabla-cambios-precio').innerHTML = tabla(
            [{ texto: 'Producto', ancha: true }, { texto: 'Casa' }, { texto: '↑ Subió', num: true }, { texto: '↓ Bajó', num: true },
             { texto: 'Variación en el periodo', num: true }, { texto: 'Precio actual', num: true }, { texto: 'Último cambio', num: true }],
            top.map((t) => {
                let variacion = '—';
                if (t.variacion) {
                    const v = t.variacion;
                    const clase = v.porcentaje > 0 ? 'kpi-sube' : (v.porcentaje < 0 ? 'kpi-baja' : '');
                    const flecha = v.porcentaje > 0 ? '<i class="ph ph-arrow-up"></i>' : (v.porcentaje < 0 ? '<i class="ph ph-arrow-down"></i>' : '');
                    variacion = '<span class="' + clase + '">' + flecha + Math.abs(v.porcentaje).toLocaleString('es-MX') + '%</span>' +
                        '<span class="detalle-sub">' + v.tipo + ': ' + money(v.inicial) + ' → ' + money(v.final) + '</span>';
                }
                return [
                    esc(t.nombre) + '<span class="detalle-sub">' + esc(t.codigo) + '</span>',
                    etiquetaCasa(t.codigo_casa, t.casa),
                    '<span class="kpi-sube">' + entero(t.subidas) + '</span>',
                    '<span class="kpi-baja">' + entero(t.bajadas) + '</span>',
                    variacion,
                    precio(t.precio_menudeo) + '<span class="detalle-sub">mayoreo ' + precio(t.precio_mayoreo) + '</span>',
                    fechaCorta(t.ultimo_cambio.slice(0, 10)),
                ];
            })
        );
    }

    function barra(valor, maximo) {
        const ancho = maximo > 0 ? Math.max(2, Math.round(valor / maximo * 100)) : 0;
        return '<div class="barra-pista"><div class="barra-relleno" style="width:' + ancho + '%"></div></div>';
    }

    function tablaClientes() {
        const lista = datos.clientes;
        const maximo = lista.length ? lista[0].importe : 0;

        document.getElementById('tabla-clientes').innerHTML = tabla(
            [{ texto: '#' }, { texto: 'Cliente', ancha: true }, { texto: 'Compras', num: true }, { texto: 'Importe', num: true },
             { texto: '', extra: true }],
            lista.map((c, i) => [
                i + 1,
                esc(c.nombre_cliente) + '<span class="detalle-sub">' + entero(c.piezas) + ' piezas</span>',
                entero(c.compras), money(c.importe),
                '<div class="barra-celda">' + barra(c.importe, maximo) + '</div>',
            ])
        );
    }

    function tablaVendedores() {
        const lista = datos.vendedores;
        const maximo = lista.length ? lista[0].importe : 0;

        document.getElementById('tabla-vendedores').innerHTML = tabla(
            [{ texto: 'Vendedor', ancha: true }, { texto: 'Ventas', num: true }, { texto: 'Importe', num: true },
             { texto: '', extra: true }],
            lista.map((v) => [
                esc(v.nombre.trim()) + '<span class="detalle-sub">' + esc(v.numero_empleado) + ' · ' + entero(v.piezas) + ' piezas</span>',
                entero(v.ventas), money(v.importe),
                '<div class="barra-celda">' + barra(v.importe, maximo) + '</div>',
            ])
        );
    }

    // ---------- Eventos ----------
    chips.forEach((chip) => {
        chip.addEventListener('click', () => {
            chips.forEach((c) => c.classList.remove('chip-activo'));
            chip.classList.add('chip-activo');

            rangoActivo = chip.dataset.rango;
            const [desde, hasta] = rangoDe(rangoActivo);
            inputDesde.value = desde;
            inputHasta.value = hasta;
            cargar();
        });
    });

    btnFiltrar.addEventListener('click', () => {
        if (!inputDesde.value) {
            mostrarAviso('Elige al menos la fecha "Desde".', 'error');
            return;
        }

        chips.forEach((c) => c.classList.remove('chip-activo'));
        rangoActivo = 'personalizado';
        cargar();
    });

    filtroCasa.addEventListener('change', cargar);

    // Piezas / Importe
    document.querySelectorAll('.segmentado').forEach((grupo) => {
        grupo.addEventListener('click', (e) => {
            const boton = e.target.closest('.segmento');
            if (!boton || !datos) return;

            grupo.querySelectorAll('.segmento').forEach((b) => {
                const activo = b === boton;
                b.classList.toggle('segmento-activo', activo);
                b.setAttribute('aria-pressed', activo ? 'true' : 'false');
            });

            metricas[grupo.dataset.grupo] = boton.dataset.metrica;
            if (grupo.dataset.grupo === 'casas') graficaCasas();
            if (grupo.dataset.grupo === 'productos') graficaProductos();
        });
    });

    // Grafica <-> tabla
    function mostrarTabla(id, verTabla) {
        const boton = document.querySelector('.btn-tabla[data-card="' + id + '"]');
        document.getElementById('grafica-' + id).hidden = verTabla;
        document.getElementById('leyenda-' + id).hidden = verTabla;
        document.getElementById('tabla-' + id).hidden = !verTabla;
        boton.setAttribute('aria-pressed', verTabla ? 'true' : 'false');
        boton.innerHTML = verTabla ? '<i class="ph ph-chart-bar"></i> Gráfica' : '<i class="ph ph-table"></i> Tabla';
    }

    document.querySelectorAll('.btn-tabla').forEach((boton) => {
        if (!hayGraficas) {
            // Sin libreria no hay grafica que ver: la tabla queda fija.
            mostrarTabla(boton.dataset.card, true);
            boton.hidden = true;
            return;
        }
        boton.addEventListener('click', () => {
            mostrarTabla(boton.dataset.card, boton.getAttribute('aria-pressed') !== 'true');
        });
    });

    // Al girar la tablet o cambiar el tamaño cambia el tamaño de letra y el
    // largo de los nombres: se vuelven a dibujar.
    // Solo cuenta el ancho: en el celular la barra del navegador cambia el alto
    // al hacer scroll y no hace falta redibujar por eso.
    let temporizador = null;
    let anchoAnterior = window.innerWidth;
    window.addEventListener('resize', () => {
        clearTimeout(temporizador);
        temporizador = setTimeout(() => {
            if (!datos || !hayGraficas || window.innerWidth === anchoAnterior) return;
            anchoAnterior = window.innerWidth;
            Chart.defaults.font.size = tamLetra();
            graficaVentas();
            graficaCasas();
            graficaProductos();
            graficaPrecios();
        }, 250);
    });

    // Arranca en el dia de hoy.
    const [hoyDesde, hoyHasta] = rangoDe('hoy');
    inputDesde.value = hoyDesde;
    inputHasta.value = hoyHasta;
    cargar();
});
