document.addEventListener('DOMContentLoaded', () => {
    const toggleBtn = document.querySelector('.toggle-password');
    const passwordInput = document.getElementById('password');

    if (toggleBtn && passwordInput) {
        toggleBtn.addEventListener('click', () => {
            const isHidden = passwordInput.type === 'password';
            passwordInput.type = isHidden ? 'text' : 'password';
            toggleBtn.setAttribute(
                'aria-label',
                isHidden ? 'Ocultar contraseña' : 'Mostrar contraseña'
            );
        });
    }

    const form = document.querySelector('.login-form');
    if (form) {
        form.addEventListener('submit', (e) => {
            const usuario = document.getElementById('usuario');
            const password = document.getElementById('password');

            if (!usuario.value.trim() || !password.value.trim()) {
                e.preventDefault();
                alert('Por favor completa usuario y contraseña.');
            }
        });
    }
});