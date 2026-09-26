document.addEventListener('DOMContentLoaded', () => {
    const lista = document.getElementById('lista-clientes');
    if (!lista) return;

    const aviso     = document.getElementById('aviso-clientes');
    const modal     = document.getElementById('modal-cliente');
    const contenido = document.getElementById('contenido-cliente');
    const btnNuevo  = document.getElementById('btn-nuevo-cliente');
    const btnCerrar = document.getElementById('btn-cerrar-cliente');
    const buscador  = document.getElementById('buscar-cliente');
    const conteo    = document.getElementById('conteo-clientes');

    // El catálogo completo, tal como llegó. El buscador filtra sobre esto en
    // memoria: son unos cientos de clientes, así que no vale la pena ir al
    // servidor en cada tecla.
    let todos = [];

    const RUTA = '../../app/controllers/ClienteController.php';

    // Dias de visita. Solo de referencia: no condicionan nada, el usuario elige
    // uno o deja "Sin asignar".
    const DIAS = ['Lunes', 'Martes', 'Miercoles', 'Jueves', 'Viernes', 'Sabado'];

    function esc(texto) {
        const div = document.createElement('div');
        div.textContent = texto === null || texto === undefined ? '' : texto;
        return div.innerHTML;
    }

    // Enlace a Google Maps: si el cliente pego un enlace propio (el "pin"
    // exacto), se usa ese; si no, se arma una busqueda con la direccion y el CP.
    function enlaceMapa(cliente) {
        if (cliente.maps_url) return cliente.maps_url;

        const partes = [cliente.direccion, cliente.codigo_postal].filter(Boolean).join(' ');
        if (!partes) return '';

        return 'https://www.google.com/maps/search/?api=1&query=' + encodeURIComponent(partes);
    }

    let temporizador = null;

    function mostrarAviso(texto, tipo, segundos = 5) {
        clearTimeout(temporizador);
        aviso.textContent = texto;
        aviso.className = 'aviso aviso-' + tipo;
        aviso.hidden = false;
        if (segundos > 0) temporizador = setTimeout(() => { aviso.hidden = true; }, segundos * 1000);
    }

    // ---------- Listado ----------
    async function cargar() {
        lista.innerHTML = '<p class="venta-vacia">Cargando...</p>';

        try {
            const respuesta = await fetch(RUTA + '?accion=listar');
            const datos = await respuesta.json();

            if (!datos.ok) {
                mostrarAviso(datos.error || 'No se pudo cargar la lista.', 'error', 0);
                lista.innerHTML = '';
                return;
            }

            todos = datos.clientes;
            filtrar();

        } catch (e) {
            mostrarAviso('Error de conexión.', 'error', 0);
            lista.innerHTML = '';
        }
    }

    /** Nombre de pila y apellidos, en un solo texto. */
    function nombrePersona(c) {
        return [c.nombres, c.apellido_paterno, c.apellido_materno].filter(Boolean).join(' ');
    }

    /** Como se identifica al cliente: el comercio manda sobre la persona. */
    function titulo(c) {
        return c.nombre_comercio || nombrePersona(c);
    }

    function filtrar() {
        const termino = buscador.value.trim().toLowerCase();

        // Se busca en todo lo que sirve para dar con un cliente en el mostrador:
        // comercio, nombre, teléfono, RFC, dirección y día de visita.
        const encontrados = termino === ''
            ? todos
            : todos.filter((c) => [
                c.nombre_comercio, nombrePersona(c), c.telefono,
                c.rfc, c.direccion, c.codigo_postal, c.dia_visita, c.email,
              ].filter(Boolean).join(' ').toLowerCase().includes(termino));

        pintar(encontrados);

        conteo.textContent = termino === ''
            ? todos.length + ' cliente' + (todos.length === 1 ? '' : 's')
            : encontrados.length + ' de ' + todos.length + ' cliente' +
              (todos.length === 1 ? '' : 's');
    }

    function pintar(clientes) {
        lista.innerHTML = '';

        if (!clientes.length) {
            lista.innerHTML = '<p class="venta-vacia">' +
                (todos.length === 0
                    ? 'Aún no hay clientes dados de alta.'
                    : 'Ningún cliente coincide con lo que buscaste.') + '</p>';
            return;
        }

        clientes.forEach((c) => {
            const fila = document.createElement('div');
            fila.className = 'venta-fila usuario-fila' + (Number(c.activo) ? '' : ' usuario-inactivo');

            // Toda la fila abre la ficha; los botones de acción se atienden antes.
            fila.setAttribute('role', 'button');
            fila.tabIndex = 0;

            const nombre = nombrePersona(c);
            const tituloFila = c.nombre_comercio ? esc(c.nombre_comercio) : esc(nombre);
            const sub        = c.nombre_comercio ? esc(nombre) : '';

            const meta = [
                c.telefono ? esc(c.telefono) : '',
                c.dia_visita ? 'Visita: ' + esc(c.dia_visita) : '',
                c.rfc ? esc(c.rfc) : '',
            ].filter(Boolean).join(' · ');

            const mapa = enlaceMapa(c);
            const etiquetaEstado = Number(c.activo)
                ? ''
                : '<span class="etiqueta etiqueta-pendiente">Inactivo</span>';

            fila.innerHTML =
                '<div class="venta-fila-datos">' +
                    '<p class="venta-fila-cliente">' + tituloFila +
                        (sub ? ' <span class="detalle-sub">' + sub + '</span>' : '') + '</p>' +
                    (meta ? '<p class="venta-fila-meta">' + meta + '</p>' : '') +
                    (c.direccion || mapa
                        ? '<p class="venta-fila-meta">' + esc(c.direccion || '') +
                          (mapa
                            ? ' <a href="' + esc(mapa) + '" target="_blank" rel="noopener" class="enlace-mapa">' +
                              '<i class="ph ph-map-pin"></i> Ver en mapa</a>'
                            : '') +
                          '</p>'
                        : '') +
                '</div>' +
                '<div class="venta-fila-derecha">' +
                    '<div class="usuario-etiquetas">' + etiquetaEstado + '</div>' +
                    '<div class="usuario-acciones">' +
                        '<button type="button" class="chip btn-editar-cliente" data-id="' + c.id + '">Editar</button>' +
                        '<button type="button" class="chip chip-peligro btn-eliminar-cliente" data-id="' + c.id +
                            '" data-nombre="' + esc(c.nombre_comercio || nombre) + '">Eliminar</button>' +
                    '</div>' +
                '</div>';

            fila.dataset.cliente = JSON.stringify(c);
            lista.appendChild(fila);
        });
    }

    // ---------- Ficha del cliente ----------
    // Todo lo que se sabe del cliente en una sola vista, ordenado. Se abre al
    // dar clic en su renglón; desde aquí se pasa a editarlo.
    const money = (n) => '$' + Number(n).toLocaleString('es-MX',
        { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    function renglon(etiqueta, valor, extra) {
        const vacio = valor === null || valor === undefined || String(valor).trim() === '';

        return '<div class="ficha-renglon">' +
            '<span class="ficha-etiqueta">' + esc(etiqueta) + '</span>' +
            '<span class="ficha-valor' + (vacio ? ' ficha-falta' : '') + '">' +
                (vacio ? 'Sin registrar' : esc(valor)) +
                (!vacio && extra ? ' ' + extra : '') +
            '</span>' +
        '</div>';
    }

    async function abrirFicha(c) {
        const mapa = enlaceMapa(c);
        const persona = nombrePersona(c);

        // Lo que le falta para que su nota salga completa.
        const faltan = [
            ['dirección', c.direccion],
            ['C.P.', c.codigo_postal],
            ['teléfono', c.telefono],
        ].filter(([, v]) => !v || String(v).trim() === '').map(([n]) => n);

        contenido.innerHTML =
            '<h2 class="detalle-titulo">' + esc(titulo(c)) + '</h2>' +
            (c.nombre_comercio && persona
                ? '<p class="detalle-sub">' + esc(persona) + '</p>'
                : '') +
            (Number(c.activo) ? '' : '<p class="aviso aviso-alerta">Este cliente está inactivo.</p>') +
            (faltan.length > 0
                ? '<p class="aviso aviso-alerta">Le falta' + (faltan.length === 1 ? '' : 'n') + ' ' +
                  // "C.P." ya trae punto: no se le pone otro.
                  esc(faltan.join(', ')) + (faltan[faltan.length - 1].endsWith('.') ? '' : '.') +
                  ' Sin eso, su nota impresa sale con ' +
                  (faltan.length === 1 ? 'ese renglón' : 'esos renglones') + ' en blanco.</p>'
                : '') +

            '<div class="ficha">' +
                renglon('Teléfono', c.telefono) +
                renglon('Correo', c.email) +
                renglon('Dirección', c.direccion,
                    mapa ? '<a href="' + esc(mapa) + '" target="_blank" rel="noopener" class="enlace-mapa">' +
                           '<i class="ph ph-map-pin"></i> Ver en mapa</a>' : '') +
                renglon('C.P.', c.codigo_postal) +
                renglon('RFC', c.rfc) +
                renglon('Día de visita', c.dia_visita) +
                '<div class="ficha-renglon">' +
                    '<span class="ficha-etiqueta">Saldo a favor</span>' +
                    '<span class="ficha-valor" id="ficha-saldo">Consultando…</span>' +
                '</div>' +
            '</div>' +

            '<div class="detalle-acciones">' +
                '<button type="button" class="chip" id="btn-cerrar-ficha">Cerrar</button>' +
                '<button type="button" class="btn-save" id="btn-editar-ficha" ' +
                    'data-id="' + c.id + '">Editar datos</button>' +
            '</div>';

        modal.hidden = false;

        // El saldo no es una columna: se calcula sumando sus ventas, por eso va
        // aparte y la ficha no se queda esperándolo para mostrarse.
        try {
            const datos = await (await fetch(
                RUTA + '?accion=saldo&cliente_id=' + encodeURIComponent(c.id)
            )).json();

            const saldo = datos.ok ? Number(datos.saldo) : 0;
            const caja  = document.getElementById('ficha-saldo');

            if (caja) {
                caja.textContent = saldo > 0 ? money(saldo) : 'Sin saldo';
                caja.className = 'ficha-valor' + (saldo > 0 ? ' ficha-saldo-favor' : ' ficha-falta');
            }
        } catch (e) {
            const caja = document.getElementById('ficha-saldo');
            if (caja) caja.textContent = 'No se pudo consultar';
        }
    }

    // ---------- Formulario ----------
    function opcionesDia(seleccionado) {
        return '<option value="">Sin asignar</option>' +
            DIAS.map((d) =>
                '<option value="' + d + '"' + (d === seleccionado ? ' selected' : '') + '>' + d + '</option>'
            ).join('');
    }

    function abrirFormulario(cliente) {
        const esNuevo = !cliente;
        const c = cliente || {
            nombres: '', apellido_paterno: '', apellido_materno: '', nombre_comercio: '',
            direccion: '', maps_url: '', codigo_postal: '', telefono: '', rfc: '',
            dia_visita: '', activo: 1,
        };

        contenido.innerHTML =
            '<h2 class="detalle-titulo">' + (esNuevo ? 'Dar de alta cliente' : 'Editar cliente') + '</h2>' +
            '<div class="form-group"><label for="c-nombres">Nombre(s):</label>' +
                '<input type="text" id="c-nombres" class="form-control" maxlength="100" value="' + esc(c.nombres) + '"></div>' +
            '<div class="form-group"><label for="c-paterno">Apellido paterno:</label>' +
                '<input type="text" id="c-paterno" class="form-control" maxlength="60" value="' + esc(c.apellido_paterno || '') + '"></div>' +
            '<div class="form-group"><label for="c-materno">Apellido materno:</label>' +
                '<input type="text" id="c-materno" class="form-control" maxlength="60" value="' + esc(c.apellido_materno || '') + '"></div>' +
            '<div class="form-group"><label for="c-comercio">Nombre del comercio:</label>' +
                '<input type="text" id="c-comercio" class="form-control" maxlength="150" value="' + esc(c.nombre_comercio || '') + '"></div>' +
            '<div class="form-group"><label for="c-direccion">Dirección:</label>' +
                '<input type="text" id="c-direccion" class="form-control" maxlength="255" value="' + esc(c.direccion || '') + '"></div>' +
            '<div class="form-group"><label for="c-maps">Enlace de Google Maps (opcional):</label>' +
                '<input type="url" id="c-maps" class="form-control" maxlength="500" placeholder="Pega aquí el enlace del pin, si lo tienes" value="' + esc(c.maps_url || '') + '">' +
                '<span class="detalle-sub">Si lo dejas vacío, el mapa se abre buscando la dirección y el CP.</span></div>' +
            '<div class="form-group"><label for="c-cp">Código postal:</label>' +
                '<input type="text" id="c-cp" class="form-control" inputmode="numeric" maxlength="5" value="' + esc(c.codigo_postal || '') + '"></div>' +
            '<div class="form-group"><label for="c-telefono">Teléfono:</label>' +
                '<input type="text" id="c-telefono" class="form-control" maxlength="20" value="' + esc(c.telefono || '') + '"></div>' +
            '<div class="form-group"><label for="c-email">Correo:</label>' +
                '<input type="email" id="c-email" class="form-control" maxlength="150" placeholder="cliente@correo.com" value="' + esc(c.email || '') + '"></div>' +
            '<div class="form-group"><label for="c-rfc">RFC:</label>' +
                '<input type="text" id="c-rfc" class="form-control" maxlength="13" placeholder="Ej. XAXX010101000" value="' + esc(c.rfc || '') + '"></div>' +
            '<div class="form-group"><label for="c-dia">Día de visita:</label>' +
                '<select id="c-dia" class="form-control">' + opcionesDia(c.dia_visita || '') + '</select>' +
                '<span class="detalle-sub">Solo de referencia, para saber qué día toca visitarlo.</span></div>' +
            (esNuevo ? '' :
                '<div class="form-group"><label for="c-activo">Estado:</label>' +
                    '<select id="c-activo" class="form-control">' +
                        '<option value="1"' + (Number(c.activo) ? ' selected' : '') + '>Activo</option>' +
                        '<option value="0"' + (Number(c.activo) ? '' : ' selected') + '>Inactivo</option>' +
                    '</select></div>') +
            '<div id="c-aviso" class="aviso" hidden></div>' +
            '<div class="detalle-acciones">' +
                '<button type="button" class="chip" id="btn-cancelar-cliente">Cancelar</button>' +
                '<button type="button" class="btn-save" id="btn-guardar-cliente" data-id="' +
                    (esNuevo ? '' : c.id) + '">Guardar</button>' +
            '</div>';

        modal.hidden = false;
        document.getElementById('c-nombres').focus();
    }

    async function guardar(id) {
        const avisoForm = document.getElementById('c-aviso');
        const boton = document.getElementById('btn-guardar-cliente');
        const esNuevo = !id;

        const cuerpo = {
            nombres:          document.getElementById('c-nombres').value.trim(),
            apellido_paterno: document.getElementById('c-paterno').value.trim(),
            apellido_materno: document.getElementById('c-materno').value.trim(),
            nombre_comercio:  document.getElementById('c-comercio').value.trim(),
            direccion:        document.getElementById('c-direccion').value.trim(),
            maps_url:         document.getElementById('c-maps').value.trim(),
            codigo_postal:    document.getElementById('c-cp').value.trim(),
            telefono:         document.getElementById('c-telefono').value.trim(),
            email:            document.getElementById('c-email').value.trim(),
            rfc:              document.getElementById('c-rfc').value.trim(),
            dia_visita:       document.getElementById('c-dia').value,
        };

        if (!esNuevo) {
            cuerpo.id = Number(id);
            cuerpo.activo = document.getElementById('c-activo').value === '1';
        }

        boton.disabled = true;
        boton.textContent = 'Guardando...';

        try {
            const respuesta = await fetch(RUTA + '?accion=' + (esNuevo ? 'crear' : 'editar'), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(cuerpo),
            });
            const datos = await respuesta.json();

            if (datos.ok) {
                modal.hidden = true;
                mostrarAviso(datos.mensaje, 'ok', 8);
                cargar();
            } else {
                avisoForm.textContent = datos.error || 'No se pudo guardar.';
                avisoForm.className = 'aviso aviso-error';
                avisoForm.hidden = false;
            }
        } catch (e) {
            avisoForm.textContent = 'Error de conexión.';
            avisoForm.className = 'aviso aviso-error';
            avisoForm.hidden = false;
        } finally {
            boton.disabled = false;
            boton.textContent = 'Guardar';
        }
    }

    async function eliminar(id, nombre) {
        if (!confirm('¿Eliminar al cliente ' + nombre + '?')) {
            return;
        }

        try {
            const respuesta = await fetch(RUTA + '?accion=eliminar', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: Number(id) }),
            });
            const datos = await respuesta.json();

            mostrarAviso(datos.ok ? datos.mensaje : (datos.error || 'No se pudo eliminar.'),
                         datos.ok ? 'ok' : 'error', datos.ok ? 8 : 0);

            if (datos.ok) cargar();

        } catch (e) {
            mostrarAviso('Error de conexión.', 'error', 0);
        }
    }

    // ---------- Eventos ----------
    btnNuevo.addEventListener('click', () => abrirFormulario(null));
    btnCerrar.addEventListener('click', () => { modal.hidden = true; });

    modal.addEventListener('click', (e) => {
        if (e.target === modal) modal.hidden = true;
    });

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') modal.hidden = true;
    });

    lista.addEventListener('click', (e) => {
        const editar = e.target.closest('.btn-editar-cliente');
        if (editar) {
            abrirFormulario(JSON.parse(editar.closest('.usuario-fila').dataset.cliente));
            return;
        }

        const borrar = e.target.closest('.btn-eliminar-cliente');
        if (borrar) {
            eliminar(borrar.dataset.id, borrar.dataset.nombre);
            return;
        }

        // Cualquier otra parte del renglón abre la ficha.
        const fila = e.target.closest('.usuario-fila');
        if (fila) abrirFicha(JSON.parse(fila.dataset.cliente));
    });

    lista.addEventListener('keydown', (e) => {
        if (e.key !== 'Enter' && e.key !== ' ') return;
        if (e.target.closest('button')) return;

        const fila = e.target.closest('.usuario-fila');
        if (fila) {
            e.preventDefault();
            abrirFicha(JSON.parse(fila.dataset.cliente));
        }
    });

    let temporizadorBusqueda = null;

    buscador.addEventListener('input', () => {
        clearTimeout(temporizadorBusqueda);
        temporizadorBusqueda = setTimeout(filtrar, 150);
    });

    contenido.addEventListener('click', (e) => {
        if (e.target.id === 'btn-cancelar-cliente') modal.hidden = true;
        if (e.target.id === 'btn-guardar-cliente') guardar(e.target.dataset.id);
        if (e.target.id === 'btn-cerrar-ficha') modal.hidden = true;

        if (e.target.id === 'btn-editar-ficha') {
            const cliente = todos.find((c) => String(c.id) === e.target.dataset.id);
            if (cliente) abrirFormulario(cliente);
        }
    });

    cargar();
});
