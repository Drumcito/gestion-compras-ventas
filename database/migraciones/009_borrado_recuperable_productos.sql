-- 009 · Borrado recuperable de productos (10 minutos para deshacer)
--
-- Borrar un producto del inventario ya era una baja lógica (`activo = 0`): la
-- fila se queda en la tabla porque las ventas registradas guardan su
-- codigo_interno y el historial de precios depende de ella. Lo que faltaba era
-- poder DESHACERLO: una vez borrado, no había forma de regresarlo desde la
-- pantalla.
--
-- Con este cambio el borrado queda sellado con la fecha y el usuario, y durante
-- 10 minutos el producto se puede recuperar desde Inventario.
--
--   * `eliminado_en`  NULL = no se borró desde la pantalla de Inventario.
--                     Con fecha = se borró en ese momento.
--   * `eliminado_por` quién lo borró (usuarios.id).
--
-- OJO con la diferencia contra el borrado de ventas (migración 007): una venta
-- se BORRA DE VERDAD al pasar los 10 minutos. Un producto NO. Pasado el plazo
-- la fila se queda igual que hoy (activo = 0, fuera del inventario y del
-- buscador); lo único que vence es la posibilidad de recuperarlo desde la
-- pantalla. Borrarla de verdad rompería las ventas que la referencian.
--
-- Importante: mover un producto de casa (EditarProductoController) también pone
-- `activo = 0` en la casa de origen, pero NO llena `eliminado_en`. Por eso la
-- lista de recuperables filtra por `eliminado_en IS NOT NULL`: un producto
-- movido no aparece ahí, que es lo correcto (no se borró, se cambió de casa).
--
-- ¿Ya está aplicada? Si esta consulta devuelve una fila, sí:
--
--   SELECT column_name FROM information_schema.columns
--    WHERE table_schema = DATABASE() AND table_name = 'productos_casa1'
--      AND column_name = 'eliminado_en';

-- Agrega las columnas a TODAS las tablas de productos que aún no las tengan
-- (incluye "Otros" o cualquier casa creada después). Es idempotente: la tabla
-- que ya las tenga se salta, así que se puede volver a correr sin peligro.
DELIMITER $$

DROP PROCEDURE IF EXISTS agregar_borrado_recuperable$$

CREATE PROCEDURE agregar_borrado_recuperable()
BEGIN
    DECLARE terminado INT DEFAULT 0;
    DECLARE tabla VARCHAR(64);
    DECLARE cur CURSOR FOR
        SELECT table_name FROM information_schema.tables
         WHERE table_schema = DATABASE()
           AND table_name REGEXP '^productos_casa[0-9]+$';
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET terminado = 1;

    OPEN cur;
    bucle: LOOP
        FETCH cur INTO tabla;
        IF terminado THEN LEAVE bucle; END IF;

        IF NOT EXISTS (
            SELECT 1 FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = tabla
               AND column_name = 'eliminado_en'
        ) THEN
            -- Las dos columnas y el índice van en un solo ALTER: reconstruir la
            -- tabla una vez en lugar de tres.
            --
            -- El índice sirve para la lista de recuperables, que busca
            -- `eliminado_en IS NOT NULL`. Como casi todas las filas lo tienen en
            -- NULL, el índice deja esa consulta en unas pocas filas en vez de
            -- recorrer el catálogo entero de la casa.
            SET @ddl = CONCAT('ALTER TABLE `', tabla, '`',
                ' ADD COLUMN eliminado_en DATETIME NULL DEFAULT NULL AFTER activo,',
                ' ADD COLUMN eliminado_por INT NULL DEFAULT NULL AFTER eliminado_en,',
                ' ADD KEY `idx_', tabla, '_eliminado_en` (eliminado_en)');
            PREPARE st FROM @ddl;
            EXECUTE st;
            DEALLOCATE PREPARE st;
        END IF;
    END LOOP;
    CLOSE cur;
END$$

DELIMITER ;

CALL agregar_borrado_recuperable();

DROP PROCEDURE IF EXISTS agregar_borrado_recuperable;

-- Si el hosting no deja crear procedimientos (CREATE ROUTINE), corre a mano una
-- línea por cada tabla de productos que exista, en vez del bloque de arriba:
--
--   ALTER TABLE productos_casa1
--     ADD COLUMN eliminado_en DATETIME NULL DEFAULT NULL AFTER activo,
--     ADD COLUMN eliminado_por INT NULL DEFAULT NULL AFTER eliminado_en,
--     ADD KEY idx_productos_casa1_eliminado_en (eliminado_en);
--   ALTER TABLE productos_casa2 ... (igual, cambiando el número)
--   ALTER TABLE productos_casa3 ...
--   ALTER TABLE productos_casa4 ...

-- vista_catalogo NO se toca: el inventario lee la tabla de la casa directo, y la
-- vista solo la usa el buscador de la venta, que filtra activo = 1 y por lo
-- tanto nunca ve un producto borrado.

-- Verificación:
--   SHOW COLUMNS FROM productos_casa1 LIKE 'eliminado%';
--   SELECT codigo_interno, nombre, activo, eliminado_en, eliminado_por
--     FROM productos_casa1 WHERE eliminado_en IS NOT NULL;
