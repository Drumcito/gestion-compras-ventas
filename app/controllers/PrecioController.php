<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Sesion expirada']);
    exit;
}

// Cambiar un precio afecta a todas las ventas futuras de ese producto, asi que
// queda reservado al administrador. La barrera vive aqui y no solo en el boton:
// ocultarlo en pantalla no impide que alguien llame al controlador directo.
if (($_SESSION['user_role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Solo un administrador puede cambiar precios']);
    exit;
}

require_once __DIR__ . '/../../config/conexionBD.php';
require_once __DIR__ . '/../helpers/casas.php';

$usuarioId = (int) $_SESSION['user_id'];

/**
 * El codigo interno ya dice de que casa es el producto (BNS03-01270), asi que
 * de ahi sale la tabla. Se resuelve contra las casas registradas: nunca se arma
 * el FROM con texto que venga del navegador.
 */
function tablaDe(string $codigoInterno): string
{
    $tabla = tablaDeProducto($codigoInterno);

    if ($tabla === null) {
        throw new RuntimeException('Codigo de producto no valido');
    }

    return $tabla;
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
        $tabla  = tablaDe($codigo);

        $stmt = $pdo->prepare(
            "SELECT codigo_interno, codigo_proveedor, nombre, marca,
                    precio_mayoreo, activo
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
    $tabla  = tablaDe($codigo);

    // Un campo vacio significa "no tocar el precio", no "ponerlo en cero".
    $nuevoMayoreo = aPrecio($datos['precio_mayoreo'] ?? null);

    if ($nuevoMayoreo === null) {
        throw new RuntimeException('Escribe el precio bruto para actualizar');
    }

    $stmt = $pdo->prepare(
        "SELECT nombre, precio_mayoreo FROM {$tabla}
          WHERE codigo_interno = :codigo LIMIT 1"
    );
    $stmt->execute(['codigo' => $codigo]);
    $antes = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$antes) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Producto no encontrado']);
        exit;
    }

    $mayoreoAntes = $antes['precio_mayoreo'] === null ? null : (float) $antes['precio_mayoreo'];

    if ($nuevoMayoreo === $mayoreoAntes) {
        echo json_encode([
            'ok'      => true,
            'cambios' => 0,
            'mensaje' => 'El precio quedo igual, no hubo nada que guardar',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // El trigger de la tabla escribe solo el renglon en historial_precios; esta
    // variable de sesion es la que le dice quien hizo el cambio.
    $pdo->prepare('SET @usuario_actual = :id')->execute(['id' => $usuarioId]);

    $stmt = $pdo->prepare(
        "UPDATE {$tabla} SET precio_mayoreo = :mayoreo WHERE codigo_interno = :codigo"
    );
    $stmt->execute([
        'mayoreo' => $nuevoMayoreo,
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
            'despues'   => $nuevoMayoreo,
            'tendencia' => $tendencia($antes['precio_mayoreo'], $nuevoMayoreo),
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
