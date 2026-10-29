<?php
/**
 * Seleccion de productos para el catalogo PDF: los mas vendidos y los que mas
 * bajaron de precio en los ultimos N dias.
 *
 * Bajadas de precio: la fuente es historial_precios, que llenan solos los
 * triggers de cada tabla productos_casaN cuando cambia el precio bruto (ver
 * migracion 004). Por cada producto se toma el PRIMER cambio dentro de la
 * ventana: su precio_anterior es lo que costaba al arrancar el periodo
 * ("Antes"), y se compara contra el precio vigente en el catalogo ("Ahora").
 * Asi, si un producto bajo y luego volvio a subir, no entra; y si bajo dos
 * veces, cuenta la bajada completa. Los renglones con precio_anterior NULL son
 * altas/cargas iniciales, no bajadas, y se ignoran.
 *
 * Mas vendidos: detalle_venta de las ventas no eliminadas de la ventana. Solo
 * entran productos que siguen activos y con precio: no tiene caso anunciar algo
 * que ya no se vende.
 *
 * Los precios que se imprimen son NETOS (los que paga el cliente), calculados
 * igual que en la venta: bruto + porcentaje del producto o de su casa.
 */

require_once __DIR__ . '/casas.php';

/** Configuracion del catalogo (config/catalogo.php). */
function configCatalogo(): array
{
    static $cfg = null;
    if ($cfg === null) {
        $cfg = require __DIR__ . '/../../config/catalogo.php';
    }
    return $cfg;
}

/** Primer cambio de precio de cada producto dentro de la ventana (MIN(id) = el mas viejo). */
const SQL_PRIMER_CAMBIO =
    "SELECT codigo_interno_producto AS codigo, MIN(id) AS primer_id
       FROM historial_precios
      WHERE fecha_cambio >= :desde_precio
        AND tipo_precio = 'mayoreo'
        AND precio_anterior IS NOT NULL
      GROUP BY codigo_interno_producto";

function inicioVentana(int $dias): string
{
    return (new DateTime())->modify("-{$dias} days")->format('Y-m-d H:i:s');
}

/**
 * Productos con mayor porcentaje de descuento, de mayor a menor.
 *
 * @return array Ver armarProducto().
 */
function obtenerTopOfertas(PDO $pdo, int $dias, int $limite): array
{
    // El descuento se ordena sobre el bruto: es el mismo % que sobre el neto,
    // porque los dos llevan el mismo porcentaje encima.
    $stmt = $pdo->prepare(
        "SELECT v.codigo_interno, v.codigo_casa, v.nombre, v.marca,
                v.precio_mayoreo, v.porcentaje_neto,
                h.precio_anterior
           FROM (" . SQL_PRIMER_CAMBIO . ") p
           JOIN historial_precios h ON h.id = p.primer_id
           JOIN vista_catalogo v    ON v.codigo_interno = p.codigo
          WHERE v.activo = 1
            AND v.precio_mayoreo > 0
            AND v.precio_mayoreo < h.precio_anterior
          ORDER BY (1 - v.precio_mayoreo / h.precio_anterior) DESC,
                   (h.precio_anterior - v.precio_mayoreo) DESC
          LIMIT :limite"
    );
    $stmt->bindValue('desde_precio', inicioVentana($dias));
    $stmt->bindValue('limite', $limite, PDO::PARAM_INT);
    $stmt->execute();

    $ofertas = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $f) {
        $oferta = armarProducto($f, (float) $f['precio_anterior']);
        // Bajadas de centavos dan "-0%": no lucen como oferta.
        if ($oferta['descuento'] >= 1) {
            $oferta['rango'] = count($ofertas) + 1;
            $ofertas[] = $oferta;
        }
    }
    return $ofertas;
}

/**
 * Productos mas vendidos. Si alguno ademas bajo de precio en la misma ventana,
 * trae su "Antes" y su descuento, y el PDF lo presenta como oferta.
 *
 * @param string $criterio 'ventas' (ventas distintas) o 'piezas' (total vendido)
 */
function obtenerMasVendidos(PDO $pdo, int $dias, int $limite, string $criterio = 'ventas'): array
{
    $orden = $criterio === 'piezas'
        ? 't.piezas DESC, t.ventas DESC'
        : 't.ventas DESC, t.piezas DESC';

    $stmt = $pdo->prepare(
        "SELECT v.codigo_interno, v.codigo_casa, v.nombre, v.marca,
                v.precio_mayoreo, v.porcentaje_neto,
                t.ventas, t.piezas, h.precio_anterior
           FROM (SELECT d.codigo_interno_producto AS codigo,
                        COUNT(DISTINCT d.venta_id) AS ventas,
                        SUM(d.cantidad)            AS piezas
                   FROM detalle_venta d
                   JOIN ventas x ON x.id = d.venta_id
                  WHERE x.eliminada_en IS NULL
                    AND x.fecha >= :desde_venta
                  GROUP BY d.codigo_interno_producto) t
           JOIN vista_catalogo v ON v.codigo_interno = t.codigo
      LEFT JOIN (" . SQL_PRIMER_CAMBIO . ") p ON p.codigo = t.codigo
      LEFT JOIN historial_precios h ON h.id = p.primer_id
          WHERE v.activo = 1
            AND v.precio_mayoreo > 0
          ORDER BY {$orden}, v.codigo_interno
          LIMIT :limite"
    );
    $desde = inicioVentana($dias);
    $stmt->bindValue('desde_venta', $desde);
    $stmt->bindValue('desde_precio', $desde);
    $stmt->bindValue('limite', $limite, PDO::PARAM_INT);
    $stmt->execute();

    $vendidos = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $i => $f) {
        $antes = $f['precio_anterior'] === null ? null : (float) $f['precio_anterior'];
        $producto = armarProducto($f, $antes);
        $producto['rango']  = $i + 1;
        $producto['ventas'] = (int) $f['ventas'];
        $producto['piezas'] = (int) $f['piezas'];
        $vendidos[] = $producto;
    }
    return $vendidos;
}

/**
 * Vista previa con productos reales y precios "Antes" INVENTADOS, para revisar
 * el diseno cuando todavia no hay datos. El PDF la marca como muestra; nunca
 * debe repartirse.
 *
 * @param int[] $descuentos % inventado por posicion; 0 = sin descuento
 */
function obtenerMuestra(PDO $pdo, int $limite, int $saltar, array $descuentos): array
{
    $stmt = $pdo->prepare(
        "SELECT codigo_interno, codigo_casa, nombre, marca, precio_mayoreo, porcentaje_neto
           FROM vista_catalogo
          WHERE activo = 1 AND precio_mayoreo BETWEEN 80 AND 3000
          ORDER BY codigo_interno
          LIMIT :limite OFFSET :saltar"
    );
    $stmt->bindValue('limite', $limite, PDO::PARAM_INT);
    $stmt->bindValue('saltar', $saltar, PDO::PARAM_INT);
    $stmt->execute();

    $productos = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $i => $f) {
        $pct = $descuentos[$i % count($descuentos)];
        $antes = $pct > 0 ? (float) $f['precio_mayoreo'] / (1 - $pct / 100) : null;
        $producto = armarProducto($f, $antes);
        $producto['rango'] = $i + 1;
        $productos[] = $producto;
    }
    return $productos;
}

/**
 * Fila del catalogo + bruto anterior -> datos listos para el PDF.
 *
 * $brutoAntes null (o no mayor al actual) = sin oferta: antes null, descuento 0.
 */
function armarProducto(array $f, ?float $brutoAntes): array
{
    $bruto = (float) $f['precio_mayoreo'];
    if ($brutoAntes !== null && $brutoAntes <= $bruto) {
        $brutoAntes = null;
    }

    $porcentaje = porcentajeProducto($f['porcentaje_neto'], $f['codigo_casa']);
    $descuento = $brutoAntes === null
        ? 0
        // Sobre el bruto (el neto ya viene redondeado a centavos y un 40% exacto
        // saldria 39%). Hacia abajo: nunca anunciar mas descuento del real.
        : (int) floor((1 - $bruto / $brutoAntes) * 100 + 1e-6);

    return [
        'codigo'      => $f['codigo_interno'],
        'codigo_casa' => $f['codigo_casa'],
        'nombre'      => $f['nombre'],
        'marca'       => $f['marca'],
        // Una bajada de centavos (<1%) no se anuncia como oferta.
        'antes'       => $descuento >= 1 ? netoDe($brutoAntes, $porcentaje) : null,
        'ahora'       => netoDe($bruto, $porcentaje),
        'descuento'   => $descuento,
        'imagen'      => imagenProducto($f['codigo_interno']),
    ];
}

/**
 * Ruta de la imagen del producto, o null si no tiene. Se busca por codigo
 * interno en la carpeta de config/catalogo.php. El catalogo en linea usa el
 * mismo nombre de archivo, asi una sola imagen sirve para los dos.
 */
function imagenProducto(string $codigoInterno): ?string
{
    $archivo = imagenesDisponibles()[strtoupper($codigoInterno)] ?? null;
    return $archivo === null ? null : configCatalogo()['dir_imagenes'] . '/' . $archivo;
}

/**
 * Imagenes que hay en la carpeta, como [CODIGO => archivo]. Se lee la carpeta
 * una sola vez: el catalogo completo pregunta por ~28 mil productos y un
 * is_file() por cada uno (y por cada extension) seria muy lento.
 */
function imagenesDisponibles(): array
{
    static $mapa = null;
    if ($mapa !== null) {
        return $mapa;
    }

    $mapa = [];
    $dir = configCatalogo()['dir_imagenes'];
    foreach (is_dir($dir) ? scandir($dir) : [] as $archivo) {
        // Solo nombres de codigo validos: nada de rutas raras.
        if (preg_match('/^([A-Za-z0-9_-]+)\.(jpe?g|png)$/i', $archivo, $m)) {
            $mapa[strtoupper($m[1])] = $archivo;
        }
    }
    return $mapa;
}

/**
 * Version de la imagen de un producto (fecha del archivo, en segundos), o 0 si
 * no tiene. Va en la direccion de la imagen: el navegador la guarda un año y al
 * cambiarla la direccion cambia sola.
 */
function versionImagen(string $codigoInterno): int
{
    $ruta = imagenProducto($codigoInterno);
    return $ruta !== null && is_file($ruta) ? (int) filemtime($ruta) : 0;
}

/**
 * Miniatura de 400 px (public/img/productos/400/), o null si no hay: las que se
 * copiaron a mano al servidor solo tienen la grande, y entonces se usa esa.
 */
function miniaturaProducto(string $codigoInterno): ?string
{
    $ruta = configCatalogo()['dir_imagenes'] . '/400/' . strtoupper($codigoInterno) . '.jpg';
    return is_file($ruta) ? $ruta : null;
}

/**
 * Catalogo completo para el sitio publico (carpeta catalogo/): todos los
 * productos activos con precio, con su neto y, si bajo de precio en la ventana
 * de ofertas, su "Antes".
 *
 * NO incluye la casa ni el codigo del proveedor: es lo mismo que se le oculta
 * al vendedor en Inventario, y con mas razon a cualquiera en internet.
 *
 * @return array Renglones compactos (asi pesa menos el JSON):
 *               [codigo, nombre, marca, categoria, ahora, antes|null, descuento, imagen 0/1]
 */
function obtenerCatalogoPublico(PDO $pdo, int $diasOfertas): array
{
    $stmt = $pdo->prepare(
        "SELECT v.codigo_interno, v.codigo_casa, v.codigo_proveedor, v.nombre,
                v.marca, v.categoria, v.precio_mayoreo, v.porcentaje_neto,
                h.precio_anterior
           FROM vista_catalogo v
      LEFT JOIN (" . SQL_PRIMER_CAMBIO . ") p ON p.codigo = v.codigo_interno
      LEFT JOIN historial_precios h ON h.id = p.primer_id
          WHERE v.activo = 1
            AND v.precio_mayoreo > 0
          ORDER BY v.nombre, v.codigo_interno"
    );
    $stmt->bindValue('desde_precio', inicioVentana($diasOfertas));
    $stmt->execute();

    $imagenes = imagenesDisponibles();
    $productos = [];

    while ($f = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $porcentaje = porcentajeProducto($f['porcentaje_neto'], $f['codigo_casa']);
        $bruto = (float) $f['precio_mayoreo'];
        $antes = $f['precio_anterior'] === null ? null : (float) $f['precio_anterior'];

        // Mismo criterio que armarProducto(): solo cuenta una bajada de 1% o mas.
        $descuento = ($antes !== null && $antes > $bruto)
            ? (int) floor((1 - $bruto / $antes) * 100 + 1e-6)
            : 0;

        $productos[] = [
            $f['codigo_interno'],
            $f['nombre'],
            trim((string) $f['marca']),
            trim((string) $f['categoria']),
            netoDe($bruto, $porcentaje),
            $descuento >= 1 ? netoDe($antes, $porcentaje) : null,
            $descuento >= 1 ? $descuento : 0,
            // Version de la imagen (0 = sin imagen): va en su direccion para cache.
            isset($imagenes[strtoupper($f['codigo_interno'])]) ? versionImagen($f['codigo_interno']) : 0,

            // Clave con la que el producto viene en el catalogo de SU casa. Va
            // al final para no mover los indices que ya consume el sitio.
            // Sirve para buscar, no para identificar: la misma clave se repite
            // entre casas (5,219 de ellas), asi que la REF que se muestra sigue
            // siendo el codigo interno, que si es unico.
            trim((string) $f['codigo_proveedor']),
        ];
    }
    return $productos;
}
