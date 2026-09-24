<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Sesion expirada']);
    exit;
}

// Editar un producto (nombre, codigo, precio) o moverlo de casa cambia el
// catalogo que ve todo el mundo, asi que queda reservado al administrador. La
// barrera vive aqui y no solo en el boton: ocultarlo en pantalla no impide que
// alguien llame al controlador directo.
if (($_SESSION['user_role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Solo un administrador puede editar productos']);
    exit;
}

require_once __DIR__ . '/../../config/conexionBD.php';
require_once __DIR__ . '/../helpers/casas.php';

$usuarioId = (int) $_SESSION['user_id'];

// ---------------------------------------------------------------- utilidades

/** Limpia un texto pegado o escrito a mano: sin espacios dobles ni bordes. */
function limpiar($valor, int $maximo): string
{
    $limpio = trim((string) $valor);
    $limpio = preg_replace('/\s+/u', ' ', $limpio) ?? $limpio;

    return mb_substr($limpio, 0, $maximo);
}

/**
 * Lee un precio escrito a mano o pegado de Excel: "$1,234.50", "1234,50".
 * Devuelve null cuando el campo viene vacio.
 */
function precio($valor): ?float
{
    if ($valor === null) {
        return null;
    }

    $crudo = trim((string) $valor);

    if ($crudo === '' || $crudo === '-' || $crudo === '—') {
        return null;
    }

    $crudo = str_replace(['$', ' ', "\xc2\xa0", 'MXN', 'mxn'], '', $crudo);

    $tieneComa  = strpos($crudo, ',') !== false;
    $tienePunto = strpos($crudo, '.') !== false;

    if ($tieneComa && $tienePunto) {
        $crudo = str_replace(',', '', $crudo);       // "1,234.50": coma de miles
    } elseif ($tieneComa) {
        $crudo = str_replace(',', '.', $crudo);       // "1234,50": coma decimal
    }

    if (!is_numeric($crudo)) {
        throw new RuntimeException('El precio debe ser un numero');
    }

    $numero = (float) $crudo;

    if ($numero < 0) {
        throw new RuntimeException('El precio no puede ser negativo');
    }
    if ($numero > 99999999.99) {
        throw new RuntimeException('El precio es demasiado alto');
    }

    return round($numero, 2);
}

/** Lista de casas activas a las que se puede mover un producto. */
function casasParaMover(): array
{
    $lista = [];

    foreach (casasRegistradas() as $codigo => $casa) {
        if ($casa['activo'] !== 1 || tablaDeCasa($codigo) === null) {
            continue;
        }

        $lista[] = [
            'codigo_casa'     => $codigo,
            'etiqueta'        => $casa['etiqueta'],
            'nombre'          => $casa['nombre'],
            'porcentaje_neto' => $casa['porcentaje_neto'],
        ];
    }

    usort($lista, fn ($a, $b) => ordenCasa($a['codigo_casa']) <=> ordenCasa($b['codigo_casa']));

    return $lista;
}

// ------------------------------------------------------------------ acciones

try {
    $pdo = Database::getConnection();

    // ---------- Datos para armar el formulario de edicion ----------
    if (($_GET['accion'] ?? '') === 'consultar') {
        $codigo = (string) ($_GET['codigo'] ?? '');
        $tabla  = tablaDeProducto($codigo);

        if ($tabla === null) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Codigo de producto no valido']);
            exit;
        }

        $stmt = $pdo->prepare(
            "SELECT codigo_interno, codigo_proveedor, nombre, marca, categoria, precio_mayoreo
               FROM `{$tabla}` WHERE codigo_interno = :codigo AND activo = 1 LIMIT 1"
        );
        $stmt->execute(['codigo' => $codigo]);
        $producto = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$producto) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'El producto ya no esta en el inventario']);
            exit;
        }

        // La casa del producto sale del prefijo de su codigo (BNS03-01270).
        $casaActual = strtoupper(substr($codigo, 0, (int) strpos($codigo, '-')));

        echo json_encode([
            'ok'          => true,
            'producto'    => $producto,
            'casa_actual' => $casaActual,
            'casas'       => casasParaMover(),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ---------- Guardar los cambios ----------
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'Metodo no permitido']);
        exit;
    }

    $datos = json_decode(file_get_contents('php://input'), true);

    if (!is_array($datos)) {
        throw new RuntimeException('No llegaron datos');
    }

    $codigo      = (string) ($datos['codigo'] ?? '');
    $casaDestino = (string) ($datos['casa_destino'] ?? '');

    $tablaOrigen  = tablaDeProducto($codigo);
    $tablaDestino = tablaDeCasa($casaDestino);

    if ($tablaOrigen === null) {
        throw new RuntimeException('Codigo de producto no valido');
    }
    if ($tablaDestino === null) {
        throw new RuntimeException('La casa destino no es valida');
    }

    // Campos ya limpios; se validan igual que en el alta de productos.
    $nombre = limpiar($datos['nombre'] ?? '', 150);

    if (mb_strlen($nombre) < 1) {
        throw new RuntimeException('Escribe el nombre del producto');
    }

    $nuevoPrecio = precio($datos['precio_mayoreo'] ?? null);

    if ($nuevoPrecio === null) {
        throw new RuntimeException('Escribe el precio bruto');
    }

    $campos = [
        'codigo_proveedor' => limpiar($datos['codigo_proveedor'] ?? '', 50),
        'nombre'           => $nombre,
        'marca'            => limpiar($datos['marca'] ?? '', 60) ?: null,
        'categoria'        => limpiar($datos['categoria'] ?? '', 60) ?: null,
        'precio_mayoreo'   => $nuevoPrecio,
    ];

    // Que el producto exista y siga activo antes de tocarlo.
    $stmt = $pdo->prepare(
        "SELECT nombre, precio_mayoreo FROM `{$tablaOrigen}`
          WHERE codigo_interno = :codigo AND activo = 1 LIMIT 1"
    );
    $stmt->execute(['codigo' => $codigo]);
    $antes = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$antes) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'El producto ya no esta en el inventario']);
        exit;
    }

    // El trigger de historial de precios usa esta variable para saber quien hizo
    // el cambio; se pone siempre, aunque el precio no cambie (el trigger decide).
    $pdo->prepare('SET @usuario_actual = :id')->execute(['id' => $usuarioId]);

    // ---------- Caso 1: misma casa -> solo se actualizan los campos ----------
    if ($tablaDestino === $tablaOrigen) {
        $pdo->prepare(
            "UPDATE `{$tablaOrigen}` SET
                codigo_proveedor = :codigo_proveedor,
                nombre           = :nombre,
                marca            = :marca,
                categoria        = :categoria,
                precio_mayoreo   = :precio_mayoreo
             WHERE codigo_interno = :codigo"
        )->execute($campos + ['codigo' => $codigo]);

        echo json_encode([
            'ok'      => true,
            'movido'  => false,
            'nombre'  => $nombre,
            'codigo'  => $codigo,
            'casa'    => etiquetaCasa($casaDestino),
            'mensaje' => 'Producto actualizado.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ---------- Caso 2: cambia de casa -> alta en la destino, baja en la origen ----------
    // Cada casa vive en su propia tabla y el codigo interno lleva el prefijo de la
    // casa, asi que "mover" es crear el producto en la casa destino (con codigo
    // nuevo) y dar de baja el de la casa origen. Las ventas ya hechas guardan su
    // propia copia del nombre y la casa (detalle_venta), asi que no se tocan.
    $pdo->beginTransaction();

    try {
        $columnas = ['codigo_proveedor', 'nombre', 'marca', 'categoria', 'precio_mayoreo'];

        $pdo->prepare(
            "INSERT INTO `{$tablaDestino}` (" . implode(', ', $columnas) . ")
             VALUES (:codigo_proveedor, :nombre, :marca, :categoria, :precio_mayoreo)"
        )->execute($campos);

        $idNuevo = (int) $pdo->lastInsertId();

        // El codigo interno se arma con el id, igual que en el resto del catalogo
        // (BNS05-00001).
        $pdo->prepare(
            "UPDATE `{$tablaDestino}`
                SET codigo_interno = CONCAT(:casa, '-', LPAD(id, 5, '0'))
              WHERE id = :id"
        )->execute(['casa' => $casaDestino, 'id' => $idNuevo]);

        $codigoNuevo = (string) $pdo->query(
            "SELECT codigo_interno FROM `{$tablaDestino}` WHERE id = " . $idNuevo
        )->fetchColumn();

        // Baja logica del producto en la casa de origen.
        $pdo->prepare("UPDATE `{$tablaOrigen}` SET activo = 0 WHERE codigo_interno = :codigo")
            ->execute(['codigo' => $codigo]);

        $pdo->commit();

    } catch (PDOException $e) {
        $pdo->rollBack();
        throw $e;
    }

    echo json_encode([
        'ok'           => true,
        'movido'       => true,
        'nombre'       => $nombre,
        'codigo'       => $codigoNuevo,
        'casa'         => etiquetaCasa($casaDestino),
        'casa_destino' => $casaDestino,
        'mensaje'      => 'Producto movido a ' . etiquetaCasa($casaDestino) . '.',
    ], JSON_UNESCAPED_UNICODE);

} catch (RuntimeException $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    error_log($e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'No se pudo guardar el producto']);
}
