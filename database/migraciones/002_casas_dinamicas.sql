-- 002 · Casas dinamicas (crear casas desde Inventario)
--
-- Hasta ahora, que una casa existiera dependia de tres cosas escritas a mano en
-- el codigo: la etiqueta visible, el orden en pantalla y a que tabla de
-- productos corresponde. Ese mapa estaba repetido en 6 controladores, asi que no
-- se podia crear una casa sin editar PHP. Estas tres columnas lo mueven a la
-- base, y el codigo las lee de aqui.
--
-- ¿Ya esta aplicada? Si esta consulta devuelve 3, si:
--
--   SELECT COUNT(*) FROM information_schema.columns
--    WHERE table_schema = DATABASE() AND table_name = 'casas'
--      AND column_name IN ('etiqueta', 'orden', 'tabla_productos');

ALTER TABLE casas
  ADD COLUMN etiqueta        VARCHAR(30)       NOT NULL DEFAULT '' AFTER nombre,
  ADD COLUMN orden           SMALLINT UNSIGNED NOT NULL DEFAULT 99  AFTER etiqueta,
  ADD COLUMN tabla_productos VARCHAR(64)       NOT NULL DEFAULT '' AFTER orden;

-- Las 4 casas que ya existen, con la etiqueta y el orden que se acordaron.
-- El codigo interno si va parejo con la tabla (BNS03 -> productos_casa3), pero
-- NO con el numero de la etiqueta: las tablas se numeraron en el orden en que
-- llegaron los archivos de Excel y las etiquetas se decidieron despues, asi que
-- BNS03 es la casa 4 en pantalla. De ahi que la tabla tenga que quedar escrita
-- aqui en lugar de deducirse del codigo.
UPDATE casas SET etiqueta = 'C1-MC',        orden = 1, tabla_productos = 'productos_casa2' WHERE codigo_casa = 'BNS02';
UPDATE casas SET etiqueta = 'C2-DP',        orden = 2, tabla_productos = 'productos_casa1' WHERE codigo_casa = 'BNS01';
UPDATE casas SET etiqueta = 'C3-CHOLULITA', orden = 3, tabla_productos = 'productos_casa4' WHERE codigo_casa = 'BNS04';
UPDATE casas SET etiqueta = 'C4-JD',        orden = 4, tabla_productos = 'productos_casa3' WHERE codigo_casa = 'BNS03';

-- Verificacion: deben salir las 4 con etiqueta y tabla llenas.
-- SELECT codigo_casa, nombre, etiqueta, orden, tabla_productos FROM casas ORDER BY orden;
