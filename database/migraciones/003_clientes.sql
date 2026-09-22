-- 003 · Catalogo de clientes (alta de clientes desde el modulo de Usuarios)
--
-- Hasta ahora "cliente" era solo un texto libre que se escribia al momento de la
-- venta (ventas.cliente). Esta tabla es un catalogo aparte: los comercios a los
-- que se visita, con sus datos de contacto y el dia en que toca visitarlos.
--
-- El dia de visita es SOLO de referencia: no se valida ni condiciona nada, por
-- eso es un texto libre y no un ENUM ni una llave a otra tabla.
--
-- ¿Ya esta aplicada? Si esta consulta devuelve una fila, si:
--
--   SELECT table_name FROM information_schema.tables
--    WHERE table_schema = DATABASE() AND table_name = 'clientes';

CREATE TABLE `clientes` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `nombres`          VARCHAR(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `apellido_paterno` VARCHAR(60)  COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `apellido_materno` VARCHAR(60)  COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nombre_comercio`  VARCHAR(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `direccion`        VARCHAR(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  -- Enlace de Google Maps pegado a mano (el "pin" exacto del comercio). Es
  -- opcional: si esta vacio, la pantalla arma un enlace de busqueda con la
  -- direccion y el CP.
  `maps_url`         VARCHAR(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `codigo_postal`    VARCHAR(10)  COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `telefono`         VARCHAR(20)  COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `rfc`              VARCHAR(13)  COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  -- Dia de visita, solo de referencia (Lunes..Sabado o vacio). Texto libre a
  -- proposito: no se valida por dia.
  `dia_visita`       VARCHAR(10)  COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `activo`           TINYINT(1)   NOT NULL DEFAULT 1,
  `creado_en`        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `actualizado_en`   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_clientes_comercio` (`nombre_comercio`),
  KEY `idx_clientes_dia` (`dia_visita`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Verificacion: debe listar las columnas de arriba.
-- DESCRIBE clientes;
