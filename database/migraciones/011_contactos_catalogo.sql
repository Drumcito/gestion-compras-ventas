-- 011 · Contactos del catálogo en línea (formulario del pie)
--
-- El catálogo público (carpeta catalogo/, subdominio) trae en el pie un
-- formulario para que un negocio o una persona deje su teléfono y/o correo y
-- reciba información (catálogo en PDF, listas de precios) por correo o WhatsApp.
--
-- Cada fila es un contacto con la CONSTANCIA de su consentimiento: qué versión
-- del aviso de privacidad aceptó, cuándo y desde dónde. Eso es lo que permite
-- demostrar, si alguien lo pregunta, que pidió recibir los mensajes.
--
--   * telefono / correo   al menos uno de los dos (lo valida el formulario).
--                         El teléfono se guarda como 10 dígitos.
--   * acepta_promociones  finalidad SECUNDARIA (ofertas y novedades). Es
--                         opcional: 0 = solo quiere lo que pidió.
--   * aviso_version       versión del aviso de privacidad vigente al aceptar
--                         (config/catalogo.php → legal.version).
--   * ip / user_agent     constancia del consentimiento y freno al abuso. El
--                         aviso de privacidad dice que se guardan.
--   * baja_en             fecha en que pidió no recibir más mensajes. Con fecha,
--                         no se le envía nada.
--
-- Si la misma persona vuelve a llenar el formulario (mismo teléfono o correo),
-- se actualiza su fila en lugar de duplicarla: así nunca recibe dos veces el
-- mismo envío.
--
-- ¿Ya está aplicada? Si esta consulta devuelve una fila, sí:
--
--   SELECT table_name FROM information_schema.tables
--    WHERE table_schema = DATABASE() AND table_name = 'catalogo_contactos';

CREATE TABLE IF NOT EXISTS catalogo_contactos (
  id                 INT          NOT NULL AUTO_INCREMENT,
  nombre             VARCHAR(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  telefono           VARCHAR(10)  COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  correo             VARCHAR(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  acepta_promociones TINYINT(1)   NOT NULL DEFAULT 0,
  aviso_version      VARCHAR(10)  COLLATE utf8mb4_unicode_ci NOT NULL,
  aceptado_en        DATETIME     NOT NULL,
  ip                 VARCHAR(45)  COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  user_agent         VARCHAR(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  baja_en            DATETIME     DEFAULT NULL,
  creado_en          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_cc_telefono (telefono),
  KEY idx_cc_correo (correo),
  KEY idx_cc_ip_fecha (ip, actualizado_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Verificación:
--   SHOW COLUMNS FROM catalogo_contactos;
