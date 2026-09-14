<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Sesion expirada']);
    exit;
}

require_once __DIR__ . '/../../config/conexionBD.php';
require_once __DIR__ . '/../helpers/casas.php';

$casa    = $_GET['casa'] ?? '';
$termino = trim($_GET['q'] ?? '');

// 'TODAS' busca en el catalogo completo cuando el vendedor no sabe de que casa
// es la pieza; el resultado dice a que casa pertenece cada una. El resto de los
// codigos son los de las casas registradas, para que una casa nueva se pueda
// buscar sin tocar esta lista.
$codigosValidos = array_merge(['TODAS'], array_keys(tablasCasa()));

if (!in_array($casa, $codigosValidos, true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Casa no valida']);
    exit;
}

if (mb_strlen($termino) < 2) {
    echo json_encode([]);
    exit;
}

try {
    $pdo  = Database::getConnection();
    $like = '%' . $termino . '%';

    // vista_catalogo une el catalogo de todas las casas y ya trae el nombre de la casa.
    $sql = 'SELECT codigo_casa, nombre_casa, codigo_interno, codigo_proveedor,
                   nombre, marca, precio_mayoreo, precio_menudeo
              FROM vista_catalogo
             WHERE activo = 1
               AND (nombre LIKE :q1 OR codigo_proveedor LIKE :q2 OR codigo_interno LIKE :q3)';

    $parametros = ['q1' => $like, 'q2' => $like, 'q3' => $like];

    if ($casa !== 'TODAS') {
        $sql .= ' AND codigo_casa = :casa';
        $parametros['casa'] = $casa;
    }

    $sql .= ' ORDER BY nombre LIMIT 25';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($parametros);

    $productos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // La etiqueta que se ve en la pastilla de color de cada resultado.
    foreach ($productos as &$producto) {
        $producto['nombre_casa'] = etiquetaCasa($producto['codigo_casa']);
    }
    unset($producto);

    echo json_encode($productos, JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    error_log($e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Error al buscar productos']);
}
