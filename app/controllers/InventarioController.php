<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Sesion expirada']);
    exit;
}

require_once __DIR__ . '/../../config/conexionBD.php';
require_once __DIR__ . '/../helpers/casas.php';

// El mapa casa -> tabla sale de la base (ver app/helpers/casas.php), asi que una
// casa creada desde esta misma pantalla queda disponible sin tocar codigo.
$tablasCasa = tablasCasa();

const POR_PAGINA = 50;

try {
    $pdo = Database::getConnection();

    // ---------- Casas con su conteo de productos ----------
    if (($_GET['accion'] ?? '') === 'casas') {
        $casas = ordenarCasas(
            $pdo->query('SELECT id, codigo_casa, nombre FROM casas WHERE activo = 1')
                ->fetchAll(PDO::FETCH_ASSOC)
        );

        foreach ($casas as &$casa) {
            $tabla = $tablasCasa[$casa['codigo_casa']] ?? null;
            if ($tabla === null) {
                $casa['productos'] = 0;
                continue;
            }
            $casa['productos'] = (int) $pdo->query("SELECT COUNT(*) FROM {$tabla} WHERE activo = 1")->fetchColumn();
        }
        unset($casa);

        echo json_encode(['ok' => true, 'casas' => $casas], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ---------- Productos de una casa ----------
    $casa = $_GET['casa'] ?? '';

    if (!isset($tablasCasa[$casa])) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Casa no valida']);
        exit;
    }

    $tabla   = $tablasCasa[$casa];
    $termino = trim($_GET['q'] ?? '');
    $pagina  = max(1, (int) ($_GET['pagina'] ?? 1));
    $desde   = ($pagina - 1) * POR_PAGINA;

    // El catalogo de una casa llega a 12 mil productos: se pagina siempre.
    $filtro     = '';
    $parametros = [];

    if (mb_strlen($termino) >= 2) {
        $filtro = ' AND (nombre LIKE :q1 OR codigo_proveedor LIKE :q2 OR codigo_interno LIKE :q3)';
        $like = '%' . $termino . '%';
        $parametros = ['q1' => $like, 'q2' => $like, 'q3' => $like];
    }

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM {$tabla} WHERE activo = 1{$filtro}");
    $stmt->execute($parametros);
    $total = (int) $stmt->fetchColumn();

    // LIMIT/OFFSET van interpolados como enteros ya validados: PDO en modo
    // sin emulacion no acepta marcadores en esa posicion.
    $stmt = $pdo->prepare(
        "SELECT codigo_interno, codigo_proveedor, nombre, marca, categoria,
                precio_mayoreo, precio_menudeo
           FROM {$tabla}
          WHERE activo = 1{$filtro}
          ORDER BY nombre
          LIMIT " . POR_PAGINA . " OFFSET " . (int) $desde
    );
    $stmt->execute($parametros);

    echo json_encode([
        'ok'          => true,
        'casa'        => $casa,
        'productos'   => $stmt->fetchAll(PDO::FETCH_ASSOC),
        'total'       => $total,
        'pagina'      => $pagina,
        'por_pagina'  => POR_PAGINA,
        'paginas'     => max(1, (int) ceil($total / POR_PAGINA)),
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    error_log($e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Error al consultar el inventario']);
}
