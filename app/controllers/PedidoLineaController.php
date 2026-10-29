<?php
/**
 * Pedidos en linea: los carritos que los clientes mandan desde el catalogo
 * publico por WhatsApp (ver catalogo/api/pedido.php). Salen en el Historial de
 * Ventas, en naranja claro, hasta que se convierten en venta o se descartan.
 *
 *   GET  accion=pendientes  Los que faltan por atender, del mas viejo al mas
 *                           nuevo, con sus productos. Pendiente = sin venta (o
 *                           con una venta que se elimino) y sin descartar.
 *
 *   GET  accion=cargar&id=  Sus productos con el formato del ticket de Venta y
 *                           el precio de HOY, para cargarlo como borrador. Lo que
 *                           ya no esta activo o no tiene precio va aparte.
 *
 *   POST accion=descartar   Spam, repetido o cancelado: deja de salir como
 *                           pendiente, sin borrarse (queda quien y cuando).
 *
 * Cualquier usuario con sesion los ve y atiende: un pedido en linea todavia no
 * es de ningun vendedor. Se vuelve suyo al guardarlo como venta.
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

$usuarioId = (int) $_SESSION['user_id'];

// Condicion de "pendiente", compartida por el listado y la carga.
const SQL_PEDIDO_PENDIENTE =
    'p.descartado_en IS NULL AND (p.venta_id IS NULL OR v.eliminada_en IS NOT NULL)';

try {
    $pdo = Database::getConnection();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $datos = json_decode(file_get_contents('php://input'), true) ?? [];

        if (($datos['accion'] ?? '') !== 'descartar') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Accion no valida']);
            exit;
        }

        $stmt = $pdo->prepare(
            'UPDATE pedidos_linea p
          LEFT JOIN ventas v ON v.id = p.venta_id
                SET p.descartado_en = NOW(), p.descartado_por = :usuario
              WHERE p.id = :id AND ' . SQL_PEDIDO_PENDIENTE
        );
        $stmt->execute(['usuario' => $usuarioId, 'id' => (int) ($datos['id'] ?? 0)]);

        if ($stmt->rowCount() === 0) {
            http_response_code(409);
            echo json_encode(['ok' => false, 'error' => 'Ese pedido ya se atendió o se descartó']);
            exit;
        }

        echo json_encode(['ok' => true]);
        exit;
    }

    $accion = $_GET['accion'] ?? 'pendientes';

    // ---------- Ventas que nacieron de un pedido del catalogo ----------
    // Son las que se ven en la seccion "En linea". Un vendedor ve solo las que
    // el atendio; el admin, todas. Las eliminadas quedan fuera: su pedido vuelve
    // a contar como pendiente (ver SQL_PEDIDO_PENDIENTE).
    if ($accion === 'ventas') {
        $esAdmin = ($_SESSION['user_role'] ?? '') === 'admin';

        $sql =
            'SELECT p.folio, p.nombre AS nombre_pedido, p.creado_en AS pedido_en,
                    p.atendido_en,
                    v.id AS venta_id, v.cliente, v.fecha, v.total,
                    v.tipo_pago, v.estado_pago,
                    CONCAT(u.nombre, " ", COALESCE(u.apellido, "")) AS vendedor,
                    (SELECT COUNT(*) FROM detalle_venta d WHERE d.venta_id = v.id) AS renglones,
                    (SELECT COALESCE(SUM(d.cantidad), 0) FROM detalle_venta d WHERE d.venta_id = v.id) AS piezas
               FROM pedidos_linea p
               JOIN ventas v ON v.id = p.venta_id
          LEFT JOIN usuarios u ON u.id = v.usuario_id
              WHERE v.eliminada_en IS NULL'
            . ($esAdmin ? '' : ' AND v.usuario_id = :usuario')
            . ' ORDER BY v.fecha DESC, v.id DESC
              LIMIT 300';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($esAdmin ? [] : ['usuario' => $usuarioId]);

        $ventas = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Resumen de la seccion: cuantas y cuanto suman.
        $total = 0.0;
        foreach ($ventas as $v) {
            $total += (float) $v['total'];
        }

        echo json_encode([
            'ok'     => true,
            'ventas' => $ventas,
            'total'  => round($total, 2),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ---------- Cargar un pedido en la pantalla de Venta ----------
    if ($accion === 'cargar') {
        $stmt = $pdo->prepare(
            'SELECT p.id, p.folio, p.nombre
               FROM pedidos_linea p
          LEFT JOIN ventas v ON v.id = p.venta_id
              WHERE p.id = :id AND ' . SQL_PEDIDO_PENDIENTE
        );
        $stmt->execute(['id' => (int) ($_GET['id'] ?? 0)]);
        $pedido = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$pedido) {
            http_response_code(409);
            echo json_encode(['ok' => false, 'error' => 'Ese pedido ya se atendió o se descartó']);
            exit;
        }

        $stmt = $pdo->prepare(
            'SELECT d.codigo_interno, d.nombre, d.cantidad,
                    c.codigo_casa, c.nombre_casa, c.codigo_proveedor, c.precio_mayoreo, c.porcentaje_neto, c.activo
               FROM pedidos_linea_detalle d
          LEFT JOIN vista_catalogo c ON c.codigo_interno = d.codigo_interno
              WHERE d.pedido_id = :id
              ORDER BY d.id'
        );
        $stmt->execute(['id' => (int) $pedido['id']]);

        $items = [];
        $fuera = [];

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $f) {
            $precio = $f['codigo_casa'] !== null && (int) $f['activo'] === 1
                ? netoDe($f['precio_mayoreo'], porcentajeProducto($f['porcentaje_neto'], $f['codigo_casa']))
                : null;

            if ($precio === null) {
                $fuera[] = $f['nombre'];
                continue;
            }

            // Mismo formato que agregarItem() de venta.js.
            $items[] = [
                'casa'           => $f['codigo_casa'],
                'casa_nombre'    => $f['nombre_casa'],
                'codigo_interno' => $f['codigo_interno'],
                'codigo_visible' => $f['codigo_proveedor'] ?: $f['codigo_interno'],
                'nombre'         => $f['nombre'],
                'precio'         => $precio,
                'cantidad'       => (int) $f['cantidad'],
            ];
        }

        echo json_encode([
            'ok'     => true,
            'pedido' => $pedido + ['items' => $items, 'fuera' => $fuera],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ---------- Pendientes ----------
    $pedidos = $pdo->query(
        'SELECT p.id, p.folio, p.nombre, p.total, p.creado_en
           FROM pedidos_linea p
      LEFT JOIN ventas v ON v.id = p.venta_id
          WHERE ' . SQL_PEDIDO_PENDIENTE . '
          ORDER BY p.creado_en, p.id'
    )->fetchAll(PDO::FETCH_ASSOC);

    if ($pedidos) {
        $ids    = array_column($pedidos, 'id');
        $marcas = implode(',', array_fill(0, count($ids), '?'));
        $stmt   = $pdo->prepare(
            "SELECT pedido_id, codigo_interno, nombre, precio, cantidad
               FROM pedidos_linea_detalle
              WHERE pedido_id IN ($marcas)
              ORDER BY id"
        );
        $stmt->execute($ids);

        $porPedido = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $d) {
            $porPedido[$d['pedido_id']][] = $d;
        }

        foreach ($pedidos as &$p) {
            $p['items']  = $porPedido[$p['id']] ?? [];
            $p['piezas'] = array_sum(array_column($p['items'], 'cantidad'));
        }
        unset($p);
    }

    echo json_encode(['ok' => true, 'pedidos' => $pedidos], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    error_log('Pedidos en linea: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Error al consultar los pedidos en línea']);
}
