document.addEventListener('DOMContentLoaded', () => {
    const form         = document.getElementById('form-venta');
    if (!form) return;

    const selectorCasa = document.getElementById('proveedor');
    const inputBuscar  = document.getElementById('piezas');
    const resultados   = document.getElementById('resultados');
    const listaItems   = document.getElementById('lista-items');
    const totalVenta   = document.getElementById('total-venta');
    const tipoPago     = document.getElementById('tipo_pago');
    const camposCredito = document.getElementById('campos-credito');
    const pagoInicial  = document.getElementById('pago_inicial');
    const fechaVenc    = document.getElementById('fecha_vencimiento');
    const aviso        = document.getElementById('aviso-venta');
    const btnGuardar   = document.getElementById('btn-guardar');

    // Cada linea guarda su propia casa: un mismo ticket puede mezclar proveedores.
    let items = [];

    const money = (n) => '$' + n.toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    let temporizadorAviso = null;

    // segundos = 0 deja el aviso fijo (errores que conviene que el vendedor lea).
    function mostrarAviso(texto, tipo, segundos = 0) {
        clearTimeout(temporizadorAviso);
        aviso.textContent = texto;
        aviso.className = 'aviso aviso-' + tipo;
        aviso.hidden = false;

        if (segundos > 0) {
            temporizadorAviso = setTimeout(ocultarAviso, segundos * 1000);
        }
    }

    function ocultarAviso() {
        clearTimeout(temporizadorAviso);
        aviso.hidden = true;
    }

    // ---------- Busqueda de productos ----------
    let temporizador = null;

    async function buscar() {
        const termino = inputBuscar.value.trim();

        if (termino.length < 2) {
            resultados.hidden = true;
            return;
        }

        const url = '../../app/controllers/ProductoController.php'
            + '?casa=' + encodeURIComponent(selectorCasa.value)
            + '&q=' + encodeURIComponent(termino);

        try {
            const respuesta = await fetch(url);
            if (respuesta.status === 401) {
                mostrarAviso('Tu sesión expiró. Vuelve a iniciar sesión.', 'error');
                return;
            }
            pintarResultados(await respuesta.json());
        } catch (e) {
            mostrarAviso('No se pudo buscar. Revisa tu conexión.', 'error');
        }
    }

    function pintarResultados(productos) {
        resultados.innerHTML = '';

        if (!Array.isArray(productos) || productos.length === 0) {
            resultados.innerHTML = '<p class="sin-resultados">Sin coincidencias</p>';
            resultados.hidden = false;
            return;
        }

        productos.forEach((p) => {
            // Va como <div> y no como <button>: Firefox, Safari y Chrome de
            // Android ignoran el display:block de los hijos de un boton y
            // amontonan los tres renglones en uno solo.
            const fila = document.createElement('div');
            fila.className = 'search-result-item';
            fila.setAttribute('role', 'button');
            fila.tabIndex = 0;

            const precios = [];
            if (p.precio_menudeo !== null) precios.push('Menudeo ' + money(Number(p.precio_menudeo)));
            if (p.precio_mayoreo !== null) precios.push('Mayoreo ' + money(Number(p.precio_mayoreo)));

            // casa-BNS01, casa-BNS02... le da a cada casa su color en el CSS.
            const claseCasa = 'casa-' + String(p.codigo_casa || '').replace(/[^A-Za-z0-9_-]/g, '');

            fila.innerHTML =
                '<span class="res-nombre">' + p.nombre +
                    '<span class="res-casa ' + claseCasa + '">' + p.nombre_casa + '</span></span>' +
                '<span class="res-meta">' + p.codigo_interno + ' · ' + (p.codigo_proveedor || '') +
                (p.marca ? ' · ' + p.marca : '') + '</span>' +
                '<span class="res-precio">' + (precios.join(' | ') || 'Sin precio') + '</span>';

            fila.addEventListener('click', () => agregarItem(p));

            // Al dejar de ser <button> hay que reponer el teclado a mano.
            fila.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    agregarItem(p);
                }
            });

            resultados.appendChild(fila);
        });

        resultados.hidden = false;
        // Cambiar el contenido no mueve el scroll de la caja: sin esto, una
        // busqueda nueva se veia desde donde se quedo la anterior. Va despues
        // de mostrarla porque en una caja oculta el scroll no se aplica.
        resultados.scrollTop = 0;
    }

    // ---------- Items de la venta ----------
    function agregarItem(producto) {
        if (producto.precio_menudeo === null && producto.precio_mayoreo === null) {
            mostrarAviso('Ese producto no tiene precio cargado, no se puede vender.', 'error');
            return;
        }

        const yaEsta = items.find((i) => i.codigo_interno === producto.codigo_interno);
        if (yaEsta) {
            yaEsta.cantidad += 1;
        } else {
            items.push({
                // La casa sale del producto, no del selector: al buscar en
                // "Todas las casas" cada pieza trae la suya.
                casa:           producto.codigo_casa,
                casa_nombre:    producto.nombre_casa,
                codigo_interno: producto.codigo_interno,
                nombre:         producto.nombre,
                precio_mayoreo: producto.precio_mayoreo === null ? null : Number(producto.precio_mayoreo),
                precio_menudeo: producto.precio_menudeo === null ? null : Number(producto.precio_menudeo),
                // La Jaladera no maneja mayoreo: si no hay precio, se queda en menudeo.
                tipo_precio:    producto.precio_menudeo !== null ? 'menudeo' : 'mayoreo',
                cantidad:       1,
            });
        }

        inputBuscar.value = '';
        resultados.hidden = true;
        ocultarAviso();
        pintarItems();
    }

    function precioDe(item) {
        return item.tipo_precio === 'mayoreo' ? item.precio_mayoreo : item.precio_menudeo;
    }

    function pintarItems() {
        listaItems.innerHTML = '';

        if (items.length === 0) {
            listaItems.innerHTML = '<p class="venta-vacia">Aún no has agregado piezas a esta venta.</p>';
            totalVenta.textContent = money(0);
            return;
        }

        let total = 0;

        items.forEach((item, indice) => {
            const precio = precioDe(item);
            const subtotal = precio * item.cantidad;
            total += subtotal;

            const fila = document.createElement('div');
            fila.className = 'venta-item';

            const opcionMayoreo = item.precio_mayoreo === null
                ? '<option value="mayoreo" disabled>Mayoreo (no disponible)</option>'
                : '<option value="mayoreo"' + (item.tipo_precio === 'mayoreo' ? ' selected' : '') + '>Mayoreo</option>';

            const opcionMenudeo = item.precio_menudeo === null
                ? '<option value="menudeo" disabled>Menudeo (no disponible)</option>'
                : '<option value="menudeo"' + (item.tipo_precio === 'menudeo' ? ' selected' : '') + '>Menudeo</option>';

            // Todo va suelto dentro de la fila para que el CSS lo acomode en dos
            // renglones: arriba nombre / precio / cantidad / subtotal y abajo
            // casa / precio unitario, alineados entre sí.
            fila.innerHTML =
                '<p class="item-nombre">' + item.nombre + '</p>' +
                '<p class="item-meta">' + item.casa_nombre + ' · ' + item.codigo_interno + '</p>' +
                '<select class="form-control select-precio" data-i="' + indice + '" aria-label="Tipo de precio">' +
                    opcionMenudeo + opcionMayoreo +
                '</select>' +
                '<span class="item-unitario">' + money(precio) + ' c/u</span>' +
                '<input type="number" class="input-qty input-cantidad" data-i="' + indice + '" ' +
                       'value="' + item.cantidad + '" min="1" step="1" aria-label="Cantidad">' +
                '<div class="item-subtotal">' + money(subtotal) + '</div>' +
                '<button type="button" class="btn-quitar" data-i="' + indice + '" ' +
                        'title="Quitar pieza" aria-label="Quitar pieza">' +
                    '<i class="ph ph-x"></i></button>';

            listaItems.appendChild(fila);
        });

        totalVenta.textContent = money(total);
    }

    // ---------- Eventos ----------
    inputBuscar.addEventListener('input', () => {
        clearTimeout(temporizador);
        temporizador = setTimeout(buscar, 300);
    });

    inputBuscar.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
            e.preventDefault();
            buscar();
        }
    });

    // Cambiar de casa limpia la busqueda, pero conserva lo ya agregado.
    selectorCasa.addEventListener('change', () => {
        inputBuscar.value = '';
        resultados.hidden = true;
    });

    listaItems.addEventListener('change', (e) => {
        const i = e.target.dataset.i;
        if (i === undefined) return;

        if (e.target.classList.contains('select-precio')) {
            items[i].tipo_precio = e.target.value;
            pintarItems();
        }

        if (e.target.classList.contains('input-cantidad')) {
            const cantidad = parseInt(e.target.value, 10);
            items[i].cantidad = (isNaN(cantidad) || cantidad < 1) ? 1 : cantidad;
            pintarItems();
        }
    });

    listaItems.addEventListener('click', (e) => {
        const boton = e.target.closest('.btn-quitar');
        if (!boton) return;
        items.splice(Number(boton.dataset.i), 1);
        pintarItems();
    });

    tipoPago.addEventListener('change', () => {
        camposCredito.hidden = tipoPago.value !== 'credito';
    });

    document.addEventListener('click', (e) => {
        if (!e.target.closest('.form-group')) resultados.hidden = true;
    });

    // ---------- Guardar ----------
    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        ocultarAviso();

        if (items.length === 0) {
            mostrarAviso('Agrega al menos una pieza antes de guardar.', 'error', 5);
            return;
        }

        btnGuardar.disabled = true;
        btnGuardar.textContent = 'Guardando...';

        const cuerpo = {
            cliente:   document.getElementById('cliente').value.trim(),
            tipo_pago: tipoPago.value,
            items: items.map((i) => ({
                casa:           i.casa,
                codigo_interno: i.codigo_interno,
                tipo_precio:    i.tipo_precio,
                cantidad:       i.cantidad,
            })),
        };

        if (tipoPago.value === 'credito') {
            cuerpo.pago_inicial = parseFloat(pagoInicial.value) || 0;
            cuerpo.fecha_vencimiento = fechaVenc.value || null;
        }

        try {
            const respuesta = await fetch('../../app/controllers/VentaController.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(cuerpo),
            });

            const datos = await respuesta.json();

            if (datos.ok) {
                mostrarAviso('Venta #' + datos.venta_id + ' guardada por $' + datos.total, 'ok', 5);
                items = [];
                form.reset();
                camposCredito.hidden = true;
                pintarItems();
            } else {
                mostrarAviso(datos.error || 'No se pudo guardar la venta.', 'error');
            }
        } catch (err) {
            mostrarAviso('Error de conexión al guardar.', 'error');
        } finally {
            btnGuardar.disabled = false;
            btnGuardar.textContent = 'Guardar';
        }
    });

    pintarItems();
});
