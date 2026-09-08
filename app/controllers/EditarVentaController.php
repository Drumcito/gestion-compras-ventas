<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Sesion expirada, vuelve a iniciar sesion']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Metodo no permitido']);
    exit;
}

require_once __DIR__ . '/../../config/conexionBD.php';

$tablasCasa = [
    'BNS01' => 'productos_casa1',
    'BNS02' => 'productos_casa2',
    'BNS03' => 'productos_casa3',
    'BNS04' => 'productos_casa4',
];

$usuarioId = (int) $_SESSION['user_id'];
$esAdmin   = ($_SESSION['user_role'] ?? '') === 'admin';

$datos            = json_decode(file_get_contents('php://input'), true);
$ventaId          = (int) ($datos['venta_id'] ?? 0);
$cliente          = trim($datos['cliente'] ?? '');
$tipoPago         = $datos['tipo_pago'] ?? 'contado';
$fechaVencimiento = $datos['fecha_vencimiento'] ?? null;
$items            = $datos['items'] ?? [];

try {
    $pdo = Database::getConnection();

    $stmt = $pdo->prepare('SELECT * FROM ventas WHERE id = :id');
    $stmt->execute(['id' => $ventaId]);
    $venta = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$venta) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Venta no encontrada']);
        exit;
    }

    // Un vendedor solo edita sus propias ventas; el admin edita todas.
    if (!$esAdmin && (int) $venta['usuario_id'] !== $usuarioId) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Solo puedes editar las ventas que tu hiciste']);
        exit;
    }

    if (!in_array($tipoPago, ['contado', 'credito'], true)) {
        throw new RuntimeException('Tipo de pago no valido');
    }

    if (!is_array($items) || count($items) === 0) {
        throw new RuntimeException('La venta debe conservar al menos una pieza');
    }

    // Lo ya cobrado al cliente: en contado es el total que pago al hacerla,
    // en credito la suma de sus abonos.
    $cobrado = (float) $venta['monto_cobrado'];

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM pagos_credito WHERE venta_id = :id');
    $stmt->execute(['id' => $ventaId]);
    $tieneAbonos = (int) $stmt->fetchColumn() > 0;

    if ($tipoPago === 'contado' && $tieneAbonos) {
        throw new RuntimeException(
            'Esta venta ya tiene abonos registrados, no se puede cambiar a contado'
        );
    }

    $casas = $pdo->query('SELECT id, codigo_casa FROM casas')->fetchAll(PDO::FETCH_KEY_PAIR);
    $casaIdPorCodigo = array_flip($casas);

    // Los precios se releen de la base, igual que al crear la venta.
    $lineas = [];
    $total  = 0.0;

    foreach ($items as $item) {
        $casa       = $item['casa'] ?? '';
        $codigo     = $item['codigo_interno'] ?? '';
        $tipoPrecio = $item['tipo_precio'] ?? 'menudeo';
        $cantidad   = (int) ($item['cantidad'] ?? 0);

        if (!isset($tablasCasa[$casa]) || !isset($casaIdPorCodigo[$casa])) {
            throw new RuntimeException('Casa no valida en uno de los productos');
        }
        if (!in_array($tipoPrecio, ['mayoreo', 'menudeo'], true)) {
            throw new RuntimeException('Tipo de precio no valido');
        }
        if ($cantidad < 1) {
            throw new RuntimeException('La cantidad debe ser al menos 1');
        }

        $stmt = $pdo->prepare(
            "SELECT nombre, precio_mayoreo, precio_menudeo
               FROM {$tablasCasa[$casa]}
              WHERE codigo_interno = :codigo AND activo = 1
              LIMIT 1"
        );
        $stmt->execute(['codigo' => $codigo]);
        $producto = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$producto) {
            throw new RuntimeException("El producto {$codigo} ya no esta disponible");
        }

        $precio = $tipoPrecio === 'mayoreo' ? $producto['precio_mayoreo'] : $producto['precio_menudeo'];

        if ($precio === null) {
            throw new RuntimeException("{$producto['nombre']} no tiene precio de {$tipoPrecio}");
        }

        $lineas[$codigo] = [
            'casa_id'    => $casaIdPorCodigo[$casa],
            'codigo'     => $codigo,
            'nombre'     => $producto['nombre'],
            'tipoPrecio' => $tipoPrecio,
            'precio'     => (float) $precio,
            'cantidad'   => $cantidad,
        ];

        $total += (float) $precio * $cantidad;
    }

    // ---------- Comparar contra lo que habia, para la auditoria ----------
    $stmt = $pdo->prepare(
        'SELECT codigo_interno_producto, nombre_producto, tipo_precio, precio_aplicado, cantidad
           FROM detalle_venta WHERE venta_id = :id'
    );
    $stmt->execute(['id' => $ventaId]);

    $anteriores = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $fila) {
        $anteriores[$fila['codigo_interno_producto']] = $fila;
    }

    $cambios = [];

    $clienteAnterior = $venta['cliente'] ?? '';
    $clienteNuevo    = $cliente !== '' ? $cliente : null;

    if ((string) $clienteAnterior !== (string) $clienteNuevo) {
        $cambios[] = ['cliente', $clienteAnterior, $clienteNuevo];
    }

    if ($venta['tipo_pago'] !== $tipoPago) {
        $cambios[] = ['tipo_pago', $venta['tipo_pago'], $tipoPago];
    }

    $vencAnterior = $venta['fecha_vencimiento'];
    $vencNuevo    = ($tipoPago === 'credito' && $fechaVencimiento) ? $fechaVencimiento : null;

    if ((string) $vencAnterior !== (string) $vencNuevo) {
        $cambios[] = ['fecha_vencimiento', $vencAnterior, $vencNuevo];
    }

    foreach ($lineas as $codigo => $linea) {
        if (!isset($anteriores[$codigo])) {
            $cambios[] = ['pieza agregada', null, $linea['nombre'] . ' x' . $linea['cantidad']];
            continue;
        }

        $antes = $anteriores[$codigo];

        if ((int) $antes['cantidad'] !== $linea['cantidad']) {
            $cambios[] = ['cantidad · ' . $codigo, $antes['cantidad'], $linea['cantidad']];
        }
        if ($antes['tipo_precio'] !== $linea['tipoPrecio']) {
            $cambios[] = ['tipo de precio · ' . $codigo, $antes['tipo_precio'], $linea['tipoPrecio']];
        }
        if ((float) $antes['precio_aplicado'] !== $linea['precio']) {
            $cambios[] = ['precio · ' . $codigo, $antes['precio_aplicado'], $linea['precio']];
        }
    }

    foreach ($anteriores as $codigo => $antes) {
        if (!isset($lineas[$codigo])) {
            $cambios[] = ['pieza eliminada', $antes['nombre_producto'] . ' x' . $antes['cantidad'], null];
        }
    }

    if ((float) $venta['total'] !== $total) {
        $cambios[] = ['total', $venta['total'], number_format($total, 2, '.', '')];
    }

    if (count($cambios) === 0) {
        echo json_encode(['ok' => true, 'venta_id' => $ventaId, 'cambios' => 0,
                          'mensaje' => 'No hubo cambios que guardar']);
        exit;
    }

    // ---------- Guardar ----------
    $pdo->beginTransaction();

    // El estado se recalcula contra el total nuevo. Si el cliente ya pago mas
    // de lo que ahora cuesta la venta (devolvio mercancia), la venta se cierra
    // en 'devolucion' y la diferencia es lo que el negocio le debe.
    if ($cobrado > $total) {
        $estadoPago = 'devolucion';
    } elseif ($cobrado >= $total) {
        $estadoPago = 'pagado';
    } elseif ($cobrado > 0) {
        $estadoPago = 'parcial';
    } else {
        $estadoPago = 'pendiente';
    }

    $stmt = $pdo->prepare(
        'UPDATE ventas
            SET cliente = :cliente, total = :total, tipo_pago = :tipo_pago,
                estado_pago = :estado_pago, fecha_vencimiento = :fecha_vencimiento
          WHERE id = :id'
    );
    $stmt->execute([
        'cliente'           => $clienteNuevo,
        'total'             => $total,
        'tipo_pago'         => $tipoPago,
        'estado_pago'       => $estadoPago,
        'fecha_vencimiento' => $vencNuevo,
        'id'                => $ventaId,
    ]);

    $pdo->prepare('DELETE FROM detalle_venta WHERE venta_id = :id')->execute(['id' => $ventaId]);

    $stmt = $pdo->prepare(
        'INSERT INTO detalle_venta
            (venta_id, casa_id, codigo_interno_producto, nombre_producto, tipo_precio, precio_aplicado, cantidad)
         VALUES (:venta_id, :casa_id, :codigo, :nombre, :tipo_precio, :precio, :cantidad)'
    );

    foreach ($lineas as $linea) {
        $stmt->execute([
            'venta_id'    => $ventaId,
            'casa_id'     => $linea['casa_id'],
            'codigo'      => $linea['codigo'],
            'nombre'      => $linea['nombre'],
            'tipo_precio' => $linea['tipoPrecio'],
            'precio'      => $linea['precio'],
            'cantidad'    => $linea['cantidad'],
        ]);
    }

    $stmt = $pdo->prepare(
        'INSERT INTO auditoria_ventas (venta_id, usuario_id, campo, valor_anterior, valor_nuevo)
         VALUES (:venta_id, :usuario_id, :campo, :anterior, :nuevo)'
    );

    foreach ($cambios as [$campo, $anterior, $nuevo]) {
        $stmt->execute([
            'venta_id'   => $ventaId,
            'usuario_id' => $usuarioId,
            'campo'      => mb_substr($campo, 0, 80),
            'anterior'   => $anterior === null ? null : mb_substr((string) $anterior, 0, 255),
            'nuevo'      => $nuevo === null ? null : mb_substr((string) $nuevo, 0, 255),
        ]);
    }

    $pdo->commit();

    echo json_encode([
        'ok'          => true,
        'venta_id'    => $ventaId,
        'total'       => number_format($total, 2, '.', ''),
        'estado_pago' => $estadoPago,
        'cobrado'     => number_format($cobrado, 2, '.', ''),
        'saldo'       => number_format(max($total - $cobrado, 0), 2, '.', ''),
        'devolucion'  => number_format(max($cobrado - $total, 0), 2, '.', ''),
        'cambios'     => count($cambios),
    ]);

} catch (RuntimeException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);

} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log($e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'No se pudo guardar la edicion']);
}
