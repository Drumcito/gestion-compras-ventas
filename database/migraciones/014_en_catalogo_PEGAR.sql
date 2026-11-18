-- ===========================================================================
-- 014 · Para pegar en phpMyAdmin (versión plana, sin procedimiento)
-- ===========================================================================
--
-- Es la migración 014 sin el bloque DELIMITER $$: hace exactamente lo mismo,
-- pero escrito a mano para las 5 casas que existen hoy. Se usa esta porque
-- phpMyAdmin se atora con DELIMITER y muchos hostings no dan permiso
-- CREATE ROUTINE.
--
-- CÓMO: phpMyAdmin -> base gabenet_prod -> pestaña SQL -> pegar TODO ->
--       Continuar. Tarda unos segundos.
--
-- SI SALE "Duplicate column name 'en_catalogo'": ya estaba aplicada. Borra
-- los 5 ALTER del paso 1 y corre solo los pasos 2 y 3.
--
-- NO borra ni modifica ningún dato: solo agrega una columna que nace en 0 y
-- pone en 1 los 612 productos que ya tienen foto.
-- ===========================================================================


-- ---------------------------------------------------------------------------
-- PASO 1 · La columna, una línea por casa
-- ---------------------------------------------------------------------------
-- en_catalogo = 0 (de fábrica): el producto NO sale en el sitio.
-- El índice es para el sitio, que pide "los publicados": 612 de 28 mil.

ALTER TABLE productos_casa1
  ADD COLUMN en_catalogo TINYINT(1) NOT NULL DEFAULT 0 AFTER activo,
  ADD KEY idx_productos_casa1_en_catalogo (en_catalogo);

ALTER TABLE productos_casa2
  ADD COLUMN en_catalogo TINYINT(1) NOT NULL DEFAULT 0 AFTER activo,
  ADD KEY idx_productos_casa2_en_catalogo (en_catalogo);

ALTER TABLE productos_casa3
  ADD COLUMN en_catalogo TINYINT(1) NOT NULL DEFAULT 0 AFTER activo,
  ADD KEY idx_productos_casa3_en_catalogo (en_catalogo);

ALTER TABLE productos_casa4
  ADD COLUMN en_catalogo TINYINT(1) NOT NULL DEFAULT 0 AFTER activo,
  ADD KEY idx_productos_casa4_en_catalogo (en_catalogo);

ALTER TABLE productos_casa5
  ADD COLUMN en_catalogo TINYINT(1) NOT NULL DEFAULT 0 AFTER activo,
  ADD KEY idx_productos_casa5_en_catalogo (en_catalogo);


-- ---------------------------------------------------------------------------
-- PASO 2 · La vista, que es de donde lee el sitio
-- ---------------------------------------------------------------------------
-- Sin esto el catálogo truena con "Unknown column 'v.en_catalogo'".
-- Va sin DEFINER a propósito: `root`@`localhost` no existe en el hosting.

CREATE OR REPLACE SQL SECURITY INVOKER VIEW vista_catalogo AS
SELECT 'BNS01' AS codigo_casa, 'DIPLOMEX' AS nombre_casa, codigo_interno, codigo_proveedor,
       nombre, marca, categoria, precio_mayoreo, porcentaje_neto, activo, en_catalogo
  FROM productos_casa1
UNION ALL
SELECT 'BNS02', 'Marcus', codigo_interno, codigo_proveedor,
       nombre, marca, categoria, precio_mayoreo, porcentaje_neto, activo, en_catalogo
  FROM productos_casa2
UNION ALL
SELECT 'BNS03', 'La Jaladera', codigo_interno, codigo_proveedor,
       nombre, marca, categoria, precio_mayoreo, porcentaje_neto, activo, en_catalogo
  FROM productos_casa3
UNION ALL
SELECT 'BNS04', 'Ferra Cholulita', codigo_interno, codigo_proveedor,
       nombre, marca, categoria, precio_mayoreo, porcentaje_neto, activo, en_catalogo
  FROM productos_casa4
UNION ALL
SELECT 'BNS05', 'Otros', codigo_interno, codigo_proveedor,
       nombre, marca, categoria, precio_mayoreo, porcentaje_neto, activo, en_catalogo
  FROM productos_casa5;


-- ---------------------------------------------------------------------------
-- PASO 3 · Prender los 612 que ya tienen foto
-- ---------------------------------------------------------------------------
-- Son los códigos con archivo en public/img/productos/. Van escritos a mano y
-- no sacados de la base a propósito: la foto es un archivo, no un dato, y así
-- esta migración deja el mismo resultado en tu máquina y en el servidor.
--
-- Cada tabla lleva solo los códigos de SU casa, que es lo que dice el prefijo.
--
-- Correr esto dos veces no hace daño: vuelve a poner en 1 lo que ya está en 1.
-- Lo que sí hay que saber: si para entonces ya apagaste alguno a mano, esto se
-- lo vuelve a prender.

-- BNS01 · 26 productos
UPDATE productos_casa1 SET en_catalogo = 1
 WHERE codigo_interno IN (
    'BNS01-00728', 'BNS01-00729', 'BNS01-01085', 'BNS01-01894', 'BNS01-02404',
    'BNS01-02405', 'BNS01-02559', 'BNS01-02567', 'BNS01-02583', 'BNS01-03768',
    'BNS01-03775', 'BNS01-03802', 'BNS01-03840', 'BNS01-03841', 'BNS01-03842',
    'BNS01-03844', 'BNS01-03846', 'BNS01-04615', 'BNS01-04728', 'BNS01-04734',
    'BNS01-04736', 'BNS01-04737', 'BNS01-04772', 'BNS01-04774', 'BNS01-05260',
    'BNS01-05270'
);

-- BNS02 · 38 productos
UPDATE productos_casa2 SET en_catalogo = 1
 WHERE codigo_interno IN (
    'BNS02-00070', 'BNS02-00098', 'BNS02-00099', 'BNS02-00279', 'BNS02-00280',
    'BNS02-00281', 'BNS02-01116', 'BNS02-01145', 'BNS02-01153', 'BNS02-01178',
    'BNS02-01185', 'BNS02-01200', 'BNS02-01204', 'BNS02-01789', 'BNS02-01945',
    'BNS02-01948', 'BNS02-01954', 'BNS02-01955', 'BNS02-01956', 'BNS02-01959',
    'BNS02-01963', 'BNS02-02113', 'BNS02-02199', 'BNS02-02200', 'BNS02-02366',
    'BNS02-02367', 'BNS02-03539', 'BNS02-03653', 'BNS02-03657', 'BNS02-03659',
    'BNS02-03712', 'BNS02-03713', 'BNS02-04882', 'BNS02-04948', 'BNS02-04967',
    'BNS02-04982', 'BNS02-05409', 'BNS02-05415'
);

-- BNS03 · 336 productos
UPDATE productos_casa3 SET en_catalogo = 1
 WHERE codigo_interno IN (
    'BNS03-00047', 'BNS03-00137', 'BNS03-00138', 'BNS03-00179', 'BNS03-00192',
    'BNS03-00422', 'BNS03-00423', 'BNS03-00424', 'BNS03-00486', 'BNS03-00525',
    'BNS03-00531', 'BNS03-00532', 'BNS03-00547', 'BNS03-00692', 'BNS03-00695',
    'BNS03-00709', 'BNS03-00836', 'BNS03-00875', 'BNS03-00883', 'BNS03-00887',
    'BNS03-00890', 'BNS03-01114', 'BNS03-01115', 'BNS03-01194', 'BNS03-01341',
    'BNS03-01344', 'BNS03-01347', 'BNS03-01609', 'BNS03-01616', 'BNS03-01635',
    'BNS03-01917', 'BNS03-02153', 'BNS03-02154', 'BNS03-02157', 'BNS03-02165',
    'BNS03-02301', 'BNS03-02302', 'BNS03-02303', 'BNS03-02304', 'BNS03-02305',
    'BNS03-02306', 'BNS03-02307', 'BNS03-02308', 'BNS03-02309', 'BNS03-02321',
    'BNS03-02421', 'BNS03-02423', 'BNS03-02507', 'BNS03-02508', 'BNS03-02509',
    'BNS03-02510', 'BNS03-02546', 'BNS03-02805', 'BNS03-02806', 'BNS03-02809',
    'BNS03-02810', 'BNS03-02811', 'BNS03-02839', 'BNS03-02844', 'BNS03-02846',
    'BNS03-02848', 'BNS03-02850', 'BNS03-02883', 'BNS03-02886', 'BNS03-02888',
    'BNS03-02889', 'BNS03-02892', 'BNS03-02904', 'BNS03-03043', 'BNS03-03044',
    'BNS03-03045', 'BNS03-03055', 'BNS03-03057', 'BNS03-03058', 'BNS03-03062',
    'BNS03-03066', 'BNS03-03072', 'BNS03-03152', 'BNS03-03153', 'BNS03-03185',
    'BNS03-03186', 'BNS03-03187', 'BNS03-03189', 'BNS03-03190', 'BNS03-03197',
    'BNS03-03200', 'BNS03-03203', 'BNS03-03261', 'BNS03-03274', 'BNS03-03281',
    'BNS03-03283', 'BNS03-03284', 'BNS03-03424', 'BNS03-03426', 'BNS03-03427',
    'BNS03-03432', 'BNS03-03459', 'BNS03-03460', 'BNS03-03649', 'BNS03-03650',
    'BNS03-03652', 'BNS03-03653', 'BNS03-03655', 'BNS03-03657', 'BNS03-03662',
    'BNS03-03663', 'BNS03-03673', 'BNS03-03753', 'BNS03-03755', 'BNS03-03759',
    'BNS03-03760', 'BNS03-03761', 'BNS03-03765', 'BNS03-03766', 'BNS03-03783',
    'BNS03-03784', 'BNS03-03851', 'BNS03-03864', 'BNS03-03876', 'BNS03-03877',
    'BNS03-03878', 'BNS03-03890', 'BNS03-03909', 'BNS03-03910', 'BNS03-04038',
    'BNS03-04039', 'BNS03-04040', 'BNS03-04041', 'BNS03-04042', 'BNS03-04043',
    'BNS03-04056', 'BNS03-04057', 'BNS03-04189', 'BNS03-04239', 'BNS03-04240',
    'BNS03-04241', 'BNS03-04242', 'BNS03-04243', 'BNS03-04244', 'BNS03-04245',
    'BNS03-04246', 'BNS03-04247', 'BNS03-04280', 'BNS03-04707', 'BNS03-04709',
    'BNS03-04713', 'BNS03-04797', 'BNS03-04798', 'BNS03-04801', 'BNS03-04808',
    'BNS03-04821', 'BNS03-04824', 'BNS03-04842', 'BNS03-04843', 'BNS03-04855',
    'BNS03-04873', 'BNS03-05072', 'BNS03-05099', 'BNS03-05112', 'BNS03-05155',
    'BNS03-05157', 'BNS03-05174', 'BNS03-05184', 'BNS03-05185', 'BNS03-05237',
    'BNS03-05286', 'BNS03-05327', 'BNS03-05351', 'BNS03-05364', 'BNS03-05365',
    'BNS03-05366', 'BNS03-05393', 'BNS03-05462', 'BNS03-05541', 'BNS03-05945',
    'BNS03-05948', 'BNS03-05949', 'BNS03-05950', 'BNS03-05952', 'BNS03-06048',
    'BNS03-06107', 'BNS03-06108', 'BNS03-06112', 'BNS03-06113', 'BNS03-06147',
    'BNS03-06240', 'BNS03-06404', 'BNS03-06405', 'BNS03-06484', 'BNS03-06487',
    'BNS03-06489', 'BNS03-06491', 'BNS03-06832', 'BNS03-06833', 'BNS03-06834',
    'BNS03-06835', 'BNS03-06836', 'BNS03-06837', 'BNS03-06838', 'BNS03-06888',
    'BNS03-06889', 'BNS03-06890', 'BNS03-06911', 'BNS03-06912', 'BNS03-06928',
    'BNS03-06930', 'BNS03-06932', 'BNS03-06934', 'BNS03-07109', 'BNS03-07185',
    'BNS03-07186', 'BNS03-07482', 'BNS03-07483', 'BNS03-07486', 'BNS03-07493',
    'BNS03-07494', 'BNS03-07495', 'BNS03-07507', 'BNS03-07512', 'BNS03-07513',
    'BNS03-07514', 'BNS03-07522', 'BNS03-07563', 'BNS03-07652', 'BNS03-07664',
    'BNS03-08032', 'BNS03-08034', 'BNS03-08240', 'BNS03-08800', 'BNS03-09082',
    'BNS03-09098', 'BNS03-09115', 'BNS03-09116', 'BNS03-09177', 'BNS03-09178',
    'BNS03-09180', 'BNS03-09188', 'BNS03-09190', 'BNS03-09191', 'BNS03-09204',
    'BNS03-09236', 'BNS03-09237', 'BNS03-09241', 'BNS03-09259', 'BNS03-09260',
    'BNS03-09261', 'BNS03-09262', 'BNS03-09263', 'BNS03-09264', 'BNS03-09265',
    'BNS03-09266', 'BNS03-09425', 'BNS03-09426', 'BNS03-09427', 'BNS03-09428',
    'BNS03-09438', 'BNS03-09440', 'BNS03-09552', 'BNS03-09553', 'BNS03-09554',
    'BNS03-09555', 'BNS03-09557', 'BNS03-09558', 'BNS03-09564', 'BNS03-09565',
    'BNS03-09566', 'BNS03-09567', 'BNS03-09568', 'BNS03-09571', 'BNS03-09573',
    'BNS03-10111', 'BNS03-10245', 'BNS03-10329', 'BNS03-10332', 'BNS03-10337',
    'BNS03-10338', 'BNS03-10339', 'BNS03-10340', 'BNS03-10341', 'BNS03-10414',
    'BNS03-10415', 'BNS03-10417', 'BNS03-10420', 'BNS03-10421', 'BNS03-10423',
    'BNS03-10424', 'BNS03-10425', 'BNS03-10447', 'BNS03-10473', 'BNS03-10479',
    'BNS03-10516', 'BNS03-10517', 'BNS03-10518', 'BNS03-10523', 'BNS03-10524',
    'BNS03-10527', 'BNS03-10785', 'BNS03-10786', 'BNS03-10787', 'BNS03-10788',
    'BNS03-10790', 'BNS03-10793', 'BNS03-10796', 'BNS03-10798', 'BNS03-10806',
    'BNS03-10872', 'BNS03-11347', 'BNS03-11640', 'BNS03-11801', 'BNS03-11889',
    'BNS03-11894', 'BNS03-11895', 'BNS03-11897', 'BNS03-11899', 'BNS03-11900',
    'BNS03-11901', 'BNS03-11902', 'BNS03-11903', 'BNS03-11905', 'BNS03-11907',
    'BNS03-11908', 'BNS03-11917', 'BNS03-11919', 'BNS03-11921', 'BNS03-11922',
    'BNS03-12195', 'BNS03-12244', 'BNS03-12396', 'BNS03-12399', 'BNS03-12400',
    'BNS03-12493', 'BNS03-12494', 'BNS03-12496', 'BNS03-12497', 'BNS03-12498',
    'BNS03-12499'
);

-- BNS04 · 18 productos
UPDATE productos_casa4 SET en_catalogo = 1
 WHERE codigo_interno IN (
    'BNS04-00217', 'BNS04-00349', 'BNS04-00923', 'BNS04-01258', 'BNS04-01259',
    'BNS04-01404', 'BNS04-01405', 'BNS04-01406', 'BNS04-01727', 'BNS04-01743',
    'BNS04-01744', 'BNS04-01993', 'BNS04-02205', 'BNS04-02832', 'BNS04-02887',
    'BNS04-03350', 'BNS04-03523', 'BNS04-03727'
);

-- BNS05 · 194 productos
UPDATE productos_casa5 SET en_catalogo = 1
 WHERE codigo_interno IN (
    'BNS05-00004', 'BNS05-00005', 'BNS05-00006', 'BNS05-00009', 'BNS05-00011',
    'BNS05-00012', 'BNS05-00013', 'BNS05-00014', 'BNS05-00018', 'BNS05-00020',
    'BNS05-00021', 'BNS05-00025', 'BNS05-00026', 'BNS05-00027', 'BNS05-00029',
    'BNS05-00030', 'BNS05-00031', 'BNS05-00035', 'BNS05-00036', 'BNS05-00037',
    'BNS05-00038', 'BNS05-00040', 'BNS05-00043', 'BNS05-00044', 'BNS05-00045',
    'BNS05-00052', 'BNS05-00053', 'BNS05-00054', 'BNS05-00055', 'BNS05-00056',
    'BNS05-00057', 'BNS05-00058', 'BNS05-00059', 'BNS05-00060', 'BNS05-00061',
    'BNS05-00067', 'BNS05-00068', 'BNS05-00069', 'BNS05-00071', 'BNS05-00072',
    'BNS05-00078', 'BNS05-00080', 'BNS05-00081', 'BNS05-00082', 'BNS05-00083',
    'BNS05-00084', 'BNS05-00085', 'BNS05-00089', 'BNS05-00090', 'BNS05-00091',
    'BNS05-00096', 'BNS05-00099', 'BNS05-00102', 'BNS05-00110', 'BNS05-00111',
    'BNS05-00112', 'BNS05-00115', 'BNS05-00116', 'BNS05-00118', 'BNS05-00120',
    'BNS05-00121', 'BNS05-00122', 'BNS05-00123', 'BNS05-00125', 'BNS05-00127',
    'BNS05-00130', 'BNS05-00131', 'BNS05-00132', 'BNS05-00134', 'BNS05-00136',
    'BNS05-00137', 'BNS05-00139', 'BNS05-00141', 'BNS05-00144', 'BNS05-00145',
    'BNS05-00147', 'BNS05-00148', 'BNS05-00149', 'BNS05-00151', 'BNS05-00155',
    'BNS05-00162', 'BNS05-00163', 'BNS05-00169', 'BNS05-00170', 'BNS05-00171',
    'BNS05-00173', 'BNS05-00180', 'BNS05-00182', 'BNS05-00183', 'BNS05-00185',
    'BNS05-00186', 'BNS05-00189', 'BNS05-00194', 'BNS05-00199', 'BNS05-00200',
    'BNS05-00201', 'BNS05-00202', 'BNS05-00203', 'BNS05-00205', 'BNS05-00206',
    'BNS05-00207', 'BNS05-00208', 'BNS05-00209', 'BNS05-00210', 'BNS05-00212',
    'BNS05-00213', 'BNS05-00214', 'BNS05-00218', 'BNS05-00221', 'BNS05-00222',
    'BNS05-00223', 'BNS05-00225', 'BNS05-00226', 'BNS05-00228', 'BNS05-00229',
    'BNS05-00230', 'BNS05-00232', 'BNS05-00233', 'BNS05-00234', 'BNS05-00235',
    'BNS05-00236', 'BNS05-00237', 'BNS05-00239', 'BNS05-00244', 'BNS05-00246',
    'BNS05-00249', 'BNS05-00259', 'BNS05-00260', 'BNS05-00261', 'BNS05-00262',
    'BNS05-00266', 'BNS05-00267', 'BNS05-00269', 'BNS05-00270', 'BNS05-00271',
    'BNS05-00272', 'BNS05-00273', 'BNS05-00274', 'BNS05-00275', 'BNS05-00276',
    'BNS05-00279', 'BNS05-00284', 'BNS05-00285', 'BNS05-00286', 'BNS05-00287',
    'BNS05-00289', 'BNS05-00316', 'BNS05-00317', 'BNS05-00318', 'BNS05-00322',
    'BNS05-00327', 'BNS05-00328', 'BNS05-00329', 'BNS05-00330', 'BNS05-00331',
    'BNS05-00332', 'BNS05-00333', 'BNS05-00334', 'BNS05-00340', 'BNS05-00341',
    'BNS05-00345', 'BNS05-00349', 'BNS05-00351', 'BNS05-00352', 'BNS05-00354',
    'BNS05-00355', 'BNS05-00365', 'BNS05-00366', 'BNS05-00367', 'BNS05-00368',
    'BNS05-00372', 'BNS05-00374', 'BNS05-00375', 'BNS05-00376', 'BNS05-00379',
    'BNS05-00380', 'BNS05-00389', 'BNS05-00395', 'BNS05-00399', 'BNS05-00401',
    'BNS05-00403', 'BNS05-00406', 'BNS05-00416', 'BNS05-00422', 'BNS05-00423',
    'BNS05-00424', 'BNS05-00426', 'BNS05-00427', 'BNS05-00428', 'BNS05-00429',
    'BNS05-00430', 'BNS05-00431', 'BNS05-00432', 'BNS05-00434'
);


-- ---------------------------------------------------------------------------
-- PASO 4 · Comprobar (opcional, pero recomendado)
-- ---------------------------------------------------------------------------
-- Debe dar 612 en el renglón del total:
--
--   SELECT codigo_casa, COUNT(*) AS publicados
--     FROM vista_catalogo
--    WHERE en_catalogo = 1 AND activo = 1 AND precio_mayoreo > 0
--    GROUP BY codigo_casa WITH ROLLUP;
--
-- Repartidos así:  BNS01 26 · BNS02 38 · BNS03 336 · BNS04 18 · BNS05 194
--
-- Y que la columna nazca apagada:
--
--   SHOW COLUMNS FROM productos_casa1 LIKE 'en_catalogo';
--   -> Default debe decir 0
