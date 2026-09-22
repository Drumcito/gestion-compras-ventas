-- 006 · Correo del cliente
--
-- Para poder enviar la nota por correo se guarda el email del cliente. Es
-- opcional (muchos clientes no tienen), igual que el telefono.
--
-- ¿Ya esta aplicada? Si esta consulta devuelve una fila, si:
--
--   SELECT column_name FROM information_schema.columns
--    WHERE table_schema = DATABASE() AND table_name = 'clientes'
--      AND column_name = 'email';

ALTER TABLE clientes
  ADD COLUMN email VARCHAR(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER telefono;
