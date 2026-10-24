/* Catalogo en linea: carga todos los productos una vez y busca/filtra en el
   navegador, asi cada tecla actualiza al instante. Los resultados se muestran
   por paginas (config: sitio.por_pagina), con la pagina en la URL para poder
   compartirla o recargarla sin perder el lugar. */
(function () {
    'use strict';

    var main = document.getElementById('contenido');
    var POR_PAGINA = parseInt(main.getAttribute('data-por-pagina'), 10) || 10;
    var MARCAS_RAPIDAS = parseInt(main.getAttribute('data-marcas-rapidas'), 10) || 10;
    var whatsapp = main.getAttribute('data-whatsapp') || '';
    var $ = function (id) { return document.getElementById(id); };
    var el = {
        busqueda: $('busqueda'), limpiar: $('limpiar-busqueda'), chips: $('filtros-rapidos'),
        marca: $('filtro-marca'), categoria: $('filtro-categoria'), orden: $('orden'),
        conteo: $('conteo'), quitar: $('quitar-filtros'), rejilla: $('rejilla'), paginacion: $('paginacion'),
        buscador: document.querySelector('.buscador'), info: document.querySelector('.resultados-info')
    };

    var productos = [];
    var porCodigo = {};             // codigo -> producto
    var rangoVendido = {};          // codigo -> lugar en el top de mas vendidos
    var resultado = [];
    var pagina = 1;
    var tokens = [];
    var estado = { q: '', rapido: '', marca: '', categoria: '', orden: 'relevancia' };

    var DIBUJO = '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="16.5" cy="7.5" r="2"/>' +
                 '<path d="M2 19 8.5 10l4 5.5 2.5-3L22 19z"/></svg>';
    var ICONO_CARRITO = '<svg viewBox="0 0 24 24" aria-hidden="true" class="icono-trazo"><circle cx="9" cy="20" r="1.4"/>' +
                        '<circle cx="18" cy="20" r="1.4"/><path d="M2 3h2.8l2.5 12a1.6 1.6 0 0 0 1.6 1.3h8.7a1.6 1.6 0 0 0 1.6-1.2L21 7.5H5.7"/></svg>';
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
            productos.forEach(function (p) { porCodigo[p.c] = p; });
            (datos.vendidos || []).forEach(function (codigo, i) { rangoVendido[codigo] = i + 1; });

            actualizarCarrito();
            armarFiltros();
            leerUrl();
            filtrar(true);
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

    /**
     * Aplica busqueda, filtros y orden. Cualquier cambio regresa a la pagina 1,
     * salvo al abrir con ?pagina=N en la URL (conservarPagina).
     */
    function filtrar(conservarPagina) {
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

        if (!conservarPagina) { pagina = 1; }
        mostrarPagina();
    }

    function totalPaginas() {
        return Math.max(1, Math.ceil(resultado.length / POR_PAGINA));
    }

    function mostrarPagina() {
        pagina = Math.min(Math.max(1, pagina), totalPaginas());
        escribirUrl();

        el.rejilla.removeAttribute('aria-busy');
        var n = resultado.length;

        if (n === 0) {
            el.rejilla.innerHTML = '<div class="vacio"><strong>No encontramos productos con esa búsqueda.</strong>' +
                'Prueba con otra palabra, revisa la ortografía o quita los filtros. ' +
                'Si no lo ves, pregúntanos: quizá lo conseguimos.</div>';
            el.conteo.textContent = 'Sin resultados';
            el.paginacion.innerHTML = '';
            return;
        }

        var desde = (pagina - 1) * POR_PAGINA;
        var hasta = Math.min(desde + POR_PAGINA, n);
        el.rejilla.innerHTML = resultado.slice(desde, hasta).map(tarjeta).join('');

        el.conteo.textContent = 'Mostrando ' + formato0.format(desde + 1) + '–' + formato0.format(hasta) +
            ' de ' + formato0.format(n) + (n === 1 ? ' producto' : ' productos') +
            (n === productos.length ? '' : ' encontrados');

        pintarPaginacion();
    }

    /**
     * Botones de pagina: primera, ultima y dos alrededor de la actual, con "…"
     * en los huecos (1 … 4 5 [6] 7 8 … 2781), mas "Ir a" para saltar lejos.
     */
    function pintarPaginacion() {
        var total = totalPaginas();
        if (total === 1) {
            el.paginacion.innerHTML = '';
            return;
        }

        var numeros = [];
        for (var i = 1; i <= total; i++) {
            if (i === 1 || i === total || Math.abs(i - pagina) <= 2) {
                if (numeros.length && i - numeros[numeros.length - 1] > 1) { numeros.push(0); }
                numeros.push(i);
            }
        }

        var boton = function (destino, texto, etiqueta, clase, deshabilitado) {
            return '<button type="button" class="pag-boton ' + clase + '" data-pagina="' + destino + '"' +
                ' aria-label="' + etiqueta + '"' + (deshabilitado ? ' disabled' : '') + '>' + texto + '</button>';
        };

        var html = boton(pagina - 1, '‹<span class="pag-texto"> Anterior</span>', 'Página anterior', 'pag-paso', pagina === 1);
        numeros.forEach(function (num) {
            if (num === 0) {
                html += '<span class="pag-hueco" aria-hidden="true">…</span>';
            } else if (num === pagina) {
                html += '<span class="pag-boton pag-numero pag-actual-num" aria-current="page">' + formato0.format(num) + '</span>';
            } else {
                html += boton(num, formato0.format(num), 'Página ' + num, 'pag-numero', false);
            }
        });
        html += boton(pagina + 1, '<span class="pag-texto">Siguiente </span>›', 'Página siguiente', 'pag-paso', pagina === total);

        html += '<span class="pag-actual">Página ' + formato0.format(pagina) + ' de ' + formato0.format(total) + '</span>';
        html += '<form class="pag-ir" novalidate><label><span>Ir a</span>' +
            '<input type="number" name="ir" min="1" max="' + total + '" inputmode="numeric" aria-label="Ir a la página"></label>' +
            '<button type="submit" class="pag-boton">Ir</button></form>';

        el.paginacion.innerHTML = html;
    }

    /** Cambia de pagina y sube al inicio de los resultados (debajo del buscador fijo). */
    function irAPagina(n) {
        pagina = n;
        mostrarPagina();
        var arriba = el.info.getBoundingClientRect().top + window.pageYOffset - el.buscador.offsetHeight - 8;
        window.scrollTo({ top: Math.max(0, arriba), behavior: 'smooth' });
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
        var accion = whatsapp ? '<div class="accion">' + accionTarjeta(p.c) + '</div>' : '';

        return '<article class="tarjeta" data-c="' + escapar(p.c) + '">' +
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
                accion +
            '</div>' +
        '</article>';
    }

    /** "Agregar" si no esta en el carrito; si ya esta, las piezas con − y +. */
    function accionTarjeta(codigo) {
        var it = enCarrito(codigo);
        if (!it) {
            return '<button type="button" class="agregar" data-accion="agregar">' + ICONO_CARRITO +
                'Agregar<span class="pedir-largo"> al carrito</span></button>';
        }
        return '<div class="cantidad" role="group" aria-label="Piezas en tu carrito">' +
            '<button type="button" data-accion="menos" aria-label="' + (it.q === 1 ? 'Quitar del carrito' : 'Una pieza menos') + '">−</button>' +
            '<span><strong>' + it.q + '</strong> en carrito</span>' +
            '<button type="button" data-accion="mas" aria-label="Una pieza más"' + (it.q >= PIEZAS_MAX ? ' disabled' : '') + '>+</button>' +
        '</div>';
    }

    el.rejilla.addEventListener('click', function (ev) {
        var b = ev.target.closest('button[data-accion]');
        if (!b) { return; }
        var tarjetaEl = b.closest('.tarjeta');
        var codigo = tarjetaEl.getAttribute('data-c');
        var accion = b.getAttribute('data-accion');
        var it = enCarrito(codigo);

        if (accion === 'menos') {
            fijarCantidad(codigo, it ? it.q - 1 : 0);
        } else if (porCodigo[codigo]) {
            agregar(porCodigo[codigo]);
        }
        // El boton se redibujo: el foco pasa al que quedo en su lugar.
        var nuevo = tarjetaEl.querySelector('[data-accion="' + accion + '"]') || tarjetaEl.querySelector('.accion button');
        if (nuevo) { nuevo.focus(); }
    });

    // Una imagen que no carga se cambia por el dibujo, igual que un producto sin imagen.
    el.rejilla.addEventListener('error', function (ev) {
        var img = ev.target;
        if (img.tagName === 'IMG') {
            img.parentNode.classList.add('sin-imagen');
            img.outerHTML = DIBUJO;
        }
    }, true);

    el.paginacion.addEventListener('click', function (ev) {
        var b = ev.target.closest('button[data-pagina]');
        if (b && !b.disabled) { irAPagina(parseInt(b.getAttribute('data-pagina'), 10)); }
    });

    el.paginacion.addEventListener('submit', function (ev) {
        ev.preventDefault();
        var n = parseInt(ev.target.elements.ir.value, 10);
        if (n >= 1) { irAPagina(Math.min(n, totalPaginas())); }
    });

    // ---------- Carrito (sin cuenta: JSON en el navegador) ----------
    // Vive en localStorage, asi sobrevive a recargar o cerrar el navegador (solo
    // en este equipo). Guarda nombre, marca y precio de cuando se agrego para
    // pintar el carrito antes de que cargue el catalogo; al cargar se refrescan
    // con los datos de hoy. Se envia completo en un solo mensaje de WhatsApp.
    var CLAVE_CARRITO = 'gabe-carrito';
    var CARRITO_MAX = parseInt(main.getAttribute('data-carrito-max'), 10) || 20;
    var PIEZAS_MAX = 999;
    var carrito = { nombre: '', productos: [] };    // productos: [{ c, n, m, p, q, x? }]
    var ca = {
        abrir: $('abrir-carrito'), contador: $('contador-carrito'),
        flotante: $('carrito-flotante'), flotanteTexto: $('carrito-flotante-texto'),
        dialogo: $('carrito'), titulo: $('carrito-titulo'), cerrar: $('carrito-cerrar'),
        lista: $('carrito-lista'), pie: $('carrito-pie'), resumen: $('carrito-resumen'),
        nombre: $('carrito-nombre'), enviar: $('carrito-enviar'), vaciar: $('carrito-vaciar'),
        aviso: $('aviso-carrito')
    };

    function leerCarrito() {
        var guardado = null;
        try {
            guardado = JSON.parse(localStorage.getItem(CLAVE_CARRITO));
        } catch (e) { /* sin acceso o JSON danado: carrito vacio */ }

        var lista = guardado && Array.isArray(guardado.productos) ? guardado.productos : [];
        carrito = {
            nombre: guardado && typeof guardado.nombre === 'string' ? guardado.nombre.slice(0, 80) : '',
            productos: lista.filter(function (it) {
                return it && typeof it.c === 'string' && typeof it.n === 'string' && isFinite(it.p) && it.q >= 1;
            }).slice(0, CARRITO_MAX).map(function (it) {
                return { c: it.c, n: it.n, m: String(it.m || ''), p: +it.p, q: Math.min(PIEZAS_MAX, Math.floor(it.q)), x: it.x ? 1 : 0 };
            })
        };
    }

    function guardarCarrito() {
        try {
            localStorage.setItem(CLAVE_CARRITO, JSON.stringify({
                v: 1, actualizado: new Date().toISOString(), nombre: carrito.nombre, productos: carrito.productos
            }));
        } catch (e) { /* modo privado o sin espacio: dura solo esta visita */ }
    }

    function enCarrito(codigo) {
        for (var i = 0; i < carrito.productos.length; i++) {
            if (carrito.productos[i].c === codigo) { return carrito.productos[i]; }
        }
        return null;
    }

    /** Con el catalogo ya cargado: precio y nombre de hoy; marca lo que ya no esta. */
    function actualizarCarrito(sinGuardar) {
        carrito.productos.forEach(function (it) {
            var p = porCodigo[it.c];
            if (p) {
                it.n = p.n; it.m = p.m || ''; it.p = p.p; it.x = 0;
            } else {
                it.x = 1;
            }
        });
        if (!sinGuardar) { guardarCarrito(); }
        pintarCarrito();
    }

    var esperaAviso;
    function avisarCarrito(texto) {
        ca.aviso.textContent = texto;
        ca.aviso.classList.add('visible');
        clearTimeout(esperaAviso);
        esperaAviso = setTimeout(function () { ca.aviso.classList.remove('visible'); }, 2600);
    }

    /** Una pieza mas (o la primera, si cabe otro producto). */
    function agregar(p) {
        var it = enCarrito(p.c);
        if (it) {
            it.q = Math.min(PIEZAS_MAX, it.q + 1);
        } else if (carrito.productos.length >= CARRITO_MAX) {
            avisarCarrito('Tu carrito ya tiene ' + CARRITO_MAX + ' productos. Envíalo por WhatsApp y luego arma otro.');
            return;
        } else {
            carrito.productos.push({ c: p.c, n: p.n, m: p.m || '', p: p.p, q: 1, x: 0 });
            avisarCarrito('Agregado al carrito: ' + p.n);
        }
        cambioCarrito(p.c);
    }

    /** 0 (o menos) lo quita del carrito. */
    function fijarCantidad(codigo, q) {
        var it = enCarrito(codigo);
        if (!it) { return; }
        if (q >= 1) {
            it.q = Math.min(PIEZAS_MAX, Math.floor(q));
        } else {
            carrito.productos.splice(carrito.productos.indexOf(it), 1);
        }
        cambioCarrito(codigo);
    }

    /** Guarda y redibuja; sin codigo, redibuja todas las tarjetas a la vista. */
    function cambioCarrito(codigo, sinGuardar) {
        if (!sinGuardar) { guardarCarrito(); }
        pintarCarrito();
        Array.prototype.forEach.call(el.rejilla.querySelectorAll('.tarjeta[data-c]'), function (t) {
            var c = t.getAttribute('data-c');
            var lugar = t.querySelector('.accion');
            if (lugar && (!codigo || c === codigo)) { lugar.innerHTML = accionTarjeta(c); }
        });
    }

    function totales() {
        var t = { piezas: 0, total: 0 };
        carrito.productos.forEach(function (it) { t.piezas += it.q; t.total += it.p * it.q; });
        return t;
    }

    function plural(n, uno, varios) {
        return formato0.format(n) + ' ' + (n === 1 ? uno : varios);
    }

    /**
     * El mensaje de WhatsApp. Los *asteriscos* salen en negritas en WhatsApp.
     * Ejemplo:
     *   Hola, vi su catálogo en línea y quiero cotizar estos productos:
     *
     *   1. *CERRADURA TUBULAR PERILLA ENTRADA SATIN*
     *      REF BNS03-07069 · SOPRANO
     *      2 pzas × $50.40 = $100.80
     *   ...
     *   Productos: 3 · Piezas: 7
     *   *Total: $1,234.50* (IVA incluido)
     *   A nombre de: Ferretería López
     *
     *   ¿Me confirman existencias? Gracias.
     *
     * Los precios son los netos (precio de mayoreo + % de la casa o del
     * producto): lo que se le cobra al cliente, por eso el total no es "estimado".
     */
    function mensajeCarrito() {
        var t = totales();
        var lineas = ['Hola, vi su catálogo en línea y quiero cotizar estos productos:', ''];

        carrito.productos.forEach(function (it, i) {
            // Sin asteriscos ni espacios de mas: romperian las negritas de WhatsApp.
            lineas.push((i + 1) + '. *' + it.n.replace(/\*/g, '').replace(/\s+/g, ' ').trim() + '*');
            lineas.push('    REF ' + it.c + (it.m ? ' · ' + it.m : ''));
            lineas.push('    ' + plural(it.q, 'pza', 'pzas') + ' × ' + dinero(it.p) + ' = ' + dinero(it.p * it.q) +
                (it.x ? ' (ya no aparece en el catálogo)' : ''));
            lineas.push('');
        });

        lineas.push('Productos: ' + formato0.format(carrito.productos.length) + ' · Piezas: ' + formato0.format(t.piezas));
        lineas.push('*Total: ' + dinero(t.total) + '* (IVA incluido)');
        if (carrito.nombre.trim()) { lineas.push('A nombre de: ' + carrito.nombre.trim()); }
        lineas.push('', '¿Me confirman existencias? Gracias.');
        return lineas.join('\n');
    }

    function enlaceCarrito() {
        ca.enviar.href = 'https://wa.me/' + whatsapp + '?text=' + encodeURIComponent(mensajeCarrito());
    }

    function filaCarrito(it) {
        var p = porCodigo[it.c];
        var imagen = p && p.img
            ? '<img src="img.php?c=' + encodeURIComponent(it.c) + '" alt="" loading="lazy" width="64" height="64">'
            : DIBUJO;
        return '<li class="carrito-item" data-c="' + escapar(it.c) + '">' +
            '<div class="carrito-imagen' + (p && p.img ? '' : ' sin-imagen') + '">' + imagen + '</div>' +
            '<div class="carrito-datos">' +
                '<span class="ref">REF ' + escapar(it.c) + '</span>' +
                '<strong>' + escapar(it.n) + '</strong>' +
                '<span class="carrito-precio">' + dinero(it.p) + ' c/u · <b>' + dinero(it.p * it.q) + '</b></span>' +
                (it.x ? '<span class="carrito-falta">Ya no aparece en el catálogo; te lo confirmamos por WhatsApp.</span>' : '') +
            '</div>' +
            '<div class="carrito-controles">' +
                '<div class="cantidad" role="group" aria-label="Piezas de ' + escapar(it.n) + '">' +
                    '<button type="button" data-accion="menos" aria-label="Una pieza menos"' + (it.q === 1 ? ' disabled' : '') + '>−</button>' +
                    '<input type="number" min="1" max="' + PIEZAS_MAX + '" value="' + it.q + '" inputmode="numeric" aria-label="Piezas">' +
                    '<button type="button" data-accion="mas" aria-label="Una pieza más"' + (it.q >= PIEZAS_MAX ? ' disabled' : '') + '>+</button>' +
                '</div>' +
                '<button type="button" class="enlace" data-accion="quitar">Quitar</button>' +
            '</div>' +
        '</li>';
    }

    function pintarCarrito() {
        if (!ca.dialogo) { return; }
        var n = carrito.productos.length;
        var t = totales();

        ca.contador.textContent = formato0.format(n);
        ca.contador.hidden = n === 0;
        ca.abrir.setAttribute('aria-label', n ? 'Abrir carrito: ' + plural(n, 'producto', 'productos') : 'Abrir carrito');
        ca.flotante.hidden = n === 0;
        ca.flotanteTexto.textContent = 'Ver carrito (' + formato0.format(n) + ') · ' + dinero(t.total);
        ca.titulo.textContent = n ? 'Mi carrito (' + formato0.format(n) + ' de ' + CARRITO_MAX + ')' : 'Mi carrito';
        ca.pie.hidden = n === 0;

        if (!n) {
            ca.lista.innerHTML = '<li class="carrito-vacio"><strong>Tu carrito está vacío.</strong>' +
                'Toca «Agregar» en los productos que te interesen y envíanos todo en un solo mensaje de WhatsApp.</li>';
            ca.vaciar.textContent = 'Vaciar carrito';
            return;
        }

        ca.lista.innerHTML = carrito.productos.map(filaCarrito).join('');
        ca.resumen.innerHTML = plural(n, 'producto', 'productos') + ' · ' + plural(t.piezas, 'pieza', 'piezas') +
            '<span>Total con IVA <strong>' + dinero(t.total) + '</strong></span>';
        if (document.activeElement !== ca.nombre) { ca.nombre.value = carrito.nombre; }
        enlaceCarrito();
    }

    function abrirCarrito() {
        pintarCarrito();
        if (ca.dialogo.showModal) { ca.dialogo.showModal(); } else { ca.dialogo.setAttribute('open', ''); }
    }

    function cerrarCarrito() {
        if (ca.dialogo.close) { ca.dialogo.close(); } else { ca.dialogo.removeAttribute('open'); }
    }

    if (whatsapp && ca.dialogo) {
        leerCarrito();
        pintarCarrito();

        ca.abrir.addEventListener('click', abrirCarrito);
        ca.flotante.addEventListener('click', abrirCarrito);
        ca.cerrar.addEventListener('click', cerrarCarrito);
        // Tocar fuera del panel (en el fondo oscuro) lo cierra.
        ca.dialogo.addEventListener('click', function (ev) {
            if (ev.target === ca.dialogo) { cerrarCarrito(); }
        });

        ca.lista.addEventListener('click', function (ev) {
            var b = ev.target.closest('button[data-accion]');
            if (!b) { return; }
            var codigo = b.closest('.carrito-item').getAttribute('data-c');
            var it = enCarrito(codigo);
            var accion = b.getAttribute('data-accion');
            if (!it) { return; }

            fijarCantidad(codigo, accion === 'quitar' ? 0 : it.q + (accion === 'mas' ? 1 : -1));
            var fila = ca.lista.querySelector('.carrito-item[data-c="' + (window.CSS && CSS.escape ? CSS.escape(codigo) : codigo) + '"]');
            var nuevo = fila && fila.querySelector('[data-accion="' + accion + '"]:not(:disabled)');
            (nuevo || (fila && fila.querySelector('input')) || ca.cerrar).focus();
        });

        ca.lista.addEventListener('change', function (ev) {
            if (ev.target.tagName !== 'INPUT') { return; }
            var codigo = ev.target.closest('.carrito-item').getAttribute('data-c');
            var q = parseInt(ev.target.value, 10);
            if (q >= 1) {
                fijarCantidad(codigo, q);
            } else {
                pintarCarrito();    // vacio o invalido: regresa al valor que tenia
            }
        });

        ca.nombre.addEventListener('input', function () {
            carrito.nombre = ca.nombre.value.slice(0, 80);
            guardarCarrito();
            enlaceCarrito();
        });

        ca.enviar.addEventListener('click', function () {
            // No se vacia solo: si WhatsApp no abrio, el pedido sigue aqui.
            ca.vaciar.textContent = '¿Ya lo enviaste? Vaciar carrito';
        });

        ca.vaciar.addEventListener('click', function () {
            if (!confirm('¿Quitar todos los productos del carrito?')) { return; }
            carrito.productos = [];
            cambioCarrito();
            ca.cerrar.focus();
        });

        // Otra pestana cambio el carrito: se refleja aqui tambien. Sin volver a
        // guardar, o las dos pestanas se contestarian sin fin.
        window.addEventListener('storage', function (ev) {
            if (ev.key !== CLAVE_CARRITO) { return; }
            leerCarrito();
            if (productos.length) { actualizarCarrito(true); }
            cambioCarrito(null, true);
        });
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
        pagina = parseInt(u.get('pagina'), 10) || 1;
    }

    function escribirUrl() {
        var u = new URLSearchParams();
        if (estado.q) { u.set('q', estado.q); }
        if (estado.rapido) { u.set('ver', estado.rapido); }
        if (estado.marca) { u.set('marca', estado.marca); }
        if (estado.categoria) { u.set('categoria', estado.categoria); }
        if (estado.orden !== 'relevancia') { u.set('orden', estado.orden); }
        if (pagina > 1) { u.set('pagina', pagina); }
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
