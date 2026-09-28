<?php
/**
 * Nota(s) de venta imprimible(s).
 *
 * Cada nota se imprime DOS VECES para poder cortar y quedarse una copia:
 *
 *  - Nota normal: hoja carta HORIZONTAL (11 x 8.5 pulgadas) partida en dos
 *    mitades verticales de 5.5 x 8.5. La MISMA nota va en la izquierda y en la
 *    derecha; se corta a lo largo por la linea punteada del centro y quedan dos
 *    copias iguales en una sola hoja.
 *
 *  - Nota con demasiados productos: no cabe en media hoja, asi que se imprime en
 *    carta VERTICAL (8.5 x 11) a hoja completa. Como tambien va doble, salen dos
 *    hojas verticales, una copia por hoja.
 *
 * Acepta una venta (?id=13) o varias (?ids=13,14,15). Con una sola venta se
 * pueden pasar ademas los datos del cliente que no viven en la base
 * (direccion, C.P. y telefono), que se capturan al momento de imprimir.
 * La fecha y el folio salen siempre de la venta registrada.
 */
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit;
}

require_once __DIR__ . '/../../config/conexionBD.php';

// Datos del negocio que salen impresos en el encabezado.
const NEGOCIO_NOMBRE   = 'COMERCIALIZADORA GA-BE';
const NEGOCIO_TELEFONO = '49728197';

// Tope para que una seleccion enorme no tumbe la pagina.
const MAX_NOTAS = 100;

// Piezas que caben en una nota. La media hoja ahora mide 5.5 x 8.5 (antes
// 8.5 x 5.5) y cada renglon ocupa una sola linea, asi que el tope es fijo: con
// 32 la tabla termina a 7.2 pulgadas y el total, que va al pie, queda libre.
const MAX_PIEZAS_NOTA = 32;

// Si la venta pasa de MAX_PIEZAS_NOTA, la nota se imprime en carta vertical
// (8.5 x 11) a hoja completa, donde cabe mucho mas. Este es el tope de esa hoja
// vertical: lo que sobre se resume en un renglon para no desbordar la pagina.
const MAX_PIEZAS_VERTICAL = 44;

// ---------- Que ventas se van a imprimir ----------
$ids = [];

if (isset($_GET['ids']) && trim($_GET['ids']) !== '') {
    foreach (explode(',', $_GET['ids']) as $valor) {
        $numero = (int) trim($valor);
        if ($numero > 0) {
            $ids[] = $numero;
        }
    }
    $ids = array_slice(array_values(array_unique($ids)), 0, MAX_NOTAS);
} elseif (isset($_GET['id'])) {
    $numero = (int) $_GET['id'];
    if ($numero > 0) {
        $ids[] = $numero;
    }
}

if ($ids === []) {
    http_response_code(400);
    exit('No se indicó ninguna venta.');
}

try {
    $pdo = Database::getConnection();

    $marcadores = implode(',', array_fill(0, count($ids), '?'));

    // El vendedor solo imprime notas de sus propias ventas; el admin, de todas.
    // Una nota ajena simplemente no aparece (queda fuera del resultado).
    $esAdmin       = ($_SESSION['user_role'] ?? '') === 'admin';
    $condDueno     = $esAdmin ? '' : ' AND v.usuario_id = ?';
    $paramsVentas  = $ids;
    if (!$esAdmin) {
        $paramsVentas[] = (int) $_SESSION['user_id'];
    }

    $stmt = $pdo->prepare(
        'SELECT v.id, v.cliente, v.fecha, v.total, v.credito_aplicado, v.tipo_pago,
                CONCAT(u.nombre, " ", COALESCE(u.apellido, "")) AS vendedor,
                c.direccion AS cliente_direccion, c.codigo_postal AS cliente_cp,
                c.telefono  AS cliente_telefono
           FROM ventas v
           JOIN usuarios u ON u.id = v.usuario_id
           LEFT JOIN clientes c ON c.id = v.cliente_id
          WHERE v.id IN (' . $marcadores . ')' . $condDueno . '
          ORDER BY v.id'
    );
    $stmt->execute($paramsVentas);
    $ventas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($ventas === []) {
        http_response_code(404);
        exit('Venta no encontrada.');
    }

    // Un solo viaje a la base para todas las piezas, agrupadas por venta.
    $stmt = $pdo->prepare(
        'SELECT venta_id, nombre_producto, precio_aplicado, cantidad, subtotal
           FROM detalle_venta
          WHERE venta_id IN (' . $marcadores . ')
          ORDER BY venta_id, id'
    );
    $stmt->execute($ids);

    $itemsPorVenta = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $fila) {
        $itemsPorVenta[$fila['venta_id']][] = $fila;
    }

} catch (PDOException $e) {
    error_log($e->getMessage());
    http_response_code(500);
    exit('No se pudo generar la nota.');
}

// Lo que se captura al imprimir solo aplica cuando es una sola venta: con
// varias no se sabria a cual pertenece.
$unaSola   = count($ventas) === 1;
$direccion = $unaSola ? trim($_GET['direccion'] ?? '') : '';
$cp        = $unaSola ? trim($_GET['cp'] ?? '') : '';

// El telefono se limita a 10 digitos: se descarta cualquier otro caracter.
$telefono  = $unaSola ? substr(preg_replace('/\D/', '', $_GET['telefono'] ?? ''), 0, 10) : '';
$clienteManual = $unaSola ? trim($_GET['cliente'] ?? '') : '';

// Cada nota decide su formato segun cuantas piezas trae: normal (media hoja
// horizontal) o vertical (hoja completa). El conteo de hojas depende de eso:
// una nota normal ocupa 1 hoja (dos copias, una por mitad) y una vertical
// ocupa 2 (una copia por hoja).
$notasRender = [];
$hojas       = 0;
foreach ($ventas as $venta) {
    $items    = $itemsPorVenta[$venta['id']] ?? [];
    $vertical = count($items) > MAX_PIEZAS_NOTA;
    $notasRender[] = ['venta' => $venta, 'items' => $items, 'vertical' => $vertical];
    $hojas += $vertical ? 2 : 1;
}

/**
 * Dato del cliente para la nota: manda lo que se escribio al imprimir y, si no
 * vino nada, lo que el cliente tenga guardado. Asi la nota sale completa aunque
 * se imprima de corrido, sin pasar por la captura, y aunque sean varias notas
 * (cada una toma lo suyo).
 */
function datoCliente(?string $capturado, array $venta, string $columna): string
{
    if ($capturado !== null && trim($capturado) !== '') {
        return trim($capturado);
    }

    return trim((string) ($venta[$columna] ?? ''));
}

function e(?string $texto): string
{
    return htmlspecialchars((string) $texto, ENT_QUOTES, 'UTF-8');
}

function dinero($monto): string
{
    return number_format((float) $monto, 2);
}

/**
 * ---------- Importe en letra ----------
 * Convierte un monto a su forma escrita en español, p. ej. 231.00 =>
 * "DOSCIENTOS TREINTA Y UNO PESOS 00/100 M.N.". Sirve el rango de una venta
 * (hasta millones); los centavos van como fracción sobre 100.
 */
function decenasEnLetra(int $n): string
{
    $unidades = [0 => '', 1 => 'uno', 2 => 'dos', 3 => 'tres', 4 => 'cuatro',
                 5 => 'cinco', 6 => 'seis', 7 => 'siete', 8 => 'ocho', 9 => 'nueve'];
    $especiales = [
        10 => 'diez', 11 => 'once', 12 => 'doce', 13 => 'trece', 14 => 'catorce',
        15 => 'quince', 16 => 'dieciséis', 17 => 'diecisiete', 18 => 'dieciocho',
        19 => 'diecinueve', 20 => 'veinte', 21 => 'veintiuno', 22 => 'veintidós',
        23 => 'veintitrés', 24 => 'veinticuatro', 25 => 'veinticinco',
        26 => 'veintiséis', 27 => 'veintisiete', 28 => 'veintiocho', 29 => 'veintinueve',
    ];
    $decenas = [3 => 'treinta', 4 => 'cuarenta', 5 => 'cincuenta', 6 => 'sesenta',
                7 => 'setenta', 8 => 'ochenta', 9 => 'noventa'];

    if ($n < 10) {
        return $unidades[$n];
    }
    if ($n <= 29) {
        return $especiales[$n];
    }

    $d = intdiv($n, 10);
    $u = $n % 10;

    return $u === 0 ? $decenas[$d] : $decenas[$d] . ' y ' . $unidades[$u];
}

function centenasEnLetra(int $n): string
{
    $centenas = [1 => 'ciento', 2 => 'doscientos', 3 => 'trescientos',
                 4 => 'cuatrocientos', 5 => 'quinientos', 6 => 'seiscientos',
                 7 => 'setecientos', 8 => 'ochocientos', 9 => 'novecientos'];

    if ($n === 100) {
        return 'cien';
    }

    $c     = intdiv($n, 100);
    $resto = $n % 100;
    $texto = $c > 0 ? $centenas[$c] : '';

    if ($resto > 0) {
        $texto = trim($texto . ' ' . decenasEnLetra($resto));
    }

    return trim($texto);
}

function enteroEnLetra(int $n): string
{
    if ($n === 0) {
        return 'cero';
    }

    $millones = intdiv($n, 1000000);
    $resto    = $n % 1000000;
    $miles    = intdiv($resto, 1000);
    $cientos  = $resto % 1000;

    $partes = [];

    if ($millones > 0) {
        $partes[] = $millones === 1 ? 'un millón' : centenasEnLetra($millones) . ' millones';
    }
    if ($miles > 0) {
        $partes[] = $miles === 1 ? 'mil' : centenasEnLetra($miles) . ' mil';
    }
    if ($cientos > 0) {
        $partes[] = centenasEnLetra($cientos);
    }

    return implode(' ', $partes);
}

function numeroEnLetra($monto): string
{
    $monto    = round((float) $monto, 2);
    $entero   = (int) floor($monto);
    $centavos = (int) round(($monto - $entero) * 100);

    $letras = mb_strtoupper(enteroEnLetra($entero), 'UTF-8');
    $frac   = str_pad((string) $centavos, 2, '0', STR_PAD_LEFT);

    return $letras . ' PESOS ' . $frac . '/100 M.N.';
}

/**
 * Dibuja una nota completa (encabezado, cliente, tabla, total). Se llama dos
 * veces por venta para dejar dos copias. En vertical caben mas piezas antes de
 * recortar.
 */
function renderNota(array $venta, array $items, bool $unaSola, string $direccion,
                    string $cp, string $telefono, string $clienteManual): void
{
    $fecha = (new DateTime($venta['fecha']))->format('d/m/Y');

    // Con una sola venta mandan los datos capturados; con varias se usa lo
    // que ya tenga guardado cada una.
    $cliente = ($unaSola && $clienteManual !== '') ? $clienteManual : ($venta['cliente'] ?? '');

    // Direccion, C.P. y telefono del cliente registrado, salvo que se hayan
    // escrito otros al imprimir.
    $notaDireccion = datoCliente($direccion, $venta, 'cliente_direccion');
    $notaCp        = datoCliente($cp,        $venta, 'cliente_cp');
    $notaTelefono  = datoCliente($telefono,  $venta, 'cliente_telefono');

    // El tope de piezas depende del tamaño de la hoja donde va la nota.
    $cap      = count($items) > MAX_PIEZAS_NOTA ? MAX_PIEZAS_VERTICAL : MAX_PIEZAS_NOTA;
    $visibles = array_slice($items, 0, $cap);
    $ocultas  = count($items) - count($visibles);

    $saldoAplicado = (float) ($venta['credito_aplicado'] ?? 0);
    $totalFinal    = $saldoAplicado > 0
        ? max((float) $venta['total'] - $saldoAplicado, 0)
        : (float) $venta['total'];
    ?>
    <div class="nota">
        <div class="encabezado">
            <div class="marca">
                <img src="../../public/img/GA-BE_logo_oscuro.png" alt="">
                <div>
                    <h1><?= NEGOCIO_NOMBRE ?></h1>
                    <p>TEL. <?= NEGOCIO_TELEFONO ?></p>
                </div>
            </div>
            <div class="folio">
                <?= e($fecha) ?><br>
                <strong>No. VENTA <?= (int) $venta['id'] ?></strong>
            </div>
        </div>

        <div class="cliente">
            <div class="campo">
                <span class="etiqueta">NOMBRE:</span>
                <span class="dato"><?= e($cliente) ?></span>
            </div>
            <div class="campo">
                <span class="etiqueta">DIRECCION:</span>
                <span class="dato"><?= e($notaDireccion) ?></span>
            </div>
            <div class="fila-corta">
                <div class="campo">
                    <span class="etiqueta">C.P.</span>
                    <span class="dato"><?= e($notaCp) ?></span>
                </div>
                <div class="campo">
                    <span class="etiqueta">Tel.</span>
                    <span class="dato"><?= e($notaTelefono) ?></span>
                </div>
            </div>
        </div>

        <table>
            <thead>
                <tr>
                    <th class="col-cant">CANT</th>
                    <th class="col-desc">DESCRIPCION</th>
                    <th class="col-precio">P. UNITARIO</th>
                    <th class="col-importe">IMPORTE</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($visibles as $i): ?>
                    <tr>
                        <td class="col-cant"><?= (int) $i['cantidad'] ?></td>
                        <td class="col-desc"><?= e($i['nombre_producto']) ?></td>
                        <td class="col-precio"><span class="signo">$</span><?= dinero($i['precio_aplicado']) ?></td>
                        <td class="col-importe"><span class="signo">$</span><?= dinero($i['subtotal']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($ocultas > 0): ?>
                    <tr>
                        <td colspan="4" class="mas-piezas">
                            y <?= $ocultas ?> pieza<?= $ocultas === 1 ? '' : 's' ?> más — ver detalle de la venta
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>

        <div class="totales">
            <table>
                <?php if ($saldoAplicado > 0): ?>
                    <tr>
                        <td class="rotulo">SUBTOTAL</td>
                        <td class="monto"><span class="signo">$</span><?= dinero($venta['total']) ?></td>
                    </tr>
                    <tr>
                        <td class="rotulo">SALDO A FAVOR</td>
                        <td class="monto"><span class="signo">-$</span><?= dinero($saldoAplicado) ?></td>
                    </tr>
                    <tr class="gran-total">
                        <td class="rotulo">TOTAL A PAGAR</td>
                        <td class="monto"><span class="signo">$</span><?= dinero($totalFinal) ?></td>
                    </tr>
                <?php else: ?>
                    <tr class="gran-total">
                        <td class="rotulo">TOTAL</td>
                        <td class="monto"><span class="signo">$</span><?= dinero($totalFinal) ?></td>
                    </tr>
                <?php endif; ?>
            </table>
        </div>

        <div class="total-letra"><?= e(numeroEnLetra($totalFinal)) ?></div>

        <div class="pie">
            <span>Atendió: <?= e(trim($venta['vendedor'])) ?></span>
            <span><?= ucfirst(e($venta['tipo_pago'])) ?></span>
        </div>
    </div>
    <?php
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title><?= count($ventas) === 1 ? 'Nota de venta #' . (int) $ventas[0]['id'] : 'Notas de venta (' . count($ventas) . ')' ?> - <?= NEGOCIO_NOMBRE ?></title>
    <style>
        /* Hoja carta horizontal partida a lo largo: cada nota mide 5.5 x 8.5,
           la misma nota a la izquierda y a la derecha (dos copias). */
        @page {
            size: letter landscape;
            margin: 0;
        }

        /* La nota con demasiados productos usa carta vertical a hoja completa. */
        @page vertical {
            size: letter portrait;
            margin: 0;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: Arial, Helvetica, sans-serif;
            color: #000000;
            background: #9e9e9e;
        }

        .hoja {
            width: 11in;
            margin: 0 auto;
        }

        /* Una hoja completa. El salto de pagina va aqui, en un bloque del
           tamaño exacto del papel: cortar dentro de un contenedor flex es poco
           confiable al imprimir, sobre el contenedor mismo no falla. */
        .par {
            width: 11in;
            height: 8.5in;
            display: flex;
            background: #ffffff;
            position: relative;
            break-after: page;
            page-break-after: always;
        }

        /* Hoja carta vertical a pagina completa: lleva una sola copia de la
           nota. La venta se imprime en dos de estas hojas. */
        .pagina-vertical {
            width: 8.5in;
            height: 11in;
            display: flex;
            background: #ffffff;
            position: relative;
            page: vertical;
            break-after: page;
            page-break-after: always;
        }

        /* El ultimo bloque de la hoja no fuerza una pagina extra en blanco. */
        .hoja > *:last-child {
            break-after: auto;
            page-break-after: auto;
        }

        /* Guia de corte por el centro de la hoja horizontal: separa las dos
           copias. */
        .par::after {
            content: '';
            position: absolute;
            top: 0.2in;
            bottom: 0.2in;
            left: 50%;
            border-left: 1px dashed #999999;
        }

        .nota {
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }

        /* Media hoja horizontal. */
        .par .nota {
            width: 5.5in;
            height: 8.5in;
            padding: 0.3in 0.28in;
        }

        /* Hoja vertical completa: mas alto y mas ancho, para muchos productos. */
        .pagina-vertical .nota {
            width: 8.5in;
            height: 11in;
            padding: 0.5in 0.55in;
        }

        /* Si una venta trae mas piezas de las que caben, el sobrante se recorta
           (overflow) en lugar de apretar los bloques y descuadrar la nota. */
        .nota > * { flex-shrink: 0; }

        /* ---------- Encabezado ---------- */
        .encabezado {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 0.2in;
        }

        .marca {
            display: flex;
            align-items: center;
            gap: 0.12in;
        }

        .marca img {
            width: 0.5in;
            height: 0.5in;
            object-fit: contain;
        }

        .marca h1 {
            font-size: 11pt;
            font-weight: bold;
            letter-spacing: 0.4pt;
            line-height: 1.1;
        }

        .marca p {
            font-size: 6.5pt;
            text-align: center;
            margin-top: 1pt;
        }

        .folio {
            text-align: right;
            font-size: 7.5pt;
            line-height: 1.5;
            white-space: nowrap;
        }

        .folio strong { font-size: 8.5pt; }

        /* ---------- Datos del cliente ---------- */
        .cliente {
            margin-top: 0.1in;
            font-size: 7pt;
            line-height: 1.55;
        }

        .cliente .campo {
            display: flex;
            gap: 0.08in;
        }

        .cliente .etiqueta {
            font-weight: bold;
            width: 0.62in;
            flex-shrink: 0;
        }

        /* El renglon se dibuja aunque el dato venga vacio: asi se puede
           escribir a mano sobre la nota impresa. */
        .cliente .dato {
            flex: 1;
            border-bottom: 0.5pt solid #999999;
            min-height: 10pt;
        }

        .fila-corta {
            display: flex;
            gap: 0.2in;
            margin-top: 2pt;
        }

        .fila-corta .campo { flex: 1; }

        /* ---------- Tabla de productos ---------- */
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 0.12in;
            font-size: 7pt;
            /* Ancho fijo para que la descripcion pueda recortarse: si los
               nombres largos hicieran dos renglones, la tabla empujaria el
               total fuera de la media hoja y se perderia al imprimir. */
            table-layout: fixed;
        }

        /* De 27,818 productos del catalogo, solo 5 pasan del ancho de la
           columna; esos se cortan con puntos suspensivos. */
        .col-desc {
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        th {
            border: 0.75pt solid #000000;
            padding: 2.5pt 3pt;
            font-size: 6.5pt;
            letter-spacing: 0.3pt;
            background: #e8e8e8;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        td {
            border-left: 0.75pt solid #000000;
            border-right: 0.75pt solid #000000;
            padding: 2pt 3pt;
            vertical-align: top;
        }

        tbody tr:last-child td { border-bottom: 0.75pt solid #000000; }

        .col-cant   { width: 0.38in; text-align: center; }
        .col-precio { width: 0.72in; text-align: right; }
        .col-importe{ width: 0.8in;  text-align: right; }

        .signo {
            float: left;
            font-weight: normal;
        }

        /* Cuando la venta trae mas piezas de las que caben en media hoja. */
        .mas-piezas {
            font-size: 6.5pt;
            font-style: italic;
            padding: 2pt 4pt;
        }

        /* ---------- Total ---------- */
        .totales {
            display: flex;
            justify-content: flex-end;
            /* La nota ahora es alta: el total se va al pie, como en la de papel. */
            margin-top: auto;
            padding-top: 0.1in;
        }

        .totales table {
            width: 2.3in;
            margin-top: 0;
            font-size: 8.5pt;
        }

        .totales td {
            border: none;
            padding: 2pt 4pt;
        }

        .totales .rotulo { text-align: right; font-weight: bold; }
        .totales .monto  { text-align: right; width: 0.95in; }

        /* Total resaltado: fondo negro, letra blanca y un poco mas grande, para
           que salte a la vista sobre la nota. */
        .totales .gran-total td {
            background: #000000;
            color: #ffffff;
            font-size: 12pt;
            font-weight: bold;
            padding: 4pt 4pt;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        /* Importe con letra, debajo del total (p. ej. "DOSCIENTOS TREINTA Y
           UNO PESOS 00/100 M.N."). */
        .total-letra {
            text-align: right;
            font-size: 7pt;
            font-weight: bold;
            margin-top: 3pt;
            text-transform: uppercase;
            word-break: break-word;
        }

        .pie {
            margin-top: 0.08in;
            font-size: 6.5pt;
            color: #444444;
            display: flex;
            justify-content: space-between;
        }

        /* ---------- Solo en pantalla ---------- */
        .barra {
            max-width: 11in;
            margin: 0.2in auto;
            display: flex;
            gap: 0.5rem;
            align-items: center;
            justify-content: flex-end;
        }

        .barra .cuenta {
            margin-right: auto;
            color: #ffffff;
            font-size: 11pt;
        }

        .barra button {
            font-family: inherit;
            font-size: 11pt;
            padding: 0.5rem 1.5rem;
            border-radius: 8px;
            border: 1px solid #888888;
            background: #ffffff;
            cursor: pointer;
        }

        .barra .principal {
            background: #08660b;
            border-color: #08660b;
            color: #ffffff;
            font-weight: 600;
        }

        /* En pantalla se separan las hojas para distinguirlas; al imprimir cada
           una llena el papel y el corte lo marca la linea punteada del centro. */
        @media screen {
            .par,
            .pagina-vertical {
                box-shadow: 0 2px 8px rgba(0, 0, 0, 0.25);
                margin-bottom: 0.25in;
            }
        }

        /* A proposito no lleva <meta viewport>: es un documento de 8.5in para
           papel, no una pantalla. Sin esa etiqueta el celular le asigna el
           ancho por defecto y ajusta la nota completa a la pantalla. */

        @media print {
            body { background: #ffffff; }
            .hoja { margin: 0; }
            .no-imprimir { display: none !important; }
        }
    </style>
</head>
<body>

<div class="barra no-imprimir">
    <span class="cuenta">
        <?= count($ventas) ?> nota<?= count($ventas) === 1 ? '' : 's' ?>
        · <?= $hojas ?> hoja<?= $hojas === 1 ? '' : 's' ?>
    </span>
    <button type="button" onclick="window.close()">Cerrar</button>
    <button type="button" class="principal" onclick="window.print()">Imprimir</button>
</div>

<div class="hoja">
<?php foreach ($notasRender as $r): ?>
    <?php if ($r['vertical']): ?>
        <?php /* Muchos productos: una copia por hoja carta vertical, dos hojas. */ ?>
        <?php for ($copia = 0; $copia < 2; $copia++): ?>
            <div class="pagina-vertical">
                <?php renderNota($r['venta'], $r['items'], $unaSola, $direccion, $cp, $telefono, $clienteManual); ?>
            </div>
        <?php endfor; ?>
    <?php else: ?>
        <?php /* Nota normal: la misma nota dos veces, una por mitad de la hoja horizontal. */ ?>
        <div class="par">
            <?php renderNota($r['venta'], $r['items'], $unaSola, $direccion, $cp, $telefono, $clienteManual); ?>
            <?php renderNota($r['venta'], $r['items'], $unaSola, $direccion, $cp, $telefono, $clienteManual); ?>
        </div>
    <?php endif; ?>
<?php endforeach; ?>
</div>

<script>
    // Se abre directo el diálogo de impresión: la nota se imprime al terminar
    // la venta, no se revisa en pantalla.
    window.addEventListener('load', () => {
        if (new URLSearchParams(location.search).get('auto') !== '0') {
            window.print();
        }
    });
</script>

</body>
</html>
