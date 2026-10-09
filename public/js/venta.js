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
    const bloqueGuardarCli  = document.getElementById('bloque-guardar-cliente');
    const chkGuardarCliente = document.getElementById('guardar-cliente');
    const bloqueSaldo       = document.getElementById('bloque-saldo');

    // Cliente elegido del catálogo (con su saldo a favor). Un nombre tecleado a
    // mano no cuenta: solo un cliente elegido de la lista lleva id y saldo.
    let clienteSel = { id: null, nombre: '', saldo: 0 };

    // Si el saldo a favor del cliente se descuenta en esta venta. Va marcado por
    // omision (es lo que se espera), pero se puede quitar cuando el cliente
    // prefiere guardarlo para despues o que se le devuelva en efectivo.
    let aplicarSaldo = true;

    // Cada linea guarda su propia casa: un mismo ticket puede mezclar proveedores.
    let items = [];

    const money = (n) => '$' + n.toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    // Para el editor de porcentaje (solo admin): mismo cálculo del neto que
    // netoDe() en el servidor, y textos con signo / porcentaje.
    const dinero  = (n) => (n === null || n === '' ? 'sin precio' : money(Number(n)));
    const pctTexto = (n) => Number(n).toLocaleString('es-MX', { maximumFractionDigits: 2 });
    const netoJS  = (bruto, pct) => (bruto === null || bruto === '' || isNaN(parseFloat(bruto)))
        ? null
        : Math.round(parseFloat(bruto) * (1 + Number(pct) / 100) * 100) / 100;

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

    // Aviso de venta guardada con acceso directo a la nota imprimible y, si se
    // acaba de dar de alta al cliente, a llenarle los datos.
    function mostrarAvisoConNota(ventaId, total, cliente, clienteId, clienteNuevo, extra) {
        clearTimeout(temporizadorAviso);

        aviso.className = 'aviso aviso-ok aviso-con-accion';
        aviso.hidden = false;
        aviso.innerHTML = '';

        const texto = document.createElement('span');
        texto.textContent = 'Venta #' + ventaId + ' guardada por $' + total + '.' +
            (extra ? ' ' + extra : '');
        aviso.appendChild(texto);

        const boton = document.createElement('button');
        boton.type = 'button';
        boton.className = 'chip btn-imprimir-nota';
        boton.innerHTML = '<i class="ph ph-printer"></i> Imprimir nota';
        boton.addEventListener('click', () => abrirCapturaNota(ventaId, cliente, clienteId));
        aviso.appendChild(boton);

        // El cliente se guardó solo con su nombre. Aquí se ofrece completarlo,
        // sin obligar: el formulario aparece únicamente si le dan clic.
        if (clienteNuevo && clienteId) {
            texto.textContent += ' ' + cliente + ' quedó dado de alta con su nombre.';

            const completar = document.createElement('button');
            completar.type = 'button';
            completar.className = 'chip';
            completar.innerHTML = '<i class="ph ph-address-book"></i> Llenar sus datos';
            completar.addEventListener('click',
                () => abrirDatosCliente(clienteId, cliente, ventaId, total));
            aviso.appendChild(completar);
        }
    }

    // ---------- Borrador de la venta ----------
    // Capturar una venta toma su tiempo y a media captura hay que ir al
    // historial, o se recarga la página sin querer. Lo que se lleva se guarda en
    // el navegador y vuelve al regresar, para no tener que escarbar otra vez
    // todos los productos.

    const BORRADOR = 'gabe:venta:' + (window.USUARIO_ID || 0);

    // Pasado este tiempo el borrador ya no es "la venta que estaba haciendo":
    // se descarta solo para no revivir algo de ayer.
    const HORAS_BORRADOR = 12;

    function guardarBorrador() {
        // Sin piezas no hay nada que recordar; además así se limpia solo cuando
        // se vacía el ticket a mano.
        if (items.length === 0) {
            borrarBorrador();
            return;
        }

        try {
            localStorage.setItem(BORRADOR, JSON.stringify({
                guardado_en: Date.now(),
                items:       items,
                cliente:     inputCliente.value,
                cliente_sel: clienteSel,
                aplicar_saldo: aplicarSaldo,
                guardar_cliente: chkGuardarCliente ? chkGuardarCliente.checked : false,
                tipo_pago:   tipoPago.value,
                pago_inicial: pagoInicial.value,
                vencimiento: fechaVenc.value,
            }));
        } catch (e) {
            // Sin espacio o con el almacenamiento bloqueado: la venta sigue,
            // simplemente no se recuerda.
        }
    }

    function borrarBorrador() {
        try {
            localStorage.removeItem(BORRADOR);
        } catch (e) { /* nada que hacer */ }
    }

    function leerBorrador() {
        try {
            const crudo = localStorage.getItem(BORRADOR);
            if (!crudo) return null;

            const b = JSON.parse(crudo);

            if (!b || !Array.isArray(b.items) || b.items.length === 0) {
                borrarBorrador();
                return null;
            }

            const horas = (Date.now() - Number(b.guardado_en || 0)) / 3600000;

            if (horas > HORAS_BORRADOR) {
                borrarBorrador();
                return null;
            }

            return b;

        } catch (e) {
            borrarBorrador();
            return null;
        }
    }

    /**
     * Vuelve a preguntar el precio de lo que está en el ticket. El servidor
     * cobra siempre lo que dice el catálogo, así que si alguien movió un precio
     * mientras la venta estaba a medias, hay que enterarse ANTES de cobrar y no
     * después.
     *
     * @return array Los productos que cambiaron, para poder avisarlo.
     */
    async function revalidarPrecios() {
        if (items.length === 0) return [];

        const codigos = items.map((i) => i.codigo_interno).join(',');

        let productos;

        try {
            const datos = await (await fetch(
                '../../app/controllers/ProductoController.php?accion=precios&codigos=' +
                encodeURIComponent(codigos)
            )).json();

            if (!datos.ok) return [];
            productos = datos.productos;

        } catch (e) {
            // Sin conexión se deja lo que hay: el servidor igual cobrará bien.
            return [];
        }

        const porCodigo = {};
        productos.forEach((p) => { porCodigo[p.codigo_interno] = p; });

        const cambios = [];

        items = items.filter((item) => {
            const actual = porCodigo[item.codigo_interno];

            // Lo que se dio de baja del catálogo ya no se puede vender.
            if (!actual || !actual.activo || actual.precio_neto === null) {
                cambios.push({ nombre: item.nombre, fuera: true });
                return false;
            }

            const nuevo = Number(actual.precio_neto);

            if (nuevo !== Number(item.precio)) {
                cambios.push({ nombre: item.nombre, antes: Number(item.precio), ahora: nuevo });
                item.precio = nuevo;
            }

            return true;
        });

        return cambios;
    }

    function restaurarBorrador() {
        const b = leerBorrador();
        if (!b) return;

        items = b.items;

        inputCliente.value = b.cliente || '';
        clienteSel = b.cliente_sel || { id: null, nombre: '', saldo: 0 };
        aplicarSaldo = b.aplicar_saldo !== false;

        if (chkGuardarCliente) chkGuardarCliente.checked = !!b.guardar_cliente;

        tipoPago.value = b.tipo_pago || 'contado';
        camposCredito.hidden = tipoPago.value !== 'credito';
        pagoInicial.value = b.pago_inicial || '';
        fechaVenc.value = b.vencimiento || '';

        pintarItems();
        actualizarGuardarCliente();

        // Se avisa con la opción de tirarlo: el vendedor tiene que poder empezar
        // de cero sin ir quitando pieza por pieza.
        const piezas = items.reduce((s, i) => s + i.cantidad, 0);

        clearTimeout(temporizadorAviso);
        aviso.className = 'aviso aviso-info aviso-con-accion';
        aviso.hidden = false;
        aviso.innerHTML = '';

        const texto = document.createElement('span');
        texto.textContent = 'Se recuperó la venta que tenías a medias: ' +
            items.length + ' producto' + (items.length === 1 ? '' : 's') +
            ' (' + piezas + ' pieza' + (piezas === 1 ? '' : 's') + ').';
        aviso.appendChild(texto);

        const boton = document.createElement('button');
        boton.type = 'button';
        boton.className = 'chip';
        boton.innerHTML = '<i class="ph ph-trash"></i> Empezar de cero';
        boton.addEventListener('click', vaciarVenta);
        aviso.appendChild(boton);

        avisarPreciosCambiados(texto);
    }

    /**
     * Revalida los precios y, si algo se movió mientras la venta estaba
     * guardada, lo cuenta DENTRO del mismo aviso de recuperación: así no se
     * pierde el botón de empezar de cero, que es lo que se querría hacer si los
     * precios ya no son los de antes.
     */
    async function avisarPreciosCambiados(texto) {
        const cambios = await revalidarPrecios();

        if (cambios.length === 0) return;

        pintarItems();   // el ticket ya trae los precios nuevos

        const fuera   = cambios.filter((c) => c.fuera);
        const movidos = cambios.filter((c) => !c.fuera);

        const partes = [];

        if (movidos.length > 0) {
            partes.push('Cambió el precio de ' + movidos.map((c) =>
                c.nombre + ' (' + money(c.antes) + ' → ' + money(c.ahora) + ')').join('; ') + '.');
        }

        if (fuera.length > 0) {
            partes.push('Se quitaron por no estar ya en el catálogo: ' +
                fuera.map((c) => c.nombre).join(', ') + '.');
        }

        // Si se vació el ticket no hay nada que recuperar.
        if (items.length === 0) {
            mostrarAviso(partes.join(' ') + ' No quedó nada en la venta.', 'alerta', 0);
            borrarBorrador();
            return;
        }

        aviso.className = 'aviso aviso-alerta aviso-con-accion';
        texto.textContent += ' ' + partes.join(' ');
    }

    /** Deja la pantalla como recién abierta, sin borrador. */
    function vaciarVenta() {
        items = [];
        clienteSel = { id: null, nombre: '', saldo: 0 };
        aplicarSaldo = true;

        form.reset();
        camposCredito.hidden = true;

        borrarBorrador();
        pintarItems();
        actualizarGuardarCliente();
        ocultarAviso();
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

            // El vendedor no ve de que casa es la pieza: la etiqueta de casa solo
            // se pinta para el admin.
            const badgeCasa = window.ES_ADMIN
                ? '<span class="res-casa ' + claseCasa + '">' + esc(p.nombre_casa) + '</span>'
                : '';

            fila.innerHTML =
                '<span class="res-nombre">' + esc(p.nombre) + badgeCasa + '</span>' +
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
        actualizarGuardarCliente();

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
        if (!clienteSel.id || clienteSel.saldo <= 0 || !aplicarSaldo) return 0;
        const total = items.reduce((s, i) => s + i.precio * i.cantidad, 0);
        return Math.min(clienteSel.saldo, total);
    }

    /**
     * La casilla de "guardar este cliente" solo tiene sentido cuando hay un
     * nombre escrito que no corresponde a nadie del catalogo. Al elegir uno de
     * la lista desaparece: ese ya esta registrado.
     */
    function actualizarGuardarCliente() {
        const hayNombre = inputCliente.value.trim() !== '';
        const mostrar   = hayNombre && clienteSel.id === null;

        bloqueGuardarCli.hidden = !mostrar;

        if (!mostrar) {
            chkGuardarCliente.checked = false;
        }
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
            '<label class="saldo-casilla">' +
                '<input type="checkbox" id="usar-saldo"' + (aplicarSaldo ? ' checked' : '') + '>' +
                ' Descontar el saldo en esta venta' +
            '</label>' +
            (aplicado > 0
                ? '<span class="saldo-linea saldo-desc">Se aplica a esta venta: <strong>-' + money(aplicado) + '</strong></span>' +
                  '<span class="saldo-linea saldo-pagar">A pagar: <strong>' + money(Math.max(total - aplicado, 0)) + '</strong></span>'
                : '<span class="saldo-linea saldo-guardado">El saldo se queda a favor del cliente para otra compra.</span>');
    }

    // Cliente, tipo de pago, anticipo y vencimiento: cambian fuera del ticket,
    // así que se guardan por su cuenta.
    ['input', 'change'].forEach((evento) => {
        form.addEventListener(evento, (e) => {
            if (e.target.closest('#lista-items')) return;   // ya lo cubre pintarItems
            guardarBorrador();
        });
    });

    bloqueSaldo.addEventListener('change', (e) => {
        if (e.target.id !== 'usar-saldo') return;

        aplicarSaldo = e.target.checked;
        actualizarSaldo();
        pintarItems();   // el total a pagar cambia
    });

    inputCliente.addEventListener('input', () => {
        // Al teclear, se pierde la liga con el cliente del catálogo (y su saldo)
        // hasta que se vuelva a elegir uno de la lista.
        if (clienteSel.id !== null) {
            clienteSel = { id: null, nombre: '', saldo: 0 };
            aplicarSaldo = true;
            actualizarSaldo();
        }

        actualizarGuardarCliente();

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

        // Todos los cambios del ticket pasan por aquí, así que es el lugar para
        // dejar guardado el borrador.
        guardarBorrador();

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

            // Un solo precio (el neto): ya no hay selector menudeo/mayoreo. La casa
            // del renglon solo se muestra al admin (el vendedor no la ve).
            const metaCasa = window.ES_ADMIN ? esc(item.casa_nombre) + ' · ' : '';

            fila.innerHTML =
                '<p class="item-nombre">' + esc(item.nombre) + '</p>' +
                '<p class="item-meta">' + metaCasa +
                    '<span class="codigo-prod">' + esc(item.codigo_visible) + '</span>' +
                    // Solo el admin puede ajustar el % de un producto durante la venta.
                    (window.ES_ADMIN
                        ? ' · <button type="button" class="link-editar-pct" data-i="' + indice + '">Editar precio</button>'
                        : '') +
                    '</p>' +
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
        const editarPct = e.target.closest('.link-editar-pct');
        if (editarPct) {
            abrirPorcentaje(Number(editarPct.dataset.i));
            return;
        }

        const boton = e.target.closest('.btn-quitar');
        if (!boton) return;
        items.splice(Number(boton.dataset.i), 1);
        pintarItems();
    });

    // ---------- Editar el porcentaje de un producto (solo admin) ----------
    // Cambia el porcentaje con el que se calcula el neto de ESTE producto (sin
    // tocar a los demás de la casa) y actualiza al momento el precio en el
    // ticket. El cambio queda guardado para las ventas futuras.
    const RUTA_PCT             = '../../app/controllers/PorcentajeProductoController.php';
    const modalPorcentaje      = document.getElementById('modal-porcentaje');
    const contenidoPorcentaje  = document.getElementById('contenido-porcentaje');

    // Índice del renglón del ticket que se está editando, para actualizar su
    // precio cuando el servidor confirme el cambio.
    let indicePorcentaje = null;

    function cerrarModalPorcentaje() {
        modalPorcentaje.hidden = true;
        indicePorcentaje = null;
    }

    async function abrirPorcentaje(indice) {
        const item = items[indice];
        if (!item) return;

        indicePorcentaje = indice;
        contenidoPorcentaje.innerHTML = '<p class="venta-vacia">Cargando porcentaje actual...</p>';
        modalPorcentaje.hidden = false;

        try {
            const datos = await (await fetch(
                RUTA_PCT + '?accion=consultar&codigo=' + encodeURIComponent(item.codigo_interno)
            )).json();

            if (!datos.ok) {
                contenidoPorcentaje.innerHTML = '<p class="aviso aviso-error">' +
                    esc(datos.error || 'No se pudo cargar el producto') + '</p>';
                return;
            }

            pintarFormularioPorcentaje(datos.producto);

        } catch (e) {
            contenidoPorcentaje.innerHTML = '<p class="aviso aviso-error">Error de conexión.</p>';
        }
    }

    function pintarFormularioPorcentaje(p) {
        const bruto    = p.precio_mayoreo;
        const casaPct  = Number(p.porcentaje_casa);
        const efectivo = Number(p.porcentaje_efectivo);

        contenidoPorcentaje.innerHTML =
            '<h2 class="detalle-titulo">Editar precio</h2>' +
            '<div class="detalle-cabecera">' +
                '<p><strong>' + esc(p.nombre) + '</strong></p>' +
                '<p class="detalle-sub"><span class="codigo-prod">' + esc(codigoVisible(p)) + '</span>' +
                    (p.marca ? ' · ' + esc(p.marca) : '') + '</p>' +
            '</div>' +
            '<p class="aviso aviso-info">El precio de venta (neto) sale del bruto más un porcentaje. ' +
                'Puedes cambiar los dos <strong>solo para este producto</strong>; los demás de ' +
                esc(p.etiqueta_casa) + ' no se tocan. Lo que guardes queda para las ventas futuras.</p>' +
            '<div class="form-group">' +
                '<label for="pct-bruto">Precio bruto</label>' +
                '<input type="number" id="pct-bruto" class="form-control" min="0" step="0.01" ' +
                       'value="' + (bruto === null || bruto === '' ? '' : Number(bruto).toFixed(2)) + '">' +
            '</div>' +
            '<p class="pct-actual">Actualmente: <strong>' + pctTexto(efectivo) + '%</strong> → ' +
                '<strong>' + dinero(netoJS(bruto, efectivo)) + '</strong> ' +
                '<span class="detalle-sub">(' + (p.usa_casa
                    ? 'porcentaje de la casa'
                    : 'porcentaje propio; la casa usa ' + pctTexto(casaPct) + '%') + ')</span></p>' +
            '<div class="form-group">' +
                '<label for="pct-nuevo">Nuevo porcentaje (%)</label>' +
                '<input type="number" id="pct-nuevo" class="form-control" min="0" max="999.99" step="0.01" ' +
                       'value="' + Number(efectivo).toFixed(2) + '">' +
            '</div>' +
            '<p id="pct-nuevo-neto" class="detalle-sub"></p>' +
            '<label class="pct-casilla">' +
                '<input type="checkbox" id="pct-usar-casa"' + (p.usa_casa ? ' checked' : '') + '> ' +
                'Usar el porcentaje de la casa (' + pctTexto(casaPct) + '%)' +
            '</label>' +
            '<div id="pct-aviso" class="aviso" hidden></div>' +
            '<div class="detalle-acciones">' +
                '<button type="button" class="chip" id="btn-cancelar-porcentaje">Cancelar</button>' +
                '<button type="button" class="btn-save" id="btn-guardar-porcentaje" ' +
                    'data-codigo="' + esc(p.codigo_interno) + '">Guardar</button>' +
            '</div>';

        const inp     = document.getElementById('pct-nuevo');
        const chk     = document.getElementById('pct-usar-casa');
        const linea   = document.getElementById('pct-nuevo-neto');
        const campoBr = document.getElementById('pct-bruto');

        function refrescar() {
            const pct = chk.checked ? casaPct : parseFloat(inp.value);
            inp.disabled = chk.checked;
            if (chk.checked) inp.value = Number(casaPct).toFixed(2);

            // El neto se calcula con lo que hay escrito en los dos campos, para
            // ver el precio final antes de guardar.
            const brutoEscrito = campoBr.value;

            linea.innerHTML = (isNaN(pct) || brutoEscrito === '')
                ? 'Escribe el precio bruto y el porcentaje para ver el precio de venta.'
                : 'Nuevo precio de venta: <strong>' + dinero(netoJS(brutoEscrito, pct)) + '</strong> ' +
                  '(' + dinero(brutoEscrito) + ' + ' + pctTexto(pct) + '%)';
        }

        chk.addEventListener('change', refrescar);
        inp.addEventListener('input', refrescar);
        campoBr.addEventListener('input', refrescar);
        refrescar();
    }

    async function guardarPorcentaje(codigo) {
        const avisoPct = document.getElementById('pct-aviso');
        const boton    = document.getElementById('btn-guardar-porcentaje');
        const usarCasa = document.getElementById('pct-usar-casa').checked;

        boton.disabled = true;
        boton.textContent = 'Guardando...';

        try {
            const datos = await (await fetch(RUTA_PCT, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    codigo:         codigo,
                    porcentaje:     usarCasa ? null : document.getElementById('pct-nuevo').value,
                    precio_mayoreo: document.getElementById('pct-bruto').value,
                }),
            })).json();

            if (!datos.ok) {
                avisoPct.textContent = datos.error || 'No se pudo guardar.';
                avisoPct.className = 'aviso aviso-error';
                avisoPct.hidden = false;
                return;
            }

            // Actualiza el precio del renglón en el ticket con el neto nuevo que
            // devuelve el servidor (el que se cobrará al guardar la venta).
            if (indicePorcentaje !== null && items[indicePorcentaje] && datos.neto !== null) {
                items[indicePorcentaje].precio = Number(datos.neto);
            }

            cerrarModalPorcentaje();
            pintarItems();

            if (datos.cambios === 0) {
                mostrarAviso(datos.mensaje, 'ok', 5);
            } else {
                // Se dice exactamente qué se movió: el precio, el porcentaje o
                // los dos, y en cuánto quedó el precio de venta.
                const partes = [];

                if (datos.cambio_precio) {
                    partes.push('bruto ' + dinero(datos.precio_anterior) +
                                ' → ' + dinero(datos.precio_mayoreo));
                }

                if (datos.cambio_porcentaje) {
                    partes.push(datos.usa_casa
                        ? 'vuelve al ' + pctTexto(datos.porcentaje_casa) + '% de la casa'
                        : pctTexto(datos.porcentaje_anterior) + '% → ' +
                          pctTexto(datos.porcentaje_efectivo) + '%');
                }

                mostrarAviso(datos.nombre + ': ' + partes.join(' · ') +
                    (datos.neto !== null ? '. Precio de venta ' + money(Number(datos.neto)) + '.' : '.'),
                    'ok', 6);
            }

        } catch (e) {
            avisoPct.textContent = 'Error de conexión.';
            avisoPct.className = 'aviso aviso-error';
            avisoPct.hidden = false;
        } finally {
            boton.disabled = false;
            boton.textContent = 'Guardar';
        }
    }

    document.getElementById('btn-cerrar-porcentaje').addEventListener('click', cerrarModalPorcentaje);

    modalPorcentaje.addEventListener('click', (e) => {
        if (e.target === modalPorcentaje) cerrarModalPorcentaje();
    });

    contenidoPorcentaje.addEventListener('click', (e) => {
        if (e.target.id === 'btn-cancelar-porcentaje') {
            cerrarModalPorcentaje();
            return;
        }
        const btnGuardar = e.target.closest('#btn-guardar-porcentaje');
        if (btnGuardar) guardarPorcentaje(btnGuardar.dataset.codigo);
    });

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && !modalPorcentaje.hidden) cerrarModalPorcentaje();
    });

    // ---------- Nuevo producto (casa Otros) ----------
    // Para piezas que no estan en ningun catalogo: se capturan a mano, el servidor
    // las guarda en la casa Otros y regresan como un resultado mas del buscador.
    const RUTA_OTRO      = '../../app/controllers/OtroProductoController.php';
    // ---------- Captura de los datos del cliente para la nota ----------
    // La forma vive en public/js/nota_datos.js: es la misma del historial, y
    // avisa cuando al cliente registrado le faltan datos.
    const modalNota     = document.getElementById('modal-nota');
    const contenidoNota = document.getElementById('contenido-nota');

    function cerrarModalNota() {
        modalNota.hidden = true;
    }

    function abrirCapturaNota(ventaId, cliente, clienteId) {
        modalNota.hidden = false;

        NotaDatos.abrir({
            ventaId:    ventaId,
            cliente:    cliente || '',
            clienteId:  Number(clienteId) || 0,
            contenedor: contenidoNota,
            rutaApp:    '../../app/controllers/',
            rutaNota:   '../ventas/nota.php',
            alCerrar:   cerrarModalNota,
        });
    }

    function abrirDatosCliente(clienteId, nombre, ventaId, total) {
        modalNota.hidden = false;

        NotaDatos.completarCliente({
            clienteId:  clienteId,
            nombre:     nombre,
            contenedor: contenidoNota,
            rutaApp:    '../../app/controllers/',
            alCerrar:   (guardados) => {
                cerrarModalNota();

                // Se rehace el aviso en vez de reemplazarlo: así no se pierde el
                // botón de imprimir la nota, que es lo que sigue.
                mostrarAvisoConNota(
                    ventaId, total, nombre, clienteId, false,
                    guardados > 0 ? 'Datos de ' + nombre + ' guardados.' : ''
                );
            },
        });
    }

    document.getElementById('btn-cerrar-nota').addEventListener('click', cerrarModalNota);

    modalNota.addEventListener('click', (e) => {
        if (e.target === modalNota) cerrarModalNota();
    });

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && !modalNota.hidden) cerrarModalNota();
    });

    const modalOtro      = document.getElementById('modal-otro');
    const formOtro       = document.getElementById('form-otro');
    const otroNombre     = document.getElementById('otro-nombre');
    const otroCasa       = document.getElementById('otro-casa');
    const otroBruto      = document.getElementById('otro-bruto');
    const otroFinal      = document.getElementById('otro-final');
    const otroPorcentaje = document.getElementById('otro-porcentaje');
    const otroAviso      = document.getElementById('otro-aviso');
    const btnGuardarOtro = document.getElementById('btn-guardar-otro');

    // Casas disponibles con su porcentaje por defecto; se llenan al abrir el
    // modal. La casa "Otros" es la que queda seleccionada de entrada.
    let casasOtro = [];

    // Mismo calculo que netoDe() en el servidor: bruto + %, a 2 decimales.
    function actualizarFinalOtro() {
        const bruto = parseFloat(otroBruto.value);
        const pct   = parseFloat(otroPorcentaje.value);
        const neto  = (isNaN(bruto) || isNaN(pct)) ? 0 : Math.round(bruto * (1 + pct / 100) * 100) / 100;
        otroFinal.textContent = money(neto);
    }

    function llenarCasasOtro(codigoOtros) {
        otroCasa.innerHTML = casasOtro.map((c) =>
            '<option value="' + esc(c.codigo_casa) + '"' +
            (c.codigo_casa === codigoOtros ? ' selected' : '') + '>' +
            esc(c.etiqueta) + '</option>'
        ).join('');
    }

    // Al elegir una casa se pone su porcentaje por defecto; el usuario lo puede
    // cambiar despues (queda como porcentaje propio del producto).
    function aplicarPorcentajeDeCasa() {
        const casa = casasOtro.find((c) => c.codigo_casa === otroCasa.value);
        if (casa) {
            otroPorcentaje.value = Number(casa.porcentaje).toFixed(2);
        }
        actualizarFinalOtro();
    }

    async function abrirModalOtro() {
        formOtro.reset();
        otroAviso.hidden = true;
        modalOtro.hidden = false;
        otroNombre.focus();

        // Fallback por si la lista no llega: el servidor igual usa Otros al 13%.
        otroPorcentaje.value = '13';

        try {
            const d = await (await fetch(RUTA_OTRO + '?accion=info')).json();
            if (d.ok && Array.isArray(d.casas)) {
                casasOtro = d.casas;
                llenarCasasOtro(d.otros);
                aplicarPorcentajeDeCasa();
            }
        } catch (e) {
            // Se queda con el fallback; el servidor calcula el precio real.
        }
        actualizarFinalOtro();
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
    otroPorcentaje.addEventListener('input', actualizarFinalOtro);
    otroCasa.addEventListener('change', aplicarPorcentajeDeCasa);

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
                    codigo_casa:  otroCasa.value || '',
                    porcentaje:   otroPorcentaje.value,
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
            guardar_cliente:  chkGuardarCliente.checked,
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
                // El id sale de la respuesta y no del cuerpo: cuando la venta dio
                // de alta al cliente, el id lo asignó el servidor.
                mostrarAvisoConNota(datos.venta_id, datos.total, cuerpo.cliente,
                                    datos.cliente_id, datos.cliente_nuevo);
                items = [];
                clienteSel = { id: null, nombre: '', saldo: 0 };
                aplicarSaldo = true;
                form.reset();
                actualizarGuardarCliente();

                // La venta ya quedó registrada: el borrador perdió sentido.
                borrarBorrador();
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

    // Si quedó una venta a medias (se cambió de pantalla, se recargó), vuelve.
    restaurarBorrador();

    pintarItems();
});
