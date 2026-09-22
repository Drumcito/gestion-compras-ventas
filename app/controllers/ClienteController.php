<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Sesion expirada']);
    exit;
}

require_once __DIR__ . '/../../config/conexionBD.php';
require_once __DIR__ . '/../helpers/clientes.php';

/**
 * Cierra el paso a quien no es administrador. La GESTION de clientes (alta,
 * edicion, baja y el listado completo) vive en el modulo de Usuarios y es solo
 * de admin; la BUSQUEDA (accion=buscar) queda abierta a cualquier usuario con
 * sesion porque el vendedor la necesita para elegir cliente al hacer una venta.
 * La barrera esta aqui y no solo en el menu: ocultar un boton no impide que
 * alguien llame al controlador directo.
 */
function soloAdmin(): void
{
    if (($_SESSION['user_role'] ?? '') !== 'admin') {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Solo un administrador puede gestionar clientes']);
        exit;
    }
}

// Dias validos para la visita. Es SOLO de referencia (no condiciona nada), pero
// se acota a esta lista para que la columna no se llene de variantes escritas a
// mano ("lun", "Lunes ", "LUNES"). Vacio significa "sin dia asignado".
const DIAS_VISITA = ['Lunes', 'Martes', 'Miercoles', 'Jueves', 'Viernes', 'Sabado'];

$accion = $_GET['accion'] ?? 'listar';

/** Recorta espacios y limita el largo, respetando UTF-8. */
function limpiar($valor, int $maximo): string
{
    $texto = trim((string) $valor);
    return mb_substr($texto, 0, $maximo);
}

/**
 * Normaliza el dia de visita: acepta con o sin acento y en cualquier caja
 * ("miércoles", "MIERCOLES") y lo devuelve como aparece en DIAS_VISITA. Vacio o
 * desconocido -> null (sin dia).
 */
function normalizarDia($valor): ?string
{
    $dia = trim((string) $valor);
    if ($dia === '') {
        return null;
    }

    // Fuera acentos para comparar (miércoles -> miercoles).
    $sinAcento = strtr(
        mb_strtolower($dia, 'UTF-8'),
        ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u']
    );

    foreach (DIAS_VISITA as $valido) {
        if (mb_strtolower($valido, 'UTF-8') === $sinAcento) {
            return $valido;
        }
    }

    return null;
}

/**
 * Lee y valida los datos de un cliente que llegan del formulario. Devuelve el
 * arreglo listo para guardar, o lanza RuntimeException con el motivo.
 */
function leerCliente(array $datos): array
{
    $nombres = limpiar($datos['nombres'] ?? '', 100);

    if ($nombres === '') {
        throw new RuntimeException('El nombre del cliente es obligatorio');
    }

    $rfc = strtoupper(limpiar($datos['rfc'] ?? '', 13));
    // El RFC es opcional, pero si lo escriben debe tener forma de RFC (persona
    // fisica de 13 o moral de 12). No se valida contra el SAT, solo el formato.
    if ($rfc !== '' && !preg_match('/^[A-ZÑ&]{3,4}\d{6}[A-Z0-9]{3}$/u', $rfc)) {
        throw new RuntimeException('El RFC no tiene un formato valido (ej. XAXX010101000)');
    }

    $cp = limpiar($datos['codigo_postal'] ?? '', 10);
    if ($cp !== '' && !preg_match('/^\d{4,5}$/', $cp)) {
        throw new RuntimeException('El codigo postal debe ser de 4 o 5 digitos');
    }

    $email = limpiar($datos['email'] ?? '', 150);
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('El correo no tiene un formato válido');
    }

    return [
        'nombres'          => $nombres,
        'apellido_paterno' => limpiar($datos['apellido_paterno'] ?? '', 60) ?: null,
        'apellido_materno' => limpiar($datos['apellido_materno'] ?? '', 60) ?: null,
        'nombre_comercio'  => limpiar($datos['nombre_comercio'] ?? '', 150) ?: null,
        'direccion'        => limpiar($datos['direccion'] ?? '', 255) ?: null,
        'maps_url'         => limpiar($datos['maps_url'] ?? '', 500) ?: null,
        'codigo_postal'    => $cp !== '' ? $cp : null,
        'telefono'         => limpiar($datos['telefono'] ?? '', 20) ?: null,
        'email'            => $email !== '' ? $email : null,
        'rfc'              => $rfc !== '' ? $rfc : null,
        'dia_visita'       => normalizarDia($datos['dia_visita'] ?? ''),
    ];
}

try {
    $pdo = Database::getConnection();

    // ---------- Busqueda (para elegir cliente en una venta) ----------
    // Abierta a cualquier usuario con sesion. Busca por nombre, apellidos o
    // comercio en un solo campo (CONCAT_WS) para que "juan tlape" encuentre a
    // "Juan Perez - Tlapaleria X". Sin termino devuelve los primeros activos,
    // que es lo que alimenta el boton "Ver lista".
    if ($accion === 'buscar') {
        $termino = trim($_GET['q'] ?? '');

        $sql = "SELECT id, nombres, apellido_paterno, apellido_materno, nombre_comercio,
                       telefono, dia_visita
                  FROM clientes
                 WHERE activo = 1";
        $parametros = [];

        if ($termino !== '') {
            $sql .= " AND CONCAT_WS(' ', nombres, apellido_paterno, apellido_materno, nombre_comercio)
                          LIKE :q";
            $parametros['q'] = '%' . $termino . '%';
        }

        $sql .= ' ORDER BY nombre_comercio, nombres LIMIT 50';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($parametros);

        echo json_encode(['ok' => true, 'clientes' => $stmt->fetchAll(PDO::FETCH_ASSOC)], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ---------- Saldo a favor de un cliente (para la venta) ----------
    // Abierta a cualquier usuario con sesion: el vendedor la necesita para saber
    // cuanto puede descontar al elegir el cliente.
    if ($accion === 'saldo') {
        $clienteId = (int) ($_GET['cliente_id'] ?? 0);

        echo json_encode([
            'ok'    => true,
            'saldo' => saldoFavorCliente($pdo, $clienteId),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ---------- Listado completo (gestion) ----------
    if ($accion === 'listar') {
        soloAdmin();

        $clientes = $pdo->query(
            'SELECT id, nombres, apellido_paterno, apellido_materno, nombre_comercio,
                    direccion, maps_url, codigo_postal, telefono, email, rfc, dia_visita, activo
               FROM clientes
              ORDER BY activo DESC, nombre_comercio, nombres'
        )->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['ok' => true, 'clientes' => $clientes], JSON_UNESCAPED_UNICODE);
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

    // ---------- Alta ----------
    if ($accion === 'crear') {
        soloAdmin();

        $cliente = leerCliente($datos);

        $stmt = $pdo->prepare(
            'INSERT INTO clientes
                (nombres, apellido_paterno, apellido_materno, nombre_comercio, direccion,
                 maps_url, codigo_postal, telefono, email, rfc, dia_visita)
             VALUES
                (:nombres, :apellido_paterno, :apellido_materno, :nombre_comercio, :direccion,
                 :maps_url, :codigo_postal, :telefono, :email, :rfc, :dia_visita)'
        );
        $stmt->execute($cliente);

        echo json_encode([
            'ok'      => true,
            'mensaje' => 'Cliente dado de alta',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ---------- Edicion ----------
    if ($accion === 'editar') {
        soloAdmin();

        $id = (int) ($datos['id'] ?? 0);

        $stmt = $pdo->prepare('SELECT id FROM clientes WHERE id = :id');
        $stmt->execute(['id' => $id]);
        if (!$stmt->fetch()) {
            throw new RuntimeException('Cliente no encontrado');
        }

        $cliente = leerCliente($datos);
        $cliente['activo'] = !empty($datos['activo']) ? 1 : 0;
        $cliente['id'] = $id;

        $stmt = $pdo->prepare(
            'UPDATE clientes SET
                nombres = :nombres, apellido_paterno = :apellido_paterno,
                apellido_materno = :apellido_materno, nombre_comercio = :nombre_comercio,
                direccion = :direccion, maps_url = :maps_url, codigo_postal = :codigo_postal,
                telefono = :telefono, email = :email, rfc = :rfc, dia_visita = :dia_visita, activo = :activo
              WHERE id = :id'
        );
        $stmt->execute($cliente);

        echo json_encode(['ok' => true, 'mensaje' => 'Cliente actualizado'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ---------- Baja ----------
    if ($accion === 'eliminar') {
        soloAdmin();

        $id = (int) ($datos['id'] ?? 0);

        $stmt = $pdo->prepare('SELECT nombres, nombre_comercio FROM clientes WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $cliente = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$cliente) {
            throw new RuntimeException('Cliente no encontrado');
        }

        // Los clientes no cuelgan de ninguna venta (ventas.cliente es texto
        // libre), asi que se pueden borrar sin dejar registros huerfanos.
        $pdo->prepare('DELETE FROM clientes WHERE id = :id')->execute(['id' => $id]);

        $nombre = $cliente['nombre_comercio'] ?: $cliente['nombres'];
        echo json_encode(['ok' => true, 'mensaje' => "Cliente {$nombre} eliminado"], JSON_UNESCAPED_UNICODE);
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
    echo json_encode(['ok' => false, 'error' => 'Error al procesar la solicitud']);
}
