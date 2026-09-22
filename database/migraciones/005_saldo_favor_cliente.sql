-- 005 · Ventas ligadas al cliente + saldo a favor
--
-- Dos columnas nuevas en `ventas`:
--
--   * cliente_id: liga la venta al cliente del catalogo (tabla clientes). Es
--     opcional: las ventas con nombre tecleado a mano quedan sin liga (NULL) y
--     no acumulan saldo. Solo las ventas nuevas donde se elija un cliente dado
--     de alta llevan cliente_id.
--
--   * credito_aplicado: cuanto saldo a favor del cliente se uso como descuento
--     en esta venta. Cuenta como pagado (igual que un abono), pero se guarda
--     aparte para poder llevar la cuenta del saldo por cliente.
--
-- El SALDO A FAVOR de un cliente NO se guarda en ningun lado: se calcula.
--   generado  = suma de lo que pago de mas en sus ventas (cobrado+credito-total)
--   aplicado  = suma de credito_aplicado de sus ventas
--   disponible = generado - aplicado
--
-- ¿Ya esta aplicada? Si esta consulta devuelve 2, si:
--
--   SELECT COUNT(*) FROM information_schema.columns
--    WHERE table_schema = DATABASE() AND table_name = 'ventas'
--      AND column_name IN ('cliente_id', 'credito_aplicado');

ALTER TABLE ventas
  ADD COLUMN cliente_id       INT           DEFAULT NULL  AFTER cliente,
  ADD COLUMN credito_aplicado DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER monto_cobrado,
  ADD KEY idx_v_cliente_id (cliente_id),
  ADD CONSTRAINT fk_ventas_cliente FOREIGN KEY (cliente_id) REFERENCES clientes (id) ON DELETE SET NULL;

-- El trigger de abonos ahora cuenta el credito aplicado como parte de lo pagado,
-- para que el estado (pendiente/parcial/pagado/devolucion) salga correcto.
DROP TRIGGER IF EXISTS trg_pago_credito_insert;

DELIMITER $$

CREATE TRIGGER trg_pago_credito_insert AFTER INSERT ON pagos_credito FOR EACH ROW
BEGIN
    DECLARE v_total   DECIMAL(10,2);
    DECLARE v_cobrado DECIMAL(10,2);
    DECLARE v_credito DECIMAL(10,2);

    SELECT total, credito_aplicado INTO v_total, v_credito FROM ventas WHERE id = NEW.venta_id;
    SELECT COALESCE(SUM(monto), 0) INTO v_cobrado FROM pagos_credito WHERE venta_id = NEW.venta_id;

    UPDATE ventas
       SET monto_cobrado = v_cobrado,
           estado_pago = CASE
               WHEN v_cobrado + v_credito >  v_total THEN 'devolucion'
               WHEN v_cobrado + v_credito >= v_total THEN 'pagado'
               WHEN v_cobrado + v_credito >  0       THEN 'parcial'
               ELSE 'pendiente'
           END
     WHERE id = NEW.venta_id;
END$$

DELIMITER ;

-- La vista de estado ahora resta tambien el credito aplicado del saldo pendiente
-- y lo suma a lo pagado para la devolucion. Agrega cliente_id y credito_aplicado.
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
       v.fecha_vencimiento AS fecha_vencimiento
FROM ventas v;

-- Verificacion:
--   SELECT id, cliente_id, credito_aplicado FROM ventas LIMIT 5;
