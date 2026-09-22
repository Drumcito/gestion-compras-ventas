<?php
/**
 * Casas (proveedores): etiqueta visible, orden en pantalla y tabla de productos.
 *
 * Cada casa guarda su catalogo en su propia tabla (productos_casa1..N). Antes,
 * saber "que casas hay" dependia de tres datos escritos a mano en el codigo, y
 * el mapa casa -> tabla estaba repetido en 6 controladores: no se podia crear
 * una casa sin editar PHP. Ahora todo eso vive en la tabla `casas` (columnas
 * etiqueta, orden y tabla_productos, ver database/migraciones/002) y aqui solo
 * queda como leerlo.
 *
 * El codigo interno de la casa (BNS01...) NO se toca nunca: de el dependen los
 * codigos de producto (BNS01-00001), el detalle de las ventas ya hechas y el
 * historial de precios.
 */

/**
 * Mapa fijo de respaldo. Solo se usa si la base todavia no tiene las columnas
 * nuevas, para que el sistema siga funcionando mientras se aplica la migracion
 * en lugar de tronar con "Unknown column".
 */
const CASAS_RESPALDO = [
    'BNS02' => ['etiqueta' => 'C1-MC',        'orden' => 1, 'tabla' => 'productos_casa2', 'porcentaje' => 13.0],
    'BNS01' => ['etiqueta' => 'C2-DP',        'orden' => 2, 'tabla' => 'productos_casa1', 'porcentaje' => 13.0],
    'BNS04' => ['etiqueta' => 'C3-CHOLULITA', 'orden' => 3, 'tabla' => 'productos_casa4', 'porcentaje' => 13.0],
    'BNS03' => ['etiqueta' => 'C4-JD',        'orden' => 4, 'tabla' => 'productos_casa3', 'porcentaje' => 12.0],
];

/** Porcentaje que se le suma al bruto para el neto cuando la casa no trae uno. */
const CASAS_PORCENTAJE_DEFECTO = 13.0;

/** Una tabla de productos siempre se llama asi; nada mas se interpola en SQL. */
const CASAS_PATRON_TABLA = '/^productos_casa[0-9]{1,3}$/';

/**
 * Todas las casas, indexadas por codigo interno. Se consulta una vez por
 * petición y queda en memoria: la ocupan casi todas las pantallas.
 *
 * @return array<string, array{id:int, codigo_casa:string, nombre:string,
 *                             etiqueta:string, orden:int, tabla_productos:string,
 *                             activo:int}>
 */
function casasRegistradas(bool $recargar = false): array
{
    static $cache = null;

    if ($cache !== null && !$recargar) {
        return $cache;
    }

    $pdo = Database::getConnection();

    try {
        $filas = $pdo->query(
            'SELECT id, codigo_casa, nombre, etiqueta, orden, tabla_productos, porcentaje_neto, activo FROM casas'
        )->fetchAll(PDO::FETCH_ASSOC);

    } catch (PDOException $e) {
        // Falta alguna migracion (002 o 004): se arma el mapa con los datos de
        // respaldo para que el sistema siga funcionando mientras se aplica.
        $filas = [];

        foreach ($pdo->query('SELECT id, codigo_casa, nombre, activo FROM casas')->fetchAll(PDO::FETCH_ASSOC) as $fila) {
            $respaldo = CASAS_RESPALDO[$fila['codigo_casa']] ?? null;

            $filas[] = $fila + [
                'etiqueta'        => $respaldo['etiqueta']   ?? $fila['codigo_casa'],
                'orden'           => $respaldo['orden']      ?? 99,
                'tabla_productos' => $respaldo['tabla']      ?? '',
                'porcentaje_neto' => $respaldo['porcentaje'] ?? CASAS_PORCENTAJE_DEFECTO,
            ];
        }
    }

    $mapa = [];

    foreach ($filas as $fila) {
        $codigo = (string) $fila['codigo_casa'];

        $mapa[$codigo] = [
            'id'              => (int) $fila['id'],
            'codigo_casa'     => $codigo,
            'nombre'          => (string) $fila['nombre'],
            'etiqueta'        => $fila['etiqueta'] !== '' ? (string) $fila['etiqueta'] : $codigo,
            'orden'           => (int) $fila['orden'],
            'tabla_productos' => (string) $fila['tabla_productos'],
            'porcentaje_neto' => (float) $fila['porcentaje_neto'],
            'activo'          => (int) $fila['activo'],
        ];
    }

    return $cache = $mapa;
}

/**
 * Etiqueta corta de una casa. Si llegara un codigo no registrado se devuelve tal
 * cual, para que se note en pantalla en vez de salir vacio.
 */
function etiquetaCasa(?string $codigoCasa): string
{
    return casasRegistradas()[$codigoCasa]['etiqueta'] ?? (string) $codigoCasa;
}

function ordenCasa(?string $codigoCasa): int
{
    // Las no registradas se van al final.
    return casasRegistradas()[$codigoCasa]['orden'] ?? 99;
}

/**
 * Porcentaje que se le suma al precio bruto (mayoreo) para obtener el neto de
 * esta casa. Una casa no registrada usa el default, para no dejar sin precio una
 * venta por un codigo raro.
 */
function porcentajeCasa(?string $codigoCasa): float
{
    return casasRegistradas()[$codigoCasa]['porcentaje_neto'] ?? CASAS_PORCENTAJE_DEFECTO;
}

/**
 * Precio neto (el que se cobra) a partir del bruto y un porcentaje: bruto mas
 * ese %, redondeado a 2 decimales. Devuelve null si no hay bruto, para que la
 * pieza sin precio se trate igual que antes (no se puede vender).
 */
function netoDe($bruto, $porcentaje): ?float
{
    if ($bruto === null || $bruto === '') {
        return null;
    }

    return round((float) $bruto * (1 + (float) $porcentaje / 100), 2);
}

/**
 * Ordena una lista de casas y le agrega la etiqueta a cada una.
 *
 * @param array $casas Filas con la clave 'codigo_casa'.
 */
function ordenarCasas(array $casas): array
{
    foreach ($casas as &$casa) {
        $casa['etiqueta'] = etiquetaCasa($casa['codigo_casa'] ?? null);
    }
    unset($casa);

    usort($casas, function ($a, $b) {
        $porOrden = ordenCasa($a['codigo_casa'] ?? null) <=> ordenCasa($b['codigo_casa'] ?? null);

        // Dos casas con el mismo numero de orden quedarian en un orden
        // impredecible; la etiqueta rompe el empate.
        return $porOrden !== 0
            ? $porOrden
            : strcmp($a['etiqueta'] ?? '', $b['etiqueta'] ?? '');
    });

    return $casas;
}

/**
 * Mapa codigo de casa -> tabla de productos, el que usan los controladores para
 * armar el FROM. Solo pasan los nombres de tabla con la forma esperada: lo que
 * sale de aqui se interpola en SQL, asi que se valida aunque venga de la base.
 *
 * Incluye las casas inactivas a proposito: una venta vieja de una casa dada de
 * baja tiene que poder seguir consultando sus productos.
 *
 * @return array<string, string>
 */
function tablasCasa(): array
{
    $mapa = [];

    foreach (casasRegistradas() as $codigo => $casa) {
        if (preg_match(CASAS_PATRON_TABLA, $casa['tabla_productos'])) {
            $mapa[$codigo] = $casa['tabla_productos'];
        }
    }

    return $mapa;
}

/** Tabla de productos de una casa, o null si el codigo no corresponde a ninguna. */
function tablaDeCasa(?string $codigoCasa): ?string
{
    return tablasCasa()[$codigoCasa] ?? null;
}

/**
 * Tabla de productos a partir de un codigo interno de producto (BNS03-01270):
 * el prefijo ya dice de que casa es.
 */
function tablaDeProducto(string $codigoInterno): ?string
{
    $guion = strpos($codigoInterno, '-');

    if ($guion === false) {
        return null;
    }

    return tablaDeCasa(strtoupper(substr($codigoInterno, 0, $guion)));
}

/**
 * Datos de catalogo para una lista de codigos internos.
 *
 * El detalle de una venta guarda el codigo interno (BNS03-02737) pero no el del
 * proveedor ni el nombre vigente, asi que cuando hace falta alguno hay que ir a
 * la tabla de cada casa. El prefijo del codigo dice a cual.
 *
 * @param  array $columnas Columnas del catalogo que se necesitan.
 * @return array<string, array> Indexado por codigo_interno.
 */
function catalogoDeProductos(PDO $pdo, array $codigos, array $columnas = ['nombre']): array
{
    // Las columnas salen del codigo, no del navegador, pero van dentro del
    // SELECT: se validan igual.
    foreach ($columnas as $columna) {
        if (!preg_match('/^[a-z_]{1,40}$/', $columna)) {
            throw new InvalidArgumentException('Columna de catalogo no valida: ' . $columna);
        }
    }

    $porTabla = [];

    foreach (array_unique($codigos) as $codigo) {
        $tabla = tablaDeProducto((string) $codigo);

        if ($tabla !== null) {
            $porTabla[$tabla][] = $codigo;
        }
    }

    $seleccion = implode(', ', array_unique(array_merge(['codigo_interno'], $columnas)));
    $catalogo  = [];

    foreach ($porTabla as $tabla => $lista) {
        $marcas = implode(',', array_fill(0, count($lista), '?'));

        $stmt = $pdo->prepare("SELECT {$seleccion} FROM {$tabla} WHERE codigo_interno IN ({$marcas})");
        $stmt->execute(array_values($lista));

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $fila) {
            $catalogo[$fila['codigo_interno']] = $fila;
        }
    }

    return $catalogo;
}

/**
 * Reescribe vista_catalogo, que une el catalogo de todas las casas y es la que
 * usa el buscador de Ventas. Hay que llamarla cada vez que nace una casa: si no,
 * sus productos no aparecen en la busqueda.
 */
function refrescarVistaCatalogo(PDO $pdo): void
{
    $tablas = tablasCasa();

    if ($tablas === []) {
        return;
    }

    $partes = [];

    foreach ($tablas as $codigo => $tabla) {
        $casa = casasRegistradas()[$codigo];

        // El codigo y el nombre van como literales dentro de la vista, igual que
        // en el esquema original. PDO::quote los escapa.
        $partes[] = 'SELECT ' . $pdo->quote($codigo) . ' AS codigo_casa, '
                  . $pdo->quote($casa['nombre']) . ' AS nombre_casa, '
                  . 'codigo_interno, codigo_proveedor, nombre, marca, categoria, '
                  . "precio_mayoreo, activo FROM {$tabla}";
    }

    $pdo->exec('CREATE OR REPLACE VIEW vista_catalogo AS ' . implode(' UNION ALL ', $partes));
}
