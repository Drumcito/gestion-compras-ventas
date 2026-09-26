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
            encabezado = '<p class="aviso aviso-info">Esta venta no está ligada a un cliente del ' +
                'catálogo, así que estos datos salen solo en esta nota y no se guardan. ' +
                'Lo que dejes vacío sale como renglón en blanco para llenarlo a mano.</p>';
        } else if (faltantes.length > 0) {
            encabezado = '<p class="aviso aviso-alerta">A este cliente le ' +
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
                    (clienteId > 0 && faltantes.length > 0 ? 'chip' : 'btn-save') +
                    '" id="btn-nota-continuar">' +
                    (clienteId > 0 && faltantes.length > 0 ? 'Imprimir sin guardar' : 'Imprimir') +
                '</button>' +
                // Solo se ofrece guardar cuando hay algo que guardar: con los
                // datos completos ese boton no haria nada.
                (clienteId > 0 && faltantes.length > 0
                    ? '<button type="button" class="btn-save" id="btn-nota-guardar">Guardar y imprimir</button>'
                    : '') +
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
            boton.disabled = true;
            boton.textContent = 'Guardando...';

            try {
                const datos = await (await fetch(ctx.rutaApp + RUTA_CLIENTE + '?accion=completar', {
                    method:  'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body:    JSON.stringify({
                        cliente_id:    ctx.clienteId,
                        direccion:     campos.direccion,
                        codigo_postal: campos.cp,
                        telefono:      campos.telefono,
                    }),
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
                boton.textContent = 'Guardar y imprimir';
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

    return { abrir: abrir };
})();
