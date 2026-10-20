<?php
/**
 * Generador minimo de codigos QR, sin librerias (el proyecto no usa Composer).
 *
 * Solo lo necesario para el QR del catalogo: modo byte, correccion de errores
 * nivel M (~15%), versiones 1 a 10 (hasta 213 bytes; una URL sobra). Sigue la
 * norma ISO/IEC 18004 tal cual la implementa la referencia de Nayuki
 * (nayuki.io/page/qr-code-generator-library).
 *
 * Devuelve la matriz de modulos (true = negro); quien la usa decide como
 * dibujarla. El PDF la pinta con rectangulos vectoriales: nitida a cualquier
 * tamano.
 */

/**
 * @return bool[][] matriz [fila][columna]
 */
function qrMatriz(string $texto): array
{
    // Nivel M, indice = version.
    $eccPorBloque = [0, 10, 16, 26, 18, 24, 16, 18, 22, 22, 26];
    $numBloques   = [0, 1, 1, 1, 2, 2, 4, 4, 4, 5, 5];

    $largo = strlen($texto);

    for ($ver = 1; $ver <= 10; $ver++) {
        $bitsConteo = $ver <= 9 ? 8 : 16;
        $capacidad  = (intdiv(qrModulosDatos($ver), 8) - $eccPorBloque[$ver] * $numBloques[$ver]) * 8;
        if (4 + $bitsConteo + 8 * $largo <= $capacidad) {
            break;
        }
    }
    if ($ver > 10) {
        throw new RuntimeException('Texto demasiado largo para el QR');
    }

    // ---------- Bits de datos ----------
    $bits = [];
    $agregar = function (int $valor, int $n) use (&$bits) {
        for ($i = $n - 1; $i >= 0; $i--) {
            $bits[] = ($valor >> $i) & 1;
        }
    };
    $agregar(0b0100, 4);              // modo byte
    $agregar($largo, $bitsConteo);
    for ($i = 0; $i < $largo; $i++) {
        $agregar(ord($texto[$i]), 8);
    }
    $agregar(0, min(4, $capacidad - count($bits)));   // terminador
    $agregar(0, (8 - count($bits) % 8) % 8);
    for ($relleno = 0xEC; count($bits) < $capacidad; $relleno ^= 0xEC ^ 0x11) {
        $agregar($relleno, 8);
    }

    $datos = [];
    foreach (array_chunk($bits, 8) as $byte) {
        $datos[] = bindec(implode('', $byte));
    }

    // ---------- Correccion de errores y entrelazado ----------
    $ecc       = $eccPorBloque[$ver];
    $bloques   = $numBloques[$ver];
    $totalCw   = intdiv(qrModulosDatos($ver), 8);
    $cortos    = $bloques - $totalCw % $bloques;
    $largoCorto = intdiv($totalCw, $bloques);
    $divisor   = qrDivisorRS($ecc);

    $partes = [];
    for ($i = 0, $k = 0; $i < $bloques; $i++) {
        $n   = $largoCorto - $ecc + ($i < $cortos ? 0 : 1);
        $dat = array_slice($datos, $k, $n);
        $k  += $n;
        $correccion = qrRestoRS($dat, $divisor);
        if ($i < $cortos) {
            $dat[] = 0;   // hueco para igualar largos; no se transmite
        }
        $partes[] = array_merge($dat, $correccion);
    }

    $palabras = [];
    for ($i = 0, $total = count($partes[0]); $i < $total; $i++) {
        foreach ($partes as $j => $parte) {
            if ($i !== $largoCorto - $ecc || $j >= $cortos) {
                $palabras[] = $parte[$i];
            }
        }
    }

    // ---------- Matriz ----------
    $qr = new QrLienzo($ver);
    $qr->dibujarPatrones();
    $qr->dibujarPalabras($palabras);

    // Se prueba cada mascara y se queda la de menor penalizacion.
    $mejor = 0;
    $minimo = PHP_INT_MAX;
    for ($m = 0; $m < 8; $m++) {
        $qr->aplicarMascara($m);
        $qr->dibujarFormato($m);
        $p = $qr->penalizacion();
        if ($p < $minimo) {
            $minimo = $p;
            $mejor = $m;
        }
        $qr->aplicarMascara($m);   // XOR: aplicarla otra vez la quita
    }
    $qr->aplicarMascara($mejor);
    $qr->dibujarFormato($mejor);

    return $qr->modulos;
}

/** Modulos disponibles para datos + correccion en una version. */
function qrModulosDatos(int $ver): int
{
    $r = (16 * $ver + 128) * $ver + 64;
    if ($ver >= 2) {
        $alin = intdiv($ver, 7) + 2;
        $r -= (25 * $alin - 10) * $alin - 55;
        if ($ver >= 7) {
            $r -= 36;
        }
    }
    return $r;
}

/** Multiplicacion en GF(2^8) con el polinomio 0x11D. */
function qrMult(int $x, int $y): int
{
    $z = 0;
    for ($i = 7; $i >= 0; $i--) {
        $z = (($z << 1) ^ (($z >> 7) * 0x11D)) & 0xFF;
        $z ^= (($y >> $i) & 1) * $x;
    }
    return $z;
}

function qrDivisorRS(int $grado): array
{
    $r = array_fill(0, $grado, 0);
    $r[$grado - 1] = 1;
    $raiz = 1;
    for ($i = 0; $i < $grado; $i++) {
        for ($j = 0; $j < $grado; $j++) {
            $r[$j] = qrMult($r[$j], $raiz);
            if ($j + 1 < $grado) {
                $r[$j] ^= $r[$j + 1];
            }
        }
        $raiz = qrMult($raiz, 0x02);
    }
    return $r;
}

function qrRestoRS(array $datos, array $divisor): array
{
    $r = array_fill(0, count($divisor), 0);
    foreach ($datos as $b) {
        $factor = $b ^ array_shift($r);
        $r[] = 0;
        foreach ($divisor as $i => $d) {
            $r[$i] ^= qrMult($d, $factor);
        }
    }
    return $r;
}

class QrLienzo
{
    public $modulos = [];
    private $funcion = [];
    private $ver;
    private $tam;

    public function __construct(int $ver)
    {
        $this->ver = $ver;
        $this->tam = $ver * 4 + 17;
        $fila = array_fill(0, $this->tam, false);
        $this->modulos = array_fill(0, $this->tam, $fila);
        $this->funcion = array_fill(0, $this->tam, $fila);
    }

    private function poner(int $x, int $y, bool $negro): void
    {
        $this->modulos[$y][$x] = $negro;
        $this->funcion[$y][$x] = true;
    }

    public function dibujarPatrones(): void
    {
        $t = $this->tam;

        for ($i = 0; $i < $t; $i++) {           // lineas de sincronia
            $this->poner(6, $i, $i % 2 === 0);
            $this->poner($i, 6, $i % 2 === 0);
        }

        foreach ([[3, 3], [$t - 4, 3], [3, $t - 4]] as [$cx, $cy]) {   // buscadores
            for ($dy = -4; $dy <= 4; $dy++) {
                for ($dx = -4; $dx <= 4; $dx++) {
                    $x = $cx + $dx;
                    $y = $cy + $dy;
                    if ($x >= 0 && $x < $t && $y >= 0 && $y < $t) {
                        $d = max(abs($dx), abs($dy));
                        $this->poner($x, $y, $d !== 2 && $d !== 4);
                    }
                }
            }
        }

        $pos = $this->posicionesAlineacion();   // alineacion
        $n = count($pos);
        for ($i = 0; $i < $n; $i++) {
            for ($j = 0; $j < $n; $j++) {
                if (($i === 0 && $j === 0) || ($i === 0 && $j === $n - 1) || ($i === $n - 1 && $j === 0)) {
                    continue;   // esquinas de los buscadores
                }
                for ($dy = -2; $dy <= 2; $dy++) {
                    for ($dx = -2; $dx <= 2; $dx++) {
                        $this->poner($pos[$i] + $dx, $pos[$j] + $dy, max(abs($dx), abs($dy)) !== 1);
                    }
                }
            }
        }

        $this->dibujarFormato(0);   // reserva las celdas; el valor real va al final

        if ($this->ver >= 7) {      // informacion de version
            $rem = $this->ver;
            for ($i = 0; $i < 12; $i++) {
                $rem = ($rem << 1) ^ (($rem >> 11) * 0x1F25);
            }
            $bits = $this->ver << 12 | $rem;
            for ($i = 0; $i < 18; $i++) {
                $b = (($bits >> $i) & 1) === 1;
                $a = $t - 11 + $i % 3;
                $c = intdiv($i, 3);
                $this->poner($a, $c, $b);
                $this->poner($c, $a, $b);
            }
        }
    }

    private function posicionesAlineacion(): array
    {
        if ($this->ver === 1) {
            return [];
        }
        $n = intdiv($this->ver, 7) + 2;
        $paso = intdiv($this->ver * 4 + $n * 2 + 1, $n * 2 - 2) * 2;
        $r = [6];
        for ($p = $this->tam - 7; count($r) < $n; $p -= $paso) {
            array_splice($r, 1, 0, [$p]);
        }
        return $r;
    }

    public function dibujarFormato(int $mascara): void
    {
        $dato = (0 << 3) | $mascara;    // nivel M = 00
        $rem = $dato;
        for ($i = 0; $i < 10; $i++) {
            $rem = ($rem << 1) ^ (($rem >> 9) * 0x537);
        }
        $bits = (($dato << 10) | $rem) ^ 0x5412;
        $bit = function ($i) use ($bits) {
            return (($bits >> $i) & 1) === 1;
        };
        $t = $this->tam;

        for ($i = 0; $i <= 5; $i++) {
            $this->poner(8, $i, $bit($i));
        }
        $this->poner(8, 7, $bit(6));
        $this->poner(8, 8, $bit(7));
        $this->poner(7, 8, $bit(8));
        for ($i = 9; $i < 15; $i++) {
            $this->poner(14 - $i, 8, $bit($i));
        }

        for ($i = 0; $i < 8; $i++) {
            $this->poner($t - 1 - $i, 8, $bit($i));
        }
        for ($i = 8; $i < 15; $i++) {
            $this->poner(8, $t - 15 + $i, $bit($i));
        }
        $this->poner(8, $t - 8, true);   // modulo oscuro fijo
    }

    public function dibujarPalabras(array $palabras): void
    {
        $t = $this->tam;
        $total = count($palabras) * 8;
        $i = 0;
        for ($der = $t - 1; $der >= 1; $der -= 2) {
            if ($der === 6) {
                $der = 5;
            }
            $subiendo = (($der + 1) & 2) === 0;
            for ($v = 0; $v < $t; $v++) {
                for ($j = 0; $j < 2; $j++) {
                    $x = $der - $j;
                    $y = $subiendo ? $t - 1 - $v : $v;
                    if (!$this->funcion[$y][$x] && $i < $total) {
                        $this->modulos[$y][$x] = (($palabras[$i >> 3] >> (7 - ($i & 7))) & 1) === 1;
                        $i++;
                    }
                }
            }
        }
    }

    public function aplicarMascara(int $m): void
    {
        for ($y = 0; $y < $this->tam; $y++) {
            for ($x = 0; $x < $this->tam; $x++) {
                if ($this->funcion[$y][$x]) {
                    continue;
                }
                switch ($m) {
                    case 0: $inv = ($x + $y) % 2 === 0; break;
                    case 1: $inv = $y % 2 === 0; break;
                    case 2: $inv = $x % 3 === 0; break;
                    case 3: $inv = ($x + $y) % 3 === 0; break;
                    case 4: $inv = (intdiv($x, 3) + intdiv($y, 2)) % 2 === 0; break;
                    case 5: $inv = $x * $y % 2 + $x * $y % 3 === 0; break;
                    case 6: $inv = ($x * $y % 2 + $x * $y % 3) % 2 === 0; break;
                    default: $inv = (($x + $y) % 2 + $x * $y % 3) % 2 === 0;
                }
                if ($inv) {
                    $this->modulos[$y][$x] = !$this->modulos[$y][$x];
                }
            }
        }
    }

    /** Penalizacion de la norma: rachas, bloques 2x2, falsos buscadores, balance. */
    public function penalizacion(): int
    {
        $t = $this->tam;
        $p = 0;

        $lineas = [];
        for ($y = 0; $y < $t; $y++) {
            $fila = '';
            $col  = '';
            for ($x = 0; $x < $t; $x++) {
                $fila .= $this->modulos[$y][$x] ? '1' : '0';
                $col  .= $this->modulos[$x][$y] ? '1' : '0';
            }
            $lineas[] = $fila;
            $lineas[] = $col;
        }

        foreach ($lineas as $l) {
            preg_match_all('/0{5,}|1{5,}/', $l, $rachas);
            foreach ($rachas[0] as $r) {
                $p += 3 + strlen($r) - 5;
            }
            $p += 40 * (substr_count($l, '10111010000') + substr_count($l, '00001011101'));
        }

        $negros = 0;
        for ($y = 0; $y < $t; $y++) {
            for ($x = 0; $x < $t; $x++) {
                $c = $this->modulos[$y][$x];
                $negros += $c ? 1 : 0;
                if ($x < $t - 1 && $y < $t - 1
                    && $c === $this->modulos[$y][$x + 1]
                    && $c === $this->modulos[$y + 1][$x]
                    && $c === $this->modulos[$y + 1][$x + 1]) {
                    $p += 3;
                }
            }
        }

        $total = $t * $t;
        $k = (int) ceil(abs($negros * 20 - $total * 10) / $total) - 1;
        return $p + max(0, $k) * 10;
    }
}
