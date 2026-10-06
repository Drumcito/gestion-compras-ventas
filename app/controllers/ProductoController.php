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

$accion  = $_GET['accion'] ?? 'buscar';
$casa    = $_GET['casa'] ?? '';
$termino = trim($_GET['q'] ?? '');

// Casa y termino solo le hacen falta a la busqueda; accion=precios va por
// codigos y no pasa por aqui.
if ($accion !== 'precios') {
    // 'TODAS' busca en el catalogo completo cuando el vendedor no sabe de que
    // casa es la pieza; el resultado dice a que casa pertenece cada una. El
    // resto de los codigos son los de las casas registradas, para que una casa
    // nueva se pueda buscar sin tocar esta lista.
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
}

try {
    $pdo = Database::getConnection();

    // ---------- Precio vigente de productos que ya estan en el ticket ----------
    // La pantalla de Venta guarda el precio con el que se agrego cada pieza. Si
    // entretanto alguien lo cambio en el inventario, el ticket mostraria uno y
    // al guardar se cobraria otro (el servidor siempre relee el catalogo). Esto
    // deja refrescar lo que ya esta en el ticket antes de cobrar.
    if ($accion === 'precios') {
        $codigos = array_values(array_unique(array_filter(
            array_map('trim', explode(',', (string) ($_GET['codigos'] ?? '')))
        )));

        if ($codigos === []) {
            echo json_encode(['ok' => true, 'productos' => []]);
            exit;
        }

        $catalogo = catalogoDeProductos(
            $pdo, array_slice($codigos, 0, 200),
            ['nombre', 'precio_mayoreo', 'porcentaje_neto', 'activo']
        );

        $productos = [];

        foreach ($catalogo as $codigo => $fila) {
            $casa = strtoupper(substr($codigo, 0, strpos($codigo, '-') ?: 0));

            $productos[] = [
                'codigo_interno' => $codigo,
                'nombre'         => $fila['nombre'],
                'activo'         => (int) $fila['activo'] === 1,
                'precio_neto'    => netoDe(
                    $fila['precio_mayoreo'],
                    porcentajeProducto($fila['porcentaje_neto'], $casa)
                ),
            ];
        }

        echo json_encode(['ok' => true, 'productos' => $productos], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $like = '%' . $termino . '%';

    // vista_catalogo une el catalogo de todas las casas y ya trae el nombre de la casa.
    $sql = 'SELECT codigo_casa, nombre_casa, codigo_interno, codigo_proveedor,
                   nombre, marca, precio_mayoreo, porcentaje_neto
              FROM vista_catalogo
             WHERE activo = 1
               AND (nombre LIKE :q1 OR codigo_proveedor LIKE :q2 OR codigo_interno LIKE :q3
                    OR marca LIKE :q4)';

    $parametros = ['q1' => $like, 'q2' => $like, 'q3' => $like, 'q4' => $like];

    if ($casa !== 'TODAS') {
        $sql .= ' AND codigo_casa = :casa';
        $parametros['casa'] = $casa;
    }

    // El orden sale de que tan seguro es el acierto:
    //
    //   1. Codigo, que es lo mas preciso: quien lo escribe ya sabe que pieza
    //      quiere. Entre los codigos manda el de la casa (el que viene impreso
    //      en el catalogo del proveedor) sobre el interno.
    //   2. Nombre.
    //   3. Marca, que es lo mas amplio: "TRUPER" trae cientos de piezas, asi que
    //      esas van hasta abajo para no tapar un acierto exacto.
    //
    // Dentro de cada grupo, alfabetico por nombre.
    //
    // Cada marcador va con nombre propio: PDO sin emulacion no deja repetir uno.
    $sql .= ' ORDER BY
                CASE
                    WHEN codigo_proveedor = :ex1      THEN 0
                    WHEN codigo_interno   = :ex2      THEN 1
                    WHEN codigo_proveedor LIKE :ini1  THEN 2
                    WHEN codigo_interno   LIKE :ini2  THEN 3
                    WHEN codigo_proveedor LIKE :med1  THEN 4
                    WHEN codigo_interno   LIKE :med2  THEN 5
                    WHEN nombre           LIKE :nom   THEN 6
                    ELSE 7
                END,
                nombre
              LIMIT 25';

    $parametros += [
        'ex1' => $termino,        'ex2' => $termino,
        'ini1' => $termino . '%', 'ini2' => $termino . '%',
        'med1' => $like,          'med2' => $like,
        'nom'  => $like,
    ];

    $stmt = $pdo->prepare($sql);
    $stmt->execute($parametros);

    $productos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Al vendedor le sale el precio NETO: el bruto (mayoreo) mas el porcentaje del
    // producto (el suyo propio si lo tiene, o el de su casa si no). El bruto no se
    // manda al navegador; lo unico que se cobra es el neto (y VentaController lo
    // recalcula por su cuenta al guardar).
    foreach ($productos as &$producto) {
        $producto['nombre_casa']  = etiquetaCasa($producto['codigo_casa']);
        $producto['precio_neto']  = netoDe(
            $producto['precio_mayoreo'],
            porcentajeProducto($producto['porcentaje_neto'], $producto['codigo_casa'])
        );
        unset($producto['precio_mayoreo'], $producto['porcentaje_neto']);
    }
    unset($producto);

    echo json_encode($productos, JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    error_log($e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Error al buscar productos']);
}
