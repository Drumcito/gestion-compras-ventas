/* Catalogo en linea: carga todos los productos una vez y busca/filtra en el
   navegador, asi cada tecla actualiza al instante. Pinta de 48 en 48 conforme
   se baja (pintar 28 mil tarjetas de golpe trabaria el telefono). */
(function () {
    'use strict';

    var POR_PAGINA = 48;

    var main = document.getElementById('contenido');
    var MARCAS_RAPIDAS = parseInt(main.getAttribute('data-marcas-rapidas'), 10) || 10;
    var whatsapp = main.getAttribute('data-whatsapp') || '';
    var $ = function (id) { return document.getElementById(id); };
    var el = {
        busqueda: $('busqueda'), limpiar: $('limpiar-busqueda'), chips: $('filtros-rapidos'),
        marca: $('filtro-marca'), categoria: $('filtro-categoria'), orden: $('orden'),
        conteo: $('conteo'), quitar: $('quitar-filtros'), rejilla: $('rejilla'), centinela: $('centinela')
    };

    var productos = [];
    var rangoVendido = {};          // codigo -> lugar en el top de mas vendidos
    var resultado = [];
    var mostrados = 0;
    var tokens = [];
    var estado = { q: '', rapido: '', marca: '', categoria: '', orden: 'relevancia' };

    var DIBUJO = '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="16.5" cy="7.5" r="2"/>' +
                 '<path d="M2 19 8.5 10l4 5.5 2.5-3L22 19z"/></svg>';
    var ICONO_WA = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2a10 10 0 0 0-8.6 15.1L2 22l5-1.3A10 10 0 1 0 12 2Zm4.5 12.1c-.2-.1-1.5-.7-1.7-.8-.2-.1-.4-.1-.6.1l-.8 1c-.1.2-.3.2-.5.1a6.7 6.7 0 0 1-3.3-2.9c-.2-.4.2-.4.7-1.3.1-.2 0-.3 0-.4l-.8-1.8c-.2-.5-.4-.4-.6-.4h-.5a1 1 0 0 0-.7.3 3 3 0 0 0-.9 2.2 5.1 5.1 0 0 0 1.1 2.7 11.7 11.7 0 0 0 4.5 4c1.7.7 2.3.8 3.2.6.5-.1 1.5-.6 1.7-1.2.2-.6.2-1.1.2-1.2-.1-.1-.3-.2-.5-.3Z"/></svg>';

    // ---------- Utilidades ----------
    /** Minusculas y sin acentos: "Ñ" -> "n". Conserva la longitud del texto (NFC). */
    function normalizar(texto) {
        return String(texto || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
    }

    function escapar(texto) {
        return String(texto).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    // Orden alfabetico en espanol, sin tomar en cuenta signos (hay nombres que
    // empiezan con "-") y con numeros en orden natural (2 antes que 10).
    var COTEJO = new Intl.Collator('es', { ignorePunctuation: true, numeric: true });

    var formato0 = new Intl.NumberFormat('es-MX', { maximumFractionDigits: 0 });
    var formato2 = new Intl.NumberFormat('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    /** $1,299 sin centavos si son cerrados; $98.31 si los hay (igual que el PDF). */
    function dinero(n) {
        return '$' + (Math.abs(n - Math.round(n)) < 0.005 ? formato0 : formato2).format(n);
    }

    /** Resalta en el nombre lo que se busco. */
    function resaltar(texto, normalizado) {
        if (!tokens.length) {
            return escapar(texto);
        }
        var marcas = [];
        tokens.forEach(function (t) {
            var i = normalizado.indexOf(t);
            while (i !== -1) {
                marcas.push([i, i + t.length]);
                i = normalizado.indexOf(t, i + t.length);
            }
        });
        if (!marcas.length || normalizado.length !== texto.length) {
            return escapar(texto);
        }
        marcas.sort(function (a, b) { return a[0] - b[0]; });
        var html = '';
        var cursor = 0;
        marcas.forEach(function (m) {
            if (m[0] < cursor) {
                if (m[1] > cursor) { html += '<mark>' + escapar(texto.slice(cursor, m[1])) + '</mark>'; cursor = m[1]; }
                return;
            }
            html += escapar(texto.slice(cursor, m[0])) + '<mark>' + escapar(texto.slice(m[0], m[1])) + '</mark>';
            cursor = m[1];
        });
        return html + escapar(texto.slice(cursor));
    }

    // ---------- Carga ----------
    fetch('api/productos.php')
        .then(function (r) {
            if (!r.ok) { throw new Error('HTTP ' + r.status); }
            return r.json();
        })
        .then(function (datos) {
            productos = datos.productos.map(function (f) {
                var nombre = f[1];
                return {
                    c: f[0], n: nombre, m: f[2], cat: f[3], p: f[4], a: f[5], d: f[6], img: f[7] === 1,
                    nn: normalizar(nombre),
                    // Para ordenar: sin los signos con que empiezan algunos nombres ("- ", "+ ").
                    o: nombre.replace(/^[^0-9A-Za-zÀ-ɏ]+/, ''),
                    cn: f[0].toLowerCase(),
                    idx: normalizar(f[0] + ' ' + nombre + ' ' + f[2] + ' ' + f[3])
                };
            });
            (datos.vendidos || []).forEach(function (codigo, i) { rangoVendido[codigo] = i + 1; });

            armarFiltros();
            leerUrl();
            filtrar();
        })
        .catch(function () {
            el.rejilla.removeAttribute('aria-busy');
            el.rejilla.innerHTML = '<div class="vacio"><strong>No pudimos cargar el catálogo.</strong>' +
                'Recarga la página o escríbenos y te lo enviamos.</div>';
            el.conteo.textContent = '';
        });

    // ---------- Filtros ----------
    function contar(campo) {
        var cuenta = {};
        productos.forEach(function (p) {
            if (p[campo]) { cuenta[p[campo]] = (cuenta[p[campo]] || 0) + 1; }
        });
        return cuenta;
    }

    function opciones(select, cuenta) {
        Object.keys(cuenta).sort(COTEJO.compare).forEach(function (valor) {
            var o = document.createElement('option');
            o.value = valor;
            o.textContent = valor + ' (' + formato0.format(cuenta[valor]) + ')';
            select.appendChild(o);
        });
    }

    function chip(texto, tipo, valor, cantidad, clase) {
        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'chip' + (clase ? ' ' + clase : '');
        b.setAttribute('data-tipo', tipo);
        b.setAttribute('data-valor', valor);
        b.setAttribute('aria-pressed', 'false');
        b.innerHTML = escapar(texto) + (cantidad ? '<small>' + formato0.format(cantidad) + '</small>' : '');
        el.chips.appendChild(b);
    }

    function armarFiltros() {
        var marcas = contar('m');
        var categorias = contar('cat');
        var ofertas = productos.filter(function (p) { return p.d > 0; }).length;
        var vendidos = productos.filter(function (p) { return rangoVendido[p.c]; }).length;

        chip('Todos', 'todos', '', 0);
        if (ofertas) { chip('Ofertas', 'rapido', 'ofertas', ofertas, 'chip-oferta'); }
        if (vendidos) { chip('Más vendidos', 'rapido', 'vendidos', 0); }
        Object.keys(marcas)
            .sort(function (a, b) { return marcas[b] - marcas[a]; })
            .slice(0, MARCAS_RAPIDAS)
            .forEach(function (m) { chip(m, 'marca', m, marcas[m]); });

        opciones(el.marca, marcas);
        opciones(el.categoria, categorias);
        // Si ningun producto trae categoria, el filtro no sirve: se esconde.
        el.categoria.closest('label').hidden = Object.keys(categorias).length === 0;
    }

    function sincronizarControles() {
        el.busqueda.value = estado.q;
        el.limpiar.hidden = estado.q === '';
        el.marca.value = estado.marca;
        el.categoria.value = estado.categoria;
        el.orden.value = estado.orden;

        var algo = estado.q || estado.rapido || estado.marca || estado.categoria;
        Array.prototype.forEach.call(el.chips.children, function (b) {
            var tipo = b.getAttribute('data-tipo');
            var valor = b.getAttribute('data-valor');
            var activo = (tipo === 'todos' && !estado.rapido && !estado.marca)
                || (tipo === 'rapido' && estado.rapido === valor)
                || (tipo === 'marca' && estado.marca === valor);
            b.setAttribute('aria-pressed', activo ? 'true' : 'false');
        });
        el.quitar.hidden = !algo;
    }

    function filtrar() {
        tokens = normalizar(estado.q).split(/\s+/).filter(Boolean);

        resultado = productos.filter(function (p) {
            if (estado.marca && p.m !== estado.marca) { return false; }
            if (estado.categoria && p.cat !== estado.categoria) { return false; }
            if (estado.rapido === 'ofertas' && !p.d) { return false; }
            if (estado.rapido === 'vendidos' && !rangoVendido[p.c]) { return false; }
            for (var i = 0; i < tokens.length; i++) {
                if (p.idx.indexOf(tokens[i]) === -1) { return false; }
            }
            return true;
        });

        ordenar();
        sincronizarControles();
        escribirUrl();

        mostrados = 0;
        el.rejilla.innerHTML = '';
        el.rejilla.removeAttribute('aria-busy');
        pintarMas();

        var n = resultado.length;
        el.conteo.textContent = n === 0 ? 'Sin resultados'
            : formato0.format(n) + (n === 1 ? ' producto' : ' productos')
              + (n === productos.length ? ' en el catálogo' : ' encontrados');
    }

    /** Que tan bien coincide con la busqueda: codigo exacto > empieza > palabra > dentro. */
    function puntaje(p) {
        var q = tokens.join(' ');
        if (p.cn === q) { return 0; }
        if (p.nn.indexOf(tokens[0]) === 0) { return 1; }
        if ((' ' + p.nn).indexOf(' ' + tokens[0]) !== -1) { return 2; }
        return 3;
    }

    function ordenar() {
        var porNombre = function (a, b) { return COTEJO.compare(a.o, b.o); };
        var criterio;

        switch (estado.orden) {
            case 'nombre': criterio = porNombre; break;
            case 'precio-asc': criterio = function (a, b) { return a.p - b.p || porNombre(a, b); }; break;
            case 'precio-desc': criterio = function (a, b) { return b.p - a.p || porNombre(a, b); }; break;
            case 'descuento': criterio = function (a, b) { return b.d - a.d || porNombre(a, b); }; break;
            default:
                if (tokens.length) {
                    resultado.forEach(function (p) { p.s = puntaje(p); });
                    criterio = function (a, b) { return a.s - b.s || porNombre(a, b); };
                } else if (estado.rapido === 'vendidos') {
                    criterio = function (a, b) { return rangoVendido[a.c] - rangoVendido[b.c]; };
                } else {
                    // Sin busqueda: primero las ofertas (mayor descuento), luego por nombre.
                    criterio = function (a, b) { return b.d - a.d || porNombre(a, b); };
                }
        }
        resultado.sort(criterio);
    }

    // ---------- Tarjetas ----------
    function tarjeta(p) {
        var imagen = p.img
            ? '<img src="img.php?c=' + encodeURIComponent(p.c) + '" alt="' + escapar(p.n) + '" loading="lazy" decoding="async" width="800" height="800">'
            : DIBUJO;
        var pedir = whatsapp
            ? '<a class="pedir" target="_blank" rel="noopener" href="https://wa.me/' + whatsapp + '?text=' +
              encodeURIComponent('Hola, me interesa este producto: ' + p.n + ' (REF ' + p.c + ') a ' + dinero(p.p) + '.') +
              '">' + ICONO_WA + 'Pedir<span class="pedir-largo"> por WhatsApp</span></a>'
            : '';

        return '<article class="tarjeta">' +
            '<div class="tarjeta-imagen' + (p.img ? '' : ' sin-imagen') + '">' + imagen +
                (p.d ? '<span class="descuento">-' + p.d + '%</span>' : '') +
                (rangoVendido[p.c] ? '<span class="top-vendido">TOP ' + rangoVendido[p.c] + '</span>' : '') +
            '</div>' +
            '<div class="tarjeta-cuerpo">' +
                '<span class="ref">REF ' + escapar(p.c) + '</span>' +
                '<h3>' + resaltar(p.n, p.nn) + '</h3>' +
                (p.m ? '<span class="marca-producto">' + escapar(p.m) + '</span>' : '') +
                '<div class="precios"><span class="precio">' + dinero(p.p) + '</span>' +
                    (p.a ? '<span class="antes"><span class="solo-lector">Antes </span>' + dinero(p.a) + '</span>' : '') +
                '</div>' +
                pedir +
            '</div>' +
        '</article>';
    }

    function pintarMas() {
        if (mostrados === 0 && resultado.length === 0) {
            el.rejilla.innerHTML = '<div class="vacio"><strong>No encontramos productos con esa búsqueda.</strong>' +
                'Prueba con otra palabra, revisa la ortografía o quita los filtros. ' +
                'Si no lo ves, pregúntanos: quizá lo conseguimos.</div>';
            return;
        }
        var lote = resultado.slice(mostrados, mostrados + POR_PAGINA);
        el.rejilla.insertAdjacentHTML('beforeend', lote.map(tarjeta).join(''));
        mostrados += lote.length;
    }

    // Una imagen que no carga se cambia por el dibujo, igual que un producto sin imagen.
    el.rejilla.addEventListener('error', function (ev) {
        var img = ev.target;
        if (img.tagName === 'IMG') {
            img.parentNode.classList.add('sin-imagen');
            img.outerHTML = DIBUJO;
        }
    }, true);

    if ('IntersectionObserver' in window) {
        new IntersectionObserver(function (entradas) {
            if (entradas[0].isIntersecting && mostrados < resultado.length) { pintarMas(); }
        }, { rootMargin: '800px 0px' }).observe(el.centinela);
    }

    // ---------- URL (para compartir una busqueda) ----------
    function leerUrl() {
        var u = new URLSearchParams(location.search);
        estado.q = u.get('q') || '';
        estado.rapido = ['ofertas', 'vendidos'].indexOf(u.get('ver')) !== -1 ? u.get('ver') : '';
        estado.marca = u.get('marca') || '';
        estado.categoria = u.get('categoria') || '';
        estado.orden = u.get('orden') || 'relevancia';
        if (!el.orden.querySelector('option[value="' + estado.orden + '"]')) { estado.orden = 'relevancia'; }
    }

    function escribirUrl() {
        var u = new URLSearchParams();
        if (estado.q) { u.set('q', estado.q); }
        if (estado.rapido) { u.set('ver', estado.rapido); }
        if (estado.marca) { u.set('marca', estado.marca); }
        if (estado.categoria) { u.set('categoria', estado.categoria); }
        if (estado.orden !== 'relevancia') { u.set('orden', estado.orden); }
        var texto = u.toString();
        history.replaceState(null, '', location.pathname + (texto ? '?' + texto : '') + location.hash);
    }

    // ---------- Eventos ----------
    var espera;
    el.busqueda.addEventListener('input', function () {
        clearTimeout(espera);
        espera = setTimeout(function () {
            estado.q = el.busqueda.value.trim();
            filtrar();
        }, 60);
    });

    el.limpiar.addEventListener('click', function () {
        estado.q = '';
        filtrar();
        el.busqueda.focus();
    });

    el.chips.addEventListener('click', function (ev) {
        var b = ev.target.closest('.chip');
        if (!b) { return; }
        var tipo = b.getAttribute('data-tipo');
        var valor = b.getAttribute('data-valor');
        var activo = b.getAttribute('aria-pressed') === 'true';

        if (tipo === 'todos') {
            estado.rapido = '';
            estado.marca = '';
        } else if (tipo === 'rapido') {
            estado.rapido = activo ? '' : valor;
        } else {
            estado.marca = activo ? '' : valor;
        }
        filtrar();
    });

    el.marca.addEventListener('change', function () { estado.marca = el.marca.value; filtrar(); });
    el.categoria.addEventListener('change', function () { estado.categoria = el.categoria.value; filtrar(); });
    el.orden.addEventListener('change', function () { estado.orden = el.orden.value; filtrar(); });

    el.quitar.addEventListener('click', function () {
        estado = { q: '', rapido: '', marca: '', categoria: '', orden: estado.orden };
        filtrar();
    });

    // "/" lleva al buscador (como en muchas tiendas en linea).
    document.addEventListener('keydown', function (ev) {
        if (ev.key === '/' && document.activeElement.tagName !== 'INPUT' && document.activeElement.tagName !== 'TEXTAREA') {
            ev.preventDefault();
            el.busqueda.focus();
        }
    });
})();
