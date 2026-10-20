/* Comun a todas las paginas del catalogo: boton de tema y formulario del pie. */
(function () {
    'use strict';

    // ---------- Modo claro / oscuro ----------
    var raiz = document.documentElement;
    var botonTema = document.getElementById('boton-tema');
    var sistemaOscuro = window.matchMedia('(prefers-color-scheme: dark)');

    function temaActual() {
        return raiz.getAttribute('data-tema') || (sistemaOscuro.matches ? 'oscuro' : 'claro');
    }

    function rotularBoton() {
        if (botonTema) {
            botonTema.setAttribute('aria-label',
                temaActual() === 'oscuro' ? 'Cambiar a modo claro' : 'Cambiar a modo oscuro');
        }
    }

    if (botonTema) {
        rotularBoton();
        botonTema.addEventListener('click', function () {
            var nuevo = temaActual() === 'oscuro' ? 'claro' : 'oscuro';
            raiz.setAttribute('data-tema', nuevo);
            try { localStorage.setItem('tema', nuevo); } catch (e) { /* modo privado: solo dura esta visita */ }
            rotularBoton();
        });
        if (sistemaOscuro.addEventListener) {
            sistemaOscuro.addEventListener('change', rotularBoton);
        }
    }

    // ---------- Formulario de contacto ----------
    var form = document.getElementById('form-contacto');
    if (!form) {
        return;
    }

    var mensaje = document.getElementById('mensaje-form');
    var boton = form.querySelector('button[type="submit"]');

    function avisar(texto, tipo) {
        mensaje.textContent = texto;
        mensaje.className = 'mensaje-form ' + (tipo || '');
    }

    function marcar(nombre, mal) {
        var campo = form.elements[nombre];
        var objetivo = campo.type === 'checkbox' ? campo.closest('.casilla') : campo;
        if (mal) {
            objetivo.setAttribute('aria-invalid', 'true');
        } else {
            objetivo.removeAttribute('aria-invalid');
        }
    }

    function telefonoLimpio(valor) {
        // Igual que el servidor: acepta +52 / 52 / 521 delante de los 10 digitos.
        return valor.replace(/\D+/g, '').replace(/^(52)?1?(?=\d{10}$)/, '');
    }

    function validar() {
        var nombre = form.elements.nombre.value.trim();
        var tel = form.elements.telefono.value.trim();
        var correo = form.elements.correo.value.trim();
        ['nombre', 'telefono', 'correo', 'acepta_aviso'].forEach(function (n) { marcar(n, false); });

        if (nombre.length < 2) {
            return ['nombre', 'Escribe tu nombre o el de tu negocio.'];
        }
        if (!tel && !correo) {
            return ['telefono', 'Escribe al menos tu teléfono o tu correo.'];
        }
        if (tel && telefonoLimpio(tel).length !== 10) {
            return ['telefono', 'El teléfono debe tener 10 dígitos.'];
        }
        if (correo && !/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(correo)) {
            return ['correo', 'Revisa el correo electrónico.'];
        }
        if (!form.elements.acepta_aviso.checked) {
            return ['acepta_aviso', 'Para enviarte información necesitamos que aceptes el Aviso de Privacidad y los Términos.'];
        }
        return null;
    }

    form.addEventListener('submit', function (ev) {
        ev.preventDefault();

        var problema = validar();
        if (problema) {
            marcar(problema[0], true);
            form.elements[problema[0]].focus();
            avisar(problema[1], 'error');
            return;
        }

        boton.disabled = true;
        avisar('Enviando…');

        fetch(form.action, { method: 'POST', body: new FormData(form), headers: { 'Accept': 'application/json' } })
            .then(function (r) {
                return r.json().catch(function () { return { ok: false, error: 'Respuesta inesperada del servidor.' }; });
            })
            .then(function (datos) {
                if (datos.ok) {
                    form.reset();
                    avisar(datos.mensaje, 'ok');
                } else {
                    if (datos.campo && form.elements[datos.campo]) {
                        marcar(datos.campo, true);
                        form.elements[datos.campo].focus();
                    }
                    avisar(datos.error || 'No se pudo enviar. Intenta de nuevo.', 'error');
                }
            })
            .catch(function () {
                avisar('Sin conexión. Revisa tu internet e intenta de nuevo.', 'error');
            })
            .then(function () {
                boton.disabled = false;
            });
    });
})();
