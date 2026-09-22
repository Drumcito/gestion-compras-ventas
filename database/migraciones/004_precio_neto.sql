-- 004 · Un solo precio (bruto) + porcentaje por casa para el neto
--
-- Cambio de fondo en como se maneja el precio:
--
--   * Se elimina el precio de MENUDEO. Ya no existe.
--   * El precio de MAYOREO pasa a ser el precio BRUTO: es el unico que el
--     administrador captura y edita en Inventario.
--   * El precio NETO (el que se cobra en una venta) NO se guarda: se calcula
--     como  bruto * (1 + porcentaje_neto/100)  y se redondea a 2 decimales.
--     Asi, cambiar el porcentaje de una casa recalcula solo todos sus netos.
--
-- El porcentaje vive por casa (columna nueva `casas.porcentaje_neto`), arranca
-- en 13% para todas y 12% para la C4 (BNS03), y el admin lo puede modificar.
--
-- ¿Ya esta aplicada? Si esta consulta devuelve una fila, si:
--
--   SELECT column_name FROM information_schema.columns
--    WHERE table_schema = DATABASE() AND table_name = 'casas'
--      AND column_name = 'porcentaje_neto';

-- 1) Porcentaje por casa. Default 13; la C4 (BNS03) queda en 12.
ALTER TABLE casas
  ADD COLUMN porcentaje_neto DECIMAL(5,2) NOT NULL DEFAULT 13.00 AFTER nombre;

UPDATE casas SET porcentaje_neto = 12.00 WHERE codigo_casa = 'BNS03';

-- 2) Fuera los triggers viejos: registran cambios de menudeo, y ademas
--    referencian la columna que vamos a borrar (si no se quitan, el DROP COLUMN
--    truena). Se recrean abajo, ya solo con mayoreo.
DROP TRIGGER IF EXISTS trg_precio_casa1_update;
DROP TRIGGER IF EXISTS trg_precio_casa2_update;
DROP TRIGGER IF EXISTS trg_precio_casa3_update;
DROP TRIGGER IF EXISTS trg_precio_casa4_update;

-- 3a) IMPORTANTE antes de borrar nada: la C4 (BNS03 / La Jaladera) nunca uso
--     precio_mayoreo; su unico precio vivia en precio_menudeo. Lo mismo con unos
--     pocos productos sueltos de las demas casas. Ese precio pasa a ser el bruto
--     (mayoreo) para NO perderlo al borrar la columna. Solo se toca donde el
--     bruto esta vacio: no pisa los precios de mayoreo que ya existen.
UPDATE productos_casa1 SET precio_mayoreo = precio_menudeo WHERE precio_mayoreo IS NULL AND precio_menudeo IS NOT NULL;
UPDATE productos_casa2 SET precio_mayoreo = precio_menudeo WHERE precio_mayoreo IS NULL AND precio_menudeo IS NOT NULL;
UPDATE productos_casa3 SET precio_mayoreo = precio_menudeo WHERE precio_mayoreo IS NULL AND precio_menudeo IS NOT NULL;
UPDATE productos_casa4 SET precio_mayoreo = precio_menudeo WHERE precio_mayoreo IS NULL AND precio_menudeo IS NOT NULL;

-- 3b) Ahora si, adios al precio de menudeo en las cuatro casas.
ALTER TABLE productos_casa1 DROP COLUMN precio_menudeo;
ALTER TABLE productos_casa2 DROP COLUMN precio_menudeo;
ALTER TABLE productos_casa3 DROP COLUMN precio_menudeo;
ALTER TABLE productos_casa4 DROP COLUMN precio_menudeo;

-- 4) Las ventas nuevas guardan el tipo de precio como 'neto'. Las viejas
--    conservan su valor ('mayoreo'/'menudeo'): el precio ya quedo congelado en
--    detalle_venta.precio_aplicado, esto es solo la etiqueta de como se calculo.
ALTER TABLE detalle_venta
  MODIFY COLUMN tipo_precio ENUM('mayoreo','menudeo','neto') COLLATE utf8mb4_unicode_ci NOT NULL;

-- 5) vista_catalogo sin la columna de menudeo (la usa el buscador de ventas).
--    Al borrar la columna, la vista queda invalida hasta recrearla. Los nombres
--    de casa son los mismos que ya tenia; cuando nace una casa, el sistema
--    reescribe esta vista solo (ver app/helpers/casas.php).
CREATE OR REPLACE VIEW vista_catalogo AS
  SELECT 'BNS01' AS codigo_casa, 'DIPLOMEX' AS nombre_casa, codigo_interno, codigo_proveedor,
         nombre, marca, categoria, precio_mayoreo, activo FROM productos_casa1
  UNION ALL
  SELECT 'BNS02', 'Marcus', codigo_interno, codigo_proveedor,
         nombre, marca, categoria, precio_mayoreo, activo FROM productos_casa2
  UNION ALL
  SELECT 'BNS03', 'La Jaladera', codigo_interno, codigo_proveedor,
         nombre, marca, categoria, precio_mayoreo, activo FROM productos_casa3
  UNION ALL
  SELECT 'BNS04', 'Ferra Cholulita', codigo_interno, codigo_proveedor,
         nombre, marca, categoria, precio_mayoreo, activo FROM productos_casa4;

-- 6) Triggers de historial, ya solo para el precio bruto (mayoreo). @usuario_actual
--    es lo que le dice quien hizo el cambio (lo pone PrecioController).
DELIMITER $$

CREATE TRIGGER trg_precio_casa1_update AFTER UPDATE ON productos_casa1 FOR EACH ROW
BEGIN
    IF NOT (OLD.precio_mayoreo <=> NEW.precio_mayoreo) THEN
        INSERT INTO historial_precios (casa_id, codigo_interno_producto, tipo_precio, precio_anterior, precio_nuevo, usuario_id)
        VALUES ((SELECT id FROM casas WHERE codigo_casa = 'BNS01'), NEW.codigo_interno, 'mayoreo', OLD.precio_mayoreo, NEW.precio_mayoreo, @usuario_actual);
    END IF;
END$$

CREATE TRIGGER trg_precio_casa2_update AFTER UPDATE ON productos_casa2 FOR EACH ROW
BEGIN
    IF NOT (OLD.precio_mayoreo <=> NEW.precio_mayoreo) THEN
        INSERT INTO historial_precios (casa_id, codigo_interno_producto, tipo_precio, precio_anterior, precio_nuevo, usuario_id)
        VALUES ((SELECT id FROM casas WHERE codigo_casa = 'BNS02'), NEW.codigo_interno, 'mayoreo', OLD.precio_mayoreo, NEW.precio_mayoreo, @usuario_actual);
    END IF;
END$$

CREATE TRIGGER trg_precio_casa3_update AFTER UPDATE ON productos_casa3 FOR EACH ROW
BEGIN
    IF NOT (OLD.precio_mayoreo <=> NEW.precio_mayoreo) THEN
        INSERT INTO historial_precios (casa_id, codigo_interno_producto, tipo_precio, precio_anterior, precio_nuevo, usuario_id)
        VALUES ((SELECT id FROM casas WHERE codigo_casa = 'BNS03'), NEW.codigo_interno, 'mayoreo', OLD.precio_mayoreo, NEW.precio_mayoreo, @usuario_actual);
    END IF;
END$$

CREATE TRIGGER trg_precio_casa4_update AFTER UPDATE ON productos_casa4 FOR EACH ROW
BEGIN
    IF NOT (OLD.precio_mayoreo <=> NEW.precio_mayoreo) THEN
        INSERT INTO historial_precios (casa_id, codigo_interno_producto, tipo_precio, precio_anterior, precio_nuevo, usuario_id)
        VALUES ((SELECT id FROM casas WHERE codigo_casa = 'BNS04'), NEW.codigo_interno, 'mayoreo', OLD.precio_mayoreo, NEW.precio_mayoreo, @usuario_actual);
    END IF;
END$$

DELIMITER ;

-- Verificacion:
--   SELECT codigo_casa, porcentaje_neto FROM casas ORDER BY codigo_casa;
--   SHOW COLUMNS FROM productos_casa1 LIKE 'precio_%';   -- solo precio_mayoreo
