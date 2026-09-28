-- 008 · Porcentaje del neto por producto (además del de la casa)
--
-- Hasta ahora el porcentaje con el que se calcula el neto vivía solo por casa
-- (`casas.porcentaje_neto`, ver migración 004): todos los productos de una casa
-- usaban el mismo. Con este cambio un producto puede llevar SU PROPIO
-- porcentaje, distinto al de su casa, sin afectar a los demás.
--
--   * Se agrega la columna `porcentaje_neto` a cada tabla de productos
--     (productos_casaN). Es NULL por omisión.
--   * NULL  = el producto usa el porcentaje de su casa (comportamiento de antes).
--   * 0..999.99 = el producto usa ESE porcentaje, ignorando el de la casa.
--
-- El neto se sigue calculando al vuelo (bruto * (1 + %/100)); no se guarda. Lo
-- único nuevo es de dónde sale el % de cada producto.
--
-- ¿Ya está aplicada? Si esta consulta devuelve una fila, sí:
--
--   SELECT column_name FROM information_schema.columns
--    WHERE table_schema = DATABASE() AND table_name = 'productos_casa1'
--      AND column_name = 'porcentaje_neto';

-- 1) Agrega la columna a TODAS las tablas de productos que aún no la tengan
--    (incluye la casa "Otros" o cualquier casa creada después). Es idempotente:
--    si ya existe en una tabla, esa se salta, así que se puede volver a correr
--    sin peligro.
DELIMITER $$

DROP PROCEDURE IF EXISTS agregar_porcentaje_producto$$

CREATE PROCEDURE agregar_porcentaje_producto()
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
               AND column_name = 'porcentaje_neto'
        ) THEN
            SET @ddl = CONCAT('ALTER TABLE `', tabla,
                '` ADD COLUMN porcentaje_neto DECIMAL(5,2) NULL DEFAULT NULL AFTER precio_mayoreo');
            PREPARE st FROM @ddl;
            EXECUTE st;
            DEALLOCATE PREPARE st;
        END IF;
    END LOOP;
    CLOSE cur;
END$$

DELIMITER ;

CALL agregar_porcentaje_producto();

DROP PROCEDURE IF EXISTS agregar_porcentaje_producto;

-- Si el hosting no deja crear procedimientos (CREATE ROUTINE), corre a mano una
-- línea por cada tabla de productos que exista, en vez del bloque de arriba:
--
--   ALTER TABLE productos_casa1 ADD COLUMN porcentaje_neto DECIMAL(5,2) NULL DEFAULT NULL AFTER precio_mayoreo;
--   ALTER TABLE productos_casa2 ADD COLUMN porcentaje_neto DECIMAL(5,2) NULL DEFAULT NULL AFTER precio_mayoreo;
--   ALTER TABLE productos_casa3 ADD COLUMN porcentaje_neto DECIMAL(5,2) NULL DEFAULT NULL AFTER precio_mayoreo;
--   ALTER TABLE productos_casa4 ADD COLUMN porcentaje_neto DECIMAL(5,2) NULL DEFAULT NULL AFTER precio_mayoreo;

-- 2) vista_catalogo la usa el buscador de la venta para calcular el neto. Se
--    recrea agregando `porcentaje_neto` para que el override viaje con cada
--    producto. Se arma dinámicamente a partir de la tabla `casas`, así incluye
--    TODAS las casas (la 1..4 y también "Otros" o cualquier otra creada después)
--    sin dejar ninguna fuera del buscador. Es el mismo criterio que usa el
--    código en refrescarVistaCatalogo() (app/helpers/casas.php).
SET SESSION group_concat_max_len = 1000000;

SELECT GROUP_CONCAT(
           CONCAT('SELECT ', QUOTE(c.codigo_casa), ' AS codigo_casa, ',
                  QUOTE(c.nombre), ' AS nombre_casa, codigo_interno, codigo_proveedor, ',
                  'nombre, marca, categoria, precio_mayoreo, porcentaje_neto, activo FROM `',
                  c.tabla_productos, '`')
           SEPARATOR ' UNION ALL '
       )
  INTO @union_catalogo
  FROM casas c
 WHERE c.tabla_productos REGEXP '^productos_casa[0-9]+$'
   AND EXISTS (SELECT 1 FROM information_schema.tables t
                WHERE t.table_schema = DATABASE()
                  AND t.table_name = c.tabla_productos);

SET @ddl_vista = CONCAT('CREATE OR REPLACE VIEW vista_catalogo AS ', @union_catalogo);
PREPARE st FROM @ddl_vista;
EXECUTE st;
DEALLOCATE PREPARE st;

-- Si el hosting no deja SQL dinámico, corre a mano un CREATE OR REPLACE VIEW con
-- un bloque "SELECT ... UNION ALL" por cada tabla productos_casaN que exista,
-- agregando la columna porcentaje_neto al SELECT (mismo formato que la migración
-- 004, pero con porcentaje_neto añadido).

-- Verificación:
--   SHOW COLUMNS FROM productos_casa1 LIKE 'porcentaje_neto';
--   SELECT codigo_interno, precio_mayoreo, porcentaje_neto FROM productos_casa1 LIMIT 5;
