/**
 * Captura de los datos del cliente para la nota impresa.
 *
 * La usan las dos pantallas desde donde se imprime: Venta (al terminar una) e
 * Historial (desde el detalle). Vive aquí y no en cada una para que el aviso de
 * datos faltantes y el guardado sean idénticos en las dos.
 *
 * Si la venta está ligada a un cliente registrado, los campos llegan llenos con
 * lo que ya tiene guardado. Si le falta alguno, se avisa y se ofrecen dos
 * caminos: imprimir así (el renglón sale en blanco para llenarlo a mano) o
 * escribirlo y dejarlo guardado en el cliente para las próximas notas.
 */
window.NotaDatos = (function () {
    'use strict';

    const RUTA_CLIENTE = 'ClienteController.php';

    function esc(texto) {
        const div = document.createElement('div');
        div.textContent = texto === null || texto === undefined ? '' : texto;
        return div.innerHTML;
    }

    /** "dirección y C.P." / "dirección, C.P. y teléfono" */
    function lista(nombres) {
        if (nombres.length === 1) return nombres[0];
        return nombres.slice(0, -1).join(', ') + ' y ' + nombres[nombres.length - 1];
    }

    /**
     * @param opciones.ventaId    Folio de la venta.
     * @param opciones.cliente    Nombre que trae la venta.
     * @param opciones.clienteId  Cliente registrado (0 si la venta no está ligada).
     * @param opciones.contenedor Elemento donde se dibuja el formulario.
     * @param opciones.rutaApp    Ruta a app/controllers/ desde la página que llama.
     * @param opciones.rutaNota   Ruta a nota.php desde la página que llama.
     * @param opciones.alCerrar   Se llama al cancelar o al terminar de imprimir.
     */
    async function abrir(opciones) {
        const { ventaId, contenedor, rutaApp, rutaNota, alCerrar } = opciones;
        const clienteId = Number(opciones.clienteId) || 0;

        contenedor.innerHTML = '<p class="venta-vacia">Cargando...</p>';

        let guardado = { direccion: '', codigo_postal: '', telefono: '' };
        let faltantes = [];

        // Sin cliente registrado no hay nada que consultar ni dónde guardar.
        if (clienteId > 0) {
            try {
                const datos = await (await fetch(
                    rutaApp + RUTA_CLIENTE + '?accion=datos&cliente_id=' + encodeURIComponent(clienteId)
                )).json();

                if (datos.ok) {
                    guardado = {
                        direccion:     datos.cliente.direccion || '',
                        codigo_postal: datos.cliente.codigo_postal || '',
                        telefono:      datos.cliente.telefono || '',
                    };
                    faltantes = datos.faltantes || [];
                }
            } catch (e) {
                // Si no se pudieron traer, se captura a mano como siempre.
            }
        }

        pintar({ ventaId, clienteId, contenedor, rutaApp, rutaNota, alCerrar, guardado, faltantes,
                 cliente: opciones.cliente || '' });
    }

    function pintar(ctx) {
        const { ventaId, clienteId, contenedor, guardado, faltantes } = ctx;

        // Un campo que ya viene del cliente no se vuelve a guardar: el aviso lo
        // dice para que nadie espere que su corrección se quede.
        const yaGuardado = (campo) =>
            clienteId > 0 && String(guardado[campo] || '').trim() !== ''
                ? '<span class="detalle-sub">Ya guardado en el cliente</span>'
                : '';

        let encabezado;

        if (clienteId === 0) {
            encabezado = '<p class="aviso aviso-alerta"><strong>' +
                esc(ctx.cliente || 'Este cliente') + '</strong> no está en el catálogo: ' +
                'se escribió a mano al hacer la venta. Con <strong>Dar de alta e imprimir</strong> ' +
                'queda registrado con estos datos y ligado a la venta; con ' +
                '<strong>Imprimir sin guardar</strong> sale la nota y lo registras después ' +
                'desde Usuarios.</p>';
        } else if (faltantes.length > 0) {
            // Con el nombre por delante: al imprimir en tanda se ve de quién es
            // cada aviso, y aquí evita confundir al cliente de la venta con otro.
            encabezado = '<p class="aviso aviso-alerta">A <strong>' +
                esc(ctx.cliente || 'este cliente') + '</strong> le ' +
                (faltantes.length === 1 ? 'falta ' : 'faltan ') +
                esc(lista(faltantes.map((f) => f.nombre))) + ' en su registro. ' +
                'Puedes imprimir así y llenarlo a mano, o escribirlo aquí y guardarlo ' +
                'para las próximas notas.</p>';
        } else {
            encabezado = '<p class="aviso aviso-ok">Los datos del cliente están completos.</p>';
        }

        contenedor.innerHTML =
            '<h2 class="detalle-titulo">Imprimir nota — Venta #' + ventaId + '</h2>' +
            encabezado +
            '<div class="form-group">' +
                '<label for="nota-cliente">Cliente:</label>' +
                '<input type="text" id="nota-cliente" class="form-control" maxlength="150" ' +
                       'value="' + esc(ctx.cliente) + '">' +
            '</div>' +
            '<div class="form-group">' +
                '<label for="nota-direccion">Dirección:</label>' +
                '<input type="text" id="nota-direccion" class="form-control" maxlength="255" ' +
                       'value="' + esc(guardado.direccion) + '">' +
                yaGuardado('direccion') +
            '</div>' +
            '<div class="form-group">' +
                '<label for="nota-cp">C.P.:</label>' +
                '<input type="text" id="nota-cp" class="form-control" maxlength="5" ' +
                       'inputmode="numeric" value="' + esc(guardado.codigo_postal) + '">' +
                yaGuardado('codigo_postal') +
            '</div>' +
            '<div class="form-group">' +
                '<label for="nota-telefono">Teléfono:</label>' +
                '<input type="tel" id="nota-telefono" class="form-control" maxlength="10" ' +
                       'inputmode="numeric" placeholder="10 dígitos" ' +
                       'value="' + esc(guardado.telefono) + '">' +
                '<span class="detalle-sub" id="nota-tel-aviso"></span>' +
                yaGuardado('telefono') +
            '</div>' +
            '<div id="nota-aviso" class="aviso" hidden></div>' +
            '<div class="detalle-acciones">' +
                '<button type="button" class="chip" id="btn-cancelar-nota">Cancelar</button>' +
                '<button type="button" class="' +
                    (clienteId === 0 || faltantes.length > 0 ? 'chip' : 'btn-save') +
                    '" id="btn-nota-continuar">' +
                    (clienteId === 0 || faltantes.length > 0 ? 'Imprimir sin guardar' : 'Imprimir') +
                '</button>' +
                // Se ofrece guardar cuando hay algo que guardar: datos que le
                // faltan al cliente, o un cliente que ni siquiera esta dado de alta.
                (clienteId === 0
                    ? '<button type="button" class="btn-save" id="btn-nota-guardar">Dar de alta e imprimir</button>'
                    : (faltantes.length > 0
                        ? '<button type="button" class="btn-save" id="btn-nota-guardar">Guardar y imprimir</button>'
                        : '')) +
            '</div>';

        // Solo dígitos en C.P. y teléfono. El recorte va DESPUÉS de limpiar:
        // maxlength cuenta también las letras, así que al teclear una por error
        // se perdían dígitos válidos (o se colaba uno de más).
        ['nota-cp', 'nota-telefono'].forEach((id) => {
            document.getElementById(id).addEventListener('input', (e) => {
                e.target.value = e.target.value.replace(/\D/g, '').slice(0, e.target.maxLength);

                if (id === 'nota-telefono') {
                    const faltan = 10 - e.target.value.length;
                    document.getElementById('nota-tel-aviso').textContent =
                        e.target.value.length === 0 || faltan === 0
                            ? '' : 'Faltan ' + faltan + ' dígito' + (faltan === 1 ? '' : 's');
                }
            });
        });

        document.getElementById('btn-cancelar-nota')
            .addEventListener('click', () => ctx.alCerrar && ctx.alCerrar());

        document.getElementById('btn-nota-continuar')
            .addEventListener('click', () => imprimir(ctx, false));

        const btnGuardar = document.getElementById('btn-nota-guardar');
        if (btnGuardar) btnGuardar.addEventListener('click', () => imprimir(ctx, true));

        document.getElementById('nota-cliente').focus();
    }

    function leerCampos() {
        return {
            cliente:   document.getElementById('nota-cliente').value.trim(),
            direccion: document.getElementById('nota-direccion').value.trim(),
            cp:        document.getElementById('nota-cp').value,
            telefono:  document.getElementById('nota-telefono').value,
        };
    }

    function avisar(texto, tipo) {
        const caja = document.getElementById('nota-aviso');
        if (!caja) return;

        caja.textContent = texto;
        caja.className = 'aviso aviso-' + tipo;
        caja.hidden = false;
    }

    async function imprimir(ctx, guardando) {
        const campos = leerCampos();

        if (campos.telefono !== '' && campos.telefono.length !== 10) {
            document.getElementById('nota-tel-aviso').textContent =
                'El teléfono debe tener 10 dígitos o quedar vacío.';
            return;
        }

        if (guardando) {
            const boton = document.getElementById('btn-nota-guardar');
            const etiqueta = boton.textContent;

            // Sin cliente en el catalogo se da de alta y se liga a la venta; con
            // cliente, solo se le completan los datos que le faltaban.
            const daDeAlta = ctx.clienteId === 0;

            if (daDeAlta && campos.cliente === '') {
                avisar('Escribe el nombre del cliente para darlo de alta.', 'error');
                return;
            }

            const ruta   = daDeAlta ? '?accion=registrar_desde_venta' : '?accion=completar';
            const cuerpo = daDeAlta
                ? {
                    venta_id:      ctx.ventaId,
                    nombre:        campos.cliente,
                    direccion:     campos.direccion,
                    codigo_postal: campos.cp,
                    telefono:      campos.telefono,
                  }
                : {
                    cliente_id:    ctx.clienteId,
                    direccion:     campos.direccion,
                    codigo_postal: campos.cp,
                    telefono:      campos.telefono,
                  };

            boton.disabled = true;
            boton.textContent = 'Guardando...';

            try {
                const datos = await (await fetch(ctx.rutaApp + RUTA_CLIENTE + ruta, {
                    method:  'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body:    JSON.stringify(cuerpo),
                })).json();

                if (!datos.ok) {
                    avisar(datos.error || 'No se pudieron guardar los datos.', 'error');
                    return;
                }

            } catch (e) {
                avisar('Error de conexión al guardar los datos.', 'error');
                return;

            } finally {
                boton.disabled = false;
                boton.textContent = etiqueta;
            }
        }

        const parametros = new URLSearchParams({
            id:        ctx.ventaId,
            cliente:   campos.cliente,
            direccion: campos.direccion,
            cp:        campos.cp,
            telefono:  campos.telefono,
        });

        window.open(ctx.rutaNota + '?' + parametros.toString(), '_blank');

        if (ctx.alCerrar) ctx.alCerrar();
    }

    // ------------------------------------------------------------------
    // Varias notas de golpe
    // ------------------------------------------------------------------
    // Al imprimir una selección del historial no hay un solo cliente, así que en
    // lugar de un formulario se muestra la lista de a quiénes les falta algo,
    // con los campos justos para completarlos ahí mismo.

    /** Solo dígitos, recortando después de limpiar (maxlength cuenta letras). */
    function soloDigitos(campo) {
        campo.addEventListener('input', (e) => {
            e.target.value = e.target.value.replace(/\D/g, '').slice(0, e.target.maxLength);
        });
    }

    async function abrirVarias(opciones) {
        const { ids, contenedor, rutaApp, rutaNota, mostrar, alCerrar } = opciones;

        const imprimir = () => {
            window.open(rutaNota + '?ids=' + ids.join(','), '_blank');
            if (alCerrar) alCerrar();
        };

        let clientes = [];
        let sinCliente = [];

        try {
            const datos = await (await fetch(
                rutaApp + RUTA_CLIENTE + '?accion=faltantes&ids=' + ids.join(',')
            )).json();

            if (datos.ok) {
                clientes = datos.clientes || [];
                sinCliente = datos.sin_cliente || [];
            }
        } catch (e) {
            // Si no se pudo revisar, se imprime igual: el aviso es una ayuda,
            // no una barrera.
            imprimir();
            return;
        }

        // Ni datos pendientes ni clientes por dar de alta: nada que avisar.
        if (clientes.length === 0 && sinCliente.length === 0) {
            imprimir();
            return;
        }

        if (mostrar) mostrar();
        pintarVarias({ ids, contenedor, rutaApp, clientes, sinCliente, imprimir, alCerrar });
    }

    function pintarVarias(ctx) {
        const { contenedor, clientes, sinCliente, ids } = ctx;

        const bloques = clientes.map((c) => {
            const campos = c.faltantes.map((f) => {
                const id = 'falta-' + c.id + '-' + f.campo;
                const numerico = f.campo !== 'direccion';

                const extra =
                    (f.campo === 'codigo_postal' ? 'maxlength="5" inputmode="numeric" ' : '') +
                    (f.campo === 'telefono' ? 'maxlength="10" inputmode="numeric" placeholder="10 dígitos" ' : '') +
                    (f.campo === 'direccion' ? 'maxlength="255" ' : '') +
                    (numerico ? 'data-digitos="1" ' : '');

                return '<div class="form-group">' +
                    '<label for="' + id + '">' +
                        esc(f.nombre.charAt(0).toUpperCase() + f.nombre.slice(1)) + ':</label>' +
                    '<input type="text" id="' + id + '" class="form-control" ' +
                           'data-cliente="' + c.id + '" data-campo="' + f.campo + '" ' + extra + '>' +
                '</div>';
            }).join('');

            return '<div class="falta-cliente">' +
                '<p class="falta-nombre">' + esc(c.nombre) +
                    '<span class="detalle-sub">' + c.notas + ' nota' + (c.notas === 1 ? '' : 's') +
                    ' · falta ' + esc(lista(c.faltantes.map((f) => f.nombre))) + '</span></p>' +
                campos +
            '</div>';
        }).join('');

        // Ventas con el nombre tecleado a mano: se ofrecen para darlas de alta.
        const bloquesNuevos = sinCliente.map((v) => {
            const campo = (sufijo, etiqueta, extra) =>
                '<div class="form-group">' +
                    '<label for="nuevo-' + v.venta_id + '-' + sufijo + '">' + etiqueta + ':</label>' +
                    '<input type="text" id="nuevo-' + v.venta_id + '-' + sufijo + '" ' +
                           'class="form-control" data-venta="' + v.venta_id + '" ' +
                           'data-campo="' + sufijo + '" ' + (extra || '') + '>' +
                '</div>';

            return '<div class="falta-cliente falta-cliente-nuevo">' +
                '<p class="falta-nombre">' + esc(v.nombre || 'Sin nombre') +
                    '<span class="detalle-sub">Nota #' + v.venta_id + ' · no está en el catálogo</span></p>' +
                '<label class="guardar-cliente">' +
                    '<input type="checkbox" class="chk-alta" data-venta="' + v.venta_id + '" ' +
                           'data-nombre="' + esc(v.nombre) + '">' +
                    '<span>Darlo de alta y ligarlo a esta nota</span>' +
                '</label>' +
                campo('direccion', 'Dirección', 'maxlength="255"') +
                campo('codigo_postal', 'C.P.', 'maxlength="5" inputmode="numeric" data-digitos="1"') +
                campo('telefono', 'Teléfono', 'maxlength="10" inputmode="numeric" data-digitos="1" placeholder="10 dígitos"') +
            '</div>';
        }).join('');

        contenedor.innerHTML =
            '<h2 class="detalle-titulo">Imprimir ' + ids.length + ' nota' +
                (ids.length === 1 ? '' : 's') + '</h2>' +
            (clientes.length > 0
                ? '<p class="aviso aviso-alerta">' + clientes.length + ' cliente' +
                  (clientes.length === 1 ? ' tiene' : 's tienen') + ' datos sin registrar. ' +
                  'Puedes llenarlos aquí y quedan guardados, o seguir con la impresión y esos ' +
                  'renglones salen en blanco para llenarlos a mano.</p>'
                : '') +
            (sinCliente.length > 0
                ? '<p class="aviso aviso-alerta">' + sinCliente.length + ' nota' +
                  (sinCliente.length === 1 ? '' : 's') + ' con el cliente escrito a mano. ' +
                  'Marca la casilla para darlo de alta en el catálogo; así la próxima vez ' +
                  'sus datos salen solos y puede acumular saldo a favor.</p>'
                : '') +
            bloques +
            bloquesNuevos +
            '<div id="nota-aviso" class="aviso" hidden></div>' +
            '<div class="detalle-acciones">' +
                '<button type="button" class="chip" id="btn-cancelar-varias">Cancelar</button>' +
                '<button type="button" class="chip" id="btn-varias-continuar">Continuar sin registrar</button>' +
                '<button type="button" class="btn-save" id="btn-varias-guardar">Guardar e imprimir</button>' +
            '</div>';

        contenedor.querySelectorAll('[data-digitos]').forEach(soloDigitos);

        document.getElementById('btn-cancelar-varias')
            .addEventListener('click', () => ctx.alCerrar && ctx.alCerrar());

        document.getElementById('btn-varias-continuar')
            .addEventListener('click', ctx.imprimir);

        document.getElementById('btn-varias-guardar')
            .addEventListener('click', () => guardarVarias(ctx));
    }

    async function guardarVarias(ctx) {
        const boton = document.getElementById('btn-varias-guardar');

        // Datos que le faltaban a clientes que ya existen, agrupados por cliente.
        const porCliente = {};

        ctx.contenedor.querySelectorAll('[data-cliente]').forEach((campo) => {
            const valor = campo.value.trim();
            if (valor === '') return;

            const id = campo.dataset.cliente;
            porCliente[id] = porCliente[id] || {};
            porCliente[id][campo.dataset.campo] = valor;
        });

        // Altas nuevas: solo las que traen la casilla marcada.
        const altas = [...ctx.contenedor.querySelectorAll('.chk-alta:checked')].map((chk) => {
            const venta = chk.dataset.venta;
            const cuerpo = { venta_id: Number(venta), nombre: chk.dataset.nombre };

            ctx.contenedor.querySelectorAll('[data-venta="' + venta + '"][data-campo]').forEach((campo) => {
                const valor = campo.value.trim();
                if (valor !== '') cuerpo[campo.dataset.campo] = valor;
            });

            return cuerpo;
        });

        const pendientes = Object.keys(porCliente);

        if (pendientes.length === 0 && altas.length === 0) {
            avisar('No marcaste ni escribiste nada. Usa "Continuar sin registrar" si así lo quieres imprimir.', 'error');
            return;
        }

        boton.disabled = true;
        boton.textContent = 'Guardando...';

        try {
            for (const alta of altas) {
                if (!alta.nombre) {
                    avisar('Una de las notas no trae nombre de cliente; no se puede dar de alta.', 'error');
                    return;
                }

                const datos = await (await fetch(
                    ctx.rutaApp + RUTA_CLIENTE + '?accion=registrar_desde_venta', {
                        method:  'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body:    JSON.stringify(alta),
                    })).json();

                if (!datos.ok) {
                    avisar(datos.error || 'No se pudo dar de alta al cliente.', 'error');
                    return;
                }
            }

            for (const id of pendientes) {
                const datos = await (await fetch(ctx.rutaApp + RUTA_CLIENTE + '?accion=completar', {
                    method:  'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body:    JSON.stringify(Object.assign({ cliente_id: Number(id) }, porCliente[id])),
                })).json();

                if (!datos.ok) {
                    avisar(datos.error || 'No se pudieron guardar los datos.', 'error');
                    return;
                }
            }
        } catch (e) {
            avisar('Error de conexión al guardar los datos.', 'error');
            return;

        } finally {
            boton.disabled = false;
            boton.textContent = 'Guardar e imprimir';
        }

        ctx.imprimir();
    }

    // ------------------------------------------------------------------
    // Completar los datos de un cliente, fuera de la impresión
    // ------------------------------------------------------------------
    // Se usa justo después de dar de alta a alguien desde la venta: queda
    // guardado solo con su nombre y aquí se le pueden poner los demás datos, sin
    // obligar a nadie a hacerlo en ese momento.

    async function completarCliente(opciones) {
        const { clienteId, nombre, contenedor, rutaApp, alCerrar } = opciones;

        contenedor.innerHTML = '<p class="venta-vacia">Cargando...</p>';

        let guardado = { direccion: '', codigo_postal: '', telefono: '' };

        try {
            const datos = await (await fetch(
                rutaApp + RUTA_CLIENTE + '?accion=datos&cliente_id=' + encodeURIComponent(clienteId)
            )).json();

            if (datos.ok) {
                guardado = {
                    direccion:     datos.cliente.direccion || '',
                    codigo_postal: datos.cliente.codigo_postal || '',
                    telefono:      datos.cliente.telefono || '',
                };
            }
        } catch (e) {
            // Se captura en blanco; el guardado igual solo llena lo que falte.
        }

        contenedor.innerHTML =
            '<h2 class="detalle-titulo">Datos de ' + esc(nombre) + '</h2>' +
            '<p class="aviso aviso-info">Quedó dado de alta en el catálogo. ' +
                'Lo que llenes aquí se guarda y saldrá solo en sus próximas notas. ' +
                'Puedes dejarlo para después: también se completa desde Usuarios ' +
                'o al imprimir una nota suya.</p>' +
            '<div class="form-group">' +
                '<label for="cli-direccion">Dirección:</label>' +
                '<input type="text" id="cli-direccion" class="form-control" maxlength="255" ' +
                       'value="' + esc(guardado.direccion) + '">' +
            '</div>' +
            '<div class="form-group">' +
                '<label for="cli-cp">C.P.:</label>' +
                '<input type="text" id="cli-cp" class="form-control" maxlength="5" ' +
                       'inputmode="numeric" value="' + esc(guardado.codigo_postal) + '">' +
            '</div>' +
            '<div class="form-group">' +
                '<label for="cli-telefono">Teléfono:</label>' +
                '<input type="tel" id="cli-telefono" class="form-control" maxlength="10" ' +
                       'inputmode="numeric" placeholder="10 dígitos" ' +
                       'value="' + esc(guardado.telefono) + '">' +
            '</div>' +
            '<div id="nota-aviso" class="aviso" hidden></div>' +
            '<div class="detalle-acciones">' +
                '<button type="button" class="chip" id="btn-cliente-despues">Después</button>' +
                '<button type="button" class="btn-save" id="btn-cliente-guardar">Guardar datos</button>' +
            '</div>';

        ['cli-cp', 'cli-telefono'].forEach((id) => soloDigitos(document.getElementById(id)));

        document.getElementById('btn-cliente-despues')
            .addEventListener('click', () => alCerrar && alCerrar());

        document.getElementById('btn-cliente-guardar').addEventListener('click', async () => {
            const boton = document.getElementById('btn-cliente-guardar');
            const telefono = document.getElementById('cli-telefono').value;

            if (telefono !== '' && telefono.length !== 10) {
                avisar('El teléfono debe tener 10 dígitos o quedar vacío.', 'error');
                return;
            }

            boton.disabled = true;
            boton.textContent = 'Guardando...';

            try {
                const datos = await (await fetch(rutaApp + RUTA_CLIENTE + '?accion=completar', {
                    method:  'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body:    JSON.stringify({
                        cliente_id:    clienteId,
                        direccion:     document.getElementById('cli-direccion').value.trim(),
                        codigo_postal: document.getElementById('cli-cp').value,
                        telefono:      telefono,
                    }),
                })).json();

                if (!datos.ok) {
                    avisar(datos.error || 'No se pudieron guardar los datos.', 'error');
                    return;
                }

                if (alCerrar) alCerrar(datos.guardados || 0);

            } catch (e) {
                avisar('Error de conexión al guardar los datos.', 'error');

            } finally {
                boton.disabled = false;
                boton.textContent = 'Guardar datos';
            }
        });

        document.getElementById('cli-direccion').focus();
    }

    return { abrir: abrir, abrirVarias: abrirVarias, completarCliente: completarCliente };
})();
