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
            // El porcentaje que se le suma al bruto para el neto (editable por casa).
            $casa['porcentaje_neto'] = porcentajeCasa($casa['codigo_casa']);

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
    $orden      = 'nombre';
    $parametros = [];
    $paramOrden = [];

    if (mb_strlen($termino) >= 2) {
        $filtro = ' AND (nombre LIKE :q1 OR codigo_proveedor LIKE :q2 OR codigo_interno LIKE :q3)';
        $like = '%' . $termino . '%';
        $parametros = ['q1' => $like, 'q2' => $like, 'q3' => $like];

        // Igual que en la busqueda de la venta: si lo escrito es un codigo, esos
        // resultados van primero y manda el codigo de la casa. Cada marcador
        // lleva nombre propio porque PDO sin emulacion no deja repetirlos.
        $orden = 'CASE
                      WHEN codigo_proveedor = :ex1     THEN 0
                      WHEN codigo_interno   = :ex2     THEN 1
                      WHEN codigo_proveedor LIKE :ini1 THEN 2
                      WHEN codigo_interno   LIKE :ini2 THEN 3
                      WHEN codigo_proveedor LIKE :med1 THEN 4
                      WHEN codigo_interno   LIKE :med2 THEN 5
                      ELSE 6
                  END, nombre';

        $paramOrden = [
            'ex1' => $termino,        'ex2' => $termino,
            'ini1' => $termino . '%', 'ini2' => $termino . '%',
            'med1' => $like,          'med2' => $like,
        ];
    }

    // El conteo no lleva los marcadores del ORDER BY: PDO rechaza los que sobran.
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM {$tabla} WHERE activo = 1{$filtro}");
    $stmt->execute($parametros);
    $total = (int) $stmt->fetchColumn();

    // LIMIT/OFFSET van interpolados como enteros ya validados: PDO en modo
    // sin emulacion no acepta marcadores en esa posicion.
    $stmt = $pdo->prepare(
        "SELECT codigo_interno, codigo_proveedor, nombre, marca, categoria,
                precio_mayoreo
           FROM {$tabla}
          WHERE activo = 1{$filtro}
          ORDER BY {$orden}
          LIMIT " . POR_PAGINA . " OFFSET " . (int) $desde
    );
    $stmt->execute($parametros + $paramOrden);

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
