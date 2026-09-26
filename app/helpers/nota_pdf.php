<?php
/**
 * Genera el PDF de una nota de venta con FPDF (libreria en lib/fpdf.php).
 *
 * Devuelve el PDF como cadena binaria, lista para adjuntar a un correo o para
 * mandarla al navegador. La info es la misma que la nota imprimible de
 * views/ventas/nota.php.
 */

require_once __DIR__ . '/../../lib/fpdf.php';

const NOTA_NEGOCIO_NOMBRE   = 'COMERCIALIZADORA GA-BE';
const NOTA_NEGOCIO_TELEFONO = '49728197';

/** FPDF usa latin1; el texto viene en UTF-8, hay que convertirlo. */
function nota_txt($texto): string
{
    return iconv('UTF-8', 'ISO-8859-1//TRANSLIT', (string) $texto);
}

function nota_dinero($monto): string
{
    return '$' . number_format((float) $monto, 2);
}

/**
 * @param array $venta id, cliente, fecha, total, credito_aplicado, tipo_pago, vendedor
 * @param array $items nombre_producto, precio_aplicado, cantidad, subtotal
 */
function construirNotaPdf(array $venta, array $items): string
{
    $pdf = new FPDF('P', 'mm', 'Letter');   // 215.9 x 279.4 mm
    $pdf->SetMargins(15, 14, 15);
    $pdf->SetAutoPageBreak(true, 15);
    $pdf->AddPage();

    $anchoUtil = 215.9 - 30;   // 185.9 mm

    // ---------- Encabezado ----------
    $logo = __DIR__ . '/../../public/img/GA-BE_logo_oscuro.png';
    if (is_file($logo)) {
        $pdf->Image($logo, 15, 12, 18, 18);
    }

    $pdf->SetXY($logo && is_file($logo) ? 36 : 15, 14);
    $pdf->SetFont('Arial', 'B', 16);
    $pdf->Cell(120, 7, nota_txt(NOTA_NEGOCIO_NOMBRE), 0, 2);
    $pdf->SetFont('Arial', '', 9);
    $pdf->Cell(120, 5, nota_txt('Tel. ' . NOTA_NEGOCIO_TELEFONO), 0, 0);

    // Folio y fecha, alineados a la derecha.
    $fecha = (new DateTime($venta['fecha']))->format('d/m/Y H:i');
    $pdf->SetXY(-70, 14);
    $pdf->SetFont('Arial', '', 9);
    $pdf->Cell(55, 5, nota_txt($fecha), 0, 2, 'R');
    $pdf->SetFont('Arial', 'B', 11);
    $pdf->Cell(55, 6, nota_txt('No. VENTA ' . (int) $venta['id']), 0, 0, 'R');

    $pdf->SetY(34);
    $pdf->SetDrawColor(180, 180, 180);
    $pdf->Line(15, $pdf->GetY(), 15 + $anchoUtil, $pdf->GetY());
    $pdf->Ln(4);

    // ---------- Cliente ----------
    // Direccion, C.P. y telefono salen del cliente registrado (tabla clientes).
    // Los renglones que no tenga dato no se dibujan: la nota no deja huecos.
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->Cell(22, 6, nota_txt('Cliente:'), 0, 0);
    $pdf->SetFont('Arial', '', 10);
    $pdf->Cell(0, 6, nota_txt($venta['cliente'] !== null && $venta['cliente'] !== '' ? $venta['cliente'] : 'Publico en general'), 0, 1);

    $renglones = [
        'Direccion:' => $venta['cliente_direccion'] ?? '',
        'C.P.:'      => $venta['cliente_cp'] ?? '',
        'Tel.:'      => $venta['cliente_telefono'] ?? '',
    ];

    foreach ($renglones as $etiqueta => $valor) {
        if (trim((string) $valor) === '') {
            continue;
        }

        $pdf->SetFont('Arial', 'B', 10);
        $pdf->Cell(22, 5.5, nota_txt($etiqueta), 0, 0);
        $pdf->SetFont('Arial', '', 10);
        // MultiCell por si la direccion no cabe en un renglon.
        $pdf->MultiCell(0, 5.5, nota_txt($valor), 0, 'L');
    }

    $pdf->Ln(2);

    // ---------- Tabla de productos ----------
    $wCant = 18; $wPrecio = 32; $wImporte = 34;
    $wDesc = $anchoUtil - $wCant - $wPrecio - $wImporte;

    $pdf->SetFont('Arial', 'B', 9);
    $pdf->SetFillColor(232, 232, 232);
    $pdf->Cell($wCant, 7, nota_txt('CANT'), 1, 0, 'C', true);
    $pdf->Cell($wDesc, 7, nota_txt('DESCRIPCION'), 1, 0, 'L', true);
    $pdf->Cell($wPrecio, 7, nota_txt('P. UNITARIO'), 1, 0, 'R', true);
    $pdf->Cell($wImporte, 7, nota_txt('IMPORTE'), 1, 1, 'R', true);

    $pdf->SetFont('Arial', '', 9);

    foreach ($items as $i) {
        $nombre = nota_txt($i['nombre_producto']);

        // El nombre puede ser largo: se calcula cuantas lineas ocupa para que
        // toda la fila tenga la misma altura.
        $lineas = max(1, ceil($pdf->GetStringWidth($nombre) / ($wDesc - 2)));
        $alto   = 5 * $lineas;

        $x = $pdf->GetX();
        $y = $pdf->GetY();

        $pdf->Cell($wCant, $alto, (string) (int) $i['cantidad'], 1, 0, 'C');

        // Descripcion con salto de linea dentro de su celda.
        $pdf->MultiCell($wDesc, 5, $nombre, 1, 'L');

        // MultiCell mueve el cursor abajo; se reposiciona para las columnas de la derecha.
        $pdf->SetXY($x + $wCant + $wDesc, $y);
        $pdf->Cell($wPrecio, $alto, nota_dinero($i['precio_aplicado']), 1, 0, 'R');
        $pdf->Cell($wImporte, $alto, nota_dinero($i['subtotal']), 1, 1, 'R');
    }

    // ---------- Totales ----------
    $pdf->Ln(3);
    $saldo = (float) ($venta['credito_aplicado'] ?? 0);
    $rotulo = $anchoUtil - $wImporte - $wPrecio;

    $filaTotal = function (string $texto, string $monto, bool $fuerte = false) use ($pdf, $rotulo, $wPrecio, $wImporte) {
        $pdf->SetFont('Arial', $fuerte ? 'B' : '', $fuerte ? 12 : 10);
        $pdf->Cell($rotulo, 7, '', 0, 0);
        $pdf->Cell($wPrecio, 7, nota_txt($texto), 0, 0, 'R');
        $pdf->Cell($wImporte, 7, nota_txt($monto), $fuerte ? 'T' : 0, 1, 'R');
    };

    if ($saldo > 0) {
        $filaTotal('SUBTOTAL', nota_dinero($venta['total']));
        $filaTotal('SALDO A FAVOR', '-' . nota_dinero($saldo));
        $filaTotal('TOTAL A PAGAR', nota_dinero(max((float) $venta['total'] - $saldo, 0)), true);
    } else {
        $filaTotal('TOTAL', nota_dinero($venta['total']), true);
    }

    // ---------- Pie ----------
    $pdf->Ln(6);
    $pdf->SetFont('Arial', '', 9);
    $pdf->SetTextColor(90, 90, 90);
    $pdf->Cell(0, 5, nota_txt('Atendio: ' . trim((string) ($venta['vendedor'] ?? ''))), 0, 1);
    $pdf->Cell(0, 5, nota_txt('Pago: ' . ucfirst((string) $venta['tipo_pago'])), 0, 1);

    return $pdf->Output('S');
}
