<?php
/**
 * Catalogo en PDF, con FPDF (lib/fpdf.php): dos hojas carta con el diseno del
 * volante de la comercializadora (banda naranja con logo y titulo, cinta,
 * rejilla de tarjetas y pie oscuro con contacto, boton al catalogo en linea y
 * codigo QR).
 *
 *   1. Mas vendidos: filas 3-2-3-2 (10 tarjetas compactas). Si un producto
 *      tambien bajo de precio, se muestra con su "Antes / Ahora".
 *   2. Ofertas: filas 2-3 (5 tarjetas grandes que llenan la hoja).
 *
 * Cada tarjeta reserva un hueco CUADRADO para la imagen: con la imagen estandar
 * (1:1, 800x800 px, ver config/catalogo.php) llena el hueco exacto; si llega
 * otra proporcion se ajusta sin deformarse; si no hay, se dibuja un recuadro.
 *
 * Devuelve el PDF como cadena binaria.
 */

require_once __DIR__ . '/../../lib/fpdf.php';
require_once __DIR__ . '/qr.php';

class CatalogoPdf extends FPDF
{
    const NARANJA  = [226, 140, 26];
    const OSCURO   = [38, 38, 40];
    const FONDO    = [241, 241, 241];
    const ROJO     = [204, 30, 36];
    const BLANCO   = [255, 255, 255];
    const TEXTO    = [34, 34, 34];
    const GRIS     = [125, 125, 125];
    const HUECO    = [245, 245, 245];
    const LINEA    = [222, 222, 222];

    /** FPDF trabaja en Windows-1252; el texto de la BD viene en UTF-8. */
    public static function txt($texto): string
    {
        return mb_convert_encoding((string) $texto, 'Windows-1252', 'UTF-8');
    }

    public static function dinero(?float $monto): string
    {
        if ($monto === null) {
            return '';
        }
        // $1,299 en vez de $1,299.00: se lee mejor en grande. Con centavos, se dejan.
        $decimales = abs($monto - round($monto)) < 0.005 ? 0 : 2;
        return '$' . number_format($monto, $decimales);
    }

    public function color(array $rgb, string $que = 'F'): void
    {
        if ($que === 'F') {
            $this->SetFillColor(...$rgb);
        } elseif ($que === 'D') {
            $this->SetDrawColor(...$rgb);
        } else {
            $this->SetTextColor(...$rgb);
        }
    }

    // ---------- Primitivas que FPDF no trae ----------

    /** Rectangulo con esquinas redondeadas (script RoundedRect de fpdf.org). */
    public function RoundedRect($x, $y, $w, $h, $r, $style = 'F'): void
    {
        $k  = $this->k;
        $hp = $this->h;
        $op = $style === 'F' ? 'f' : (($style === 'FD' || $style === 'DF') ? 'B' : 'S');
        $a  = 4 / 3 * (sqrt(2) - 1);

        $this->_out(sprintf('%.2F %.2F m', ($x + $r) * $k, ($hp - $y) * $k));
        $xc = $x + $w - $r; $yc = $y + $r;
        $this->_out(sprintf('%.2F %.2F l', $xc * $k, ($hp - $y) * $k));
        $this->arco($xc + $r * $a, $yc - $r, $xc + $r, $yc - $r * $a, $xc + $r, $yc);
        $xc = $x + $w - $r; $yc = $y + $h - $r;
        $this->_out(sprintf('%.2F %.2F l', ($x + $w) * $k, ($hp - $yc) * $k));
        $this->arco($xc + $r, $yc + $r * $a, $xc + $r * $a, $yc + $r, $xc, $yc + $r);
        $xc = $x + $r; $yc = $y + $h - $r;
        $this->_out(sprintf('%.2F %.2F l', $xc * $k, ($hp - ($y + $h)) * $k));
        $this->arco($xc - $r * $a, $yc + $r, $xc - $r, $yc + $r * $a, $xc - $r, $yc);
        $xc = $x + $r; $yc = $y + $r;
        $this->_out(sprintf('%.2F %.2F l', $x * $k, ($hp - $yc) * $k));
        $this->arco($xc - $r, $yc - $r * $a, $xc - $r * $a, $yc - $r, $xc, $yc - $r);
        $this->_out($op);
    }

    private function arco($x1, $y1, $x2, $y2, $x3, $y3): void
    {
        $h = $this->h;
        $k = $this->k;
        $this->_out(sprintf('%.2F %.2F %.2F %.2F %.2F %.2F c',
            $x1 * $k, ($h - $y1) * $k, $x2 * $k, ($h - $y2) * $k, $x3 * $k, ($h - $y3) * $k));
    }

    /** Poligono relleno. $puntos = [[x, y], ...] en mm. */
    public function Poligono(array $puntos): void
    {
        $k = $this->k;
        $h = $this->h;
        $ops = [];
        foreach ($puntos as $i => [$x, $y]) {
            $ops[] = sprintf('%.2F %.2F %s', $x * $k, ($h - $y) * $k, $i === 0 ? 'm' : 'l');
        }
        $this->_out(implode(' ', $ops) . ' h f');
    }

    public function Circulo(float $cx, float $cy, float $r): void
    {
        $this->RoundedRect($cx - $r, $cy - $r, 2 * $r, 2 * $r, $r);
    }

    /** Texto en una sola linea, achicando la letra hasta que quepa en $ancho. */
    public function textoAjustado(float $x, float $y, float $ancho, float $alto, string $texto,
                                  float $tam, string $estilo = 'B', string $alinear = 'L', float $minimo = 5): void
    {
        $texto = self::txt($texto);
        $this->SetFont('Helvetica', $estilo, $tam);
        while ($tam > $minimo && $this->GetStringWidth($texto) > $ancho) {
            $tam -= 0.5;
            $this->SetFont('Helvetica', $estilo, $tam);
        }
        $this->SetXY($x, $y);
        $this->Cell($ancho, $alto, $texto, 0, 0, $alinear);
    }

    /** Parte el texto en a lo mas $max renglones; si sobra, el ultimo termina en "...". */
    public function renglones(string $texto, float $ancho, int $max): array
    {
        $palabras = preg_split('/\s+/', trim(self::txt($texto)));
        $lineas = [];
        $actual = '';

        foreach ($palabras as $p) {
            $prueba = $actual === '' ? $p : "{$actual} {$p}";
            if ($this->GetStringWidth($prueba) <= $ancho || $actual === '') {
                $actual = $prueba;
            } else {
                $lineas[] = $actual;
                $actual = $p;
            }
        }
        if ($actual !== '') {
            $lineas[] = $actual;
        }

        if (count($lineas) > $max) {
            $lineas = array_slice($lineas, 0, $max);
            $ultima = $lineas[$max - 1];
            $puntos = "\x85";   // "..." en Windows-1252
            while ($ultima !== '' && $this->GetStringWidth($ultima . $puntos) > $ancho) {
                $ultima = substr($ultima, 0, -1);
            }
            $lineas[$max - 1] = rtrim($ultima) . $puntos;
        }

        // Una palabra sola mas ancha que el renglon (codigos largos) se recorta.
        foreach ($lineas as &$l) {
            while (strlen($l) > 1 && $this->GetStringWidth($l) > $ancho) {
                $l = substr($l, 0, -2) . "\x85";
            }
        }
        return $lineas;
    }
}

/**
 * @param array $paginas  Cada una: ['tipo' => 'vendidos'|'ofertas', 'textos' => cfg de
 *                        esa pagina, 'productos' => filas de catalogo_ofertas.php]
 * @param array $cfg      config/catalogo.php
 * @param bool  $muestra  true = precios "Antes" inventados: el volante lo dice
 */
function construirCatalogoPdf(array $paginas, array $cfg, bool $muestra = false): string
{
    $pdf = new CatalogoPdf('P', 'mm', 'Letter');   // 215.9 x 279.4 mm
    $pdf->SetMargins(0, 0, 0);
    $pdf->SetAutoPageBreak(false);
    $pdf->SetTitle(CatalogoPdf::txt('Catalogo - ' . $cfg['negocio']), false);
    $pdf->SetAuthor(CatalogoPdf::txt($cfg['negocio']), false);

    foreach ($paginas as $pagina) {
        $pdf->AddPage();

        $pdf->color(CatalogoPdf::FONDO);
        $pdf->Rect(0, 0, $pdf->GetPageWidth(), $pdf->GetPageHeight(), 'F');

        catalogoEncabezado($pdf, $cfg['negocio'], $pagina['textos'], $muestra);

        if ($pagina['tipo'] === 'ofertas') {
            // Pocas y grandes: filas 2-3 de tarjetas verticales que llenan la hoja.
            catalogoRejilla($pdf, $pagina['productos'], [[2, 'vertical'], [3, 'vertical']], null);
        } else {
            // Diez compactas: filas 3-2-3-2, como el volante original.
            catalogoRejilla($pdf, $pagina['productos'], [[3, 'vertical'], [2, 'horizontal']], 46.5);
        }

        catalogoPie($pdf, $cfg, $muestra);
    }

    return $pdf->Output('S');
}

function catalogoEncabezado(CatalogoPdf $pdf, string $negocio, array $textos, bool $muestra): void
{
    $W = $pdf->GetPageWidth();

    $pdf->color(CatalogoPdf::NARANJA);
    $pdf->Rect(0, 0, $W, 36, 'F');

    // Logo + nombre, a la izquierda.
    $logo = __DIR__ . '/../../public/img/GA-BE_logo_blanco.png';
    if (is_file($logo)) {
        $pdf->Image($logo, 15, 2.5, 22, 22);
    }
    $pdf->color(CatalogoPdf::BLANCO, 'T');
    $partes = explode(' ', $negocio, 2);
    $pdf->textoAjustado(3, 24.5, 46, 4, $partes[0], 9, 'B', 'C');
    if (isset($partes[1])) {
        $pdf->textoAjustado(3, 28.5, 46, 4, $partes[1], 9, 'B', 'C');
    }

    // Titulo, a la derecha.
    $pdf->color(CatalogoPdf::BLANCO, 'T');
    $pdf->textoAjustado(52, 5, $W - 60, 13, $textos['titulo'], 38, 'B', 'C');
    $pdf->color(CatalogoPdf::OSCURO, 'T');
    $pdf->textoAjustado(52, 19, $W - 60, 10, $textos['subtitulo'], 25, 'B', 'C');

    // Cinta inclinada bajo la banda. En la vista previa avisa que es muestra.
    $leyenda = $muestra ? 'VISTA PREVIA - PRECIOS DE EJEMPLO, NO REPARTIR' : $textos['leyenda'];
    $pdf->SetFont('Helvetica', 'B', 9.5);
    $ancho = $pdf->GetStringWidth(CatalogoPdf::txt($leyenda)) + 18;
    $x = ($W - $ancho) / 2;
    $pdf->color($muestra ? CatalogoPdf::ROJO : CatalogoPdf::OSCURO);
    $pdf->Poligono([[$x + 3, 37.5], [$x + $ancho, 37.5], [$x + $ancho - 3, 45], [$x, 45]]);
    $pdf->color(CatalogoPdf::BLANCO, 'T');
    $pdf->SetXY($x, 37.5);
    $pdf->Cell($ancho, 7.5, CatalogoPdf::txt($leyenda), 0, 0, 'C');
}

/**
 * Rejilla de tarjetas entre la cinta y el pie.
 *
 * @param array      $patron   Filas que se repiten: [[columnas, 'vertical'|'horizontal'], ...]
 * @param float|null $altoFila Alto fijo, o null = las filas se reparten la hoja.
 *
 * Una fila incompleta conserva el tamano de tarjeta y se centra.
 */
function catalogoRejilla(CatalogoPdf $pdf, array $productos, array $patron, ?float $altoFila): void
{
    $margen = 8;
    $hueco  = 4;
    $arriba = 49;
    $abajo  = 247;
    $util = $pdf->GetPageWidth() - 2 * $margen;

    // Cuantas filas salen, para repartir el alto si no viene fijo.
    $filas = 0;
    for ($quedan = count($productos); $quedan > 0; $filas++) {
        $quedan -= $patron[$filas % count($patron)][0];
    }
    if ($altoFila === null) {
        $altoFila = $filas ? ($abajo - $arriba - ($filas - 1) * $hueco) / $filas : 0;
    }

    $y = $arriba;
    for ($fila = 0; $productos; $fila++) {
        [$columnas, $tipo] = $patron[$fila % count($patron)];
        $enFila = array_splice($productos, 0, $columnas);
        $ancho = ($util - ($columnas - 1) * $hueco) / $columnas;
        $x = $margen + ($util - (count($enFila) * $ancho + (count($enFila) - 1) * $hueco)) / 2;

        foreach ($enFila as $o) {
            if ($tipo === 'horizontal') {
                tarjetaHorizontal($pdf, $o, $x, $y, $ancho, $altoFila);
            } else {
                tarjetaVertical($pdf, $o, $x, $y, $ancho, $altoFila);
            }
            $x += $ancho + $hueco;
        }

        $y += $altoFila + $hueco;
    }
}

function fondoTarjeta(CatalogoPdf $pdf, float $x, float $y, float $w, float $h): void
{
    $pdf->color([224, 224, 224]);                     // sombra
    $pdf->RoundedRect($x + 0.5, $y + 0.7, $w, $h, 3);
    $pdf->color(CatalogoPdf::BLANCO);
    $pdf->color(CatalogoPdf::LINEA, 'D');
    $pdf->SetLineWidth(0.2);
    $pdf->RoundedRect($x, $y, $w, $h, 3, 'FD');
}

/**
 * Hueco cuadrado de la imagen. La imagen se ajusta "contain": cabe completa,
 * centrada, sin deformarse, asi que cualquier proporcion se ve bien; la
 * estandar (1:1) lo llena exacto.
 */
function imagenOHueco(CatalogoPdf $pdf, ?string $ruta, float $x, float $y, float $lado): void
{
    if ($ruta !== null) {
        $info = @getimagesize($ruta);
        if ($info && $info[0] > 0 && $info[1] > 0) {
            $escala = min($lado / $info[0], $lado / $info[1]);
            $w = $info[0] * $escala;
            $h = $info[1] * $escala;
            try {
                $pdf->Image($ruta, $x + ($lado - $w) / 2, $y + ($lado - $h) / 2, $w, $h);
                return;
            } catch (Exception $e) {
                // Formato que FPDF no lee (WebP, PNG entrelazado...): va el hueco.
                error_log('Catalogo: imagen no valida ' . $ruta . ': ' . $e->getMessage());
            }
        }
    }

    $pdf->color(CatalogoPdf::HUECO);
    $pdf->color(CatalogoPdf::LINEA, 'D');
    $pdf->RoundedRect($x, $y, $lado, $lado, 2, 'FD');

    // Dibujo de "imagen" (monte + sol) y la medida que debe tener la real.
    $cx = $x + $lado / 2;
    $cy = $y + $lado * 0.45;
    $s  = $lado * 0.16;
    $pdf->color([212, 212, 212]);
    $pdf->Poligono([[$cx - 1.6 * $s, $cy + $s], [$cx - 0.4 * $s, $cy - 0.5 * $s], [$cx + 0.3 * $s, $cy + 0.3 * $s],
                    [$cx + 0.8 * $s, $cy - 0.1 * $s], [$cx + 1.6 * $s, $cy + $s]]);
    $pdf->Circulo($cx + 0.9 * $s, $cy - 0.8 * $s, 0.32 * $s);

    $pdf->color([175, 175, 175], 'T');
    $pdf->textoAjustado($x, $y + $lado * 0.76, $lado, $lado * 0.12, '1:1 - 800x800 px', $lado * 0.24, '', 'C', 4);
}

/**
 * Barra de precio. Con oferta: "Antes" (oscuro, tachado) | "Ahora" (naranja).
 * Sin oferta (un mas vendido que no bajo): una sola barra naranja con el precio.
 */
function barraPrecio(CatalogoPdf $pdf, array $o, float $x, float $y, float $w, float $h): void
{
    $etiqueta = $h * 0.28;
    $pad = 1.8;

    if ($o['antes'] === null) {
        $pdf->color(CatalogoPdf::NARANJA);
        $pdf->RoundedRect($x, $y, $w, $h, 1.2);
        $pdf->color([255, 236, 205], 'T');
        $pdf->textoAjustado($x + $pad, $y + 0.4, $w - 2 * $pad, $etiqueta, 'Precio', $h * 0.75, '', 'L');
        $pdf->color(CatalogoPdf::BLANCO, 'T');
        $pdf->textoAjustado($x + $pad, $y + $etiqueta, $w - 2 * $pad, $h - $etiqueta,
            CatalogoPdf::dinero($o['ahora']), $h * 1.7, 'B', 'L');
        return;
    }

    $corte = $x + $w * 0.47;
    $inclina = $h * 0.35;

    $pdf->color(CatalogoPdf::NARANJA);
    $pdf->RoundedRect($x, $y, $w, $h, 1.2);
    $pdf->color(CatalogoPdf::OSCURO);
    $pdf->RoundedRect($x, $y, $corte - $x, $h, 1.2);
    $pdf->Poligono([[$corte - 2, $y], [$corte + $inclina, $y], [$corte - $inclina, $y + $h], [$corte - 2, $y + $h]]);

    $anchoIzq = $corte - $inclina - $x - $pad;
    $pdf->color([200, 200, 200], 'T');
    $pdf->textoAjustado($x + $pad, $y + 0.4, $anchoIzq, $etiqueta, 'Antes', $h * 0.75, '', 'L');
    $pdf->color(CatalogoPdf::BLANCO, 'T');
    $antes = CatalogoPdf::dinero($o['antes']);
    $pdf->textoAjustado($x + $pad, $y + $etiqueta, $anchoIzq, $h - $etiqueta, $antes, $h * 1.45, 'B', 'L');

    // Tachado del precio anterior.
    $largo = min($anchoIzq, $pdf->GetStringWidth(CatalogoPdf::txt($antes)));
    $pdf->color(CatalogoPdf::ROJO, 'D');
    $pdf->SetLineWidth(0.45 * $h / 8);
    $yt = $y + $etiqueta + ($h - $etiqueta) / 2;
    $pdf->Line($x + $pad + 1, $yt, $x + $pad + 1 + $largo, $yt);

    $xDer = $corte + $inclina + 0.6;
    $anchoDer = $x + $w - $xDer - $pad;
    $pdf->color([255, 236, 205], 'T');
    $pdf->textoAjustado($xDer, $y + 0.4, $anchoDer, $etiqueta, 'Ahora', $h * 0.75, '', 'L');
    $pdf->color(CatalogoPdf::BLANCO, 'T');
    $pdf->textoAjustado($xDer, $y + $etiqueta, $anchoDer, $h - $etiqueta, CatalogoPdf::dinero($o['ahora']), $h * 1.7, 'B', 'L');
}

function referencia(CatalogoPdf $pdf, array $o, float $x, float $y, float $w, float $tam): void
{
    $pdf->color(CatalogoPdf::TEXTO, 'T');
    $pdf->textoAjustado($x, $y, $w * 0.62, $tam * 0.45, 'REF ' . $o['codigo'], $tam, 'B', 'L');
    if (!empty($o['marca'])) {
        $pdf->color(CatalogoPdf::GRIS, 'T');
        $pdf->textoAjustado($x + $w * 0.62, $y, $w * 0.38, $tam * 0.45, $o['marca'], $tam * 0.82, '', 'R');
    }
}

function nombreProducto(CatalogoPdf $pdf, array $o, float $x, float $y, float $w, float $tam, int $max): void
{
    $pdf->SetFont('Helvetica', '', $tam);
    $pdf->color([70, 70, 70], 'T');
    $alto = $tam * 0.42;
    foreach ($pdf->renglones($o['nombre'], $w, $max) as $i => $linea) {
        $pdf->SetXY($x, $y + $i * $alto);
        $pdf->Cell($w, $alto, $linea, 0, 0, 'L');
    }
}

/** Sello estrellado de 18 picos con dos renglones de texto. */
function sello(CatalogoPdf $pdf, float $cx, float $cy, float $r, array $color, string $arriba, string $abajo): void
{
    $puntos = [];
    for ($i = 0; $i < 36; $i++) {
        $radio = $i % 2 === 0 ? $r : $r * 0.86;
        $a = M_PI * $i / 18 - M_PI / 2;
        $puntos[] = [$cx + $radio * cos($a), $cy + $radio * sin($a)];
    }
    $pdf->color($color);
    $pdf->Poligono($puntos);

    $k = $r / 10.5;
    $pdf->color(CatalogoPdf::BLANCO, 'T');
    $pdf->textoAjustado($cx - 8 * $k, $cy - 5 * $k, 16 * $k, 6.5 * $k, $arriba, 15 * $k, 'B', 'C');
    $pdf->textoAjustado($cx - 8 * $k, $cy + 1.3 * $k, 16 * $k, 3.5 * $k, $abajo, 7 * $k, 'B', 'C', 4);
}

/** Lo que dice el distintivo: el descuento si es oferta, si no el lugar en el top. */
function distintivo(array $o): array
{
    return $o['descuento'] >= 1
        ? [CatalogoPdf::ROJO, '-' . $o['descuento'] . '%', 'DESC.']
        : [CatalogoPdf::NARANJA, '#' . $o['rango'], 'TOP'];
}

/**
 * Imagen arriba, texto y precio abajo. Se adapta al tamano: compacta (fila de
 * 3 en mas vendidos) o grande (pagina de ofertas), escalando letras y barra.
 */
function tarjetaVertical(CatalogoPdf $pdf, array $o, float $x, float $y, float $w, float $h): void
{
    fondoTarjeta($pdf, $x, $y, $w, $h);

    $s = max(1, min(1.5, $h / 46.5));
    $pad = 3 * $s;

    // De abajo hacia arriba: barra, REF, nombre; lo que sobra es para la imagen.
    $altoBarra = 8 * $s;
    $yBarra = $y + $h - $pad - $altoBarra;
    $tamRef = 7.4 * $s;
    $yRef = $yBarra - 1.5 * $s - $tamRef * 0.45;
    $tamNombre = 6.6 * $s;
    $yNombre = $yRef - 0.6 * $s - 2 * $tamNombre * 0.42;
    $yImagen = $y + 2.5 * $s;
    $lado = min($w - 2 * $pad, $yNombre - 1 * $s - $yImagen);

    $xImagen = $x + ($w - $lado) / 2;
    imagenOHueco($pdf, $o['imagen'], $xImagen, $yImagen, $lado);

    [$color, $arriba, $abajo] = distintivo($o);
    if ($s > 1.2) {
        // Tarjeta grande: sello encimado en la esquina de la imagen.
        $r = 11 * $s;
        sello($pdf, min($x + $w - $r - 1.5, $xImagen + $lado - $r * 0.4), $yImagen + $r * 0.75, $r, $color, $arriba, $abajo);
    } else {
        $pdf->color($color);
        $pdf->RoundedRect($x + $w - 16.5, $y + 2.5, 14, 7, 1.2);
        $pdf->color(CatalogoPdf::BLANCO, 'T');
        $pdf->textoAjustado($x + $w - 16.5, $y + 2.5, 14, 7, $arriba, 12, 'B', 'C');
    }

    nombreProducto($pdf, $o, $x + $pad, $yNombre, $w - 2 * $pad, $tamNombre, 2);
    referencia($pdf, $o, $x + $pad, $yRef, $w - 2 * $pad, $tamRef);
    barraPrecio($pdf, $o, $x + $pad, $yBarra, $w - 2 * $pad, $altoBarra);
}

/** Imagen a la izquierda, sello arriba a la derecha (fila de 2 en mas vendidos). */
function tarjetaHorizontal(CatalogoPdf $pdf, array $o, float $x, float $y, float $w, float $h): void
{
    fondoTarjeta($pdf, $x, $y, $w, $h);

    $lado = 40;
    $pad = 3.25;
    imagenOHueco($pdf, $o['imagen'], $x + $pad, $y + $pad, $lado);

    [$color, $arriba, $abajo] = distintivo($o);
    sello($pdf, $x + $w - 13, $y + 12.5, 10.5, $color, $arriba, $abajo);

    $xd = $x + $pad + $lado + 4;
    $wd = $x + $w - $pad - $xd;

    // REF y marca en renglones separados: a un lado esta el sello y no caben juntos.
    $wt = $wd - 22;
    $pdf->color(CatalogoPdf::TEXTO, 'T');
    $pdf->textoAjustado($xd, $y + 4.5, $wt, 4, 'REF ' . $o['codigo'], 9, 'B', 'L', 6.5);
    if (!empty($o['marca'])) {
        $pdf->color(CatalogoPdf::GRIS, 'T');
        $pdf->textoAjustado($xd, $y + 8.8, $wt, 3.5, $o['marca'], 7.5, '', 'L');
    }
    nombreProducto($pdf, $o, $xd, $y + 14, $wt, 7.6, 3);
    barraPrecio($pdf, $o, $xd, $y + $h - $pad - 10, $wd, 10);
}

function catalogoPie(CatalogoPdf $pdf, array $cfg, bool $muestra): void
{
    $W = $pdf->GetPageWidth();
    $H = $pdf->GetPageHeight();
    $y0 = 251;

    $pdf->color(CatalogoPdf::NARANJA);
    $pdf->Rect(0, $y0, $W, 1.2, 'F');
    $pdf->color(CatalogoPdf::OSCURO);
    $pdf->Rect(0, $y0 + 1.2, $W, $H - $y0 - 1.2, 'F');

    // Contacto: solo los datos que existan.
    $datos = [
        'T' => $cfg['telefono'] ? 'Tel. ' . $cfg['telefono'] : '',
        'W' => $cfg['whatsapp'] ? 'WhatsApp ' . $cfg['whatsapp'] : '',
        '@' => $cfg['correo'],
    ];
    $y = $y0 + 5;
    foreach ($datos as $icono => $texto) {
        if ($texto === '') {
            continue;
        }
        $pdf->color(CatalogoPdf::NARANJA);
        $pdf->Circulo(12, $y + 2, 2);
        $pdf->color(CatalogoPdf::BLANCO, 'T');
        $pdf->textoAjustado(10, $y, 4, 4, $icono, 6.5, 'B', 'C');
        $pdf->textoAjustado(16, $y, 60, 4, $texto, 9, 'B', 'L');
        $y += 5.5;
    }

    $pdf->color([160, 160, 160], 'T');
    $vigencia = 'Precios netos con IVA incluido, vigentes al ' . date('d/m/Y') . '. Sujetos a cambio sin previo aviso.';
    if ($muestra) {
        $vigencia = 'MUESTRA: los precios "Antes" son inventados para revisar el diseño.';
    }
    $pdf->textoAjustado(10, $H - 7, 70, 3.5, $vigencia, 6, '', 'L', 4);

    // Boton al catalogo en linea (con enlace) y QR.
    $url = $cfg['url_catalogo'];
    $bx = 86; $by = $y0 + 6; $bw = 80; $bh = 14;
    $pdf->color(CatalogoPdf::NARANJA);
    $pdf->RoundedRect($bx, $by, $bw, $bh, $bh / 2);
    $pdf->color(CatalogoPdf::BLANCO, 'T');
    $pdf->textoAjustado($bx, $by + 2, $bw, 5, 'VER CATÁLOGO', 11, 'B', 'C');
    $pdf->textoAjustado($bx, $by + 7, $bw, 5, 'COMPLETO EN LÍNEA', 11, 'B', 'C');
    $pdf->Link($bx, $by, $bw, $bh, $url);
    $pdf->color([175, 175, 175], 'T');
    $pdf->textoAjustado($bx, $by + $bh + 1.2, $bw, 3.5, preg_replace('#^https?://#', '', $url), 7, '', 'C');

    // El margen blanco alrededor (~4 modulos) es parte del QR: sin el, muchos
    // celulares no lo leen sobre el fondo oscuro.
    $lado = 20;
    $qx = $W - $lado - 11;
    $qy = $y0 + 1.2 + ($H - $y0 - 1.2 - $lado) / 2;
    $pdf->color(CatalogoPdf::BLANCO);
    $pdf->RoundedRect($qx - 3, $qy - 3, $lado + 6, $lado + 6, 1.5);
    $matriz = qrMatriz($url);
    $n = count($matriz);
    $m = $lado / $n;
    $pdf->color([0, 0, 0]);
    foreach ($matriz as $fy => $fila) {
        // Rachas horizontales en un solo rectangulo: menos operaciones en el PDF
        // y sin lineas finas entre modulos al hacer zoom.
        for ($fx = 0; $fx < $n; $fx++) {
            if (!$fila[$fx]) {
                continue;
            }
            $ini = $fx;
            while ($fx + 1 < $n && $fila[$fx + 1]) {
                $fx++;
            }
            $pdf->Rect($qx + $ini * $m, $qy + $fy * $m, ($fx - $ini + 1) * $m, $m + 0.02, 'F');
        }
    }
    $pdf->Link($qx, $qy, $lado, $lado, $url);
}
