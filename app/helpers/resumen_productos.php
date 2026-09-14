<?php
/**
 * Resumen de productos vendidos en un periodo.
 *
 * Junta todas las ventas del filtro y suma por producto, para responder "que se
 * vendio y cuanto" sin abrir venta por venta. Lo usan dos pantallas: el modal
 * "Ver productos" del historial y la hoja imprimible del mismo resumen, asi que
 * la consulta vive aqui y no dentro de un controlador.
 */

require_once __DIR__ . '/casas.php';

/**
 * @param  int $usuarioId 0 = todos los vendedores.
 * @return array{productos: array, resumen: array}
 */
function resumenProductosVendidos(PDO $pdo, string $desde, string $hasta, int $usuarioId = 0): array
{
    $condicionUsuario = $usuarioId > 0 ? ' AND v.usuario_id = :usuario' : '';

    $stmt = $pdo->prepare(
        'SELECT c.nombre AS casa, c.codigo_casa,
                d.codigo_interno_producto,
                MAX(d.nombre_producto) AS nombre_producto,
                SUM(d.cantidad)  AS piezas,
                SUM(d.subtotal)  AS importe,
                COUNT(DISTINCT d.venta_id) AS ventas
           FROM detalle_venta d
           JOIN ventas v  ON v.id = d.venta_id
           JOIN casas c   ON c.id = d.casa_id
          WHERE DATE(v.fecha) BETWEEN :desde AND :hasta' . $condicionUsuario . '
          GROUP BY c.nombre, c.codigo_casa, d.codigo_interno_producto
          ORDER BY piezas DESC, importe DESC'
    );

    $parametros = ['desde' => $desde, 'hasta' => $hasta];

    if ($usuarioId > 0) {
        $parametros['usuario'] = $usuarioId;
    }

    $stmt->execute($parametros);
    $productos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // El detalle de la venta guarda el codigo interno; en pantalla y en papel se
    // ocupa el del proveedor.
    $catalogo = catalogoDeProductos(
        $pdo, array_column($productos, 'codigo_interno_producto'), ['codigo_proveedor']
    );

    foreach ($productos as &$p) {
        $p['casa']             = etiquetaCasa($p['codigo_casa']);
        $p['codigo_proveedor'] = $catalogo[$p['codigo_interno_producto']]['codigo_proveedor'] ?? null;
    }
    unset($p);

    $totalPiezas  = 0;
    $totalImporte = 0.0;
    $porCasa      = [];

    foreach ($productos as $p) {
        $totalPiezas  += (int) $p['piezas'];
        $totalImporte += (float) $p['importe'];

        $clave = $p['codigo_casa'];

        if (!isset($porCasa[$clave])) {
            $porCasa[$clave] = [
                'codigo_casa' => $clave,
                'casa'        => $p['casa'],
                'productos'   => 0,
                'piezas'      => 0,
                'importe'     => 0.0,
            ];
        }

        $porCasa[$clave]['productos']++;
        $porCasa[$clave]['piezas']  += (int) $p['piezas'];
        $porCasa[$clave]['importe'] += (float) $p['importe'];
    }

    // Las casas se ordenan por lo que mas se vendio.
    usort($porCasa, fn($a, $b) => $b['piezas'] <=> $a['piezas']);

    return [
        'productos' => $productos,
        'resumen'   => [
            'distintos' => count($productos),
            'piezas'    => $totalPiezas,
            'importe'   => number_format($totalImporte, 2, '.', ''),
            'por_casa'  => array_values($porCasa),
        ],
    ];
}

/** Los productos de una casa, en el orden en que ya vienen (mas vendidos primero). */
function productosDeCasa(array $productos, string $codigoCasa): array
{
    return array_values(array_filter(
        $productos,
        fn($p) => $p['codigo_casa'] === $codigoCasa
    ));
}
