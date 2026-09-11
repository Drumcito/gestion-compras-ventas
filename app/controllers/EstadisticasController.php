<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Sesion expirada']);
    exit;
}

require_once __DIR__ . '/../../config/conexionBD.php';

$tablasCasa = [
    'BNS01' => 'productos_casa1',
    'BNS02' => 'productos_casa2',
    'BNS03' => 'productos_casa3',
    'BNS04' => 'productos_casa4',
];

// ---------- Periodo ----------
$desde = $_GET['desde'] ?? date('Y-m-d');
$hasta = $_GET['hasta'] ?? $desde;
$rango = $_GET['rango'] ?? 'personalizado';

$fDesde = DateTime::createFromFormat('!Y-m-d', $desde);
$fHasta = DateTime::createFromFormat('!Y-m-d', $hasta);

if (!$fDesde || !$fHasta || $fDesde->format('Y-m-d') !== $desde || $fHasta->format('Y-m-d') !== $hasta) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Fechas no validas']);
    exit;
}

if ($fDesde > $fHasta) {
    [$fDesde, $fHasta] = [$fHasta, $fDesde];
}

$dias = (int) $fDesde->diff($fHasta)->days + 1;

// La agrupacion de las graficas de tiempo sale del largo del periodo: un dia
// se ve por hora, un mes por dia, un año por mes.
if ($dias === 1) {
    $agrupacion = 'hora';
} elseif ($dias <= 45) {
    $agrupacion = 'dia';
} elseif ($dias <= 190) {
    $agrupacion = 'semana';
} else {
    $agrupacion = 'mes';
}

/**
 * Periodo contra el que se comparan los totales. Los rangos rapidos se comparan
 * contra "el mismo tramo" del periodo anterior (este mes del 1 al 11 contra el
 * mes pasado del 1 al 11); un rango a mano, contra los mismos dias justo antes.
 */
function periodoAnterior(DateTime $desde, DateTime $hasta, string $rango, int $dias): array
{
    $mover = function (DateTime $fecha, int $meses): DateTime {
        $dia = (int) $fecha->format('d');
        $destino = (clone $fecha)->modify('first day of this month')->modify(($meses >= 0 ? '+' : '') . $meses . ' month');
        $ultimo = (int) $destino->format('t');
        return $destino->setDate((int) $destino->format('Y'), (int) $destino->format('m'), min($dia, $ultimo));
    };

    if ($rango === 'semana') {
        return [(clone $desde)->modify('-7 days'), (clone $hasta)->modify('-7 days')];
    }
    if ($rango === 'mes') {
        return [$mover($desde, -1), $mover($hasta, -1)];
    }
    if ($rango === 'anio') {
        return [$mover($desde, -12), $mover($hasta, -12)];
    }

    return [(clone $desde)->modify("-{$dias} days"), (clone $desde)->modify('-1 day')];
}

[$fAntDesde, $fAntHasta] = periodoAnterior($fDesde, $fHasta, $rango, $dias);

// Rango semiabierto [inicio, fin) para que MySQL pueda usar el indice de fecha.
$inicio    = $fDesde->format('Y-m-d 00:00:00');
$fin       = (clone $fHasta)->modify('+1 day')->format('Y-m-d 00:00:00');
$antInicio = $fAntDesde->format('Y-m-d 00:00:00');
$antFin    = (clone $fAntHasta)->modify('+1 day')->format('Y-m-d 00:00:00');

/**
 * Expresion SQL para agrupar una columna de fecha. Solo se arma con la lista
 * fija de abajo, nunca con texto del navegador.
 */
function cubeta(string $columna, string $agrupacion): string
{
    switch ($agrupacion) {
        case 'hora':   return "DATE_FORMAT({$columna}, '%Y-%m-%d %H')";
        case 'dia':    return "DATE_FORMAT({$columna}, '%Y-%m-%d')";
        case 'semana': return "DATE_FORMAT(DATE_SUB(DATE({$columna}), INTERVAL WEEKDAY({$columna}) DAY), '%Y-%m-%d')";
        default:       return "DATE_FORMAT({$columna}, '%Y-%m')";
    }
}

/**
 * Todas las cubetas del periodo, en orden, para que la grafica muestre en cero
 * los dias (u horas) sin movimiento en lugar de saltárselos.
 */
function cubetasDelPeriodo(DateTime $desde, DateTime $hasta, string $agrupacion): array
{
    $claves = [];

    if ($agrupacion === 'hora') {
        for ($h = 0; $h < 24; $h++) {
            $claves[] = $desde->format('Y-m-d') . ' ' . str_pad((string) $h, 2, '0', STR_PAD_LEFT);
        }
        return $claves;
    }

    if ($agrupacion === 'dia') {
        for ($f = clone $desde; $f <= $hasta; $f->modify('+1 day')) {
            $claves[] = $f->format('Y-m-d');
        }
        return $claves;
    }

    if ($agrupacion === 'semana') {
        $lunes = (clone $desde)->modify('-' . ((int) $desde->format('N') - 1) . ' days');
        for ($f = $lunes; $f <= $hasta; $f->modify('+7 days')) {
            $claves[] = $f->format('Y-m-d');
        }
        return $claves;
    }

    $limite = $hasta->format('Y-m');
    for ($f = (clone $desde)->modify('first day of this month'); $f->format('Y-m') <= $limite; $f->modify('+1 month')) {
        $claves[] = $f->format('Y-m');
    }
    return $claves;
}

$aNumero = function (array $filas, array $campos): array {
    foreach ($filas as &$fila) {
        foreach ($campos as $campo) {
            $fila[$campo] = $fila[$campo] === null ? 0 : (float) $fila[$campo];
        }
    }
    unset($fila);
    return $filas;
};

try {
    $pdo = Database::getConnection();

    $casas = $pdo->query('SELECT id, codigo_casa, nombre FROM casas WHERE activo = 1 ORDER BY codigo_casa')
        ->fetchAll(PDO::FETCH_ASSOC);

    // Filtro opcional por casa: aplica a todo lo que sale del detalle de venta
    // (importe, piezas, productos) y a los cambios de precio.
    $codigoCasa = $_GET['casa'] ?? 'TODAS';
    $casaId = null;

    if ($codigoCasa !== 'TODAS') {
        foreach ($casas as $c) {
            if ($c['codigo_casa'] === $codigoCasa) {
                $casaId = (int) $c['id'];
            }
        }
        if ($casaId === null) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Casa no valida']);
            exit;
        }
    }

    $filtroCasaDetalle = $casaId !== null ? ' AND d.casa_id = :casa' : '';
    $filtroCasaPrecio  = $casaId !== null ? ' AND hp.casa_id = :casa' : '';

    $parametros = function (string $ini, string $fin) use ($casaId): array {
        $p = ['inicio' => $ini, 'fin' => $fin];
        if ($casaId !== null) {
            $p['casa'] = $casaId;
        }
        return $p;
    };

    // ---------- Resumen (periodo actual y anterior) ----------
    // Todo sale del detalle, asi el filtro por casa cuadra con los totales:
    // sin filtro, la suma de los subtotales es el total de las ventas.
    $stmtResumen = $pdo->prepare(
        "SELECT COUNT(DISTINCT v.id) AS ventas,
                COALESCE(SUM(d.subtotal), 0) AS total,
                COALESCE(SUM(d.cantidad), 0) AS piezas,
                COALESCE(SUM(CASE WHEN v.tipo_pago = 'contado' THEN d.subtotal END), 0) AS contado,
                COALESCE(SUM(CASE WHEN v.tipo_pago = 'credito' THEN d.subtotal END), 0) AS credito,
                COUNT(DISTINCT CASE WHEN v.tipo_pago = 'credito' THEN v.id END) AS ventas_credito
           FROM ventas v
           JOIN detalle_venta d ON d.venta_id = v.id
          WHERE v.fecha >= :inicio AND v.fecha < :fin{$filtroCasaDetalle}"
    );

    $stmtResumen->execute($parametros($inicio, $fin));
    $resumen = $stmtResumen->fetch(PDO::FETCH_ASSOC);

    $stmtResumen->execute($parametros($antInicio, $antFin));
    $anterior = $stmtResumen->fetch(PDO::FETCH_ASSOC);

    // Lo que falta por cobrar es de la venta completa, no de una casa.
    $stmt = $pdo->prepare(
        "SELECT COALESCE(SUM(saldo_pendiente), 0) AS por_cobrar,
                COUNT(CASE WHEN saldo_pendiente > 0 THEN 1 END) AS ventas_con_saldo
           FROM vista_estado_ventas
          WHERE tipo_pago = 'credito' AND fecha >= :inicio AND fecha < :fin"
    );
    $stmt->execute(['inicio' => $inicio, 'fin' => $fin]);
    $cobranza = $stmt->fetch(PDO::FETCH_ASSOC);

    // ---------- Ventas en el tiempo ----------
    $expr = cubeta('v.fecha', $agrupacion);
    $stmt = $pdo->prepare(
        "SELECT {$expr} AS clave,
                SUM(d.subtotal) AS total,
                COUNT(DISTINCT v.id) AS ventas,
                SUM(d.cantidad) AS piezas
           FROM ventas v
           JOIN detalle_venta d ON d.venta_id = v.id
          WHERE v.fecha >= :inicio AND v.fecha < :fin{$filtroCasaDetalle}
          GROUP BY clave
          ORDER BY clave"
    );
    $stmt->execute($parametros($inicio, $fin));
    $porClave = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $fila) {
        $porClave[$fila['clave']] = $fila;
    }

    $serieVentas = [];
    foreach (cubetasDelPeriodo($fDesde, $fHasta, $agrupacion) as $clave) {
        $serieVentas[] = [
            'clave'  => $clave,
            'total'  => isset($porClave[$clave]) ? (float) $porClave[$clave]['total'] : 0,
            'ventas' => isset($porClave[$clave]) ? (int) $porClave[$clave]['ventas'] : 0,
            'piezas' => isset($porClave[$clave]) ? (int) $porClave[$clave]['piezas'] : 0,
        ];
    }

    // ---------- Productos mas vendidos ----------
    $consultaTop = "SELECT d.codigo_interno_producto AS codigo,
                           MAX(d.nombre_producto) AS nombre,
                           c.codigo_casa, c.nombre AS casa,
                           SUM(d.cantidad) AS piezas,
                           SUM(d.subtotal) AS importe,
                           COUNT(DISTINCT d.venta_id) AS ventas
                      FROM detalle_venta d
                      JOIN ventas v ON v.id = d.venta_id
                      JOIN casas c ON c.id = d.casa_id
                     WHERE v.fecha >= :inicio AND v.fecha < :fin{$filtroCasaDetalle}
                     GROUP BY d.codigo_interno_producto, c.codigo_casa, c.nombre";

    $stmt = $pdo->prepare($consultaTop . ' ORDER BY piezas DESC, importe DESC LIMIT 10');
    $stmt->execute($parametros($inicio, $fin));
    $topPiezas = $aNumero($stmt->fetchAll(PDO::FETCH_ASSOC), ['piezas', 'importe', 'ventas']);

    $stmt = $pdo->prepare($consultaTop . ' ORDER BY importe DESC, piezas DESC LIMIT 10');
    $stmt->execute($parametros($inicio, $fin));
    $topImporte = $aNumero($stmt->fetchAll(PDO::FETCH_ASSOC), ['piezas', 'importe', 'ventas']);

    // ---------- Ventas por casa (siempre todas, para comparar) ----------
    $stmt = $pdo->prepare(
        "SELECT c.codigo_casa, c.nombre,
                COALESCE(t.piezas, 0) AS piezas,
                COALESCE(t.importe, 0) AS importe,
                COALESCE(t.ventas, 0) AS ventas,
                COALESCE(t.productos, 0) AS productos
           FROM casas c
           LEFT JOIN (
                SELECT d.casa_id,
                       SUM(d.cantidad) AS piezas,
                       SUM(d.subtotal) AS importe,
                       COUNT(DISTINCT d.venta_id) AS ventas,
                       COUNT(DISTINCT d.codigo_interno_producto) AS productos
                  FROM detalle_venta d
                  JOIN ventas v ON v.id = d.venta_id
                 WHERE v.fecha >= :inicio AND v.fecha < :fin
                 GROUP BY d.casa_id
           ) t ON t.casa_id = c.id
          WHERE c.activo = 1
          ORDER BY c.codigo_casa"
    );
    $stmt->execute(['inicio' => $inicio, 'fin' => $fin]);
    $porCasa = $aNumero($stmt->fetchAll(PDO::FETCH_ASSOC), ['piezas', 'importe', 'ventas', 'productos']);

    // ---------- Cambios de precio ----------
    // Solo cuentan los cambios reales: de un precio a otro distinto. Cuando un
    // producto no tenia precio y se le pone uno, es "nuevo", no una subida.
    $cambioReal = 'hp.precio_anterior IS NOT NULL AND hp.precio_nuevo IS NOT NULL
                   AND hp.precio_nuevo <> hp.precio_anterior';

    $stmt = $pdo->prepare(
        "SELECT COALESCE(SUM(hp.precio_nuevo > hp.precio_anterior), 0) AS subidas,
                COALESCE(SUM(hp.precio_nuevo < hp.precio_anterior), 0) AS bajadas,
                COUNT(DISTINCT hp.codigo_interno_producto) AS productos
           FROM historial_precios hp
          WHERE hp.fecha_cambio >= :inicio AND hp.fecha_cambio < :fin
            AND {$cambioReal}{$filtroCasaPrecio}"
    );
    $stmt->execute($parametros($inicio, $fin));
    $resumenPrecios = $stmt->fetch(PDO::FETCH_ASSOC);

    $exprPrecio = cubeta('hp.fecha_cambio', $agrupacion);
    $stmt = $pdo->prepare(
        "SELECT {$exprPrecio} AS clave,
                SUM(hp.precio_nuevo > hp.precio_anterior) AS subidas,
                SUM(hp.precio_nuevo < hp.precio_anterior) AS bajadas
           FROM historial_precios hp
          WHERE hp.fecha_cambio >= :inicio AND hp.fecha_cambio < :fin
            AND {$cambioReal}{$filtroCasaPrecio}
          GROUP BY clave
          ORDER BY clave"
    );
    $stmt->execute($parametros($inicio, $fin));
    $preciosPorClave = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $fila) {
        $preciosPorClave[$fila['clave']] = $fila;
    }

    $seriePrecios = [];
    foreach (cubetasDelPeriodo($fDesde, $fHasta, $agrupacion) as $clave) {
        $seriePrecios[] = [
            'clave'   => $clave,
            'subidas' => isset($preciosPorClave[$clave]) ? (int) $preciosPorClave[$clave]['subidas'] : 0,
            'bajadas' => isset($preciosPorClave[$clave]) ? (int) $preciosPorClave[$clave]['bajadas'] : 0,
        ];
    }

    $stmt = $pdo->prepare(
        "SELECT hp.codigo_interno_producto AS codigo,
                c.codigo_casa, c.nombre AS casa,
                SUM(hp.precio_nuevo > hp.precio_anterior) AS subidas,
                SUM(hp.precio_nuevo < hp.precio_anterior) AS bajadas,
                COUNT(*) AS cambios,
                MAX(hp.fecha_cambio) AS ultimo_cambio
           FROM historial_precios hp
           JOIN casas c ON c.id = hp.casa_id
          WHERE hp.fecha_cambio >= :inicio AND hp.fecha_cambio < :fin
            AND {$cambioReal}{$filtroCasaPrecio}
          GROUP BY hp.codigo_interno_producto, c.codigo_casa, c.nombre
          ORDER BY cambios DESC, ultimo_cambio DESC
          LIMIT 10"
    );
    $stmt->execute($parametros($inicio, $fin));
    $topPrecios = $aNumero($stmt->fetchAll(PDO::FETCH_ASSOC), ['subidas', 'bajadas', 'cambios']);

    if ($topPrecios) {
        $codigos = array_column($topPrecios, 'codigo');

        // Nombre y precio vigente, de la tabla de su casa (el prefijo del
        // codigo dice cual es; se valida contra la lista fija).
        $porTabla = [];
        foreach ($codigos as $codigo) {
            $prefijo = strtoupper(substr($codigo, 0, 5));
            if (isset($tablasCasa[$prefijo])) {
                $porTabla[$tablasCasa[$prefijo]][] = $codigo;
            }
        }

        $catalogo = [];
        foreach ($porTabla as $tabla => $lista) {
            $marcas = implode(',', array_fill(0, count($lista), '?'));
            $stmt = $pdo->prepare(
                "SELECT codigo_interno, nombre, precio_menudeo, precio_mayoreo
                   FROM {$tabla} WHERE codigo_interno IN ({$marcas})"
            );
            $stmt->execute($lista);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $p) {
                $catalogo[$p['codigo_interno']] = $p;
            }
        }

        // Precio al empezar el periodo y al terminarlo, por tipo, para sacar
        // cuanto se movio en total (no solo cuantas veces).
        $marcas = implode(',', array_fill(0, count($codigos), '?'));
        $stmt = $pdo->prepare(
            "SELECT hp.codigo_interno_producto AS codigo, hp.tipo_precio,
                    hp.precio_anterior, hp.precio_nuevo
               FROM historial_precios hp
              WHERE hp.codigo_interno_producto IN ({$marcas})
                AND hp.fecha_cambio >= ? AND hp.fecha_cambio < ?
                AND {$cambioReal}
              ORDER BY hp.fecha_cambio, hp.id"
        );
        $stmt->execute(array_merge($codigos, [$inicio, $fin]));

        $extremos = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $fila) {
            $k = $fila['codigo'] . '|' . $fila['tipo_precio'];
            if (!isset($extremos[$k])) {
                $extremos[$k] = ['inicial' => (float) $fila['precio_anterior']];
            }
            $extremos[$k]['final'] = (float) $fila['precio_nuevo'];
        }

        foreach ($topPrecios as &$fila) {
            $p = $catalogo[$fila['codigo']] ?? null;
            $fila['nombre']         = $p['nombre'] ?? $fila['codigo'];
            $fila['precio_menudeo'] = $p && $p['precio_menudeo'] !== null ? (float) $p['precio_menudeo'] : null;
            $fila['precio_mayoreo'] = $p && $p['precio_mayoreo'] !== null ? (float) $p['precio_mayoreo'] : null;

            // Se reporta el menudeo; si ese no cambio en el periodo, el mayoreo.
            $fila['variacion'] = null;
            foreach (['menudeo', 'mayoreo'] as $tipo) {
                $e = $extremos[$fila['codigo'] . '|' . $tipo] ?? null;
                if ($e && $e['inicial'] > 0) {
                    $fila['variacion'] = [
                        'tipo'    => $tipo,
                        'inicial' => $e['inicial'],
                        'final'   => $e['final'],
                        'porcentaje' => round(($e['final'] - $e['inicial']) / $e['inicial'] * 100, 1),
                    ];
                    break;
                }
            }
        }
        unset($fila);
    }

    // ---------- Mejores clientes ----------
    $stmt = $pdo->prepare(
        "SELECT TRIM(v.cliente) AS nombre_cliente,
                COUNT(DISTINCT v.id) AS compras,
                SUM(d.subtotal) AS importe,
                SUM(d.cantidad) AS piezas
           FROM ventas v
           JOIN detalle_venta d ON d.venta_id = v.id
          WHERE v.fecha >= :inicio AND v.fecha < :fin
            AND v.cliente IS NOT NULL AND TRIM(v.cliente) <> ''{$filtroCasaDetalle}
          GROUP BY TRIM(v.cliente)
          ORDER BY importe DESC
          LIMIT 8"
    );
    $stmt->execute($parametros($inicio, $fin));
    $clientes = $aNumero($stmt->fetchAll(PDO::FETCH_ASSOC), ['compras', 'importe', 'piezas']);

    // ---------- Vendedores ----------
    $stmt = $pdo->prepare(
        "SELECT u.id,
                CONCAT(u.nombre, ' ', COALESCE(u.apellido, '')) AS nombre,
                u.numero_empleado,
                COUNT(DISTINCT v.id) AS ventas,
                SUM(d.subtotal) AS importe,
                SUM(d.cantidad) AS piezas
           FROM ventas v
           JOIN detalle_venta d ON d.venta_id = v.id
           JOIN usuarios u ON u.id = v.usuario_id
          WHERE v.fecha >= :inicio AND v.fecha < :fin{$filtroCasaDetalle}
          GROUP BY u.id, u.nombre, u.apellido, u.numero_empleado
          ORDER BY importe DESC"
    );
    $stmt->execute($parametros($inicio, $fin));
    $vendedores = $aNumero($stmt->fetchAll(PDO::FETCH_ASSOC), ['ventas', 'importe', 'piezas']);

    echo json_encode([
        'ok'         => true,
        'desde'      => $fDesde->format('Y-m-d'),
        'hasta'      => $fHasta->format('Y-m-d'),
        'agrupacion' => $agrupacion,
        'casa'       => $casaId !== null ? $codigoCasa : 'TODAS',
        'casas'      => array_map(function ($c) {
            return ['codigo_casa' => $c['codigo_casa'], 'nombre' => $c['nombre']];
        }, $casas),
        'resumen' => [
            'total'            => (float) $resumen['total'],
            'ventas'           => (int) $resumen['ventas'],
            'piezas'           => (int) $resumen['piezas'],
            'contado'          => (float) $resumen['contado'],
            'credito'          => (float) $resumen['credito'],
            'ventas_credito'   => (int) $resumen['ventas_credito'],
            'por_cobrar'       => (float) $cobranza['por_cobrar'],
            'ventas_con_saldo' => (int) $cobranza['ventas_con_saldo'],
        ],
        'anterior' => [
            'desde'  => $fAntDesde->format('Y-m-d'),
            'hasta'  => $fAntHasta->format('Y-m-d'),
            'total'  => (float) $anterior['total'],
            'ventas' => (int) $anterior['ventas'],
            'piezas' => (int) $anterior['piezas'],
        ],
        'serie_ventas'   => $serieVentas,
        'top_piezas'     => $topPiezas,
        'top_importe'    => $topImporte,
        'por_casa'       => $porCasa,
        'precios' => [
            'subidas'   => (int) $resumenPrecios['subidas'],
            'bajadas'   => (int) $resumenPrecios['bajadas'],
            'productos' => (int) $resumenPrecios['productos'],
            'serie'     => $seriePrecios,
            'top'       => $topPrecios,
        ],
        'clientes'   => $clientes,
        'vendedores' => $vendedores,
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    error_log($e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Error al consultar las estadisticas']);
}
