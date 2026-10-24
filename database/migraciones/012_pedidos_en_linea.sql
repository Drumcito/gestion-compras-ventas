-- 012 · Pedidos en línea (carrito del catálogo público)
--
-- El catálogo en línea (carpeta catalogo/) tiene un carrito sin cuenta: el
-- cliente junta productos y los manda en un solo mensaje de WhatsApp. Al
-- enviarlo, el pedido también se guarda aquí con el FOLIO que lleva el mensaje,
-- para que en el Historial de Ventas aparezca resaltado y se pueda cargar en la
-- pantalla de Venta (sin volver a capturar producto por producto), cobrarlo y
-- sacar la nota como cualquier venta.
--
--   * folio          6 caracteres (sin 0/O ni 1/I/L) que se genera en el
--                    navegador y va en el mensaje de WhatsApp. Es UNICO: si el
--                    cliente vuelve a mandar el mismo carrito, no se duplica.
--   * nombre         "Tu nombre o negocio" del carrito (opcional).
--   * total          lo que el cliente vio en el catálogo (precio neto, IVA
--                    incluido). Al cargarlo en venta se cobra el precio del día.
--   * venta_id       la venta en que se convirtió. NULL = pendiente. Si esa
--                    venta se elimina, el pedido vuelve a quedar pendiente.
--   * descartado_en  spam, pedido repetido o que el cliente canceló: deja de
--                    salir como pendiente, sin borrarlo.
--   * aviso_version / ip / user_agent   constancia y freno al abuso (máximo 10
--                    pedidos por hora por IP). El aviso de privacidad lo dice.
--
-- Los productos van en pedidos_linea_detalle con el nombre y precio de ese
-- momento (si después cambian, el pedido sigue diciendo lo que el cliente vio).
--
-- ¿Ya está aplicada? Si esta consulta devuelve 2, sí:
--
--   SELECT COUNT(*) FROM information_schema.tables
--    WHERE table_schema = DATABASE()
--      AND table_name IN ('pedidos_linea', 'pedidos_linea_detalle');

CREATE TABLE IF NOT EXISTS pedidos_linea (
  id             INT           NOT NULL AUTO_INCREMENT,
  folio          VARCHAR(10)   COLLATE utf8mb4_unicode_ci NOT NULL,
  nombre         VARCHAR(80)   COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  total          DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  venta_id       INT           DEFAULT NULL,
  atendido_por   INT           DEFAULT NULL,
  atendido_en    DATETIME      DEFAULT NULL,
  descartado_en  DATETIME      DEFAULT NULL,
  descartado_por INT           DEFAULT NULL,
  aviso_version  VARCHAR(10)   COLLATE utf8mb4_unicode_ci NOT NULL,
  ip             VARCHAR(45)   COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  user_agent     VARCHAR(255)  COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  creado_en      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_pl_folio (folio),
  KEY idx_pl_venta (venta_id),
  KEY idx_pl_creado (creado_en),
  KEY idx_pl_ip_creado (ip, creado_en),
  CONSTRAINT fk_pl_venta FOREIGN KEY (venta_id)
      REFERENCES ventas (id) ON DELETE SET NULL,
  CONSTRAINT fk_pl_atendido_por FOREIGN KEY (atendido_por)
      REFERENCES usuarios (id) ON DELETE SET NULL,
  CONSTRAINT fk_pl_descartado_por FOREIGN KEY (descartado_por)
      REFERENCES usuarios (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pedidos_linea_detalle (
  id             INT           NOT NULL AUTO_INCREMENT,
  pedido_id      INT           NOT NULL,
  codigo_interno VARCHAR(20)   COLLATE utf8mb4_unicode_ci NOT NULL,
  nombre         VARCHAR(150)  COLLATE utf8mb4_unicode_ci NOT NULL,
  precio         DECIMAL(10,2) NOT NULL,
  cantidad       INT           NOT NULL,
  PRIMARY KEY (id),
  KEY idx_pld_pedido (pedido_id),
  CONSTRAINT fk_pld_pedido FOREIGN KEY (pedido_id)
      REFERENCES pedidos_linea (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Verificación:
--   SHOW COLUMNS FROM pedidos_linea;
--   SHOW COLUMNS FROM pedidos_linea_detalle;
