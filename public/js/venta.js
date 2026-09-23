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

    const inputCliente      = document.getElementById('cliente');
    const resultadosCliente = document.getElementById('resultados-cliente');
    const btnListaClientes  = document.getElementById('btn-lista-clientes');
    const bloqueSaldo       = document.getElementById('bloque-saldo');

    // Cliente elegido del catálogo (con su saldo a favor). Un nombre tecleado a
    // mano no cuenta: solo un cliente elegido de la lista lleva id y saldo.
    let clienteSel = { id: null, nombre: '', saldo: 0 };

    // Cada linea guarda su propia casa: un mismo ticket puede mezclar proveedores.
    let items = [];

    const money = (n) => '$' + n.toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    /**
     * El codigo que ocupa el vendedor es el del proveedor: es el que viene
     * impreso en el catalogo y en la caja. El interno (BNS02-02411) es de la
     * base de datos y no se muestra; solo aparece si la pieza no trae codigo de
     * proveedor, para que la fila no quede sin nada con que identificarla.
     */
    const codigoVisible = (p) => p.codigo_proveedor || p.codigo_interno;

    // Los nombres salen del catalogo y se pintan con innerHTML: uno con < o &
    // rompe la pantalla (y desde que se pueden dar de alta productos pegando
    // desde Excel, el texto ya no viene solo de los archivos del proveedor).
    function esc(texto) {
        const div = document.createElement('div');
        div.textContent = texto === null || texto === undefined ? '' : texto;
        return div.innerHTML;
    }

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

    // Aviso de venta guardada con acceso directo a la nota imprimible.
    function mostrarAvisoConNota(ventaId, total, cliente) {
        clearTimeout(temporizadorAviso);

        aviso.className = 'aviso aviso-ok aviso-con-accion';
        aviso.hidden = false;
        aviso.innerHTML = '';

        const texto = document.createElement('span');
        texto.textContent = 'Venta #' + ventaId + ' guardada por $' + total + '.';
        aviso.appendChild(texto);

        const boton = document.createElement('button');
        boton.type = 'button';
        boton.className = 'chip btn-imprimir-nota';
        boton.innerHTML = '<i class="ph ph-printer"></i> Imprimir nota';
        boton.addEventListener('click', () => {
            const parametros = new URLSearchParams({ id: ventaId, cliente: cliente || '' });
            window.open('../ventas/nota.php?' + parametros.toString(), '_blank');
        });
        aviso.appendChild(boton);
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

            // El precio que se muestra y se cobra es el neto (lo calcula el
            // servidor a partir del bruto y el porcentaje de la casa).
            const precioTexto = p.precio_neto !== null && p.precio_neto !== undefined
                ? money(Number(p.precio_neto))
                : 'Sin precio';

            // casa-BNS01, casa-BNS02... le da a cada casa su color en el CSS.
            const claseCasa = 'casa-' + String(p.codigo_casa || '').replace(/[^A-Za-z0-9_-]/g, '');

            fila.innerHTML =
                '<span class="res-nombre">' + esc(p.nombre) +
                    '<span class="res-casa ' + claseCasa + '">' + esc(p.nombre_casa) + '</span></span>' +
                '<span class="res-meta">' +
                    '<span class="codigo-prod">' + esc(codigoVisible(p)) + '</span>' +
                    (p.marca ? ' · ' + esc(p.marca) : '') + '</span>' +
                '<span class="res-precio">' + precioTexto + '</span>';

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

    // ---------- Selección de cliente ----------
    // El cliente puede escribirse a mano (como siempre) o elegirse del catálogo
    // que se da de alta en Usuarios. Elegir uno solo llena el campo de texto:
    // la venta sigue guardando el nombre, sin cambiar cómo se guarda ni la nota.
    let temporizadorCliente = null;

    async function buscarClientes(termino) {
        const url = '../../app/controllers/ClienteController.php?accion=buscar&q='
            + encodeURIComponent(termino);

        try {
            const respuesta = await fetch(url);
            if (respuesta.status === 401) {
                mostrarAviso('Tu sesión expiró. Vuelve a iniciar sesión.', 'error');
                return;
            }
            const datos = await respuesta.json();
            pintarClientes(datos.ok ? datos.clientes : []);
        } catch (e) {
            mostrarAviso('No se pudieron cargar los clientes. Revisa tu conexión.', 'error');
        }
    }

    function pintarClientes(clientes) {
        resultadosCliente.innerHTML = '';

        if (!Array.isArray(clientes) || clientes.length === 0) {
            resultadosCliente.innerHTML = '<p class="sin-resultados">Sin clientes que coincidan</p>';
            resultadosCliente.hidden = false;
            return;
        }

        clientes.forEach((c) => {
            const persona = [c.nombres, c.apellido_paterno, c.apellido_materno].filter(Boolean).join(' ');
            // El comercio es lo que identifica la venta; si no hay, va la persona.
            const titulo = c.nombre_comercio || persona;
            const sub    = c.nombre_comercio ? persona : '';
            const meta   = [sub, c.telefono, c.dia_visita ? 'Visita: ' + c.dia_visita : '']
                .filter(Boolean).join(' · ');

            const fila = document.createElement('div');
            fila.className = 'search-result-item';
            fila.setAttribute('role', 'button');
            fila.tabIndex = 0;

            fila.innerHTML =
                '<span class="res-nombre">' + esc(titulo) + '</span>' +
                (meta ? '<span class="res-meta">' + esc(meta) + '</span>' : '');

            const elegir = () => {
                inputCliente.value = titulo;
                resultadosCliente.hidden = true;
                seleccionarClienteCatalogo(c.id, titulo);
            };

            fila.addEventListener('click', elegir);
            fila.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    elegir();
                }
            });

            resultadosCliente.appendChild(fila);
        });

        resultadosCliente.hidden = false;
        resultadosCliente.scrollTop = 0;
    }

    // Al elegir un cliente del catálogo se trae su saldo a favor para poder
    // ofrecerlo como descuento.
    async function seleccionarClienteCatalogo(id, nombre) {
        clienteSel = { id: Number(id), nombre: nombre, saldo: 0 };

        try {
            const d = await (await fetch(
                '../../app/controllers/ClienteController.php?accion=saldo&cliente_id=' + encodeURIComponent(id)
            )).json();
            clienteSel.saldo = d.ok ? Number(d.saldo) : 0;
        } catch (e) {
            clienteSel.saldo = 0;
        }
        actualizarSaldo();
    }

    // Saldo a favor que se aplica: todo el disponible, con tope en el total de la
    // venta (no se puede descontar más de lo que cuesta).
    function creditoAAplicar() {
        if (!clienteSel.id || clienteSel.saldo <= 0) return 0;
        const total = items.reduce((s, i) => s + i.precio * i.cantidad, 0);
        return Math.min(clienteSel.saldo, total);
    }

    function actualizarSaldo() {
        if (!clienteSel.id || clienteSel.saldo <= 0) {
            bloqueSaldo.hidden = true;
            bloqueSaldo.innerHTML = '';
            return;
        }

        const total    = items.reduce((s, i) => s + i.precio * i.cantidad, 0);
        const aplicado  = creditoAAplicar();

        bloqueSaldo.hidden = false;
        bloqueSaldo.innerHTML =
            '<span class="saldo-linea">Saldo a favor del cliente: <strong>' + money(clienteSel.saldo) + '</strong></span>' +
            (aplicado > 0
                ? '<span class="saldo-linea saldo-desc">Se aplica a esta venta: <strong>-' + money(aplicado) + '</strong></span>' +
                  '<span class="saldo-linea saldo-pagar">A pagar: <strong>' + money(Math.max(total - aplicado, 0)) + '</strong></span>'
                : '');
    }

    inputCliente.addEventListener('input', () => {
        // Al teclear, se pierde la liga con el cliente del catálogo (y su saldo)
        // hasta que se vuelva a elegir uno de la lista.
        if (clienteSel.id !== null) {
            clienteSel = { id: null, nombre: '', saldo: 0 };
            actualizarSaldo();
        }

        clearTimeout(temporizadorCliente);
        const termino = inputCliente.value.trim();

        // Con una sola letra ya buscamos: el catálogo de clientes es chico y así
        // aparece pronto. Vacío cierra el desplegable.
        if (termino.length < 1) {
            resultadosCliente.hidden = true;
            return;
        }
        temporizadorCliente = setTimeout(() => buscarClientes(termino), 250);
    });

    // El botón de lista alterna el catálogo completo (primeros 50 activos).
    btnListaClientes.addEventListener('click', () => {
        if (!resultadosCliente.hidden) {
            resultadosCliente.hidden = true;
            return;
        }
        buscarClientes('');
        inputCliente.focus();
    });

    // ---------- Items de la venta ----------
    function agregarItem(producto) {
        if (producto.precio_neto === null || producto.precio_neto === undefined) {
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
                codigo_visible: codigoVisible(producto),
                nombre:         producto.nombre,
                // Precio neto ya calculado por el servidor (bruto + % de la casa).
                precio:         Number(producto.precio_neto),
                cantidad:       1,
            });
        }

        inputBuscar.value = '';
        resultados.hidden = true;
        ocultarAviso();
        pintarItems();
    }

    function pintarItems() {
        listaItems.innerHTML = '';

        if (items.length === 0) {
            listaItems.innerHTML = '<p class="venta-vacia">Aún no has agregado piezas a esta venta.</p>';
            totalVenta.textContent = money(0);
            actualizarSaldo();
            return;
        }

        let total = 0;

        items.forEach((item, indice) => {
            const subtotal = item.precio * item.cantidad;
            total += subtotal;

            const fila = document.createElement('div');
            fila.className = 'venta-item';

            // Un solo precio (el neto): ya no hay selector menudeo/mayoreo.
            fila.innerHTML =
                '<p class="item-nombre">' + esc(item.nombre) + '</p>' +
                '<p class="item-meta">' + esc(item.casa_nombre) +
                    ' · <span class="codigo-prod">' + esc(item.codigo_visible) + '</span></p>' +
                '<span class="item-precio">' + money(item.precio) + ' c/u</span>' +
                '<input type="number" class="input-qty input-cantidad" data-i="' + indice + '" ' +
                       'value="' + item.cantidad + '" min="1" step="1" aria-label="Cantidad">' +
                '<div class="item-subtotal">' + money(subtotal) + '</div>' +
                '<button type="button" class="btn-quitar" data-i="' + indice + '" ' +
                        'title="Quitar pieza" aria-label="Quitar pieza">' +
                    '<i class="ph ph-x"></i></button>';

            listaItems.appendChild(fila);
        });

        totalVenta.textContent = money(total);
        actualizarSaldo();
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

    // ---------- Nuevo producto (casa Otros) ----------
    // Para piezas que no estan en ningun catalogo: se capturan a mano, el servidor
    // las guarda en la casa Otros y regresan como un resultado mas del buscador.
    const RUTA_OTRO      = '../../app/controllers/OtroProductoController.php';
    const modalOtro      = document.getElementById('modal-otro');
    const formOtro       = document.getElementById('form-otro');
    const otroNombre     = document.getElementById('otro-nombre');
    const otroBruto      = document.getElementById('otro-bruto');
    const otroFinal      = document.getElementById('otro-final');
    const otroPorcentaje = document.getElementById('otro-porcentaje');
    const otroAviso      = document.getElementById('otro-aviso');
    const btnGuardarOtro = document.getElementById('btn-guardar-otro');

    let porcentajeOtros = 13;

    // Mismo calculo que netoDe() en el servidor: bruto + %, a 2 decimales.
    function actualizarFinalOtro() {
        const bruto = parseFloat(otroBruto.value);
        const neto  = isNaN(bruto) ? 0 : Math.round(bruto * (1 + porcentajeOtros / 100) * 100) / 100;
        otroFinal.textContent = money(neto);
    }

    async function abrirModalOtro() {
        formOtro.reset();
        otroAviso.hidden = true;
        actualizarFinalOtro();
        modalOtro.hidden = false;
        otroNombre.focus();

        // El porcentaje es el de la casa Otros (el admin lo puede cambiar).
        try {
            const d = await (await fetch(RUTA_OTRO + '?accion=info')).json();
            if (d.ok) {
                porcentajeOtros = Number(d.porcentaje);
                otroPorcentaje.textContent = porcentajeOtros.toLocaleString('es-MX');
                actualizarFinalOtro();
            }
        } catch (e) {
            // Se queda con el 13% de siempre; el servidor calcula el precio real.
        }
    }

    function cerrarModalOtro() {
        modalOtro.hidden = true;
    }

    document.getElementById('btn-agregar-otro').addEventListener('click', abrirModalOtro);
    document.getElementById('btn-cerrar-otro').addEventListener('click', cerrarModalOtro);
    document.getElementById('btn-cancelar-otro').addEventListener('click', cerrarModalOtro);
    modalOtro.addEventListener('click', (e) => {
        if (e.target === modalOtro) cerrarModalOtro();
    });
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && !modalOtro.hidden) cerrarModalOtro();
    });
    otroBruto.addEventListener('input', actualizarFinalOtro);

    formOtro.addEventListener('submit', async (e) => {
        e.preventDefault();
        otroAviso.hidden = true;

        btnGuardarOtro.disabled = true;
        btnGuardarOtro.textContent = 'Guardando...';

        try {
            const respuesta = await fetch(RUTA_OTRO, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    nombre:       otroNombre.value.trim(),
                    precio_bruto: otroBruto.value,
                }),
            });
            const datos = await respuesta.json();

            if (!datos.ok) {
                otroAviso.textContent = datos.error || 'No se pudo guardar el producto.';
                otroAviso.className = 'aviso aviso-error';
                otroAviso.hidden = false;
                return;
            }

            cerrarModalOtro();
            agregarItem(datos.producto);
        } catch (err) {
            otroAviso.textContent = 'Error de conexión al guardar.';
            otroAviso.className = 'aviso aviso-error';
            otroAviso.hidden = false;
        } finally {
            btnGuardarOtro.disabled = false;
            btnGuardarOtro.textContent = 'Guardar';
        }
    });

    tipoPago.addEventListener('change', () => {
        camposCredito.hidden = tipoPago.value !== 'credito';
    });

    document.addEventListener('click', (e) => {
        if (!e.target.closest('.form-group')) resultados.hidden = true;

        // El desplegable de clientes se cierra al hacer clic fuera del campo, su
        // lista o el botón de lista (un clic en un resultado ya lo cierra solo).
        if (!e.target.closest('#cliente, #resultados-cliente, #btn-lista-clientes')) {
            resultadosCliente.hidden = true;
        }
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
            cliente:          document.getElementById('cliente').value.trim(),
            cliente_id:       clienteSel.id,
            credito_aplicado: creditoAAplicar(),
            tipo_pago:        tipoPago.value,
            items: items.map((i) => ({
                casa:           i.casa,
                codigo_interno: i.codigo_interno,
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
                // El aviso lleva el boton de imprimir: es el momento en que se
                // entrega la nota al cliente. Sin limite de tiempo para que no
                // desaparezca antes de alcanzar a imprimirla.
                mostrarAvisoConNota(datos.venta_id, datos.total, cuerpo.cliente);
                items = [];
                clienteSel = { id: null, nombre: '', saldo: 0 };
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
