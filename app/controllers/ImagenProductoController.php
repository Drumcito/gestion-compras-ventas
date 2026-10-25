<?php
/**
 * Imagenes de producto desde Inventario (solo admin).
 *
 * Las imagenes son ARCHIVOS, no van en la base (ver migracion 013):
 *   public/img/productos/CODIGO.jpg       800 x 800 (detalle del catalogo y PDF)
 *   public/img/productos/400/CODIGO.jpg   400 x 400 (tarjetas del catalogo)
 *
 * El navegador ya las manda cuadradas, con fondo blanco y en los dos tamaños
 * (asi no depende de que el servidor tenga GD y se sube poco peso). Aqui solo
 * se revisa que de verdad sean JPG de esas medidas y se guardan.
 *
 * "codigo" puede ser el interno (BNS03-07092) o el del proveedor (IMP641), que
 * es el que se ve en Inventario y con el que suelen venir nombradas las fotos;
 * el del proveedor solo vale si lo tiene un solo producto activo.
 *
 *   GET  accion=info&codigo=     Si tiene imagen, su version y quien la subio.
 *   POST accion=subir            codigo + archivos "grande" y "chica".
 *   POST accion=quitar           codigo: borra los dos archivos.
 *
 * Al subir o quitar se borra la lista del catalogo en linea guardada en cache,
 * para que el cambio se vea de inmediato y no hasta que venza.
 */

session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Sesion expirada']);
    exit;
}

if (($_SESSION['user_role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Solo un administrador puede cambiar imágenes']);
    exit;
}

require_once __DIR__ . '/../../config/conexionBD.php';
require_once __DIR__ . '/../helpers/catalogo_ofertas.php';   // configCatalogo(), imagenProducto(), casas.php

// Medidas y peso maximo de cada archivo (el navegador los manda muy por debajo).
const MEDIDAS_IMAGEN = ['grande' => 800, 'chica' => 400];
const PESO_MAXIMO    = ['grande' => 900 * 1024, 'chica' => 400 * 1024];

function responder(int $codigo, array $datos): void
{
    http_response_code($codigo);
    echo json_encode($datos, JSON_UNESCAPED_UNICODE);
    exit;
}

/** Nombre y si existe el producto (en cualquier casa, activo o no). */
function productoPorCodigo(PDO $pdo, string $codigo): ?array
{
    $tabla = tablaDeProducto($codigo);
    if ($tabla === null) {
        return null;
    }
    $stmt = $pdo->prepare("SELECT codigo_interno, nombre FROM {$tabla} WHERE codigo_interno = :c LIMIT 1");
    $stmt->execute(['c' => $codigo]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Producto activo por codigo del proveedor. Si dos casas usan el mismo codigo no
 * se adivina cual: se pide el interno.
 */
function productoPorCodigoProveedor(PDO $pdo, string $codigo): ?array
{
    $stmt = $pdo->prepare(
        'SELECT codigo_interno, nombre FROM vista_catalogo WHERE codigo_proveedor = :c AND activo = 1 LIMIT 2'
    );
    $stmt->execute(['c' => $codigo]);
    $filas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (count($filas) > 1) {
        responder(409, ['ok' => false, 'error' => "Varios productos tienen el código {$codigo}: usa el código interno."]);
    }
    return $filas[0] ?? null;
}

/**
 * Borra los archivos del producto: la grande en cualquier extension (una
 * copiada a mano pudo ser .png o .jpeg) y la miniatura.
 */
function borrarArchivosImagen(string $codigo): void
{
    $dir = configCatalogo()['dir_imagenes'];
    foreach (is_dir($dir) ? scandir($dir) : [] as $archivo) {
        if (preg_match('/^([A-Za-z0-9_-]+)\.(jpe?g|png)$/i', $archivo, $m) && strtoupper($m[1]) === $codigo) {
            @unlink($dir . '/' . $archivo);
        }
    }
    @unlink($dir . '/400/' . $codigo . '.jpg');
}

/** La lista del catalogo en linea se rehace sola en la siguiente visita. */
function refrescarCatalogoEnLinea(): void
{
    @unlink(__DIR__ . '/../../catalogo/cache/productos.json');
}

try {
    $pdo = Database::getConnection();

    $accion = $_SERVER['REQUEST_METHOD'] === 'POST' ? ($_POST['accion'] ?? '') : ($_GET['accion'] ?? 'info');
    $pedido = trim((string) ($_POST['codigo'] ?? $_GET['codigo'] ?? ''));

    if (!preg_match('/^[A-Za-z0-9 .\/_-]{1,50}$/', $pedido)) {
        responder(400, ['ok' => false, 'error' => 'Código no válido']);
    }

    $codigo   = strtoupper($pedido);
    $producto = (strlen($codigo) <= 20 ? productoPorCodigo($pdo, $codigo) : null)
             ?? productoPorCodigoProveedor($pdo, $pedido);
    if ($producto === null) {
        responder(404, ['ok' => false, 'error' => "No existe un producto con el código {$codigo}"]);
    }
    $codigo = strtoupper($producto['codigo_interno']);

    // ---------- Info ----------
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        $stmt = $pdo->prepare(
            'SELECT i.ancho, i.alto, i.peso_bytes, i.subida_en,
                    CONCAT(u.nombre, " ", COALESCE(u.apellido, "")) AS subida_por
               FROM producto_imagenes i
          LEFT JOIN usuarios u ON u.id = i.subida_por
              WHERE i.codigo_interno = :c'
        );
        $stmt->execute(['c' => $codigo]);

        $version = versionImagen($codigo);
        responder(200, [
            'ok'        => true,
            'codigo'    => $codigo,
            'nombre'    => $producto['nombre'],
            'version'   => $version,
            'miniatura' => $version > 0 && miniaturaProducto($codigo) !== null,
            // Sin fila: la imagen se copio a mano al servidor.
            'datos'     => $version > 0 ? ($stmt->fetch(PDO::FETCH_ASSOC) ?: null) : null,
        ]);
    }

    // ---------- Quitar ----------
    if ($accion === 'quitar') {
        borrarArchivosImagen($codigo);
        $pdo->prepare('DELETE FROM producto_imagenes WHERE codigo_interno = :c')->execute(['c' => $codigo]);
        refrescarCatalogoEnLinea();
        responder(200, ['ok' => true, 'codigo' => $codigo, 'nombre' => $producto['nombre'], 'version' => 0]);
    }

    if ($accion !== 'subir') {
        responder(400, ['ok' => false, 'error' => 'Acción no válida']);
    }

    // ---------- Subir ----------
    foreach (MEDIDAS_IMAGEN as $campo => $lado) {
        $archivo = $_FILES[$campo] ?? null;

        if (!$archivo || $archivo['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($archivo['tmp_name'])) {
            responder(400, ['ok' => false, 'error' => 'No llegó la imagen completa. Intenta de nuevo.']);
        }
        if ($archivo['size'] > PESO_MAXIMO[$campo]) {
            responder(400, ['ok' => false, 'error' => 'La imagen pesa demasiado.']);
        }

        // getimagesize lee el archivo de verdad: un .jpg que no es imagen no pasa.
        $info = @getimagesize($archivo['tmp_name']);
        if (!$info || $info[2] !== IMAGETYPE_JPEG || $info[0] !== $lado || $info[1] !== $lado) {
            responder(400, ['ok' => false, 'error' => "La imagen debe ser JPG de {$lado} × {$lado} px."]);
        }
    }

    $dir = configCatalogo()['dir_imagenes'];
    if (!is_dir($dir . '/400') && !mkdir($dir . '/400', 0755, true)) {
        throw new RuntimeException('No se pudo crear la carpeta de miniaturas');
    }

    // Se mueven primero a un nombre temporal y luego se renombran: nadie ve una
    // imagen a medio escribir. Antes se quitan las de otra extension (.png).
    $temporalGrande = $dir . '/.' . $codigo . '.' . getmypid() . '.tmp';
    $temporalChica  = $dir . '/400/.' . $codigo . '.' . getmypid() . '.tmp';

    if (!move_uploaded_file($_FILES['grande']['tmp_name'], $temporalGrande)
        || !move_uploaded_file($_FILES['chica']['tmp_name'], $temporalChica)) {
        @unlink($temporalGrande);
        throw new RuntimeException('No se pudo guardar la imagen en el servidor');
    }

    borrarArchivosImagen($codigo);
    rename($temporalGrande, $dir . '/' . $codigo . '.jpg');
    rename($temporalChica, $dir . '/400/' . $codigo . '.jpg');
    @chmod($dir . '/' . $codigo . '.jpg', 0644);
    @chmod($dir . '/400/' . $codigo . '.jpg', 0644);

    clearstatcache();
    $version = (int) filemtime($dir . '/' . $codigo . '.jpg');

    $pdo->prepare(
        'INSERT INTO producto_imagenes (codigo_interno, version, ancho, alto, peso_bytes, subida_por, subida_en)
         VALUES (:c, :v, 800, 800, :peso, :usuario, NOW())
         ON DUPLICATE KEY UPDATE version = VALUES(version), peso_bytes = VALUES(peso_bytes),
                                 subida_por = VALUES(subida_por), subida_en = VALUES(subida_en)'
    )->execute([
        'c'       => $codigo,
        'v'       => $version,
        'peso'    => (int) filesize($dir . '/' . $codigo . '.jpg'),
        'usuario' => (int) $_SESSION['user_id'],
    ]);

    refrescarCatalogoEnLinea();
    responder(200, ['ok' => true, 'codigo' => $codigo, 'nombre' => $producto['nombre'], 'version' => $version]);

} catch (RuntimeException $e) {
    error_log('Imagen de producto: ' . $e->getMessage());
    responder(500, ['ok' => false, 'error' => $e->getMessage()]);
} catch (PDOException $e) {
    error_log('Imagen de producto: ' . $e->getMessage());
    responder(500, ['ok' => false, 'error' => 'No se pudo guardar la imagen']);
}
