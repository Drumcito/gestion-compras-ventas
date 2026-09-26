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
require_once __DIR__ . '/../helpers/casas.php';
require_once __DIR__ . '/../helpers/clientes.php';

// El mapa casa -> tabla sale de la base (ver app/helpers/casas.php), asi que una
// casa creada desde Inventario queda disponible sin tocar codigo.
$tablasCasa = tablasCasa();

$datos = json_decode(file_get_contents('php://input'), true);

$cliente          = trim($datos['cliente'] ?? '');
$clienteId        = (int) ($datos['cliente_id'] ?? 0);

// Casilla "guardar este cliente en el catalogo": solo aplica cuando el nombre se
// tecleo a mano, sin elegir a nadie de la lista.
$guardarCliente   = !empty($datos['guardar_cliente']);
$tipoPago         = $datos['tipo_pago'] ?? 'contado';
$fechaVencimiento = $datos['fecha_vencimiento'] ?? null;
$pagoInicial      = (float) ($datos['pago_inicial'] ?? 0);
$saldoSolicitado  = (float) ($datos['credito_aplicado'] ?? 0);
$items            = $datos['items'] ?? [];

if (!in_array($tipoPago, ['contado', 'credito'], true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Tipo de pago no valido']);
    exit;
}

if (!is_array($items) || count($items) === 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Agrega al menos un producto a la venta']);
    exit;
}

try {
    $pdo = Database::getConnection();

    // Ids de las casas, para guardarlos en cada linea del detalle.
    $casas = $pdo->query('SELECT id, codigo_casa FROM casas')->fetchAll(PDO::FETCH_KEY_PAIR);
    $casaIdPorCodigo = array_flip($casas);

    // Los precios NO se toman de lo que manda el navegador: se releen de la base
    // para que nadie pueda mandar un precio alterado desde el cliente.
    $lineas = [];
    $total  = 0.0;

    foreach ($items as $item) {
        $casa       = $item['casa'] ?? '';
        $codigo     = $item['codigo_interno'] ?? '';
        $cantidad   = (int) ($item['cantidad'] ?? 0);

        if (!isset($tablasCasa[$casa]) || !isset($casaIdPorCodigo[$casa])) {
            throw new RuntimeException('Casa no valida en uno de los productos');
        }
        if ($cantidad < 1) {
            throw new RuntimeException('La cantidad debe ser al menos 1');
        }

        $stmt = $pdo->prepare(
            "SELECT nombre, precio_mayoreo
               FROM {$tablasCasa[$casa]}
              WHERE codigo_interno = :codigo AND activo = 1
              LIMIT 1"
        );
        $stmt->execute(['codigo' => $codigo]);
        $producto = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$producto) {
            throw new RuntimeException("El producto {$codigo} ya no esta disponible");
        }

        // El precio se calcula aqui, no se toma del navegador: el neto es el bruto
        // (mayoreo) mas el porcentaje de la casa, redondeado a 2 decimales.
        $precio = netoDe($producto['precio_mayoreo'], porcentajeCasa($casa));

        if ($precio === null) {
            throw new RuntimeException("{$producto['nombre']} no tiene precio cargado");
        }

        $lineas[] = [
            'casa_id'    => $casaIdPorCodigo[$casa],
            'codigo'     => $codigo,
            'nombre'     => $producto['nombre'],
            'tipoPrecio' => 'neto',
            'precio'     => $precio,
            'cantidad'   => $cantidad,
        ];

        $total += $precio * $cantidad;
    }

    // El cliente del catalogo (opcional). Solo una venta ligada a un cliente
    // acumula y puede gastar saldo a favor; un nombre tecleado a mano no.
    if ($clienteId > 0) {
        $stmt = $pdo->prepare('SELECT id FROM clientes WHERE id = :id');
        $stmt->execute(['id' => $clienteId]);
        if (!$stmt->fetch()) {
            $clienteId = 0;
        }
    }

    // Nombre tecleado + casilla marcada: se da de alta y la venta queda ligada,
    // asi desde la primera compra acumula saldo y sale completa en la nota.
    $clienteNuevo = false;

    if ($clienteId === 0 && $guardarCliente && $cliente !== '') {
        // Si el nombre ya estaba registrado, se liga con ese en vez de duplicar:
        // por eso el aviso lo decide el helper y no esta linea.
        $clienteId = altaRapidaCliente($pdo, $cliente, [], $clienteNuevo);
    }

    // Saldo a favor que se puede aplicar como descuento: nunca mas que lo que el
    // cliente tiene disponible ni mas que el total de la venta. Se recalcula aqui
    // (no se confia del navegador).
    $creditoAplicado = 0.0;
    if ($clienteId > 0 && $saldoSolicitado > 0) {
        $disponible      = saldoFavorCliente($pdo, $clienteId);
        $creditoAplicado = round(min($saldoSolicitado, $disponible, $total), 2);
        if ($creditoAplicado < 0) {
            $creditoAplicado = 0.0;
        }
    }

    // Lo que al cliente le toca pagar en efectivo despues de aplicar su saldo.
    $porPagar = $total - $creditoAplicado;

    if ($tipoPago === 'credito' && $pagoInicial > $porPagar) {
        throw new RuntimeException('El pago inicial no puede ser mayor a lo que queda por pagar');
    }

    $pdo->beginTransaction();

    // En contado paga de una vez lo que resta tras el saldo; en credito solo el
    // abono inicial (los siguientes los suma el trigger de pagos_credito).
    $cobrado = $tipoPago === 'contado' ? $porPagar : $pagoInicial;

    // El estado sale de lo efectivamente cubierto = efectivo + saldo aplicado.
    $efectivo = $cobrado + $creditoAplicado;
    if ($efectivo >= $total) {
        $estadoPago = 'pagado';
    } elseif ($efectivo > 0) {
        $estadoPago = 'parcial';
    } else {
        $estadoPago = 'pendiente';
    }

    $stmt = $pdo->prepare(
        'INSERT INTO ventas (usuario_id, cliente, cliente_id, total, monto_cobrado, credito_aplicado, tipo_pago, estado_pago, fecha_vencimiento)
         VALUES (:usuario_id, :cliente, :cliente_id, :total, :monto_cobrado, :credito_aplicado, :tipo_pago, :estado_pago, :fecha_vencimiento)'
    );
    $stmt->execute([
        'usuario_id'        => $_SESSION['user_id'],
        'cliente'           => $cliente !== '' ? $cliente : null,
        'cliente_id'        => $clienteId > 0 ? $clienteId : null,
        'total'             => $total,
        'monto_cobrado'     => $cobrado,
        'credito_aplicado'  => $creditoAplicado,
        'tipo_pago'         => $tipoPago,
        'estado_pago'       => $estadoPago,
        'fecha_vencimiento' => ($tipoPago === 'credito' && $fechaVencimiento) ? $fechaVencimiento : null,
    ]);

    $ventaId = (int) $pdo->lastInsertId();

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

    // El abono inicial es opcional y puede ser 0; el trigger de pagos_credito
    // recalcula solo el estado de la venta (pendiente / parcial / pagado).
    if ($tipoPago === 'credito' && $pagoInicial > 0) {
        $stmt = $pdo->prepare(
            'INSERT INTO pagos_credito (venta_id, monto, usuario_id, nota)
             VALUES (:venta_id, :monto, :usuario_id, :nota)'
        );
        $stmt->execute([
            'venta_id'   => $ventaId,
            'monto'      => $pagoInicial,
            'usuario_id' => $_SESSION['user_id'],
            'nota'       => 'Pago inicial',
        ]);
    }

    $pdo->commit();

    echo json_encode([
        'ok'               => true,
        'venta_id'         => $ventaId,
        'total'            => number_format($total, 2, '.', ''),
        'credito_aplicado' => number_format($creditoAplicado, 2, '.', ''),
        'cliente_id'       => $clienteId > 0 ? $clienteId : null,
        'cliente_nuevo'    => $clienteNuevo,
        'por_pagar'        => number_format($porPagar, 2, '.', ''),
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
    echo json_encode(['ok' => false, 'error' => 'No se pudo guardar la venta']);
}
