-- 001 · Columna usuarios.debe_cambiar_password
--
-- El commit 03ab242 ("Asignacion de password y forzar cambio de password en el
-- primer inicio de sesion") agrego el codigo pero no la columna. AuthController
-- la pide en el SELECT del login: si falta, NADIE puede entrar (el login manda
-- a login.php?error=server).
--
-- ¿Ya esta aplicada? Si esta consulta devuelve una fila, si:
--
--   SELECT column_name FROM information_schema.columns
--    WHERE table_schema = DATABASE() AND table_name = 'usuarios'
--      AND column_name = 'debe_cambiar_password';

ALTER TABLE usuarios
  ADD COLUMN debe_cambiar_password TINYINT(1) NOT NULL DEFAULT 0 AFTER password;

-- DEFAULT 0: los usuarios que ya existen siguen entrando igual, sin que el
-- sistema les exija cambiar la contraseña.
