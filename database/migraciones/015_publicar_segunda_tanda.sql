-- 015 · Publicar los 99 productos de la segunda tanda de fotos
--
-- Siete fotos más (extensión, manguera, placa, flexómetro, socket, piola y
-- regulador) alcanzaron para 99 productos que ya se venden y que estaban sin
-- imagen. Esto los prende en el catálogo, con el mismo criterio de la 014: si
-- tiene foto, se publica.
--
-- Solo toca la columna en_catalogo, y solo de estos 99 códigos. No agrega
-- columnas ni cambia la vista: eso ya lo hizo la 014, que debe estar aplicada
-- antes que esta.
--
-- CÓMO: phpMyAdmin -> base gabenet_prod -> pestaña SQL -> pegar -> Continuar.
--
-- Correrla dos veces no hace daño. Si alguno lo apagaste a mano, esto se lo
-- vuelve a prender.

-- BNS01 · 7 productos
UPDATE productos_casa1 SET en_catalogo = 1
 WHERE codigo_interno IN (
    'BNS01-03009', 'BNS01-03044', 'BNS01-03045', 'BNS01-04519', 'BNS01-04520',
    'BNS01-04521', 'BNS01-04530'
);

-- BNS03 · 38 productos
UPDATE productos_casa3 SET en_catalogo = 1
 WHERE codigo_interno IN (
    'BNS03-00288', 'BNS03-00595', 'BNS03-00596', 'BNS03-00603', 'BNS03-00604',
    'BNS03-00605', 'BNS03-00606', 'BNS03-00607', 'BNS03-00723', 'BNS03-00724',
    'BNS03-00725', 'BNS03-01370', 'BNS03-01371', 'BNS03-01372', 'BNS03-01376',
    'BNS03-01377', 'BNS03-01378', 'BNS03-01381', 'BNS03-02243', 'BNS03-04633',
    'BNS03-04635', 'BNS03-04636', 'BNS03-04637', 'BNS03-04638', 'BNS03-04639',
    'BNS03-04640', 'BNS03-05368', 'BNS03-05386', 'BNS03-07101', 'BNS03-07105',
    'BNS03-07107', 'BNS03-07108', 'BNS03-08156', 'BNS03-10239', 'BNS03-10368',
    'BNS03-10455', 'BNS03-11148', 'BNS03-11149'
);

-- BNS04 · 3 productos
UPDATE productos_casa4 SET en_catalogo = 1
 WHERE codigo_interno IN (
    'BNS04-03155', 'BNS04-03157', 'BNS04-03158'
);

-- BNS05 · 51 productos
UPDATE productos_casa5 SET en_catalogo = 1
 WHERE codigo_interno IN (
    'BNS05-00028', 'BNS05-00076', 'BNS05-00086', 'BNS05-00087', 'BNS05-00105',
    'BNS05-00117', 'BNS05-00166', 'BNS05-00167', 'BNS05-00168', 'BNS05-00172',
    'BNS05-00176', 'BNS05-00177', 'BNS05-00178', 'BNS05-00179', 'BNS05-00215',
    'BNS05-00220', 'BNS05-00250', 'BNS05-00251', 'BNS05-00252', 'BNS05-00253',
    'BNS05-00277', 'BNS05-00290', 'BNS05-00312', 'BNS05-00313', 'BNS05-00320',
    'BNS05-00326', 'BNS05-00336', 'BNS05-00337', 'BNS05-00347', 'BNS05-00348',
    'BNS05-00361', 'BNS05-00362', 'BNS05-00363', 'BNS05-00364', 'BNS05-00369',
    'BNS05-00370', 'BNS05-00382', 'BNS05-00383', 'BNS05-00384', 'BNS05-00385',
    'BNS05-00386', 'BNS05-00387', 'BNS05-00388', 'BNS05-00391', 'BNS05-00392',
    'BNS05-00393', 'BNS05-00413', 'BNS05-00414', 'BNS05-00415', 'BNS05-00425',
    'BNS05-00433'
);

-- Verificación: el total debe pasar de 612 a 711.
--
--   SELECT COUNT(*) FROM vista_catalogo
--    WHERE en_catalogo = 1 AND activo = 1 AND precio_mayoreo > 0;
--
-- Y al terminar, borra catalogo/cache/productos.json en el servidor.
