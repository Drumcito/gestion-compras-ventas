# Migraciones

Cambios de estructura de la base de datos, en orden. Cada archivo se corre
**una sola vez** por base (local y producción) y se deja aquí para que las dos
terminen iguales.

Cómo aplicarlas:

- En local: pégalas en MySQL Workbench y ejecuta.
- En producción: cPanel → phpMyAdmin → pestaña SQL → pegar → Continuar.

Antes de correr una, cada archivo trae arriba una consulta de verificación para
saber si ya está aplicada. Si ya lo está, no la vuelvas a correr.

| Archivo | Qué hace |
|---|---|
| `001_debe_cambiar_password.sql` | Columna que faltó del commit de contraseñas (03ab242). Sin ella el login truena. |
| `002_casas_dinamicas.sql` | Deja que las casas se creen desde Inventario: etiqueta, orden y tabla de productos dejan de estar escritos en el código. |
| `003_clientes.sql` | Tabla `clientes`: catálogo de comercios que se visitan (nombre, comercio, dirección, CP, teléfono, RFC, día de visita). Alta desde el módulo de Usuarios. |
| `004_precio_neto.sql` | Se elimina el precio de menudeo. El mayoreo pasa a ser el precio **bruto** y cada casa guarda un **porcentaje** (`casas.porcentaje_neto`, default 13% y 12% para C4) con el que se calcula el **neto** de venta. Antes de borrar menudeo, ese precio se pasa a bruto donde el bruto estaba vacío (la C4 sólo tenía menudeo). Recrea los triggers de precio. |
| `005_saldo_favor_cliente.sql` | `ventas.cliente_id` (liga la venta al cliente del catálogo) y `ventas.credito_aplicado` (saldo a favor usado como descuento). Actualiza el trigger de abonos y `vista_estado_ventas` para contar el crédito aplicado como pagado. El saldo a favor por cliente se calcula (no se guarda). |
| `006_cliente_email.sql` | `clientes.email`, para poder enviar la nota por correo. |
| `007_entrega_y_borrado_ventas.sql` | `ventas.entregada_en` (fecha de entrega, la marca la palomita del historial), `ventas.eliminada_en` y `ventas.eliminada_por` (borrado suave: la venta se desactiva y hay 10 min para recuperarla; luego el historial la purga de verdad). Recrea `vista_estado_ventas` con `eliminada_en` para dejar fuera las desactivadas. |
| `008_porcentaje_por_producto.sql` | Columna `porcentaje_neto` en cada tabla de productos (`productos_casaN`): porcentaje del neto **por producto**. NULL = usa el de su casa (como antes); un número = ese producto usa ese %, sin afectar a los demás de la casa. Editable desde Inventario, el historial de ventas y durante la venta (solo admin). Recrea `vista_catalogo` con la columna. |
| `009_borrado_recuperable_productos.sql` | Columnas `eliminado_en` y `eliminado_por` en cada tabla de productos (`productos_casaN`): al borrar un producto desde Inventario queda sellado con la fecha y el usuario, y hay **10 minutos para recuperarlo** desde la misma pantalla. A diferencia de una venta, pasado el plazo el producto **no** se borra de verdad (las ventas guardan su código y el historial de precios depende de la fila): solo deja de poder recuperarse. Un producto que quedó inactivo por haberse **movido de casa** no lleva sello, así que no aparece como recuperable. |
