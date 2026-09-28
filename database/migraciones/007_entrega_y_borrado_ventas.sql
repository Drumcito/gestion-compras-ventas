-- 007 · Entrega y borrado suave de ventas
--
-- Tres columnas nuevas en `ventas`:
--
--   * entregada_en: fecha en que la mercancia se entrego al cliente. NULL =
--     todavia no se marca como entregada. La captura el boton de la palomita
--     en el historial.
--
--   * eliminada_en: momento en que la venta se "elimino". Es un BORRADO SUAVE:
--     la venta se desactiva (deja de contar y de aparecer en el historial,
--     estadisticas, exportaciones y saldo de clientes) pero sigue en la base.
--     NULL = venta activa. Quien la elimino tiene 10 minutos para recuperarla;
--     pasados los 10 min, la proxima vez que se abra el historial se borra de
--     verdad (ver HistorialController: purga las vencidas).
--
--   * eliminada_por: usuario que la desactivo, para poder auditar quien fue.
--
-- Un vendedor solo puede eliminar/recuperar sus propias ventas; el admin, todas.
-- No se pueden eliminar ventas de credito que ya tengan abonos registrados.
--
-- ¿Ya esta aplicada? Si esta consulta devuelve 3, si:
--
--   SELECT COUNT(*) FROM information_schema.columns
--    WHERE table_schema = DATABASE() AND table_name = 'ventas'
--      AND column_name IN ('entregada_en', 'eliminada_en', 'eliminada_por');

ALTER TABLE ventas
  ADD COLUMN entregada_en  DATE     DEFAULT NULL AFTER fecha_vencimiento,
  ADD COLUMN eliminada_en  DATETIME DEFAULT NULL AFTER entregada_en,
  ADD COLUMN eliminada_por INT      DEFAULT NULL AFTER eliminada_en,
  ADD KEY idx_v_eliminada_en (eliminada_en),
  ADD CONSTRAINT fk_ventas_eliminada_por FOREIGN KEY (eliminada_por)
      REFERENCES usuarios (id) ON DELETE SET NULL;

-- La vista de estado suma lo pagado/pendiente por venta. Se agrega eliminada_en
-- para que quien lea la vista (p. ej. la cobranza del dashboard) pueda dejar
-- fuera las ventas desactivadas. Mismo contenido que en la 005, mas la columna.
CREATE OR REPLACE VIEW vista_estado_ventas AS
SELECT v.id                AS venta_id,
       v.usuario_id        AS usuario_id,
       v.cliente           AS cliente,
       v.cliente_id        AS cliente_id,
       v.fecha             AS fecha,
       v.tipo_pago         AS tipo_pago,
       v.estado_pago       AS estado_pago,
       v.total             AS total,
       v.monto_cobrado     AS total_abonado,
       v.credito_aplicado  AS credito_aplicado,
       GREATEST(v.total - v.monto_cobrado - v.credito_aplicado, 0) AS saldo_pendiente,
       GREATEST(v.monto_cobrado + v.credito_aplicado - v.total, 0) AS devolucion,
       v.fecha_vencimiento AS fecha_vencimiento,
       v.eliminada_en      AS eliminada_en
FROM ventas v;

-- Verificacion:
--   SELECT id, entregada_en, eliminada_en, eliminada_por FROM ventas LIMIT 5;
