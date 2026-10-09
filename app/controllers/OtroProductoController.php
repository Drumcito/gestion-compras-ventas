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

    // ---------- Casas y porcentajes para el modal ----------
    // Devuelve el desplegable de casas (activas) con su porcentaje por defecto y
    // cual es la casa "Otros", que queda seleccionada de entrada.
    if (($_GET['accion'] ?? '') === 'info') {
        // Se asegura de que "Otros" exista para poder ofrecerla por defecto
        // (se crea sola la primera vez).
        $codigoOtros = asegurarCasaOtros($pdo);

        $casas = [];
        foreach (casasRegistradas() as $codigo => $casa) {
            if ($casa['activo']) {
                $casas[] = [
                    'codigo_casa' => $codigo,
                    'etiqueta'    => $casa['etiqueta'],
                    'orden'       => $casa['orden'],
                    'porcentaje'  => $casa['porcentaje_neto'],
                ];
            }
        }

        // Mismo orden que en las demas pantallas.
        usort($casas, fn($a, $b) => $a['orden'] <=> $b['orden']);

        echo json_encode([
            'ok'         => true,
            'casas'      => $casas,
            'otros'      => $codigoOtros,
            'porcentaje' => porcentajeCasa($codigoOtros),
        ], JSON_UNESCAPED_UNICODE);
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

    // Casa destino: por defecto "Otros", pero el usuario puede elegir otra del
    // desplegable. Se valida que sea una casa activa y con tabla; cualquier otra
    // cosa cae en Otros, para no dejar la pieza sin casa.
    $casas        = casasRegistradas();
    $codigoPedido = strtoupper(trim((string) ($datos['codigo_casa'] ?? '')));

    if ($codigoPedido !== ''
        && isset($casas[$codigoPedido])
        && $casas[$codigoPedido]['activo']
        && tablaDeCasa($codigoPedido) !== null) {
        $codigoCasa = $codigoPedido;
    } else {
        $codigoCasa = asegurarCasaOtros($pdo);
    }

    $tabla = tablaDeCasa($codigoCasa);

    if ($tabla === null) {
        throw new RuntimeException('No se pudo preparar la casa destino');
    }

    // Porcentaje elegido. Vacio = el de la casa. Si coincide con el de la casa se
    // deja atado a ella (NULL); si es distinto, se guarda como porcentaje propio
    // del producto (columna porcentaje_neto), igual que el editor de %.
    $pctCasa  = porcentajeCasa($codigoCasa);
    $crudoPct = str_replace(['%', ' '], '', (string) ($datos['porcentaje'] ?? ''));

    if ($crudoPct === '') {
        $porcentajeGuardar = null;
    } elseif (!is_numeric($crudoPct)) {
        throw new RuntimeException('El porcentaje no es un numero valido');
    } else {
        $pct = round((float) $crudoPct, 2);

        if ($pct < 0 || $pct > 999.99) {
            throw new RuntimeException('El porcentaje debe estar entre 0 y 999.99');
        }

        $porcentajeGuardar = abs($pct - $pctCasa) < 0.005 ? null : $pct;
    }

    // Si ya se habia capturado una pieza con el mismo nombre en esta casa se
    // reutiliza (con el precio y porcentaje nuevos) en lugar de duplicarla.
    $stmt = $pdo->prepare(
        "SELECT codigo_interno FROM `{$tabla}` WHERE nombre = :nombre ORDER BY id LIMIT 1"
    );
    $stmt->execute(['nombre' => $nombre]);
    $existente = $stmt->fetch(PDO::FETCH_ASSOC);

    // Porcentaje efectivo con el que se calcula el precio mostrado y cobrado.
    $porcentaje = porcentajeProducto($porcentajeGuardar, $codigoCasa);

    $pdo->beginTransaction();

    if ($existente) {
        $codigoInterno = $existente['codigo_interno'];

        // El trigger de historial registra el cambio de precio con este usuario.
        $pdo->prepare('SET @usuario_actual = :id')->execute(['id' => (int) $_SESSION['user_id']]);
        // Si ese producto se habia borrado desde Inventario, al volver a darlo de
        // alta aqui se limpia el sello del borrado: ya no tiene nada que
        // recuperar, y de otro modo seguiria contando en la lista de
        // recuperables aun estando activo.
        $pdo->prepare(
            "UPDATE `{$tabla}`
                SET precio_mayoreo = :bruto, porcentaje_neto = :pct, activo = 1,
                    eliminado_en = NULL, eliminado_por = NULL
              WHERE codigo_interno = :codigo"
        )->execute(['bruto' => $bruto, 'pct' => $porcentajeGuardar, 'codigo' => $codigoInterno]);

    } else {
        $pdo->prepare(
            "INSERT INTO `{$tabla}` (codigo_proveedor, nombre, precio_mayoreo, porcentaje_neto)
             VALUES ('', :nombre, :bruto, :pct)"
        )->execute(['nombre' => $nombre, 'bruto' => $bruto, 'pct' => $porcentajeGuardar]);

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
            'precio_neto'      => netoDe($bruto, $porcentaje),
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
