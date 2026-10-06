<?php
/**
 * Ganancia real del periodo (seccion nueva del Dashboard).
 *
 * El negocio vende cada producto a su NETO, que es el precio bruto (mayoreo) mas
 * un porcentaje: neto = bruto * (1 + % / 100). Lo que de verdad se gana por pieza
 * es ese porcentaje, es decir  neto - bruto. Ejemplo del dueno: un producto de
 * $599 con 13% se vende en $676.87 y la ganancia es $77.87.
 *
 * En la venta solo queda congelado el neto (detalle_venta.precio_aplicado y su
 * subtotal); el bruto no se guarda. Por eso la ganancia se reconstruye desde el
 * neto con el porcentaje efectivo de cada producto:
 *
 *     ganancia = neto - neto / (1 + % / 100)        (= neto * % / (100 + %))
 *     costo    = neto - ganancia
 *
 * El porcentaje efectivo es el del producto (columna porcentaje_neto de su tabla)
 * si lo tiene, o el de su casa en caso contrario (ver app/helpers/casas.php). Es
 * el porcentaje VIGENTE: si alguna vez se cambio, la ganancia de ventas viejas
 * queda estimada con el de hoy.
 *
 * Este controlador NO toca nada del tablero existente: es un endpoint aparte que
 * el Dashboard consulta para pintar la seccion de ganancias. Respeta el mismo
 * periodo y el mismo filtro por casa que EstadisticasController.
 *
 *   GET ?desde=YYYY-MM-DD&hasta=YYYY-MM-DD&rango=hoy|semana|mes|anio|personalizado&casa=CODIGO|TODAS
 *
 * Solo para el administrador: el margen del negocio no se le muestra al vendedor.
 */
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Sesion expirada']);
    exit;
}

// La ganancia (margen) es informacion del negocio; queda reservada al admin,
// igual que las casas, los vendedores y los cambios de precio.
if (($_SESSION['user_role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Solo un administrador puede ver las ganancias']);
    exit;
}

require_once __DIR__ . '/../../config/conexionBD.php';
require_once __DIR__ . '/../helpers/casas.php';

/** Cuantos productos trae la tabla de "mas ganancia". */
const GAN_TOP_PRODUCTOS = 10;

// ---------- Periodo (mismas reglas que EstadisticasController) ----------
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

if ($dias === 1) {
    $agrupacion = 'hora';
} elseif ($dias <= 45) {
    $agrupacion = 'dia';
} elseif ($dias <= 190) {
    $agrupacion = 'semana';
} else {
    $agrupacion = 'mes';
}

/** Periodo anterior comparable (igual criterio que el tablero). */
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

$inicio    = $fDesde->format('Y-m-d 00:00:00');
$fin       = (clone $fHasta)->modify('+1 day')->format('Y-m-d 00:00:00');
$antInicio = $fAntDesde->format('Y-m-d 00:00:00');
$antFin    = (clone $fAntHasta)->modify('+1 day')->format('Y-m-d 00:00:00');

/** Expresion para agrupar una fecha en cubetas (lista fija, nunca texto libre). */
function cubeta(string $columna, string $agrupacion): string
{
    switch ($agrupacion) {
        case 'hora':   return "DATE_FORMAT({$columna}, '%Y-%m-%d %H')";
        case 'dia':    return "DATE_FORMAT({$columna}, '%Y-%m-%d')";
        case 'semana': return "DATE_FORMAT(DATE_SUB(DATE({$columna}), INTERVAL WEEKDAY({$columna}) DAY), '%Y-%m-%d')";
        default:       return "DATE_FORMAT({$columna}, '%Y-%m')";
    }
}

/** Todas las cubetas del periodo en orden, para mostrar en cero las vacias. */
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

/** Ganancia que deja un neto dado su porcentaje: neto * % / (100 + %). */
function gananciaDeNeto(float $neto, float $porcentaje): float
{
    if ($porcentaje <= 0) {
        return 0.0;
    }
    return $neto - $neto / (1 + $porcentaje / 100);
}

try {
    $pdo = Database::getConnection();

    // Filtro por casa (igual que el tablero): el admin puede acotar a una casa.
    $codigoCasa = $_GET['casa'] ?? 'TODAS';
    $casaId = null;

    if ($codigoCasa !== 'TODAS') {
        foreach (casasRegistradas() as $c) {
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

    $paramsVenta = function (string $ini, string $fin) use ($casaId): array {
        $p = ['inicio' => $ini, 'fin' => $fin];
        if ($casaId !== null) {
            $p['casa'] = $casaId;
        }
        return $p;
    };

    // ---------- Neto vendido por producto en un periodo ----------
    // El neto sale del detalle (asi el filtro por casa cuadra con los totales).
    // Mas adelante, en PHP, a cada producto se le aplica su porcentaje efectivo
    // para sacar la ganancia.
    $sqlProductos =
        "SELECT d.codigo_interno_producto AS codigo,
                c.codigo_casa,
                MAX(d.nombre_producto) AS nombre,
                SUM(d.subtotal)  AS neto,
                SUM(d.cantidad)  AS piezas,
                COUNT(DISTINCT d.venta_id) AS ventas
           FROM detalle_venta d
           JOIN ventas v ON v.id = d.venta_id
           JOIN casas  c ON c.id = d.casa_id
          WHERE v.fecha >= :inicio AND v.fecha < :fin AND v.eliminada_en IS NULL{$filtroCasaDetalle}
          GROUP BY d.codigo_interno_producto, c.codigo_casa";

    $stmtProd = $pdo->prepare($sqlProductos);

    $stmtProd->execute($paramsVenta($inicio, $fin));
    $filasAct = $stmtProd->fetchAll(PDO::FETCH_ASSOC);

    $stmtProd->execute($paramsVenta($antInicio, $antFin));
    $filasAnt = $stmtProd->fetchAll(PDO::FETCH_ASSOC);

    // Porcentaje efectivo de cada producto: su override si lo trae, si no el de la
    // casa. Se lee el override del catalogo una sola vez para los dos periodos.
    $codigos = array_values(array_unique(array_merge(
        array_column($filasAct, 'codigo'),
        array_column($filasAnt, 'codigo')
    )));

    $catalogo = $codigos ? catalogoDeProductos($pdo, $codigos, ['porcentaje_neto', 'codigo_proveedor']) : [];

    $porcentajeDe = function (string $codigo, ?string $codigoCasa) use ($catalogo): float {
        $override = $catalogo[$codigo]['porcentaje_neto'] ?? null;
        return porcentajeProducto($override, $codigoCasa);
    };

    // Suma ganancia / neto / costo de una lista de filas (periodo completo).
    $totales = function (array $filas) use ($porcentajeDe): array {
        $neto = 0.0;
        $ganancia = 0.0;
        foreach ($filas as $f) {
            $n = (float) $f['neto'];
            $neto += $n;
            $ganancia += gananciaDeNeto($n, $porcentajeDe($f['codigo'], $f['codigo_casa']));
        }
        return [
            'neto'     => round($neto, 2),
            'costo'    => round($neto - $ganancia, 2),
            'ganancia' => round($ganancia, 2),
            'margen'   => $neto > 0 ? round($ganancia / $neto * 100, 1) : 0.0,
        ];
    };

    $resumen  = $totales($filasAct);
    $anterior = $totales($filasAnt);

    // ---------- Ganancia por producto (tabla y top) ----------
    $productos = [];
    foreach ($filasAct as $f) {
        $neto = (float) $f['neto'];
        $porc = $porcentajeDe($f['codigo'], $f['codigo_casa']);
        $gan  = gananciaDeNeto($neto, $porc);

        $productos[] = [
            'codigo'           => $f['codigo'],
            'codigo_proveedor' => $catalogo[$f['codigo']]['codigo_proveedor'] ?? null,
            'nombre'           => $f['nombre'],
            'codigo_casa'      => $f['codigo_casa'],
            'casa'             => etiquetaCasa($f['codigo_casa']),
            'porcentaje'       => round($porc, 2),
            'piezas'           => (int) $f['piezas'],
            'ventas'           => (int) $f['ventas'],
            'neto'             => round($neto, 2),
            'costo'            => round($neto - $gan, 2),
            'ganancia'         => round($gan, 2),
        ];
    }

    // Top por ganancia (de mayor a menor).
    usort($productos, function ($a, $b) {
        return $b['ganancia'] <=> $a['ganancia'];
    });
    $topProductos = array_slice($productos, 0, GAN_TOP_PRODUCTOS);

    // ---------- Ganancia por casa ----------
    $porCasa = [];
    foreach ($filasAct as $f) {
        $cod = $f['codigo_casa'];
        if (!isset($porCasa[$cod])) {
            $porCasa[$cod] = ['codigo_casa' => $cod, 'casa' => etiquetaCasa($cod), 'neto' => 0.0, 'ganancia' => 0.0];
        }
        $n = (float) $f['neto'];
        $porCasa[$cod]['neto']     += $n;
        $porCasa[$cod]['ganancia'] += gananciaDeNeto($n, $porcentajeDe($f['codigo'], $cod));
    }

    $porCasa = array_values($porCasa);
    foreach ($porCasa as &$c) {
        $c['costo']    = round($c['neto'] - $c['ganancia'], 2);
        $c['margen']   = $c['neto'] > 0 ? round($c['ganancia'] / $c['neto'] * 100, 1) : 0.0;
        $c['neto']     = round($c['neto'], 2);
        $c['ganancia'] = round($c['ganancia'], 2);
    }
    unset($c);
    usort($porCasa, function ($a, $b) {
        return $b['ganancia'] <=> $a['ganancia'];
    });

    // ---------- Ganancia en el tiempo ----------
    // El neto por cubeta y producto; en PHP se aplica el porcentaje de cada uno.
    $expr = cubeta('v.fecha', $agrupacion);
    $stmt = $pdo->prepare(
        "SELECT {$expr} AS clave,
                d.codigo_interno_producto AS codigo,
                c.codigo_casa,
                SUM(d.subtotal) AS neto
           FROM detalle_venta d
           JOIN ventas v ON v.id = d.venta_id
           JOIN casas  c ON c.id = d.casa_id
          WHERE v.fecha >= :inicio AND v.fecha < :fin AND v.eliminada_en IS NULL{$filtroCasaDetalle}
          GROUP BY clave, d.codigo_interno_producto, c.codigo_casa"
    );
    $stmt->execute($paramsVenta($inicio, $fin));

    $acum = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $fila) {
        $clave = $fila['clave'];
        $n = (float) $fila['neto'];
        if (!isset($acum[$clave])) {
            $acum[$clave] = ['neto' => 0.0, 'ganancia' => 0.0];
        }
        $acum[$clave]['neto']     += $n;
        $acum[$clave]['ganancia'] += gananciaDeNeto($n, $porcentajeDe($fila['codigo'], $fila['codigo_casa']));
    }

    $serie = [];
    foreach (cubetasDelPeriodo($fDesde, $fHasta, $agrupacion) as $clave) {
        $serie[] = [
            'clave'    => $clave,
            'neto'     => isset($acum[$clave]) ? round($acum[$clave]['neto'], 2) : 0,
            'ganancia' => isset($acum[$clave]) ? round($acum[$clave]['ganancia'], 2) : 0,
        ];
    }

    echo json_encode([
        'ok'         => true,
        'desde'      => $fDesde->format('Y-m-d'),
        'hasta'      => $fHasta->format('Y-m-d'),
        'agrupacion' => $agrupacion,
        'casa'       => $casaId !== null ? $codigoCasa : 'TODAS',
        'resumen'    => $resumen,
        'anterior'   => [
            'desde'    => $fAntDesde->format('Y-m-d'),
            'hasta'    => $fAntHasta->format('Y-m-d'),
            'neto'     => $anterior['neto'],
            'costo'    => $anterior['costo'],
            'ganancia' => $anterior['ganancia'],
        ],
        'serie'         => $serie,
        'top_productos' => $topProductos,
        'por_casa'      => $porCasa,
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    error_log($e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Error al consultar las ganancias']);
}
