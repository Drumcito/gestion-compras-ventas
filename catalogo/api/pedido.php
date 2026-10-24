<?php
/**
 * Guarda el carrito del catalogo cuando el cliente lo manda por WhatsApp.
 *
 * El mensaje de WhatsApp sigue siendo el pedido "de verdad" (ahi se platica con
 * el cliente); esto solo deja una copia en pedidos_linea con el mismo FOLIO,
 * para que en el Historial de Ventas salga resaltado y se cargue en Venta sin
 * capturarlo otra vez. Si falla, el cliente ni se entera: su mensaje ya salio.
 *
 * Recibe JSON: { folio, nombre, ficha, productos: [{ c: codigo, q: piezas }] }.
 * Los nombres y precios NO se toman del navegador: se releen de la base.
 *
 * Defensas, sin captcha ni cookies: ficha firmada con la hora, mismo origen,
 * maximo de productos y piezas, y 10 pedidos por hora por IP.
 */

require_once __DIR__ . '/../inc/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function responder(int $codigo, array $datos): void
{
    http_response_code($codigo);
    echo json_encode($datos, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    responder(405, ['ok' => false, 'error' => 'Método no permitido']);
}

$origen = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origen !== '' && parse_url($origen, PHP_URL_HOST) !== parse_url('//' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST)) {
    responder(403, ['ok' => false, 'error' => 'Origen no permitido']);
}

$cfg      = configCatalogo();
$maximo   = max(1, (int) ($cfg['sitio']['carrito_max'] ?? 20));
$entrada  = json_decode((string) file_get_contents('php://input'), true);

if (!is_array($entrada)) {
    responder(400, ['ok' => false, 'error' => 'Pedido no válido']);
}

// El carrito vive dias en el navegador y la pagina puede quedarse abierta: la
// ficha se acepta hasta 7 dias (el minimo de 3 s frena a los robots).
if (!fichaValida((string) ($entrada['ficha'] ?? ''), 3, 7 * 86400)) {
    responder(422, ['ok' => false, 'error' => 'Recarga la página e intenta de nuevo.']);
}

// Mismo alfabeto que catalogo.js: sin 0/O ni 1/I/L, que se confunden al dictarlo.
$folio = strtoupper(trim((string) ($entrada['folio'] ?? '')));
if (!preg_match('/^[2-9A-HJKMNP-Z]{6}$/', $folio)) {
    responder(400, ['ok' => false, 'error' => 'Folio no válido']);
}

$nombre = trim(preg_replace('/\s+/u', ' ', (string) ($entrada['nombre'] ?? '')));
$nombre = $nombre === '' ? null : mb_substr($nombre, 0, 80);

// Piezas por codigo; un codigo repetido suma.
$piezas = [];
foreach ((array) ($entrada['productos'] ?? []) as $p) {
    $codigo   = strtoupper(trim((string) ($p['c'] ?? '')));
    $cantidad = (int) ($p['q'] ?? 0);
    if ($codigo === '' || strlen($codigo) > 20 || $cantidad < 1) {
        continue;
    }
    $piezas[$codigo] = min(999, ($piezas[$codigo] ?? 0) + $cantidad);
}

if ($piezas === [] || count($piezas) > $maximo) {
    responder(400, ['ok' => false, 'error' => 'El carrito está vacío o trae demasiados productos.']);
}

$ip     = substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
$agente = mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);

try {
    $pdo = catalogoBd();

    // El mismo carrito mandado otra vez trae el mismo folio: solo se actualiza el
    // nombre (si sigue pendiente) y se contesta igual que la primera vez.
    $stmt = $pdo->prepare('SELECT id FROM pedidos_linea WHERE folio = :folio');
    $stmt->execute(['folio' => $folio]);
    $existente = $stmt->fetchColumn();

    if ($existente) {
        if ($nombre !== null) {
            $pdo->prepare('UPDATE pedidos_linea SET nombre = :nombre WHERE id = :id AND venta_id IS NULL')
                ->execute(['nombre' => $nombre, 'id' => (int) $existente]);
        }
        responder(200, ['ok' => true, 'folio' => $folio]);
    }

    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM pedidos_linea WHERE ip = :ip AND creado_en >= NOW() - INTERVAL 1 HOUR'
    );
    $stmt->execute(['ip' => $ip]);
    if ((int) $stmt->fetchColumn() >= 10) {
        responder(429, ['ok' => false, 'error' => 'Demasiados pedidos desde tu conexión.']);
    }

    // Nombre y precio de hoy, con el mismo calculo del neto que el catalogo y la
    // venta. Lo que ya no esta activo o no tiene precio se queda fuera.
    $marcas = implode(',', array_fill(0, count($piezas), '?'));
    $stmt = $pdo->prepare(
        "SELECT codigo_interno, codigo_casa, nombre, precio_mayoreo, porcentaje_neto
           FROM vista_catalogo
          WHERE activo = 1 AND codigo_interno IN ($marcas)"
    );
    $stmt->execute(array_keys($piezas));

    $lineas = [];
    $total  = 0.0;
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $f) {
        $precio = netoDe($f['precio_mayoreo'], porcentajeProducto($f['porcentaje_neto'], $f['codigo_casa']));
        if ($precio === null || $precio <= 0) {
            continue;
        }
        $cantidad = $piezas[strtoupper($f['codigo_interno'])];
        $lineas[] = [$f['codigo_interno'], $f['nombre'], $precio, $cantidad];
        $total += $precio * $cantidad;
    }

    if ($lineas === []) {
        responder(422, ['ok' => false, 'error' => 'Ninguno de los productos está disponible.']);
    }

    // Mismo orden en que el cliente los agrego.
    $orden = array_flip(array_keys($piezas));
    usort($lineas, fn($a, $b) => $orden[strtoupper($a[0])] <=> $orden[strtoupper($b[0])]);

    $pdo->beginTransaction();

    $pdo->prepare(
        'INSERT INTO pedidos_linea (folio, nombre, total, aviso_version, ip, user_agent)
         VALUES (:folio, :nombre, :total, :version, :ip, :agente)'
    )->execute([
        'folio'   => $folio,
        'nombre'  => $nombre,
        'total'   => round($total, 2),
        'version' => (string) $cfg['legal']['version'],
        'ip'      => $ip,
        'agente'  => $agente,
    ]);
    $pedidoId = (int) $pdo->lastInsertId();

    $stmt = $pdo->prepare(
        'INSERT INTO pedidos_linea_detalle (pedido_id, codigo_interno, nombre, precio, cantidad)
         VALUES (:pedido, :codigo, :nombre, :precio, :cantidad)'
    );
    foreach ($lineas as [$codigo, $nombreProducto, $precio, $cantidad]) {
        $stmt->execute([
            'pedido'   => $pedidoId,
            'codigo'   => $codigo,
            'nombre'   => $nombreProducto,
            'precio'   => $precio,
            'cantidad' => $cantidad,
        ]);
    }

    $pdo->commit();

} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    // Dos envios del mismo carrito al mismo tiempo: el segundo choca con el folio
    // unico, y eso significa que el pedido ya quedo guardado.
    if ($e->getCode() === '23000') {
        responder(200, ['ok' => true, 'folio' => $folio]);
    }
    error_log('Pedido en linea: ' . $e->getMessage());
    responder(500, ['ok' => false, 'error' => 'No se pudo guardar el pedido.']);
}

responder(200, ['ok' => true, 'folio' => $folio]);
