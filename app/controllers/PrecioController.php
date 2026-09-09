<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Sesion expirada']);
    exit;
}

require_once __DIR__ . '/../../config/conexionBD.php';

$usuarioId = (int) $_SESSION['user_id'];

$tablasCasa = [
    'BNS01' => 'productos_casa1',
    'BNS02' => 'productos_casa2',
    'BNS03' => 'productos_casa3',
    'BNS04' => 'productos_casa4',
];

/**
 * El codigo interno ya dice de que casa es el producto (BNS03-01270), asi que
 * de ahi sale la tabla. Se valida contra la lista fija: nunca se arma el FROM
 * con texto que venga del navegador.
 */
function tablaDe(string $codigoInterno, array $tablasCasa): string
{
    $prefijo = strtoupper(substr($codigoInterno, 0, 5));

    if (!isset($tablasCasa[$prefijo])) {
        throw new RuntimeException('Codigo de producto no valido');
    }

    return $tablasCasa[$prefijo];
}

function aPrecio($valor): ?float
{
    if ($valor === null || $valor === '' ) {
        return null;
    }
    if (!is_numeric($valor)) {
        throw new RuntimeException('Los precios deben ser numeros');
    }
    $numero = (float) $valor;
    if ($numero < 0) {
        throw new RuntimeException('Los precios no pueden ser negativos');
    }
    if ($numero > 99999999.99) {
        throw new RuntimeException('El precio es demasiado alto');
    }
    return round($numero, 2);
}

try {
    $pdo = Database::getConnection();

    // ---------- Consultar el precio vigente en el catalogo ----------
    if (($_GET['accion'] ?? '') === 'consultar') {
        $codigo = $_GET['codigo'] ?? '';
        $tabla  = tablaDe($codigo, $tablasCasa);

        $stmt = $pdo->prepare(
            "SELECT codigo_interno, codigo_proveedor, nombre, marca,
                    precio_mayoreo, precio_menudeo, activo
               FROM {$tabla} WHERE codigo_interno = :codigo LIMIT 1"
        );
        $stmt->execute(['codigo' => $codigo]);
        $producto = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$producto) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'Producto no encontrado']);
            exit;
        }

        echo json_encode(['ok' => true, 'producto' => $producto], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ---------- Guardar precios nuevos ----------
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'Metodo no permitido']);
        exit;
    }

    $datos  = json_decode(file_get_contents('php://input'), true);
    $codigo = $datos['codigo'] ?? '';
    $tabla  = tablaDe($codigo, $tablasCasa);

    // Un campo vacio significa "no tocar ese precio", no "ponerlo en cero".
    $nuevoMayoreo = aPrecio($datos['precio_mayoreo'] ?? null);
    $nuevoMenudeo = aPrecio($datos['precio_menudeo'] ?? null);

    if ($nuevoMayoreo === null && $nuevoMenudeo === null) {
        throw new RuntimeException('Escribe al menos un precio para actualizar');
    }

    $stmt = $pdo->prepare(
        "SELECT nombre, precio_mayoreo, precio_menudeo FROM {$tabla}
          WHERE codigo_interno = :codigo LIMIT 1"
    );
    $stmt->execute(['codigo' => $codigo]);
    $antes = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$antes) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Producto no encontrado']);
        exit;
    }

    $mayoreoFinal = $nuevoMayoreo ?? ($antes['precio_mayoreo'] === null ? null : (float) $antes['precio_mayoreo']);
    $menudeoFinal = $nuevoMenudeo ?? ($antes['precio_menudeo'] === null ? null : (float) $antes['precio_menudeo']);

    $sinCambios = ($mayoreoFinal === ($antes['precio_mayoreo'] === null ? null : (float) $antes['precio_mayoreo']))
               && ($menudeoFinal === ($antes['precio_menudeo'] === null ? null : (float) $antes['precio_menudeo']));

    if ($sinCambios) {
        echo json_encode([
            'ok'      => true,
            'cambios' => 0,
            'mensaje' => 'Los precios quedaron igual, no hubo nada que guardar',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // El trigger de la tabla escribe solo el renglon en historial_precios; esta
    // variable de sesion es la que le dice quien hizo el cambio.
    $pdo->prepare('SET @usuario_actual = :id')->execute(['id' => $usuarioId]);

    $stmt = $pdo->prepare(
        "UPDATE {$tabla}
            SET precio_mayoreo = :mayoreo, precio_menudeo = :menudeo
          WHERE codigo_interno = :codigo"
    );
    $stmt->execute([
        'mayoreo' => $mayoreoFinal,
        'menudeo' => $menudeoFinal,
        'codigo'  => $codigo,
    ]);

    $tendencia = function ($viejo, $nuevo) {
        if ($viejo === null && $nuevo === null) return 'igual';
        if ($viejo === null) return 'nuevo';
        if ($nuevo === null) return 'sin precio';
        if ((float) $nuevo > (float) $viejo) return 'subio';
        if ((float) $nuevo < (float) $viejo) return 'bajo';
        return 'igual';
    };

    echo json_encode([
        'ok'      => true,
        'cambios' => 1,
        'nombre'  => $antes['nombre'],
        'mayoreo' => [
            'antes'     => $antes['precio_mayoreo'],
            'despues'   => $mayoreoFinal,
            'tendencia' => $tendencia($antes['precio_mayoreo'], $mayoreoFinal),
        ],
        'menudeo' => [
            'antes'     => $antes['precio_menudeo'],
            'despues'   => $menudeoFinal,
            'tendencia' => $tendencia($antes['precio_menudeo'], $menudeoFinal),
        ],
    ], JSON_UNESCAPED_UNICODE);

} catch (RuntimeException $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    error_log($e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'No se pudo actualizar el precio']);
}
