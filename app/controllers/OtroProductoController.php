<?php
/**
 * Producto capturado a mano desde Venta, para piezas que no estan en el catalogo
 * de ninguna casa. Se guarda en la casa "Otros" (que se crea sola la primera
 * vez) y se devuelve igual que un resultado del buscador, asi que la venta lo
 * trata como cualquier otro producto: VentaController relee el bruto de la base
 * y le suma el porcentaje de la casa.
 *
 *   GET  ?accion=info  -> porcentaje con el que se calcula el precio final.
 *   POST {nombre, precio_bruto} -> el producto listo para agregar a la venta.
 */
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Sesion expirada, vuelve a iniciar sesion']);
    exit;
}

require_once __DIR__ . '/../../config/conexionBD.php';
require_once __DIR__ . '/../helpers/casas.php';

try {
    $pdo = Database::getConnection();

    // ---------- Porcentaje para mostrar el precio final en el modal ----------
    if (($_GET['accion'] ?? '') === 'info') {
        $codigo = codigoCasaOtros();

        echo json_encode([
            'ok'         => true,
            'porcentaje' => $codigo !== null ? porcentajeCasa($codigo) : CASAS_PORCENTAJE_DEFECTO,
        ]);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'Metodo no permitido']);
        exit;
    }

    $datos = json_decode(file_get_contents('php://input'), true);

    if (!is_array($datos)) {
        throw new RuntimeException('No llegaron datos');
    }

    // Mismo limpiado que el alta de productos: sin tabuladores ni dobles espacios.
    $nombre = trim((string) ($datos['nombre'] ?? ''));
    $nombre = mb_substr(preg_replace('/\s+/u', ' ', $nombre) ?? $nombre, 0, 150);

    if (mb_strlen($nombre) < 2) {
        throw new RuntimeException('Escribe el nombre o la descripcion del producto');
    }

    $crudo = str_replace(['$', ',', ' '], '', (string) ($datos['precio_bruto'] ?? ''));

    if ($crudo === '' || !is_numeric($crudo)) {
        throw new RuntimeException('Escribe el precio antes del porcentaje');
    }

    $bruto = round((float) $crudo, 2);

    if ($bruto <= 0 || $bruto > 99999999.99) {
        throw new RuntimeException('El precio debe ser mayor a 0');
    }

    $codigoCasa = asegurarCasaOtros($pdo);
    $tabla      = tablaDeCasa($codigoCasa);

    if ($tabla === null) {
        throw new RuntimeException('No se pudo preparar la casa Otros');
    }

    // Si ya se habia capturado una pieza con el mismo nombre se reutiliza (con el
    // precio nuevo) en lugar de llenar Otros de duplicados.
    $stmt = $pdo->prepare(
        "SELECT codigo_interno, precio_mayoreo FROM `{$tabla}` WHERE nombre = :nombre ORDER BY id LIMIT 1"
    );
    $stmt->execute(['nombre' => $nombre]);
    $existente = $stmt->fetch(PDO::FETCH_ASSOC);

    $pdo->beginTransaction();

    if ($existente) {
        $codigoInterno = $existente['codigo_interno'];

        // El trigger de historial registra el cambio de precio con este usuario.
        $pdo->prepare('SET @usuario_actual = :id')->execute(['id' => (int) $_SESSION['user_id']]);
        $pdo->prepare(
            "UPDATE `{$tabla}` SET precio_mayoreo = :bruto, activo = 1 WHERE codigo_interno = :codigo"
        )->execute(['bruto' => $bruto, 'codigo' => $codigoInterno]);

    } else {
        $pdo->prepare(
            "INSERT INTO `{$tabla}` (codigo_proveedor, nombre, precio_mayoreo) VALUES ('', :nombre, :bruto)"
        )->execute(['nombre' => $nombre, 'bruto' => $bruto]);

        // Mismo formato de codigo que el resto del catalogo (BNS05-00001).
        $id            = (int) $pdo->lastInsertId();
        $codigoInterno = $codigoCasa . '-' . str_pad((string) $id, 5, '0', STR_PAD_LEFT);

        $pdo->prepare(
            "UPDATE `{$tabla}` SET codigo_interno = :codigo WHERE id = :id"
        )->execute(['codigo' => $codigoInterno, 'id' => $id]);
    }

    $pdo->commit();

    // Misma forma que un resultado de ProductoController.
    echo json_encode([
        'ok'       => true,
        'producto' => [
            'codigo_casa'      => $codigoCasa,
            'nombre_casa'      => etiquetaCasa($codigoCasa),
            'codigo_interno'   => $codigoInterno,
            'codigo_proveedor' => '',
            'nombre'           => $nombre,
            'marca'            => null,
            'precio_neto'      => netoDe($bruto, porcentajeCasa($codigoCasa)),
        ],
    ], JSON_UNESCAPED_UNICODE);

// PDOException hereda de RuntimeException: va primero para no mostrar el SQL.
} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log($e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'No se pudo guardar el producto']);

} catch (RuntimeException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
