<?php
/**
 * Porcentaje del neto de UN producto.
 *
 * El neto (lo que se cobra) se calcula como bruto + un porcentaje. Ese
 * porcentaje normalmente es el de la casa (todas sus piezas usan el mismo), pero
 * aquí el administrador puede fijarle a un producto SU PROPIO porcentaje, sin
 * afectar a los demás de la casa. Se guarda en la columna porcentaje_neto de la
 * tabla del producto:
 *
 *   NULL       -> el producto usa el porcentaje de su casa (lo normal).
 *   0..999.99  -> el producto usa ese porcentaje, ignorando el de la casa.
 *
 * El mismo botón "Editar %" se abre desde Inventario, desde el historial de
 * ventas y durante la venta: los tres llaman a este controlador.
 *
 *   GET  ?accion=consultar&codigo=BNS03-01270
 *        -> datos para armar el formulario (bruto, % de la casa, % del producto,
 *           % efectivo y neto actual).
 *   POST {codigo, porcentaje, precio_mayoreo?}
 *        -> guarda el porcentaje del producto. porcentaje null / "" / "casa"
 *           vuelve a usar el de la casa. Si ademas viene precio_mayoreo, se
 *           guarda el bruto nuevo: los dos cambios van juntos, en una sola
 *           transaccion, porque entre ambos sale el precio que se cobra.
 *           El cambio de bruto queda en historial_precios como cualquier otro.
 */
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Sesion expirada']);
    exit;
}

// Cambiar el porcentaje mueve el precio de venta del producto para todas las
// ventas futuras, así que queda reservado al administrador. La barrera vive aquí
// y no solo en el botón: ocultarlo en pantalla no impide llamar al controlador.
if (($_SESSION['user_role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Solo un administrador puede cambiar el porcentaje']);
    exit;
}

require_once __DIR__ . '/../../config/conexionBD.php';
require_once __DIR__ . '/../helpers/casas.php';

$usuarioId = (int) $_SESSION['user_id'];

/** El código interno dice de qué casa es el producto (BNS03-01270). */
function tablaDe(string $codigoInterno): string
{
    $tabla = tablaDeProducto($codigoInterno);

    if ($tabla === null) {
        throw new RuntimeException('Codigo de producto no valido');
    }

    return $tabla;
}

/** Casa (BNS03) a partir del código interno del producto. */
function casaDe(string $codigoInterno): string
{
    $guion = strpos($codigoInterno, '-');

    if ($guion === false) {
        throw new RuntimeException('Codigo de producto no valido');
    }

    return strtoupper(substr($codigoInterno, 0, $guion));
}

/**
 * Lee el porcentaje escrito a mano. Vacío, null o "casa" significan "usar el de
 * la casa" (se guarda NULL). Un número se valida entre 0 y 999.99.
 *
 * @return float|null null = volver al porcentaje de la casa.
 */
function aPorcentajeProducto($valor): ?float
{
    if ($valor === null || $valor === '' || $valor === 'casa') {
        return null;
    }

    // Acepta "17", "17.5" o "17,5" (coma decimal).
    if (is_string($valor)) {
        $valor = str_replace(['%', ' '], '', $valor);
        if (strpos($valor, ',') !== false && strpos($valor, '.') === false) {
            $valor = str_replace(',', '.', $valor);
        }
    }

    if (!is_numeric($valor)) {
        throw new RuntimeException('El porcentaje debe ser un numero');
    }

    $numero = round((float) $valor, 2);

    if ($numero < 0 || $numero > 999.99) {
        throw new RuntimeException('El porcentaje debe estar entre 0 y 999.99');
    }

    return $numero;
}

/**
 * Lee el precio bruto escrito a mano. Null o vacio = no se toca el precio.
 *
 * @return float|null null = dejar el bruto como esta.
 */
function aPrecioBruto($valor): ?float
{
    if ($valor === null || $valor === '') {
        return null;
    }

    if (is_string($valor)) {
        $valor = str_replace(['$', ' ', ','], ['', '', '.'], $valor);
    }

    if (!is_numeric($valor)) {
        throw new RuntimeException('El precio debe ser un numero');
    }

    $numero = round((float) $valor, 2);

    if ($numero < 0) {
        throw new RuntimeException('El precio no puede ser negativo');
    }
    if ($numero > 99999999.99) {
        throw new RuntimeException('El precio es demasiado alto');
    }

    return $numero;
}

try {
    $pdo = Database::getConnection();

    // ---------- Datos para armar el formulario ----------
    if (($_GET['accion'] ?? '') === 'consultar') {
        $codigo = (string) ($_GET['codigo'] ?? '');
        $tabla  = tablaDe($codigo);
        $casa   = casaDe($codigo);

        $stmt = $pdo->prepare(
            "SELECT codigo_interno, codigo_proveedor, nombre, marca,
                    precio_mayoreo, porcentaje_neto
               FROM `{$tabla}` WHERE codigo_interno = :codigo AND activo = 1 LIMIT 1"
        );
        $stmt->execute(['codigo' => $codigo]);
        $producto = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$producto) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'El producto ya no esta en el inventario']);
            exit;
        }

        $override    = $producto['porcentaje_neto'];
        $porcCasa    = porcentajeCasa($casa);
        $porcEfectivo = porcentajeProducto($override, $casa);

        echo json_encode([
            'ok' => true,
            'producto' => [
                'codigo_interno'      => $producto['codigo_interno'],
                'codigo_proveedor'    => $producto['codigo_proveedor'],
                'nombre'              => $producto['nombre'],
                'marca'               => $producto['marca'],
                'precio_mayoreo'      => $producto['precio_mayoreo'],
                'porcentaje_casa'     => $porcCasa,
                'porcentaje_override' => $override === null ? null : (float) $override,
                'porcentaje_efectivo' => $porcEfectivo,
                'usa_casa'            => $override === null,
                'etiqueta_casa'       => etiquetaCasa($casa),
                'neto_actual'         => netoDe($producto['precio_mayoreo'], $porcEfectivo),
            ],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ---------- Guardar el porcentaje ----------
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'Metodo no permitido']);
        exit;
    }

    $datos = json_decode(file_get_contents('php://input'), true);

    if (!is_array($datos)) {
        throw new RuntimeException('No llegaron datos');
    }

    $codigo = (string) ($datos['codigo'] ?? '');
    $tabla  = tablaDe($codigo);
    $casa   = casaDe($codigo);

    // El campo puede no venir en el cuerpo; se distingue "no vino" de "vino
    // vacío" (vacío = volver al % de la casa).
    $nuevo = aPorcentajeProducto(array_key_exists('porcentaje', $datos) ? $datos['porcentaje'] : null);

    // El bruto es opcional: si no viene, el precio del producto no se toca.
    $brutoNuevo = aPrecioBruto(array_key_exists('precio_mayoreo', $datos) ? $datos['precio_mayoreo'] : null);

    $stmt = $pdo->prepare(
        "SELECT nombre, precio_mayoreo, porcentaje_neto FROM `{$tabla}`
          WHERE codigo_interno = :codigo AND activo = 1 LIMIT 1"
    );
    $stmt->execute(['codigo' => $codigo]);
    $antes = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$antes) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'El producto ya no esta en el inventario']);
        exit;
    }

    $override_antes = $antes['porcentaje_neto'] === null ? null : (float) $antes['porcentaje_neto'];
    $brutoAntes     = $antes['precio_mayoreo'] === null ? null : (float) $antes['precio_mayoreo'];

    $cambiaPorcentaje = $override_antes !== $nuevo;
    $cambiaPrecio     = $brutoNuevo !== null && $brutoNuevo !== $brutoAntes;

    $porcCasa     = porcentajeCasa($casa);
    $porcEfectivo = porcentajeProducto($nuevo, $casa);
    $brutoFinal   = $cambiaPrecio ? $brutoNuevo : $brutoAntes;

    // Nada que guardar: ni el precio ni el porcentaje se movieron.
    if (!$cambiaPorcentaje && !$cambiaPrecio) {
        echo json_encode([
            'ok'                  => true,
            'cambios'             => 0,
            'nombre'              => $antes['nombre'],
            'porcentaje_casa'     => $porcCasa,
            'porcentaje_override' => $nuevo,
            'porcentaje_efectivo' => $porcEfectivo,
            'usa_casa'            => $nuevo === null,
            'precio_mayoreo'      => $brutoAntes,
            'neto'                => netoDe($brutoAntes, $porcEfectivo),
            'mensaje'             => 'El precio y el porcentaje quedaron igual, no hubo nada que guardar',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Los dos van juntos: el precio que se cobra sale de ambos, así que no se
    // puede quedar a medias uno sin el otro.
    $pdo->beginTransaction();

    try {
        if ($cambiaPrecio) {
            // El trigger de la tabla escribe el renglon en historial_precios;
            // esta variable de sesion es la que le dice quien hizo el cambio.
            $pdo->prepare('SET @usuario_actual = :id')->execute(['id' => $usuarioId]);

            $pdo->prepare(
                "UPDATE `{$tabla}` SET precio_mayoreo = :bruto WHERE codigo_interno = :codigo"
            )->execute(['bruto' => $brutoNuevo, 'codigo' => $codigo]);
        }

        if ($cambiaPorcentaje) {
            $pdo->prepare(
                "UPDATE `{$tabla}` SET porcentaje_neto = :porcentaje WHERE codigo_interno = :codigo"
            )->execute(['porcentaje' => $nuevo, 'codigo' => $codigo]);
        }

        $pdo->commit();

    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    echo json_encode([
        'ok'                  => true,
        'cambios'             => 1,
        'cambio_precio'       => $cambiaPrecio,
        'cambio_porcentaje'   => $cambiaPorcentaje,
        'nombre'              => $antes['nombre'],
        'porcentaje_casa'     => $porcCasa,
        'porcentaje_anterior' => $override_antes === null ? $porcCasa : $override_antes,
        'porcentaje_override' => $nuevo,
        'porcentaje_efectivo' => $porcEfectivo,
        'usa_casa'            => $nuevo === null,
        'precio_anterior'     => $brutoAntes,
        'precio_mayoreo'      => $brutoFinal,
        'neto'                => netoDe($brutoFinal, $porcEfectivo),
    ], JSON_UNESCAPED_UNICODE);

} catch (RuntimeException $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    error_log($e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'No se pudo guardar el porcentaje']);
}
