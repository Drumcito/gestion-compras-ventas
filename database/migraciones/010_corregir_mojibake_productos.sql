-- 010 · Corrige nombres de productos con caracteres dañados (mojibake)
--
-- Al importar el catálogo desde Excel, el texto en UTF-8 se leyó como si fuera
-- CP850 (la página de códigos de la consola de Windows) y se guardó así. Cada
-- carácter especial quedó convertido en dos símbolos raros que siempre empiezan
-- con "├" o "┬". Ejemplo: "ABRAZADERA L)U├æA TUBO" debía ser "L)UÑA".
--
-- Afecta nombre (y en un par de casos marca y categoría) de las tablas
-- productos_casaN. vista_catalogo es un UNION de esas tablas, así que se corrige
-- sola.
--
-- Secuencias encontradas y su carácter correcto (en local: 631 filas, todas
-- activas; ninguna otra tabla tiene el problema):
--
--   Dañado  Correcto  Ejemplo
--   ├æ      Ñ         ABRAZADERA L)U├æA TUBO         → L)UÑA
--   ├▒      ñ         ABRAZADERA U├▒A 1/2"           → UñA
--   ┬░      °         CODO HIDRAULICO 45┬░           → 45°
--   ┬º      º         CODO DE ALUMINIO 4" X 90┬º     → 90º
--   ┬¢      ½         DISCO 4┬¢ DIAM                 → 4½
--   ┬╝      ¼         LIJADORA 220W ┬╝               → ¼
--   ┬¥      ¾         LLAVE 13/16-┬¥-17MM            → ¾
--   ┬┤      ´         HARVEY┬┤S                      → HARVEY´S
--   ┬í      ¡         HOOF CUSHION #┬íREF!           → #¡REF!
--   ┬¬      ª         BISAGRA AEREA HIDR 90┬¬        → 90ª
--
-- Estas cuatro venían dañadas DOS veces (el texto original ya estaba en CP850
-- antes de pasar por Excel), por eso no salen de leer una sola vez como CP850:
--
--   ┬½      ½         DISCO 4┬½ INOX                 → 4½
--   ┬¼      ¼         BROCA JGO 5PZ HEX┬¼            → HEX¼
--   ┬Ñ      Ñ         BASCULA BA┬ÑO                  → BAÑO
--   ┬ñ      ñ         CAMPA┬ñA 100 A┬ñOS             → CAMPAñA 100 AñOS
--
-- Se dejan "ñ" minúscula donde el dato original la traía (no se cambia a
-- mayúscula): el buscador no distingue mayúsculas, así que no afecta.
--
-- Los valores del UPDATE van en hexadecimal (X'...') a propósito: así la
-- migración funciona igual aunque el editor o phpMyAdmin peguen el archivo con
-- otra codificación. Los símbolos legibles solo están en estos comentarios.
--
-- No se toca `fecha_actualizacion`: es una limpieza de datos, no una edición
-- del producto. Tampoco se dispara el historial de precios (el trigger solo
-- registra cambios de precio).
--
-- ¿Ya está aplicada? Si esta consulta da 0 en todas las filas, sí (o la base
-- nunca tuvo el problema). Agrega una línea por cada productos_casaN que exista:
--
--   SELECT 'casa1' t, COUNT(*) dañados FROM productos_casa1
--    WHERE LOCATE(_utf8mb4 X'E2949C', CONCAT_WS(' ', nombre, marca, categoria))
--       OR LOCATE(_utf8mb4 X'E294AC', CONCAT_WS(' ', nombre, marca, categoria))
--   UNION ALL
--   SELECT 'casa2', COUNT(*) FROM productos_casa2
--    WHERE LOCATE(_utf8mb4 X'E2949C', CONCAT_WS(' ', nombre, marca, categoria))
--       OR LOCATE(_utf8mb4 X'E294AC', CONCAT_WS(' ', nombre, marca, categoria))
--   UNION ALL
--   SELECT 'casa3', COUNT(*) FROM productos_casa3
--    WHERE LOCATE(_utf8mb4 X'E2949C', CONCAT_WS(' ', nombre, marca, categoria))
--       OR LOCATE(_utf8mb4 X'E294AC', CONCAT_WS(' ', nombre, marca, categoria))
--   UNION ALL
--   SELECT 'casa4', COUNT(*) FROM productos_casa4
--    WHERE LOCATE(_utf8mb4 X'E2949C', CONCAT_WS(' ', nombre, marca, categoria))
--       OR LOCATE(_utf8mb4 X'E294AC', CONCAT_WS(' ', nombre, marca, categoria));
--
-- (X'E2949C' es "├" y X'E294AC' es "┬".)

-- Corrige TODAS las tablas de productos (incluye "Otros" o cualquier casa
-- creada después). Es idempotente: lo corregido ya no contiene "├" ni "┬", así
-- que volver a correrla no cambia nada.
DELIMITER $$

DROP PROCEDURE IF EXISTS corregir_mojibake_productos$$

CREATE PROCEDURE corregir_mojibake_productos()
BEGIN
    DECLARE terminado INT DEFAULT 0;
    DECLARE tabla VARCHAR(64);
    DECLARE arreglo TEXT;
    DECLARE cur CURSOR FOR
        SELECT table_name FROM information_schema.tables
         WHERE table_schema = DATABASE()
           AND table_name REGEXP '^productos_casa[0-9]+$';
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET terminado = 1;

    -- Un REPLACE anidado por secuencia; {c} se cambia por cada columna.
    SET arreglo = '{c}';
    SET arreglo = CONCAT('REPLACE(', arreglo, ', _utf8mb4 X''E2949CC3A6'', _utf8mb4 X''C391'')');   -- ├æ → Ñ
    SET arreglo = CONCAT('REPLACE(', arreglo, ', _utf8mb4 X''E2949CE29692'', _utf8mb4 X''C3B1'')'); -- ├▒ → ñ
    SET arreglo = CONCAT('REPLACE(', arreglo, ', _utf8mb4 X''E294ACE29691'', _utf8mb4 X''C2B0'')'); -- ┬░ → °
    SET arreglo = CONCAT('REPLACE(', arreglo, ', _utf8mb4 X''E294ACE29591'', _utf8mb4 X''C2BA'')'); -- ┬º → º
    SET arreglo = CONCAT('REPLACE(', arreglo, ', _utf8mb4 X''E294ACC2A2'', _utf8mb4 X''C2BD'')');   -- ┬¢ → ½
    SET arreglo = CONCAT('REPLACE(', arreglo, ', _utf8mb4 X''E294ACE2959D'', _utf8mb4 X''C2BC'')'); -- ┬╝ → ¼
    SET arreglo = CONCAT('REPLACE(', arreglo, ', _utf8mb4 X''E294ACC2A5'', _utf8mb4 X''C2BE'')');   -- ┬¥ → ¾
    SET arreglo = CONCAT('REPLACE(', arreglo, ', _utf8mb4 X''E294ACE294A4'', _utf8mb4 X''C2B4'')'); -- ┬┤ → ´
    SET arreglo = CONCAT('REPLACE(', arreglo, ', _utf8mb4 X''E294ACC3AD'', _utf8mb4 X''C2A1'')');   -- ┬í → ¡
    SET arreglo = CONCAT('REPLACE(', arreglo, ', _utf8mb4 X''E294ACC2AC'', _utf8mb4 X''C2AA'')');   -- ┬¬ → ª
    SET arreglo = CONCAT('REPLACE(', arreglo, ', _utf8mb4 X''E294ACC2BD'', _utf8mb4 X''C2BD'')');   -- ┬½ → ½ (doble)
    SET arreglo = CONCAT('REPLACE(', arreglo, ', _utf8mb4 X''E294ACC2BC'', _utf8mb4 X''C2BC'')');   -- ┬¼ → ¼ (doble)
    SET arreglo = CONCAT('REPLACE(', arreglo, ', _utf8mb4 X''E294ACC391'', _utf8mb4 X''C391'')');   -- ┬Ñ → Ñ (doble)
    SET arreglo = CONCAT('REPLACE(', arreglo, ', _utf8mb4 X''E294ACC3B1'', _utf8mb4 X''C3B1'')');   -- ┬ñ → ñ (doble)

    OPEN cur;
    bucle: LOOP
        FETCH cur INTO tabla;
        IF terminado THEN LEAVE bucle; END IF;

        -- Las tres columnas en un solo UPDATE y solo sobre las filas dañadas.
        SET @ddl = CONCAT('UPDATE `', tabla, '` SET',
            ' nombre = ',    REPLACE(arreglo, '{c}', 'nombre'),
            ', marca = ',    REPLACE(arreglo, '{c}', 'marca'),
            ', categoria = ', REPLACE(arreglo, '{c}', 'categoria'));

        -- Dejar la fecha de actualización como estaba (si la tabla la tiene).
        IF EXISTS (
            SELECT 1 FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = tabla
               AND column_name = 'fecha_actualizacion'
        ) THEN
            SET @ddl = CONCAT(@ddl, ', fecha_actualizacion = fecha_actualizacion');
        END IF;

        SET @ddl = CONCAT(@ddl,
            ' WHERE LOCATE(_utf8mb4 X''E2949C'', CONCAT_WS('' '', nombre, marca, categoria))',
            '    OR LOCATE(_utf8mb4 X''E294AC'', CONCAT_WS('' '', nombre, marca, categoria))');

        PREPARE st FROM @ddl;
        EXECUTE st;
        DEALLOCATE PREPARE st;
    END LOOP;
    CLOSE cur;
END$$

DELIMITER ;

CALL corregir_mojibake_productos();

DROP PROCEDURE IF EXISTS corregir_mojibake_productos;

-- Si el hosting no deja crear procedimientos (CREATE ROUTINE), corre a mano este
-- UPDATE una vez por cada tabla de productos que exista, cambiando el número de
-- casa en la primera línea (productos_casa1, productos_casa2, ...). La misma
-- lista de secuencias va aplicada a nombre, marca y categoría:
--
--   UPDATE productos_casa1 SET
--     nombre = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(nombre,
--       X'E2949CC3A6', X'C391'), X'E2949CE29692', X'C3B1'), X'E294ACE29691', X'C2B0'), X'E294ACE29591', X'C2BA'),
--       X'E294ACC2A2', X'C2BD'), X'E294ACE2959D', X'C2BC'), X'E294ACC2A5', X'C2BE'), X'E294ACE294A4', X'C2B4'),
--       X'E294ACC3AD', X'C2A1'), X'E294ACC2AC', X'C2AA'), X'E294ACC2BD', X'C2BD'), X'E294ACC2BC', X'C2BC'),
--       X'E294ACC391', X'C391'), X'E294ACC3B1', X'C3B1'),
--     marca = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(marca,
--       X'E2949CC3A6', X'C391'), X'E2949CE29692', X'C3B1'), X'E294ACE29691', X'C2B0'), X'E294ACE29591', X'C2BA'),
--       X'E294ACC2A2', X'C2BD'), X'E294ACE2959D', X'C2BC'), X'E294ACC2A5', X'C2BE'), X'E294ACE294A4', X'C2B4'),
--       X'E294ACC3AD', X'C2A1'), X'E294ACC2AC', X'C2AA'), X'E294ACC2BD', X'C2BD'), X'E294ACC2BC', X'C2BC'),
--       X'E294ACC391', X'C391'), X'E294ACC3B1', X'C3B1'),
--     categoria = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(categoria,
--       X'E2949CC3A6', X'C391'), X'E2949CE29692', X'C3B1'), X'E294ACE29691', X'C2B0'), X'E294ACE29591', X'C2BA'),
--       X'E294ACC2A2', X'C2BD'), X'E294ACE2959D', X'C2BC'), X'E294ACC2A5', X'C2BE'), X'E294ACE294A4', X'C2B4'),
--       X'E294ACC3AD', X'C2A1'), X'E294ACC2AC', X'C2AA'), X'E294ACC2BD', X'C2BD'), X'E294ACC2BC', X'C2BC'),
--       X'E294ACC391', X'C391'), X'E294ACC3B1', X'C3B1'),
--     fecha_actualizacion = fecha_actualizacion
--   WHERE LOCATE(_utf8mb4 X'E2949C', CONCAT_WS(' ', nombre, marca, categoria))
--      OR LOCATE(_utf8mb4 X'E294AC', CONCAT_WS(' ', nombre, marca, categoria));

-- Verificación:
--   1) La consulta de "¿ya está aplicada?" de arriba debe dar 0 en todas las casas.
--   2) Los ejemplos deben verse bien:
--        SELECT codigo_interno, nombre FROM productos_casa1
--         WHERE codigo_interno IN ('BNS01-00049', 'BNS01-01299', 'BNS01-03119');
--        -- ABRAZADERA L)UÑA TUBO  3/8" ..., CODO X1) HIDRAULICO 45° ..., ... HARVEY´S
--        SELECT codigo_interno, nombre FROM productos_casa2
--         WHERE codigo_interno IN ('BNS02-00041', 'BNS02-00330', 'BNS02-00073');
--        -- BROCA SDSM 1½X21 ..., BASCULA BAÑO ..., DISCO 4½ DIAM/...
