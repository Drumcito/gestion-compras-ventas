<?php
/**
 * Resumen de productos vendidos, imprimible / para guardar en PDF.
 *
 * Mismo estilo que la nota de venta, pero aqui la hoja carta VERTICAL se ocupa
 * completa y el contenido corre de una hoja a la siguiente: primero la casa y
 * debajo su tabla, igual que se ve en pantalla.
 *
 * Toma los mismos filtros del historial: ?desde=&hasta=&usuario=
 */
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit;
}

require_once __DIR__ . '/../../config/conexionBD.php';
require_once __DIR__ . '/../../app/helpers/resumen_productos.php';

// Datos del negocio que salen impresos en el encabezado.
const NEGOCIO_NOMBRE   = 'COMERCIALIZADORA GA-BE';
const NEGOCIO_TELEFONO = '49728197';

$desde = $_GET['desde'] ?? date('Y-m-d');
$hasta = $_GET['hasta'] ?? $desde;

if (!DateTime::createFromFormat('Y-m-d', $desde) || !DateTime::createFromFormat('Y-m-d', $hasta)) {
    http_response_code(400);
    exit('Fechas no válidas.');
}

if ($desde > $hasta) {
    [$desde, $hasta] = [$hasta, $desde];
}

$filtroUsuario = isset($_GET['usuario']) ? (int) $_GET['usuario'] : 0;

try {
    $pdo = Database::getConnection();

    $datos     = resumenProductosVendidos($pdo, $desde, $hasta, $filtroUsuario);
    $productos = $datos['productos'];
    $resumen   = $datos['resumen'];

    // Nombre del vendedor, solo cuando el reporte va filtrado por uno.
    $vendedor = '';

    if ($filtroUsuario > 0) {
        $stmt = $pdo->prepare(
            'SELECT CONCAT(nombre, " ", COALESCE(apellido, "")) AS nombre, numero_empleado
               FROM usuarios WHERE id = :id'
        );
        $stmt->execute(['id' => $filtroUsuario]);

        if ($fila = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $vendedor = trim($fila['nombre']) . ' (' . $fila['numero_empleado'] . ')';
        }
    }

} catch (PDOException $e) {
    error_log($e->getMessage());
    http_response_code(500);
    exit('No se pudo generar el reporte.');
}

function e(?string $texto): string
{
    return htmlspecialchars((string) $texto, ENT_QUOTES, 'UTF-8');
}

function dinero($monto): string
{
    return '$' . number_format((float) $monto, 2);
}

function entero($numero): string
{
    return number_format((float) $numero, 0);
}

function fechaCorta(string $iso): string
{
    return (new DateTime($iso))->format('d/m/Y');
}

$periodo = $desde === $hasta
    ? fechaCorta($desde)
    : fechaCorta($desde) . ' al ' . fechaCorta($hasta);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Productos vendidos <?= e($periodo) ?> - <?= NEGOCIO_NOMBRE ?></title>
    <style>
        /* Hoja carta vertical completa; el contenido corre de una a la otra. */
        @page {
            size: letter portrait;
            margin: 0.45in 0.5in;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: Arial, Helvetica, sans-serif;
            color: #000000;
            background: #9e9e9e;
        }

        .hoja {
            width: 8.5in;
            min-height: 11in;
            margin: 0 auto;
            padding: 0.45in 0.5in;
            background: #ffffff;
        }

        /* ---------- Encabezado ---------- */
        .encabezado {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 0.2in;
            border-bottom: 1pt solid #000000;
            padding-bottom: 0.12in;
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

        .titulo-reporte {
            text-align: right;
            font-size: 9pt;
            line-height: 1.5;
        }

        .titulo-reporte strong {
            display: block;
            font-size: 12pt;
            letter-spacing: 0.3pt;
        }

        /* ---------- Cifras del periodo ---------- */
        .totales-periodo {
            display: flex;
            gap: 0.25in;
            margin-top: 0.14in;
        }

        .cifra {
            flex: 1;
            border: 0.75pt solid #000000;
            padding: 5pt 7pt;
        }

        .cifra span {
            display: block;
            font-size: 7.5pt;
            letter-spacing: 0.3pt;
            color: #333333;
        }

        .cifra strong { font-size: 13pt; }

        /* ---------- Un bloque por casa ---------- */
        .bloque {
            margin-top: 0.22in;
        }

        /* El titulo de la casa nunca se queda solo al final de una hoja. */
        .bloque-titulo {
            display: flex;
            align-items: baseline;
            justify-content: space-between;
            gap: 0.2in;
            background: #e8e8e8;
            border: 0.75pt solid #000000;
            padding: 4pt 7pt;
            break-after: avoid;
            page-break-after: avoid;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        .bloque-casa {
            font-size: 11pt;
            font-weight: bold;
            letter-spacing: 0.4pt;
        }

        .bloque-cifras { font-size: 8.5pt; }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 8.5pt;
        }

        /* Si la tabla de una casa pasa de hoja, los titulos se repiten arriba. */
        thead { display: table-header-group; }

        th {
            border: 0.75pt solid #000000;
            border-top: none;
            padding: 3pt 5pt;
            font-size: 8pt;
            letter-spacing: 0.3pt;
            text-align: left;
            background: #f4f4f4;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        td {
            border: 0.75pt solid #000000;
            border-top: none;
            padding: 3pt 5pt;
            vertical-align: top;
        }

        tr { break-inside: avoid; page-break-inside: avoid; }

        .col-codigo  { width: 1.15in; }
        .col-piezas  { width: 0.7in;  text-align: right; }
        .col-ventas  { width: 0.75in; text-align: right; }
        .col-importe { width: 1.15in; text-align: right; }

        .num { text-align: right; }

        tfoot td {
            font-weight: bold;
            background: #f4f4f4;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        /* ---------- Gran total ---------- */
        .gran-total {
            display: flex;
            justify-content: flex-end;
            margin-top: 0.2in;
            break-inside: avoid;
            page-break-inside: avoid;
        }

        .gran-total table {
            width: 3.2in;
            font-size: 10pt;
        }

        .gran-total td {
            border: none;
            padding: 2pt 5pt;
        }

        .gran-total .rotulo { text-align: right; font-weight: bold; }

        .gran-total .final td {
            border-top: 1pt solid #000000;
            font-size: 11.5pt;
            font-weight: bold;
            padding-top: 4pt;
        }

        .pie {
            margin-top: 0.25in;
            padding-top: 0.08in;
            border-top: 0.5pt solid #999999;
            font-size: 7.5pt;
            color: #444444;
            display: flex;
            justify-content: space-between;
        }

        .vacio {
            margin-top: 0.4in;
            text-align: center;
            font-size: 10pt;
            color: #555555;
        }

        /* ---------- Solo en pantalla ---------- */
        .barra {
            max-width: 8.5in;
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

        @media screen {
            .hoja { box-shadow: 0 2px 8px rgba(0, 0, 0, 0.25); }
        }

        /* A proposito no lleva <meta viewport>: es un documento de 8.5in para
           papel, no una pantalla. */

        @media print {
            body { background: #ffffff; }
            .hoja {
                width: auto;
                min-height: 0;
                margin: 0;
                padding: 0;
                box-shadow: none;
            }
            .no-imprimir { display: none !important; }
        }
    </style>
</head>
<body>

<div class="barra no-imprimir">
    <span class="cuenta">
        <?= entero($resumen['distintos']) ?> producto<?= $resumen['distintos'] === 1 ? '' : 's' ?>
        · <?= count($resumen['por_casa']) ?> casa<?= count($resumen['por_casa']) === 1 ? '' : 's' ?>
    </span>
    <button type="button" onclick="window.close()">Cerrar</button>
    <button type="button" class="principal" onclick="window.print()">Guardar en PDF</button>
</div>

<div class="hoja">
    <div class="encabezado">
        <div class="marca">
            <img src="../../public/img/GA-BE_logo_oscuro.png" alt="">
            <div>
                <h1><?= NEGOCIO_NOMBRE ?></h1>
                <p>TEL. <?= NEGOCIO_TELEFONO ?></p>
            </div>
        </div>
        <div class="titulo-reporte">
            <strong>PRODUCTOS VENDIDOS</strong>
            <?= e($periodo) ?><br>
            <?= $vendedor !== '' ? 'Vendedor: ' . e($vendedor) : 'Todos los vendedores' ?>
        </div>
    </div>

    <?php if ($productos === []): ?>
        <p class="vacio">No hubo ventas en este periodo.</p>
    <?php else: ?>

        <div class="totales-periodo">
            <div class="cifra">
                <span>PIEZAS VENDIDAS</span>
                <strong><?= entero($resumen['piezas']) ?></strong>
            </div>
            <div class="cifra">
                <span>PRODUCTOS DISTINTOS</span>
                <strong><?= entero($resumen['distintos']) ?></strong>
            </div>
            <div class="cifra">
                <span>IMPORTE TOTAL</span>
                <strong><?= dinero($resumen['importe']) ?></strong>
            </div>
        </div>

        <?php foreach ($resumen['por_casa'] as $casa): ?>
            <?php $deLaCasa = productosDeCasa($productos, $casa['codigo_casa']); ?>
            <div class="bloque">
                <div class="bloque-titulo">
                    <span class="bloque-casa"><?= e($casa['casa']) ?></span>
                    <span class="bloque-cifras">
                        <?= entero($casa['piezas']) ?> pieza<?= (int) $casa['piezas'] === 1 ? '' : 's' ?> ·
                        <?= entero($casa['productos']) ?> producto<?= $casa['productos'] === 1 ? '' : 's' ?> ·
                        <?= dinero($casa['importe']) ?>
                    </span>
                </div>

                <table>
                    <thead>
                        <tr>
                            <th>PRODUCTO</th>
                            <th class="col-codigo">CÓDIGO</th>
                            <th class="col-piezas">PIEZAS</th>
                            <th class="col-ventas">VENTAS</th>
                            <th class="col-importe">IMPORTE</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($deLaCasa as $p): ?>
                            <tr>
                                <td><?= e($p['nombre_producto']) ?></td>
                                <td class="col-codigo">
                                    <?= e($p['codigo_proveedor'] ?: $p['codigo_interno_producto']) ?>
                                </td>
                                <td class="col-piezas"><?= entero($p['piezas']) ?></td>
                                <td class="col-ventas"><?= entero($p['ventas']) ?></td>
                                <td class="col-importe"><?= dinero($p['importe']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="2">TOTAL <?= e($casa['casa']) ?></td>
                            <td class="col-piezas"><?= entero($casa['piezas']) ?></td>
                            <td class="col-ventas"></td>
                            <td class="col-importe"><?= dinero($casa['importe']) ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        <?php endforeach; ?>

        <div class="gran-total">
            <table>
                <tr>
                    <td class="rotulo">Piezas vendidas:</td>
                    <td class="num"><?= entero($resumen['piezas']) ?></td>
                </tr>
                <tr>
                    <td class="rotulo">Productos distintos:</td>
                    <td class="num"><?= entero($resumen['distintos']) ?></td>
                </tr>
                <tr class="final">
                    <td class="rotulo">IMPORTE TOTAL:</td>
                    <td class="num"><?= dinero($resumen['importe']) ?></td>
                </tr>
            </table>
        </div>

    <?php endif; ?>

    <div class="pie">
        <span>Generado el <?= date('d/m/Y H:i') ?> por <?= e($_SESSION['user_name'] ?? '') ?></span>
        <span><?= NEGOCIO_NOMBRE ?></span>
    </div>
</div>

</body>
</html>
