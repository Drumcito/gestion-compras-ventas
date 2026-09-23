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

/** DDL de una tabla de catalogo, igual a las cuatro que ya existen. */
function ddlTablaProductos(string $tabla, int $numero): string
{
    return "CREATE TABLE `{$tabla}` (
      `id` INT NOT NULL AUTO_INCREMENT,
      `codigo_proveedor` VARCHAR(50) NOT NULL,
      `codigo_interno` VARCHAR(20) DEFAULT NULL,
      `nombre` VARCHAR(150) NOT NULL,
      `marca` VARCHAR(60) DEFAULT NULL,
      `categoria` VARCHAR(60) DEFAULT NULL,
      `codigo_sat` VARCHAR(20) DEFAULT NULL,
      `precio_mayoreo` DECIMAL(10,2) DEFAULT NULL,
      `piezas_inner` INT DEFAULT NULL,
      `piezas_master` INT DEFAULT NULL,
      `fecha_precio_proveedor` DATE DEFAULT NULL,
      `activo` TINYINT(1) NOT NULL DEFAULT 1,
      `fecha_creacion` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      `fecha_actualizacion` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      UNIQUE KEY `codigo_interno` (`codigo_interno`),
      KEY `idx_p{$numero}_codigo_proveedor` (`codigo_proveedor`),
      KEY `idx_p{$numero}_nombre` (`nombre`),
      KEY `idx_p{$numero}_marca` (`marca`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
}

/**
 * Trigger que guarda en historial_precios cada cambio de precio, igual que el de
 * las otras casas. @usuario_actual es lo que le dice quien hizo el cambio.
 */
function ddlTriggerPrecios(string $tabla, int $numero, string $codigoCasa): string
{
    return "CREATE TRIGGER `trg_precio_casa{$numero}_update`
    AFTER UPDATE ON `{$tabla}` FOR EACH ROW
    BEGIN
        IF NOT (OLD.precio_mayoreo <=> NEW.precio_mayoreo) THEN
            INSERT INTO historial_precios (casa_id, codigo_interno_producto, tipo_precio, precio_anterior, precio_nuevo, usuario_id)
            VALUES ((SELECT id FROM casas WHERE codigo_casa = '{$codigoCasa}'), NEW.codigo_interno, 'mayoreo', OLD.precio_mayoreo, NEW.precio_mayoreo, @usuario_actual);
        END IF;
    END";
}

/** El numero mas alto ya usado, para seguir la serie sin repetir. */
function siguienteNumero(array $valores, string $patron): int
{
    $maximo = 0;

    foreach ($valores as $valor) {
        if (preg_match($patron, (string) $valor, $coincidencia)) {
            $maximo = max($maximo, (int) $coincidencia[1]);
        }
    }

    return $maximo + 1;
}

/**
 * Da de alta una casa: su tabla de productos, la fila en `casas`, el trigger de
 * historial de precios y la vista del buscador. Los datos ya deben venir
 * validados (nombre, etiqueta y orden unicos y con formato).
 *
 * @return array{codigo_casa:string, tabla:string, avisos:string[]}
 */
function crearCasa(PDO $pdo, string $nombre, string $etiqueta, int $orden, float $porcentaje): array
{
    // Sin las columnas de la migracion 002 el INSERT truena; mejor decirlo claro.
    $columnas = (int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.columns
          WHERE table_schema = DATABASE() AND table_name = 'casas'
            AND column_name IN ('etiqueta', 'orden', 'tabla_productos')"
    )->fetchColumn();

    if ($columnas < 3) {
        throw new RuntimeException(
            'Falta aplicar la migracion 002_casas_dinamicas.sql en la base de datos; '
            . 'sin ella no se pueden crear casas.'
        );
    }

    $casas = casasRegistradas();

    $codigoCasa = 'BNS' . str_pad(
        (string) siguienteNumero(array_keys($casas), '/^BNS0*(\d+)$/'),
        2, '0', STR_PAD_LEFT
    );

    // El numero de la tabla va por su propia serie: no coincide con el del
    // codigo ni con el de la etiqueta (BNS03 es productos_casa3 pero se
    // muestra como C4-JD).
    $numeroTabla = siguienteNumero(
        array_column($casas, 'tabla_productos'),
        '/^productos_casa(\d+)$/'
    );

    // Por si quedo una tabla huerfana de un intento anterior.
    $existentes = $pdo->query(
        "SELECT table_name FROM information_schema.tables
          WHERE table_schema = DATABASE() AND table_name LIKE 'productos_casa%'"
    )->fetchAll(PDO::FETCH_COLUMN);

    $numeroTabla = max($numeroTabla, siguienteNumero($existentes, '/^productos_casa(\d+)$/'));
    $tabla       = 'productos_casa' . $numeroTabla;

    if (!preg_match(CASAS_PATRON_TABLA, $tabla)) {
        throw new RuntimeException('No se pudo calcular el nombre de la tabla');
    }

    // Un CREATE TABLE hace commit implicito, asi que esto no puede ir dentro
    // de una transaccion: si el INSERT falla, se deshace a mano la tabla.
    $pdo->exec(ddlTablaProductos($tabla, $numeroTabla));

    try {
        $stmt = $pdo->prepare(
            'INSERT INTO casas (codigo_casa, nombre, etiqueta, orden, tabla_productos, porcentaje_neto)
             VALUES (:codigo, :nombre, :etiqueta, :orden, :tabla, :porcentaje)'
        );
        $stmt->execute([
            'codigo'     => $codigoCasa,
            'nombre'     => $nombre,
            'etiqueta'   => $etiqueta,
            'orden'      => $orden,
            'tabla'      => $tabla,
            'porcentaje' => $porcentaje,
        ]);

    } catch (PDOException $e) {
        $pdo->exec("DROP TABLE IF EXISTS `{$tabla}`");
        throw $e;
    }

    // La lista en memoria ya cambio.
    casasRegistradas(true);

    $avisos = [];

    // El trigger de historial de precios y la vista del buscador son DDL:
    // en algunos hostings el usuario de la base no tiene permiso. Si fallan,
    // la casa igual sirve, pero hay que decir que quedo a medias.
    try {
        $pdo->exec(ddlTriggerPrecios($tabla, $numeroTabla, $codigoCasa));
    } catch (PDOException $e) {
        error_log('Trigger de ' . $tabla . ': ' . $e->getMessage());
        $avisos[] = 'No se pudo crear el registro automatico de cambios de precio '
                  . 'para esta casa (falta permiso de TRIGGER en la base). Todo lo demas funciona.';
    }

    try {
        refrescarVistaCatalogo($pdo);
    } catch (PDOException $e) {
        error_log('vista_catalogo: ' . $e->getMessage());
        $avisos[] = 'No se pudo actualizar el catalogo general, asi que los productos de '
                  . 'esta casa todavia no apareceran al buscar en "Todas las casas".';
    }

    return ['codigo_casa' => $codigoCasa, 'tabla' => $tabla, 'avisos' => $avisos];
}

/** Nombre y etiqueta de la casa donde caen los productos capturados a mano en Venta. */
const CASA_OTROS_NOMBRE   = 'Otros';
const CASA_OTROS_ETIQUETA = 'OTROS';

/** Codigo de la casa "Otros", o null si todavia no existe. Se busca por nombre. */
function codigoCasaOtros(): ?string
{
    foreach (casasRegistradas() as $codigo => $casa) {
        if (mb_strtolower($casa['nombre']) === mb_strtolower(CASA_OTROS_NOMBRE)
            && tablaDeCasa($codigo) !== null) {
            return $codigo;
        }
    }

    return null;
}

/**
 * Codigo de la casa "Otros"; si no existe la crea (al final del orden y con el
 * porcentaje por defecto), para que el vendedor no dependa del administrador.
 */
function asegurarCasaOtros(PDO $pdo): string
{
    $codigo = codigoCasaOtros();

    if ($codigo !== null) {
        return $codigo;
    }

    $orden = 1;
    foreach (casasRegistradas() as $casa) {
        $orden = max($orden, $casa['orden'] + 1);
    }

    $nueva = crearCasa($pdo, CASA_OTROS_NOMBRE, CASA_OTROS_ETIQUETA, min($orden, 999), CASAS_PORCENTAJE_DEFECTO);

    foreach ($nueva['avisos'] as $aviso) {
        error_log('Casa Otros: ' . $aviso);
    }

    return $nueva['codigo_casa'];
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
