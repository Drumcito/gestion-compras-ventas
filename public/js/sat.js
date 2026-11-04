/**
 * Modal para capturar la clave del SAT (ClaveProdServ) de un producto.
 *
 * Lo usan dos pantallas y hacen lo mismo:
 *
 *   - Historial / En línea → ver una venta → "Ver código SAT" → lápiz del renglón.
 *   - Inventario → el chip "SAT" de cada producto.
 *
 * Se arma solo: inyecta su propio diálogo en el documento, así ninguna vista
 * tiene que llevar el marcado. Guarda contra EditarProductoController
 * (accion=guardar_sat), que toca únicamente la columna codigo_sat.
 *
 * Dejar el campo vacío BORRA la clave: es una forma válida de corregirse, no un
 * error, y por eso el botón cambia de texto cuando el campo se queda en blanco.
 *
 * Uso:
 *   SatEditor.abrir({
 *       codigo:   'BNS01-03768',     // interno, es la llave
 *       visible:  '915',             // el de la casa, para que lo reconozcan
 *       nombre:   'PASTA PARA SOLDAR',
 *       sat:      '31161500',        // lo que tiene hoy ('' si no tiene)
 *       ruta:     '../../app/controllers/EditarProductoController.php',
 *       alGuardar: function (satNuevo, mensaje) { ... }
 *   });
 */
window.SatEditor = (function () {
    'use strict';

    var modal   = null;
    var caja    = null;
    var actual  = null;   // los datos de lo que se está editando

    function esc(texto) {
        var div = document.createElement('div');
        div.textContent = texto === null || texto === undefined ? '' : texto;

        // innerHTML no escapa comillas y esto va dentro de value="…".
        return div.innerHTML.replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    /** El diálogo se crea una sola vez y se reusa. */
    function armar() {
        if (modal) return;

        modal = document.createElement('div');
        modal.className = 'modal';
        modal.id = 'modal-sat';
        modal.hidden = true;
        modal.innerHTML =
            '<div class="modal-caja">' +
                '<button type="button" class="modal-cerrar" data-sat="cerrar" title="Cerrar">' +
                    '<i class="ph ph-x"></i></button>' +
                '<div id="sat-editor-cuerpo"></div>' +
            '</div>';

        document.body.appendChild(modal);
        caja = modal.querySelector('#sat-editor-cuerpo');

        modal.addEventListener('click', function (ev) {
            if (ev.target === modal || ev.target.closest('[data-sat="cerrar"]')) {
                cerrar();
                return;
            }
            if (ev.target.closest('[data-sat="guardar"]')) { guardar(); }
            if (ev.target.closest('[data-sat="vaciar"]')) {
                var campo = document.getElementById('sat-campo');
                campo.value = '';
                campo.focus();
                refrescarBoton();
            }
        });

        modal.addEventListener('input', function (ev) {
            if (ev.target.id === 'sat-campo') { refrescarBoton(); }
        });

        modal.addEventListener('keydown', function (ev) {
            if (ev.key === 'Enter' && ev.target.id === 'sat-campo') {
                ev.preventDefault();
                guardar();
            }
        });

        document.addEventListener('keydown', function (ev) {
            if (ev.key === 'Escape' && modal && !modal.hidden) { cerrar(); }
        });
    }

    /** Avisa si al guardar se va a borrar la clave en lugar de ponerla. */
    function refrescarBoton() {
        var campo = document.getElementById('sat-campo');
        var boton = document.getElementById('sat-guardar');
        if (!campo || !boton) return;

        var vacio = campo.value.trim() === '';
        var tenia = (actual.sat || '').trim() !== '';

        boton.innerHTML = (vacio && tenia)
            ? '<i class="ph ph-trash"></i> Quitar la clave'
            : '<i class="ph ph-check"></i> Guardar';
    }

    function abrir(opciones) {
        armar();
        actual = opciones;

        caja.innerHTML =
            '<h2 class="detalle-titulo">Clave del SAT</h2>' +
            '<div class="detalle-cabecera">' +
                '<p><strong>' + esc(opciones.nombre) + '</strong></p>' +
                '<p class="detalle-sub"><span class="codigo-prod">' +
                    esc(opciones.visible || opciones.codigo) + '</span></p>' +
            '</div>' +
            '<div class="form-group">' +
                '<label for="sat-campo">ClaveProdServ</label>' +
                '<input type="text" id="sat-campo" class="form-control" maxlength="20" ' +
                       'inputmode="numeric" autocomplete="off" placeholder="Ej. 31161500" ' +
                       'value="' + esc(opciones.sat || '') + '">' +
                '<p class="detalle-sub">Es la clave con la que se factura el producto. ' +
                    'Déjala en blanco para quitarla.</p>' +
            '</div>' +
            '<div id="sat-editor-aviso" class="aviso" hidden></div>' +
            '<div class="detalle-acciones">' +
                '<button type="button" class="chip" data-sat="cerrar">Cancelar</button>' +
                ((opciones.sat || '').trim() !== ''
                    ? '<button type="button" class="chip" data-sat="vaciar">Vaciar</button>'
                    : '') +
                '<button type="button" class="btn-save" id="sat-guardar" data-sat="guardar">' +
                    '<i class="ph ph-check"></i> Guardar</button>' +
            '</div>';

        modal.hidden = false;

        var campo = document.getElementById('sat-campo');
        campo.focus();
        campo.select();
        refrescarBoton();
    }

    function cerrar() {
        if (modal) modal.hidden = true;
        actual = null;
    }

    function fallar(texto) {
        var aviso = document.getElementById('sat-editor-aviso');
        if (!aviso) return;

        aviso.textContent = texto;
        aviso.className = 'aviso aviso-error';
        aviso.hidden = false;
    }

    async function guardar() {
        var campo = document.getElementById('sat-campo');
        var boton = document.getElementById('sat-guardar');
        var valor = campo.value.trim();

        boton.disabled = true;

        try {
            var respuesta = await fetch(actual.ruta + '?accion=guardar_sat', {
                method:  'POST',
                headers: { 'Content-Type': 'application/json' },
                body:    JSON.stringify({ codigo: actual.codigo, codigo_sat: valor }),
            });
            var datos = await respuesta.json();

            if (!datos.ok) {
                fallar(datos.error || 'No se pudo guardar la clave.');
                boton.disabled = false;
                return;
            }

            var alGuardar = actual.alGuardar;
            var satNuevo  = datos.codigo_sat || '';
            var codigo    = actual.codigo;

            cerrar();

            if (typeof alGuardar === 'function') {
                alGuardar(satNuevo, datos.mensaje, codigo);
            }

        } catch (e) {
            fallar('Error de conexión.');
            boton.disabled = false;
        }
    }

    return { abrir: abrir, cerrar: cerrar };
}());
