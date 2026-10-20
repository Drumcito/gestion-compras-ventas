<?php
/**
 * Contactos que llegan del formulario del catálogo en línea (tabla
 * catalogo_contactos, ver database/migraciones/011_contactos_catalogo.sql).
 *
 * Aquí solo se GESTIONA la lista; no se manda ningún mensaje. Lo que importa es
 * cumplir lo que promete el aviso de privacidad del catálogo:
 *   - quien responde BAJA deja de recibir mensajes  -> accion=baja (baja_en = NOW())
 *   - las promociones solo van a quien las aceptó    -> filtro "promos"
 *
 * Acciones:
 *   GET  ?accion=listar    (q, promos, estado)  lista en JSON
 *   GET  ?accion=exportar  (mismos filtros)     descarga .xlsx
 *   POST ?accion=baja      {id}                 marca la baja
 *   POST ?accion=reactivar {id}                 deshace la baja
 *
 * Filtros:
 *   q       busca en nombre, teléfono y correo
 *   promos  'todos' | 'si' | 'no'        (acepta promociones)
 *   estado  'activos' | 'bajas' | 'todos'
 */
session_start();

$accion = $_GET['accion'] ?? 'listar';

// El export responde un archivo, el resto JSON: los errores tienen que llegar
// en el formato que el navegador espera en cada caso.
function fallar(int $codigo, string $mensaje): void
{
    global $accion;

    http_response_code($codigo);
    if ($accion === 'exportar') {
        header('Content-Type: text/plain; charset=utf-8');
        echo $mensaje;
    } else {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => $mensaje], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if (!isset($_SESSION['user_id'])) {
    fallar(401, 'Sesion expirada, vuelve a iniciar sesion.');
}

// Son datos personales de terceros (teléfono, correo, IP): solo el admin. La
// barrera vive aquí y no solo en la pestaña: ocultarla no impide que alguien
// llame al controlador directo.
if (($_SESSION['user_role'] ?? '') !== 'admin') {
    fallar(403, 'Solo un administrador puede ver los contactos del catálogo');
}

require_once __DIR__ . '/../../config/conexionBD.php';
require_once __DIR__ . '/../helpers/ExcelSimple.php';

// La lista en pantalla se corta aquí; el export siempre lleva todo lo filtrado.
const MAX_EN_PANTALLA = 500;

/**
 * Arma el WHERE con los filtros de la pantalla. Lo usan el listado y el export
 * para que el Excel traiga exactamente lo que se estaba viendo.
 *
 * @return array{0: string, 1: array}
 */
function condicionFiltros(array $entrada): array
{
    $partes     = [];
    $parametros = [];

    $termino = mb_substr(trim((string) ($entrada['q'] ?? '')), 0, 100);
    if ($termino !== '') {
        $like = '%' . $termino . '%';
        $busqueda = 'nombre LIKE :q1 OR correo LIKE :q2';
        $parametros['q1'] = $like;
        $parametros['q2'] = $like;

        // El teléfono se guarda como 10 dígitos: "33 1234-5678" debe encontrarlo.
        $digitos = preg_replace('/\D+/', '', $termino);
        if ($digitos !== '') {
            $busqueda .= ' OR telefono LIKE :q3';
            $parametros['q3'] = '%' . $digitos . '%';
        }

        $partes[] = '(' . $busqueda . ')';
    }

    $promos = $entrada['promos'] ?? 'todos';
    if ($promos === 'si') {
        $partes[] = 'acepta_promociones = 1';
    } elseif ($promos === 'no') {
        $partes[] = 'acepta_promociones = 0';
    }

    $estado = $entrada['estado'] ?? 'activos';
    if ($estado === 'activos') {
        $partes[] = 'baja_en IS NULL';
    } elseif ($estado === 'bajas') {
        $partes[] = 'baja_en IS NOT NULL';
    }

    $where = $partes ? ' WHERE ' . implode(' AND ', $partes) : '';

    return [$where, $parametros];
}

const COLUMNAS = 'id, nombre, telefono, correo, acepta_promociones, aviso_version,
                  aceptado_en, ip, baja_en, creado_en';

try {
    $pdo = Database::getConnection();

    // ---------- Listado ----------
    if ($accion === 'listar') {
        header('Content-Type: application/json; charset=utf-8');

        [$where, $parametros] = condicionFiltros($_GET);

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM catalogo_contactos' . $where);
        $stmt->execute($parametros);
        $encontrados = (int) $stmt->fetchColumn();

        $stmt = $pdo->prepare(
            'SELECT ' . COLUMNAS . ' FROM catalogo_contactos' . $where .
            ' ORDER BY aceptado_en DESC, id DESC LIMIT ' . MAX_EN_PANTALLA
        );
        $stmt->execute($parametros);
        $contactos = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Totales sin filtros: para que el resumen diga "3 de 40".
        $totales = $pdo->query(
            'SELECT COUNT(*) AS total,
                    COALESCE(SUM(baja_en IS NULL), 0) AS activos,
                    COALESCE(SUM(baja_en IS NULL AND acepta_promociones = 1), 0) AS con_promos
               FROM catalogo_contactos'
        )->fetch(PDO::FETCH_ASSOC);

        echo json_encode([
            'ok'          => true,
            'contactos'   => $contactos,
            'encontrados' => $encontrados,
            'limite'      => MAX_EN_PANTALLA,
            'totales'     => array_map('intval', $totales),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ---------- Export a Excel ----------
    if ($accion === 'exportar') {
        [$where, $parametros] = condicionFiltros($_GET);

        $stmt = $pdo->prepare(
            'SELECT ' . COLUMNAS . ' FROM catalogo_contactos' . $where .
            ' ORDER BY aceptado_en DESC, id DESC'
        );
        $stmt->execute($parametros);

        $filas = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $filas[] = [
                $c['nombre'],
                // Texto, no número: así Excel no se come el formato ni lo pasa a notación científica.
                (string) ($c['telefono'] ?? ''),
                (string) ($c['correo'] ?? ''),
                (int) $c['acepta_promociones'] ? 'Sí' : 'No',
                $c['baja_en'] === null ? 'Activo' : 'Baja',
                (string) $c['aviso_version'],
                substr($c['aceptado_en'], 0, 16),
                $c['baja_en'] === null ? '' : substr($c['baja_en'], 0, 16),
                (string) ($c['ip'] ?? ''),
            ];
        }

        $excel = new ExcelSimple();
        $excel->agregarHoja(
            'Contactos catálogo',
            ['Nombre / negocio', 'Teléfono', 'Correo', 'Acepta promociones', 'Estado',
             'Versión del aviso', 'Aceptó el', 'Baja el', 'IP'],
            $filas,
            [32, 14, 32, 12, 10, 10, 17, 17, 16]
        );
        $excel->descargar('contactos_catalogo_' . date('Y-m-d') . '.xlsx');
        exit;
    }

    // ---------- Baja / reactivar ----------
    if ($accion === 'baja' || $accion === 'reactivar') {
        header('Content-Type: application/json; charset=utf-8');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            fallar(405, 'Metodo no permitido');
        }

        $datos = json_decode(file_get_contents('php://input'), true);
        $id    = (int) ($datos['id'] ?? 0);

        if ($id <= 0) {
            fallar(400, 'Contacto no valido');
        }

        // La condición sobre baja_en evita pisar la fecha original si se pulsa
        // dos veces "Dar de baja": la constancia es cuándo la pidió por primera vez.
        $sql = $accion === 'baja'
            ? 'UPDATE catalogo_contactos SET baja_en = NOW() WHERE id = :id AND baja_en IS NULL'
            : 'UPDATE catalogo_contactos SET baja_en = NULL WHERE id = :id AND baja_en IS NOT NULL';

        $stmt = $pdo->prepare($sql);
        $stmt->execute(['id' => $id]);

        $stmt = $pdo->prepare('SELECT ' . COLUMNAS . ' FROM catalogo_contactos WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $contacto = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$contacto) {
            fallar(404, 'Contacto no encontrado');
        }

        echo json_encode(['ok' => true, 'contacto' => $contacto], JSON_UNESCAPED_UNICODE);
        exit;
    }

    fallar(400, 'Accion no valida');

} catch (PDOException $e) {
    error_log('Contactos catalogo: ' . $e->getMessage());
    fallar(500, 'No se pudo completar la operacion con los contactos');
}
