<?php
/**
 * Nota de venta imprimible, del tamaño de media hoja carta (8.5 x 5.5 pulgadas).
 *
 * Recibe por GET el folio de la venta y, opcionalmente, los datos del cliente
 * que no viven en la base (direccion, C.P. y telefono se capturan al momento
 * de imprimir). La fecha y el folio salen siempre de la venta registrada.
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

$ventaId = (int) ($_GET['id'] ?? 0);

try {
    $pdo = Database::getConnection();

    $stmt = $pdo->prepare(
        'SELECT v.id, v.cliente, v.fecha, v.total, v.tipo_pago, v.estado_pago,
                CONCAT(u.nombre, " ", COALESCE(u.apellido, "")) AS vendedor
           FROM ventas v
           JOIN usuarios u ON u.id = v.usuario_id
          WHERE v.id = :id'
    );
    $stmt->execute(['id' => $ventaId]);
    $venta = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$venta) {
        http_response_code(404);
        exit('Venta no encontrada.');
    }

    $stmt = $pdo->prepare(
        'SELECT nombre_producto, precio_aplicado, cantidad, subtotal
           FROM detalle_venta
          WHERE venta_id = :id
          ORDER BY id'
    );
    $stmt->execute(['id' => $ventaId]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log($e->getMessage());
    http_response_code(500);
    exit('No se pudo generar la nota.');
}

// Los datos capturados al imprimir mandan sobre los de la base; si no se
// escribio nada, se usa el cliente que quedo guardado en la venta.
$cliente   = trim($_GET['cliente'] ?? '') !== '' ? trim($_GET['cliente']) : ($venta['cliente'] ?? '');
$direccion = trim($_GET['direccion'] ?? '');
$cp        = trim($_GET['cp'] ?? '');

// El telefono se limita a 10 digitos: se descarta cualquier otro caracter.
$telefono  = substr(preg_replace('/\D/', '', $_GET['telefono'] ?? ''), 0, 10);

$fecha = (new DateTime($venta['fecha']))->format('d/m/Y');

function e(?string $texto): string
{
    return htmlspecialchars((string) $texto, ENT_QUOTES, 'UTF-8');
}

function dinero($monto): string
{
    return number_format((float) $monto, 2);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Nota de venta #<?= (int) $venta['id'] ?> - <?= NEGOCIO_NOMBRE ?></title>
    <style>
        /* Media hoja carta. Si se imprime en papel carta completo, la nota
           ocupa la mitad superior y la hoja se corta a la mitad. */
        @page {
            size: 8.5in 5.5in;
            margin: 0;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: Arial, Helvetica, sans-serif;
            color: #000000;
            background: #9e9e9e;
        }

        .nota {
            width: 8.5in;
            min-height: 5.5in;
            padding: 0.3in 0.35in;
            background: #ffffff;
            margin: 0 auto;
        }

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
            width: 0.72in;
            height: 0.72in;
            object-fit: contain;
        }

        .marca h1 {
            font-size: 15pt;
            font-weight: bold;
            letter-spacing: 0.4pt;
            line-height: 1.1;
        }

        .marca p {
            font-size: 8pt;
            text-align: center;
            margin-top: 1pt;
        }

        .folio {
            text-align: right;
            font-size: 9pt;
            line-height: 1.5;
            white-space: nowrap;
        }

        .folio strong { font-size: 10pt; }

        /* ---------- Datos del cliente ---------- */
        .cliente {
            margin-top: 0.12in;
            font-size: 8.5pt;
            line-height: 1.55;
        }

        .cliente .campo {
            display: flex;
            gap: 0.08in;
        }

        .cliente .etiqueta {
            font-weight: bold;
            width: 0.75in;
            flex-shrink: 0;
        }

        /* El renglon se dibuja aunque el dato venga vacio: asi se puede
           escribir a mano sobre la nota impresa. */
        .cliente .dato {
            flex: 1;
            border-bottom: 0.5pt solid #999999;
            min-height: 12pt;
        }

        .fila-corta {
            display: flex;
            gap: 0.3in;
            margin-top: 2pt;
        }

        .fila-corta .campo { flex: 1; }

        /* ---------- Tabla de productos ---------- */
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 0.14in;
            font-size: 8.5pt;
        }

        thead { display: table-header-group; }

        th {
            border: 0.75pt solid #000000;
            padding: 3pt 4pt;
            font-size: 8pt;
            letter-spacing: 0.3pt;
            background: #e8e8e8;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        td {
            border-left: 0.75pt solid #000000;
            border-right: 0.75pt solid #000000;
            padding: 2.5pt 4pt;
            vertical-align: top;
        }

        tbody tr:last-child td { border-bottom: 0.75pt solid #000000; }

        .col-cant   { width: 0.55in; text-align: center; }
        .col-precio { width: 1.05in; text-align: right; }
        .col-importe{ width: 1.15in; text-align: right; }

        .signo {
            float: left;
            font-weight: normal;
        }

        /* ---------- Total ---------- */
        .totales {
            display: flex;
            justify-content: flex-end;
            margin-top: 0.1in;
        }

        .totales table {
            width: 2.9in;
            margin-top: 0;
            font-size: 10pt;
        }

        .totales td {
            border: none;
            padding: 2pt 4pt;
        }

        .totales .rotulo { text-align: right; font-weight: bold; }
        .totales .monto  { text-align: right; width: 1.15in; }

        .totales .gran-total td {
            border-top: 1pt solid #000000;
            font-size: 11.5pt;
            font-weight: bold;
            padding-top: 3pt;
        }

        .pie {
            margin-top: 0.12in;
            font-size: 7.5pt;
            color: #444444;
            display: flex;
            justify-content: space-between;
        }

        /* ---------- Solo en pantalla ---------- */
        .barra {
            max-width: 8.5in;
            margin: 0.2in auto;
            display: flex;
            gap: 0.5rem;
            justify-content: flex-end;
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

        /* A proposito no lleva <meta viewport>: es un documento de 8.5in para
           papel, no una pantalla. Sin esa etiqueta el celular le asigna el
           ancho por defecto y ajusta la nota completa a la pantalla, que es
           justo lo que se quiere para revisarla antes de imprimir. */

        @media print {
            body { background: #ffffff; }

            /* Sin min-height al imprimir: un bloque de exactamente 5.5in dentro
               de una hoja de 5.5in puede empujar una segunda pagina en blanco
               por redondeo. Aqui la nota ocupa solo lo que necesita, y si la
               venta trae muchas piezas continua en la hoja siguiente. */
            .nota {
                margin: 0;
                min-height: 0;
            }

            .no-imprimir { display: none !important; }
        }
    </style>
</head>
<body>

<div class="barra no-imprimir">
    <button type="button" onclick="window.close()">Cerrar</button>
    <button type="button" class="principal" onclick="window.print()">Imprimir</button>
</div>

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
            <span class="dato"><?= e($direccion) ?></span>
        </div>
        <div class="fila-corta">
            <div class="campo">
                <span class="etiqueta">C.P.</span>
                <span class="dato"><?= e($cp) ?></span>
            </div>
            <div class="campo">
                <span class="etiqueta">Tel.</span>
                <span class="dato"><?= e($telefono) ?></span>
            </div>
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th class="col-cant">CANT</th>
                <th>DESCRIPCION</th>
                <th class="col-precio">P. UNITARIO</th>
                <th class="col-importe">IMPORTE</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($items as $i): ?>
                <tr>
                    <td class="col-cant"><?= (int) $i['cantidad'] ?></td>
                    <td><?= e($i['nombre_producto']) ?></td>
                    <td class="col-precio"><span class="signo">$</span><?= dinero($i['precio_aplicado']) ?></td>
                    <td class="col-importe"><span class="signo">$</span><?= dinero($i['subtotal']) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div class="totales">
        <table>
            <tr class="gran-total">
                <td class="rotulo">TOTAL</td>
                <td class="monto"><span class="signo">$</span><?= dinero($venta['total']) ?></td>
            </tr>
        </table>
    </div>

    <div class="pie">
        <span>Atendió: <?= e(trim($venta['vendedor'])) ?></span>
        <span><?= ucfirst(e($venta['tipo_pago'])) ?></span>
    </div>
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
