<?php
/**
 * Punto de entrada de la carpeta del proyecto: manda directo al login.
 *
 * Vive en el repositorio a proposito. El despliegue usa "rsync --delete", que
 * borra del servidor todo lo que no este versionado; cuando este archivo solo
 * existia en el hosting, el primer deploy lo habria eliminado y la direccion
 * https://ga-be.net/GABE/ se habria quedado sin indice.
 */

header('Location: views/auth/login.php');
exit;
