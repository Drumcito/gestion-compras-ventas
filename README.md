# Comercializadora GA-BE — Gestión de compras y ventas

Sistema web para administrar las **ventas, el inventario y los clientes** de la
Comercializadora GA-BE. Permite registrar ventas de contado y a crédito, llevar
el catálogo de productos agrupado por casa (proveedor), consultar el historial,
confirmar compras contra lo vendido, imprimir y enviar notas, y revisar
estadísticas y ganancias.

Está hecho con **PHP + MySQL** del lado del servidor y **HTML/CSS/JavaScript
sin framework** del lado del cliente. No requiere paso de compilación.

---

## Características

- **Ventas** (pantalla principal): búsqueda de productos en todas las casas,
  venta de contado o a crédito, saldo a favor del cliente aplicado como
  descuento, y alta rápida de un **producto nuevo** (que cae por defecto en la
  casa *Otros*, pudiendo elegir casa y porcentaje).
- **Clientes**: catálogo de comercios (nombre, dirección, CP, teléfono, RFC, día
  de visita, correo). El vendedor puede darlos de alta; el admin los gestiona.
- **Historial de ventas**: filtro por rango de fechas o búsqueda por cliente,
  detalle de cada venta, edición, abonos a crédito, borrado **recuperable**
  (10 min para deshacer), marca de entrega, y exportación a Excel.
- **Confirmar compra**: contra el ticket del proveedor se marcan los productos
  que no llegaron y el sistema ajusta las ventas afectadas.
- **Notas**: generación de la nota de venta en **PDF** e envío por **WhatsApp**
  o **correo**.
- **Inventario** (solo admin): alta/edición de productos, precios y porcentajes,
  creación de casas y borrado recuperable de productos.
- **Estadísticas y ganancias** (dashboard): resúmenes del periodo.
- **Usuarios y permisos**: dos roles (`admin` y `vendedor`), con cambio de
  contraseña obligatorio en el primer acceso.

---

## Stack tecnológico

| Capa | Tecnología |
|---|---|
| Servidor | PHP 8 (desarrollado en 8.2), PDO |
| Base de datos | MySQL 8 / MariaDB (`utf8mb4`) |
| Frontend | HTML, CSS y JavaScript vanilla (sin build) |
| PDF | [FPDF](http://www.fpdf.org/) (incluido en `lib/`) |
| Correo | [PHPMailer](https://github.com/PHPMailer/PHPMailer) (incluido en `lib/`) |
| Iconos | [Phosphor Icons](https://phosphoricons.com/) (vía CDN) |

> No se usa Composer: PHPMailer y FPDF vienen incluidos en `lib/`.

---

## Estructura del proyecto

```
GA-BE/
├── index.php                 Redirige al login
├── config/
│   ├── conexionBD.php         Conexión PDO (singleton)
│   ├── credenciales.*.php     Credenciales de la BD (fuera de Git)
│   └── smtp.*.php             Config de correo (fuera de Git)
├── app/
│   ├── controllers/           Lógica por módulo (Auth, Venta, Historial, …)
│   └── helpers/               Casas, clientes, PDF de la nota, Excel, correo
├── views/
│   ├── auth/                  Login y cambio de contraseña
│   ├── dashboard/             Pantalla de Venta
│   ├── estadisticas/          Dashboard de estadísticas
│   ├── historial/             Historial y resumen de productos
│   ├── inventario/            Inventario (solo admin)
│   ├── users/                 Usuarios / Clientes
│   ├── ventas/                Nota imprimible
│   └── partials/              Menú y assets compartidos
├── public/
│   ├── css/style.css          Estilos
│   ├── js/                    Un archivo por pantalla (venta.js, historial.js, …)
│   ├── img/                   Logos
│   └── notas/                 PDFs generados al vuelo (fuera de Git)
├── lib/                       FPDF y PHPMailer (dependencias incluidas)
├── database/
│   ├── migraciones/           Cambios de estructura, numerados (ver su README)
│   ├── migrations/            Volcado base del esquema (no versionado)
│   └── seeds/                 Datos de ejemplo (no versionado)
└── .cpanel.yml                Tarea de despliegue en cPanel
```

---

## Requisitos

- **PHP 8.0 o superior** con las extensiones `pdo_mysql` y `mbstring`.
- **MySQL 8** o **MariaDB**.
- Un servidor web con PHP, p. ej. **XAMPP** (Apache) en local.

---

## Instalación en local (XAMPP)

1. **Coloca el proyecto** dentro de `htdocs`, de modo que quede en
   `C:\xampp\htdocs\GA-BE` (o clónalo ahí):

   ```bash
   git clone <url-del-repo> GA-BE
   ```

2. **Crea el archivo de credenciales** de la base de datos en
   `config/credenciales.local.php` (este archivo está en `.gitignore` y nunca se
   sube). Debe devolver un arreglo con tus datos:

   ```php
   <?php
   return [
       'host'    => 'localhost',
       'db'      => 'gabe_system',
       'user'    => 'root',
       'pass'    => '',
       'charset' => 'utf8mb4',
   ];
   ```

3. **Prepara la base de datos**:

   - Crea la base `gabe_system` (p. ej. desde phpMyAdmin o MySQL Workbench).
   - Importa el **esquema base** desde
     `database/migrations/gabe_system_hosting.sql`.
     > Este volcado no está versionado (vive en local y en el hosting). Si partes
     > de una copia del servidor, úsalo tal cual; en un clon nuevo, pídelo a quien
     > mantiene la base.
   - Aplica las **migraciones pendientes** de `database/migraciones/`, en orden,
     una sola vez cada una. Cada archivo trae arriba una consulta para saber si ya
     está aplicada. (Ver [`database/migraciones/README.md`](database/migraciones/README.md).)

4. **(Opcional) Configura el correo** para enviar notas por email: copia
   `config/smtp.example.php` como `config/smtp.local.php` y pon tus datos SMTP
   (para Gmail/Outlook necesitas una *contraseña de aplicación*). Sin esto, el
   envío por WhatsApp y la generación de PDF siguen funcionando.

5. **Abre la aplicación** en el navegador:

   ```
   http://localhost/GA-BE/
   ```

   El `index.php` te redirige al login.

---

## Acceso y roles

- Se entra con **número de empleado** y **contraseña**.
- Hay dos roles:
  - **admin**: acceso completo, incluido Inventario y la gestión de usuarios y
    clientes.
  - **vendedor**: ventas, historial de lo suyo, y alta de clientes.
- En el **primer acceso** (usuario nuevo o con contraseña reiniciada), el sistema
  obliga a **cambiar la contraseña** antes de entrar.

> Los usuarios se administran desde el módulo *Usuarios* (solo admin). El primer
> usuario administrador se crea al preparar la base de datos.

---

## Base de datos y migraciones

El esquema se mantiene con migraciones numeradas en
[`database/migraciones/`](database/migraciones/). Reglas:

- Cada archivo se corre **una sola vez por base** (local y producción), para que
  ambas queden iguales.
- En local se ejecutan desde MySQL Workbench; en producción, desde
  **cPanel → phpMyAdmin → SQL**.
- Antes de correr una, revisa la consulta de verificación que trae al inicio: si
  ya está aplicada, no la repitas.

Conceptos clave del modelo:

- **Casas**: cada proveedor es una "casa" con su propia tabla de productos
  (`productos_casaN`) y un **porcentaje** con el que el precio **bruto** se
  convierte en **neto** de venta. Un producto puede tener su propio porcentaje.
- **Casa *Otros***: se crea sola la primera vez; ahí caen los productos
  capturados a mano durante una venta.
- **Ventas**: de contado o crédito; guardan una copia del precio cobrado, admiten
  abonos, entrega y **borrado recuperable** (10 min).

---

## Notas en PDF y correo

- La nota de venta se genera en PDF con FPDF y queda en `public/notas/` (carpeta
  no versionada, se limpia sola).
- Los enlaces públicos del PDF se **firman** con `notas_secret` (definido en la
  config SMTP) para que no se puedan adivinar.
- El envío por correo usa PHPMailer con los datos de `config/smtp.local.php` /
  `config/smtp.prod.php`.

---

## Despliegue

El despliegue a producción se hace vía **cPanel** con la tarea de
[`.cpanel.yml`](.cpanel.yml), que copia el proyecto a
`public_html/GABE/` en el hosting.

Notas importantes:

- Se usa `cp -R` (no `rsync --delete`): un archivo **eliminado del repo no se
  borra** solo del servidor; hay que quitarlo a mano. A cambio, los archivos que
  solo existen en el hosting (como `config/credenciales.prod.php` y
  `config/smtp.prod.php`) nunca corren peligro.
- Las **credenciales reales** y la **config de correo** viven solo en el servidor
  (`*.prod.php`) y nunca se versionan.
- Las migraciones nuevas deben aplicarse **a mano** en la base de producción.

---

## Convenciones

- Un archivo JavaScript por pantalla en `public/js/` (p. ej. `venta.js`,
  `historial.js`), cargado desde su vista.
- Los controladores en `app/controllers/` responden JSON y validan permisos en el
  servidor (no basta con ocultar un botón en la interfaz).
- Código y comentarios en **español**.

---

## Privado

Proyecto interno de la Comercializadora GA-BE. Todos los derechos reservados.
