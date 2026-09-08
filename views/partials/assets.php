<?php
/**
 * Devuelve la ruta de un archivo de public/ con un número de versión pegado:
 *
 *     ../../public/css/style.css?v=1757353012
 *
 * La versión es la fecha de modificación del archivo, así que cambia sola cada
 * vez que se edita. El navegador lo trata como una URL distinta y descarga la
 * versión nueva; mientras el archivo no cambie, sigue usando su caché.
 *
 * Sin esto, después de cada deploy los navegadores siguen mostrando el CSS y el
 * JS viejos hasta que el usuario limpia la caché a mano.
 */
function recurso(string $rutaEnPublic): string
{
    $rutaEnPublic = ltrim($rutaEnPublic, '/');
    $absoluta = __DIR__ . '/../../public/' . $rutaEnPublic;

    $version = is_file($absoluta) ? filemtime($absoluta) : time();

    return '../../public/' . $rutaEnPublic . '?v=' . $version;
}
