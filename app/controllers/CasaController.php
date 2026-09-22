<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Sesion expirada']);
    exit;
}

// Crear una casa crea una tabla y cambia el catalogo que ve todo el mundo, asi
// que queda reservado al administrador. La barrera vive aqui y no solo en el
// boton: ocultarlo en pantalla no impide que alguien llame al controlador.
if (($_SESSION['user_role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Solo un administrador puede crear casas o agregar productos']);
    exit;
}

require_once __DIR__ . '/../../config/conexionBD.php';
require_once __DIR__ . '/../helpers/casas.php';

/** Tope por envio: mas que esto y se pasa el post_max_size del servidor. */
const MAX_FILAS = 5000;

/** Cuantas filas van por INSERT. */
const FILAS_POR_LOTE = 500;

$usuarioId = (int) $_SESSION['user_id'];

// ---------------------------------------------------------------- utilidades

function texto($valor, int $maximo): string
{
    $limpio = trim((string) $valor);

    // Los nombres de producto traen tabuladores y dobles espacios cuando vienen
    // pegados. Si el texto no fuera UTF-8 valido, preg_replace devuelve null: en
    // ese caso se usa el original en lugar de perder la fila.
    $limpio = preg_replace('/\s+/u', ' ', $limpio) ?? $limpio;

    return mb_substr($limpio, 0, $maximo);
}

/**
 * Convierte a precio lo que venga escrito a mano o pegado de Excel: "$1,234.50",
 * "1 234,50", "45.00". Devuelve null cuando el campo viene vacio.
 */
function aPrecio($valor): ?float
{
    if ($valor === null) {
        return null;
    }

    $crudo = trim((string) $valor);

    if ($crudo === '' || $crudo === '-' || $crudo === '—') {
        return null;
    }

    // Fuera moneda y espacios (incluido el espacio duro que suelta Excel).
    $crudo = str_replace(['$', ' ', "\xc2\xa0", 'MXN', 'mxn'], '', $crudo);

    $tieneComa = strpos($crudo, ',') !== false;
    $tienePunto = strpos($crudo, '.') !== false;

    if ($tieneComa && $tienePunto) {
        // "1,234.50": la coma es separador de miles.
        $crudo = str_replace(',', '', $crudo);
    } elseif ($tieneComa) {
        // "1234,50": la coma es el decimal.
        $crudo = str_replace(',', '.', $crudo);
    }

    if (!is_numeric($crudo)) {
        throw new RuntimeException('no es un numero');
    }

    $numero = (float) $crudo;

    if ($numero < 0) {
        throw new RuntimeException('no puede ser negativo');
    }
    if ($numero > 99999999.99) {
        throw new RuntimeException('es demasiado alto');
    }

    return round($numero, 2);
}

/**
 * Revisa una fila de producto. Devuelve la fila lista para guardar, o lanza
 * RuntimeException con el motivo para que se reporte con su numero de fila.
 */
function revisarProducto(array $fila): array
{
    $nombre = texto($fila['nombre'] ?? '', 150);

    if ($nombre === '') {
        throw new RuntimeException('falta el nombre del producto');
    }

    try {
        $mayoreo = aPrecio($fila['precio_mayoreo'] ?? null);
    } catch (RuntimeException $e) {
        throw new RuntimeException('el precio bruto ' . $e->getMessage());
    }

    if ($mayoreo === null) {
        throw new RuntimeException('falta el precio bruto');
    }

    return [
        'codigo_proveedor' => texto($fila['codigo_proveedor'] ?? '', 50),
        'nombre'           => $nombre,
        'marca'            => texto($fila['marca'] ?? '', 60) ?: null,
        'categoria'        => texto($fila['categoria'] ?? '', 60) ?: null,
        'precio_mayoreo'   => $mayoreo,
    ];
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

/** Porcentaje de neto de una casa: numero de 0 a 999.99, con 2 decimales. */
function aPorcentaje($valor): float
{
    if ($valor === null || $valor === '' || !is_numeric($valor)) {
        throw new RuntimeException('El porcentaje debe ser un numero');
    }

    $numero = round((float) $valor, 2);

    if ($numero < 0 || $numero > 999.99) {
        throw new RuntimeException('El porcentaje debe estar entre 0 y 999.99');
    }

    return $numero;
}

// ------------------------------------------------------------------ acciones

try {
    $pdo = Database::getConnection();

    $accion = $_GET['accion'] ?? '';

    // ---------- Datos para armar el formulario de casa nueva ----------
    if ($accion === 'nueva') {
        $casas = casasRegistradas();

        $siguiente = siguienteNumero(array_keys($casas), '/^BNS0*(\d+)$/');
        $orden     = 1;

        foreach ($casas as $casa) {
            $orden = max($orden, $casa['orden'] + 1);
        }

        echo json_encode([
            'ok'                 => true,
            'codigo_casa'        => 'BNS' . str_pad((string) $siguiente, 2, '0', STR_PAD_LEFT),
            'orden_sugerido'     => $orden,
            'etiqueta_sugerida'  => 'C' . (count($casas) + 1) . '-',
            'porcentaje_sugerido' => CASAS_PORCENTAJE_DEFECTO,
            'max_filas'          => MAX_FILAS,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ---------- Modificar una casa (nombre y porcentaje de neto) ----------
    // El porcentaje no se guarda en ningun precio: cambiarlo aqui recalcula solos
    // todos los netos de la casa, porque el neto siempre se saca del bruto.
    if ($accion === 'editar') {
        $codigo     = (string) ($datos['codigo_casa'] ?? '');
        $nombre     = texto($datos['nombre'] ?? '', 100);
        $porcentaje = aPorcentaje($datos['porcentaje_neto'] ?? null);

        $casas = casasRegistradas();

        if (!isset($casas[$codigo])) {
            throw new RuntimeException('Casa no encontrada');
        }
        if (mb_strlen($nombre) < 2) {
            throw new RuntimeException('Escribe el nombre de la casa (al menos 2 letras)');
        }

        foreach ($casas as $otra) {
            if ($otra['codigo_casa'] !== $codigo && mb_strtolower($otra['nombre']) === mb_strtolower($nombre)) {
                throw new RuntimeException('Ya hay otra casa llamada ' . $otra['nombre']);
            }
        }

        $pdo->prepare(
            'UPDATE casas SET nombre = :nombre, porcentaje_neto = :porcentaje WHERE codigo_casa = :codigo'
        )->execute([
            'nombre'     => $nombre,
            'porcentaje' => $porcentaje,
            'codigo'     => $codigo,
        ]);

        casasRegistradas(true);

        $avisos = [];

        // El nombre de la casa va como literal dentro de vista_catalogo, asi que
        // si cambio hay que reescribirla para que el buscador lo muestre.
        try {
            refrescarVistaCatalogo($pdo);
        } catch (PDOException $e) {
            error_log('vista_catalogo (editar casa): ' . $e->getMessage());
            $avisos[] = 'Se guardo la casa, pero no se pudo actualizar el catalogo general.';
        }

        echo json_encode([
            'ok'              => true,
            'mensaje'         => 'Casa actualizada',
            'porcentaje_neto' => $porcentaje,
            'avisos'          => $avisos,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'Metodo no permitido']);
        exit;
    }

    $datos = json_decode(file_get_contents('php://input'), true);

    if (!is_array($datos)) {
        throw new RuntimeException('No llegaron datos');
    }

    // ---------- Crear una casa ----------
    if ($accion === 'crear') {
        $nombre     = texto($datos['nombre'] ?? '', 100);
        $etiqueta   = texto($datos['etiqueta'] ?? '', 30);
        $orden      = (int) ($datos['orden'] ?? 0);
        $porcentaje = aPorcentaje($datos['porcentaje_neto'] ?? CASAS_PORCENTAJE_DEFECTO);

        if (mb_strlen($nombre) < 2) {
            throw new RuntimeException('Escribe el nombre de la casa (al menos 2 letras)');
        }
        if (mb_strlen($etiqueta) < 2) {
            throw new RuntimeException('Escribe la etiqueta corta, por ejemplo C5-XY');
        }
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9 .\-]*$/', $etiqueta)) {
            throw new RuntimeException('La etiqueta solo admite letras, numeros, guiones y puntos');
        }
        if ($orden < 1 || $orden > 999) {
            throw new RuntimeException('El orden debe ser un numero del 1 al 999');
        }

        $casas = casasRegistradas();

        foreach ($casas as $casa) {
            if (mb_strtolower($casa['etiqueta']) === mb_strtolower($etiqueta)) {
                throw new RuntimeException('Ya hay una casa con la etiqueta ' . $casa['etiqueta']);
            }
            if (mb_strtolower($casa['nombre']) === mb_strtolower($nombre)) {
                throw new RuntimeException('Ya hay una casa llamada ' . $casa['nombre']);
            }
        }

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

        // El helper tiene las casas en memoria; ya cambio la lista.
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

        echo json_encode([
            'ok'          => true,
            'codigo_casa' => $codigoCasa,
            'etiqueta'    => $etiqueta,
            'nombre'      => $nombre,
            'orden'       => $orden,
            'tabla'       => $tabla,
            'avisos'      => $avisos,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ---------- Agregar productos a una casa ----------
    if ($accion === 'agregar') {
        $codigoCasa = (string) ($datos['casa'] ?? '');
        $tabla      = tablaDeCasa($codigoCasa);

        if ($tabla === null) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Casa no valida']);
            exit;
        }

        $filas = $datos['productos'] ?? [];

        if (!is_array($filas) || count($filas) === 0) {
            throw new RuntimeException('No hay productos que agregar');
        }
        if (count($filas) > MAX_FILAS) {
            throw new RuntimeException(
                'Son ' . number_format(count($filas)) . ' filas y el maximo por vez es '
                . number_format(MAX_FILAS) . '. Pegalas en varias tandas.'
            );
        }

        $validas  = [];
        $errores  = [];

        foreach ($filas as $indice => $fila) {
            if (!is_array($fila)) {
                continue;
            }

            // El navegador manda el numero de linea del texto pegado, para que
            // el error apunte a donde el usuario lo puede corregir.
            $linea = (int) ($fila['linea'] ?? ($indice + 1));

            try {
                $validas[] = revisarProducto($fila);
            } catch (RuntimeException $e) {
                $errores[] = [
                    'linea'   => $linea,
                    'nombre'  => texto($fila['nombre'] ?? '', 60),
                    'detalle' => $e->getMessage(),
                ];
            }
        }

        // Con errores no se guarda nada, salvo que el usuario ya los haya visto
        // y decida guardar solo lo que si sirve.
        if ($errores !== [] && empty($datos['forzar'])) {
            echo json_encode([
                'ok'       => true,
                'guardado' => false,
                'validas'  => count($validas),
                'errores'  => $errores,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($validas === []) {
            throw new RuntimeException('Ninguna fila se pudo leer; revisa los datos');
        }

        $columnas = ['codigo_proveedor', 'nombre', 'marca', 'categoria', 'precio_mayoreo'];

        $pdo->beginTransaction();

        try {
            foreach (array_chunk($validas, FILAS_POR_LOTE) as $lote) {
                $valores    = [];
                $parametros = [];

                foreach ($lote as $fila) {
                    $valores[] = '(' . rtrim(str_repeat('?, ', count($columnas)), ', ') . ')';

                    foreach ($columnas as $columna) {
                        $parametros[] = $fila[$columna];
                    }
                }

                $stmt = $pdo->prepare(
                    "INSERT INTO `{$tabla}` (" . implode(', ', $columnas) . ') VALUES '
                    . implode(', ', $valores)
                );
                $stmt->execute($parametros);
            }

            // El codigo interno se arma con el id, asi que se asigna despues de
            // insertar (misma regla que el resto del catalogo: BNS05-00001).
            $pdo->prepare(
                "UPDATE `{$tabla}`
                    SET codigo_interno = CONCAT(:codigo, '-', LPAD(id, 5, '0'))
                  WHERE codigo_interno IS NULL"
            )->execute(['codigo' => $codigoCasa]);

            $pdo->commit();

        } catch (PDOException $e) {
            $pdo->rollBack();
            throw $e;
        }

        $total = (int) $pdo->query("SELECT COUNT(*) FROM `{$tabla}` WHERE activo = 1")->fetchColumn();

        echo json_encode([
            'ok'        => true,
            'guardado'  => true,
            'guardados' => count($validas),
            'errores'   => $errores,
            'total'     => $total,
            'etiqueta'  => etiquetaCasa($codigoCasa),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Accion no valida']);

} catch (RuntimeException $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    error_log($e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'No se pudo completar la operacion en la base de datos']);
}
