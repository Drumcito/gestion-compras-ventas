document.addEventListener('DOMContentLoaded', () => {
    const lista     = document.getElementById('lista-usuarios');
    if (!lista) return;

    const aviso     = document.getElementById('aviso-usuarios');
    const modal     = document.getElementById('modal-usuario');
    const contenido = document.getElementById('contenido-usuario');
    const btnNuevo  = document.getElementById('btn-nuevo');
    const btnCerrar = document.getElementById('btn-cerrar-usuario');

    const RUTA = '../../app/controllers/UsuarioController.php';

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

            pintar(datos.usuarios);

        } catch (e) {
            mostrarAviso('Error de conexión.', 'error', 0);
            lista.innerHTML = '';
        }
    }

    function pintar(usuarios) {
        lista.innerHTML = '';

        usuarios.forEach((u) => {
            const fila = document.createElement('div');
            fila.className = 'venta-fila usuario-fila' + (Number(u.activo) ? '' : ' usuario-inactivo');

            const etiquetaRol = u.rol === 'admin'
                ? '<span class="etiqueta etiqueta-admin">Admin</span>'
                : '<span class="etiqueta etiqueta-pagado">Vendedor</span>';

            const etiquetaEstado = Number(u.activo)
                ? ''
                : '<span class="etiqueta etiqueta-pendiente">Inactivo</span>';

            fila.innerHTML =
                '<div class="venta-fila-datos">' +
                    '<p class="venta-fila-cliente">' + esc(u.nombre) + ' ' + esc(u.apellido || '') +
                        (u.es_usuario_actual ? '<span class="detalle-sub">(tú)</span>' : '') + '</p>' +
                    '<p class="venta-fila-meta">' + esc(u.numero_empleado) +
                        (u.numero_telefono ? ' · ' + esc(u.numero_telefono) : '') +
                        ' · ' + u.ventas + ' venta' + (u.ventas === '1' ? '' : 's') + '</p>' +
                '</div>' +
                '<div class="venta-fila-derecha">' +
                    '<div class="usuario-etiquetas">' + etiquetaRol + etiquetaEstado + '</div>' +
                    '<div class="usuario-acciones">' +
                        '<button type="button" class="chip btn-editar" data-id="' + u.id + '">Editar</button>' +
                        (u.es_usuario_actual
                            ? ''
                            : '<button type="button" class="chip chip-peligro btn-eliminar" data-id="' + u.id +
                              '" data-nombre="' + esc(u.numero_empleado) + '">Eliminar</button>') +
                    '</div>' +
                '</div>';

            fila.dataset.usuario = JSON.stringify(u);
            lista.appendChild(fila);
        });
    }

    // ---------- Formulario ----------
    function abrirFormulario(usuario) {
        const esNuevo = !usuario;
        const u = usuario || { nombre: '', apellido: '', numero_empleado: '', numero_telefono: '', rol: 'vendedor', activo: 1 };

        contenido.innerHTML =
            '<h2 class="detalle-titulo">' + (esNuevo ? 'Nuevo usuario' : 'Editar usuario') + '</h2>' +
            '<div class="form-group"><label for="u-nombre">Nombre:</label>' +
                '<input type="text" id="u-nombre" class="form-control" maxlength="100" value="' + esc(u.nombre) + '"></div>' +
            '<div class="form-group"><label for="u-apellido">Apellido:</label>' +
                '<input type="text" id="u-apellido" class="form-control" maxlength="50" value="' + esc(u.apellido || '') + '"></div>' +
            '<div class="form-group"><label for="u-numero">Número de empleado:</label>' +
                '<input type="text" id="u-numero" class="form-control" maxlength="20" placeholder="Ej. GA-BE-01" value="' + esc(u.numero_empleado) + '"></div>' +
            '<div class="form-group"><label for="u-telefono">Teléfono:</label>' +
                '<input type="text" id="u-telefono" class="form-control" maxlength="20" value="' + esc(u.numero_telefono || '') + '"></div>' +
            '<div class="form-group"><label for="u-rol">Rol:</label>' +
                '<select id="u-rol" class="form-control">' +
                    '<option value="vendedor"' + (u.rol === 'vendedor' ? ' selected' : '') + '>Vendedor</option>' +
                    '<option value="admin"' + (u.rol === 'admin' ? ' selected' : '') + '>Administrador</option>' +
                '</select></div>' +
            (esNuevo ? '' :
                '<div class="form-group"><label for="u-activo">Estado:</label>' +
                    '<select id="u-activo" class="form-control">' +
                        '<option value="1"' + (Number(u.activo) ? ' selected' : '') + '>Activo</option>' +
                        '<option value="0"' + (Number(u.activo) ? '' : ' selected') + '>Inactivo (no puede entrar)</option>' +
                    '</select></div>') +
            '<div class="form-group"><label for="u-password">' +
                (esNuevo ? 'Contraseña:' : 'Nueva contraseña (dejar vacío para no cambiarla):') + '</label>' +
                '<input type="password" id="u-password" class="form-control" autocomplete="new-password"></div>' +
            '<div id="u-aviso" class="aviso" hidden></div>' +
            '<div class="detalle-acciones">' +
                '<button type="button" class="chip" id="btn-cancelar-usuario">Cancelar</button>' +
                '<button type="button" class="btn-save" id="btn-guardar-usuario" data-id="' +
                    (esNuevo ? '' : u.id) + '">Guardar</button>' +
            '</div>';

        modal.hidden = false;
        document.getElementById('u-nombre').focus();
    }

    async function guardar(id) {
        const avisoForm = document.getElementById('u-aviso');
        const boton = document.getElementById('btn-guardar-usuario');
        const esNuevo = !id;

        const cuerpo = {
            nombre:          document.getElementById('u-nombre').value.trim(),
            apellido:        document.getElementById('u-apellido').value.trim(),
            numero_empleado: document.getElementById('u-numero').value.trim(),
            numero_telefono: document.getElementById('u-telefono').value.trim(),
            rol:             document.getElementById('u-rol').value,
            password:        document.getElementById('u-password').value,
        };

        if (!esNuevo) {
            cuerpo.id = Number(id);
            cuerpo.activo = document.getElementById('u-activo').value === '1';
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
                mostrarAviso(datos.mensaje, 'ok');
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
        if (!confirm('¿Eliminar al usuario ' + nombre + '?\n\nSi ya tiene ventas o movimientos registrados, ' +
                     'se desactivará en lugar de borrarse para no perder su historial.')) {
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
        const editar = e.target.closest('.btn-editar');
        if (editar) {
            abrirFormulario(JSON.parse(editar.closest('.usuario-fila').dataset.usuario));
            return;
        }

        const borrar = e.target.closest('.btn-eliminar');
        if (borrar) eliminar(borrar.dataset.id, borrar.dataset.nombre);
    });

    contenido.addEventListener('click', (e) => {
        if (e.target.id === 'btn-cancelar-usuario') modal.hidden = true;
        if (e.target.id === 'btn-guardar-usuario') guardar(e.target.dataset.id);
    });

    cargar();
});
