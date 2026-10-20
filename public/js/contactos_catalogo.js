document.addEventListener('DOMContentLoaded', () => {
    // Pestaña "Contactos" de Usuarios (solo admin): la gente que dejó sus datos
    // en el formulario del catálogo en línea. Aquí no se envía nada; solo se
    // marca quién pidió la BAJA y se descarga la lista para los envíos.
    const lista = document.getElementById('lista-contactos');
    if (!lista) return;

    const aviso        = document.getElementById('aviso-contactos');
    const conteo       = document.getElementById('conteo-contactos');
    const buscador     = document.getElementById('buscar-contacto');
    const filtroPromos = document.getElementById('filtro-promos');
    const filtroEstado = document.getElementById('filtro-estado');
    const btnExportar  = document.getElementById('btn-exportar-contactos');

    const RUTA = '../../app/controllers/CatalogoContactosController.php';

    function esc(texto) {
        const div = document.createElement('div');
        div.textContent = texto === null || texto === undefined ? '' : texto;
        return div.innerHTML;
    }

    let temporizador = null;

    function mostrarAviso(texto, tipo, segundos = 5) {
        clearTimeout(temporizador);
        aviso.textContent = texto;
        aviso.className = 'aviso aviso-' + tipo;
        aviso.hidden = false;
        if (segundos > 0) temporizador = setTimeout(() => { aviso.hidden = true; }, segundos * 1000);
    }

    /** "2026-10-19 14:05:33" -> "19/10/2026 14:05" */
    function fecha(valor) {
        if (!valor) return '';
        const [f, h = ''] = valor.split(' ');
        const [a, m, d] = f.split('-');
        return d + '/' + m + '/' + a + (h ? ' ' + h.slice(0, 5) : '');
    }

    /** 3312345678 -> 33 1234 5678 (solo para leerlo; se guarda en 10 dígitos). */
    function telefono(valor) {
        if (!valor || valor.length !== 10) return valor || '';
        return valor.slice(0, 2) + ' ' + valor.slice(2, 6) + ' ' + valor.slice(6);
    }

    /** Los mismos filtros van al listado y al export. */
    function filtros() {
        return new URLSearchParams({
            q:      buscador.value.trim(),
            promos: filtroPromos.value,
            estado: filtroEstado.value,
        }).toString();
    }

    // ---------- Listado ----------
    // Cada búsqueda va al servidor (no se filtra en memoria) para que la lista y
    // el Excel salgan siempre de la misma consulta. Si llegan respuestas fuera
    // de orden, solo cuenta la última pedida.
    let ultimaPeticion = 0;

    async function cargar() {
        const numero = ++ultimaPeticion;

        try {
            const respuesta = await fetch(RUTA + '?accion=listar&' + filtros());
            const datos = await respuesta.json();
            if (numero !== ultimaPeticion) return;

            if (!datos.ok) {
                mostrarAviso(datos.error || 'No se pudo cargar la lista.', 'error', 0);
                lista.innerHTML = '';
                return;
            }

            pintar(datos);

        } catch (e) {
            if (numero !== ultimaPeticion) return;
            mostrarAviso('Error de conexión.', 'error', 0);
            lista.innerHTML = '';
        }
    }

    function pintar(datos) {
        const t = datos.totales;

        conteo.innerHTML =
            '<span>' + datos.encontrados + ' en este filtro' +
                (datos.encontrados > datos.contactos.length
                    ? ' (se muestran los ' + datos.contactos.length + ' más recientes; el Excel lleva todos)'
                    : '') + '</span>' +
            '<span>' + t.total + ' en total · <strong>' + t.activos + '</strong> activos · ' +
                t.con_promos + ' activos con promociones</span>';

        lista.innerHTML = '';

        if (!datos.contactos.length) {
            lista.innerHTML = '<p class="venta-vacia">' +
                (t.total === 0
                    ? 'Aún no llega ningún contacto desde el catálogo.'
                    : 'Ningún contacto coincide con el filtro.') + '</p>';
            return;
        }

        datos.contactos.forEach((c) => lista.appendChild(fila(c)));
    }

    function fila(c) {
        const deBaja = c.baja_en !== null;
        const div = document.createElement('div');
        div.className = 'venta-fila usuario-fila' + (deBaja ? ' usuario-inactivo' : '');
        div.dataset.id = c.id;

        const datosContacto = [
            c.telefono ? '<i class="ph ph-phone" aria-hidden="true"></i> ' + esc(telefono(c.telefono)) : '',
            c.correo ? '<i class="ph ph-envelope-simple" aria-hidden="true"></i> ' + esc(c.correo) : '',
        ].filter(Boolean).join(' · ');

        const constancia = 'Aceptó el aviso v' + esc(c.aviso_version) + ' el ' + esc(fecha(c.aceptado_en)) +
            (deBaja ? ' · Baja el ' + esc(fecha(c.baja_en)) : '');

        const etiquetas =
            (Number(c.acepta_promociones)
                ? '<span class="etiqueta etiqueta-pagado">Promociones</span>'
                : '<span class="etiqueta etiqueta-parcial">Sin promociones</span>') +
            (deBaja ? '<span class="etiqueta etiqueta-pendiente">Baja</span>' : '');

        const boton = deBaja
            ? '<button type="button" class="chip btn-reactivar" data-id="' + c.id + '">Deshacer baja</button>'
            : '<button type="button" class="chip chip-peligro btn-baja" data-id="' + c.id + '">Dar de baja</button>';

        div.innerHTML =
            '<div class="venta-fila-datos">' +
                '<p class="venta-fila-cliente">' + esc(c.nombre) + '</p>' +
                '<p class="venta-fila-meta">' + (datosContacto || 'Sin teléfono ni correo') + '</p>' +
                '<p class="venta-fila-meta">' + constancia + '</p>' +
            '</div>' +
            '<div class="venta-fila-derecha">' +
                '<div class="usuario-etiquetas">' + etiquetas + '</div>' +
                '<div class="usuario-acciones">' + boton + '</div>' +
            '</div>';

        div.dataset.nombre = c.nombre;
        return div;
    }

    // ---------- Baja / deshacer ----------
    async function cambiar(accion, id, boton) {
        boton.disabled = true;

        try {
            const respuesta = await fetch(RUTA + '?accion=' + accion, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: Number(id) }),
            });
            const datos = await respuesta.json();

            if (!datos.ok) {
                mostrarAviso(datos.error || 'No se pudo guardar el cambio.', 'error');
                boton.disabled = false;
                return;
            }

            mostrarAviso(
                accion === 'baja'
                    ? datos.contacto.nombre + ' quedó dado de baja: ya no se le debe enviar nada.'
                    : 'Se deshizo la baja de ' + datos.contacto.nombre + '.',
                'ok'
            );
            // Se recarga con los filtros puestos: si se ven solo los activos, el
            // que se dio de baja desaparece de la lista (y al revés).
            cargar();

        } catch (e) {
            mostrarAviso('Error de conexión.', 'error');
            boton.disabled = false;
        }
    }

    lista.addEventListener('click', (e) => {
        const baja = e.target.closest('.btn-baja');
        if (baja) {
            const nombre = baja.closest('.venta-fila').dataset.nombre;
            if (!confirm('¿Dar de baja a ' + nombre + '?\n\nÚsalo cuando la persona responda BAJA: ' +
                         'ya no debe recibir ningún mensaje.')) {
                return;
            }
            cambiar('baja', baja.dataset.id, baja);
            return;
        }

        const reactivar = e.target.closest('.btn-reactivar');
        if (reactivar) {
            const nombre = reactivar.closest('.venta-fila').dataset.nombre;
            if (!confirm('¿Deshacer la baja de ' + nombre + '?\n\nHazlo solo si la baja se marcó por error ' +
                         'o si la persona pidió volver a recibir mensajes.')) {
                return;
            }
            cambiar('reactivar', reactivar.dataset.id, reactivar);
        }
    });

    // ---------- Filtros ----------
    let espera = null;
    buscador.addEventListener('input', () => {
        clearTimeout(espera);
        espera = setTimeout(cargar, 300);
    });
    filtroPromos.addEventListener('change', cargar);
    filtroEstado.addEventListener('change', cargar);

    // La descarga es una navegación normal: el navegador la resuelve como
    // archivo adjunto y se lleva la cookie de sesión.
    btnExportar.addEventListener('click', () => {
        window.location = RUTA + '?accion=exportar&' + filtros();
    });

    cargar();
});
