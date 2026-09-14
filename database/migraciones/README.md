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
