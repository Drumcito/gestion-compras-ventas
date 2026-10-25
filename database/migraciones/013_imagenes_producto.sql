-- 013 · Imágenes de producto subidas desde Inventario
--
-- Las imágenes NO se guardan en la base: siguen siendo archivos en
-- public/img/productos/ nombrados con el código interno (BNS01-00001.jpg), que
-- es lo que leen el catálogo PDF y el catálogo en línea. Así se entregan rápido
-- (directo del disco, con caché del navegador) y los respaldos de la base no
-- crecen. Desde Inventario se sube cada una ya cuadrada y en dos tamaños:
--
--   public/img/productos/BNS01-00001.jpg       800 × 800 (detalle y PDF)
--   public/img/productos/400/BNS01-00001.jpg   400 × 400 (tarjetas del catálogo)
--
-- Esta tabla solo guarda los DATOS de cada imagen subida desde el sistema:
-- quién y cuándo, medidas y peso. La existencia de la imagen la sigue diciendo
-- la carpeta, así que una imagen copiada a mano al servidor también funciona
-- (solo que sin fila aquí ni miniatura de 400).
--
--   * version     momento de la subida (segundos Unix). Va en la dirección de
--                 la imagen (img.php?c=...&v=...) para que el navegador la
--                 guarde un año y, al cambiarla, pida la nueva sola.
--   * peso_bytes  de la imagen grande.
--
-- ¿Ya está aplicada? Si esta consulta devuelve una fila, sí:
--
--   SELECT table_name FROM information_schema.tables
--    WHERE table_schema = DATABASE() AND table_name = 'producto_imagenes';

CREATE TABLE IF NOT EXISTS producto_imagenes (
  codigo_interno VARCHAR(20)  COLLATE utf8mb4_unicode_ci NOT NULL,
  version        INT UNSIGNED NOT NULL,
  ancho          SMALLINT UNSIGNED NOT NULL,
  alto           SMALLINT UNSIGNED NOT NULL,
  peso_bytes     INT UNSIGNED NOT NULL,
  subida_por     INT          DEFAULT NULL,
  subida_en      DATETIME     NOT NULL,
  PRIMARY KEY (codigo_interno),
  CONSTRAINT fk_pi_subida_por FOREIGN KEY (subida_por)
      REFERENCES usuarios (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Verificación:
--   SHOW COLUMNS FROM producto_imagenes;
