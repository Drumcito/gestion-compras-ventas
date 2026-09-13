<?php
/**
 * Generador minimo de archivos .xlsx sin dependencias.
 *
 * Un .xlsx es un ZIP con varios XML adentro. Este servidor no tiene la
 * extension ZipArchive ni Composer, asi que el ZIP se arma a mano con pack()
 * y crc32(), que son parte del nucleo de PHP. Los archivos se guardan sin
 * comprimir (metodo "store"): pesan mas, pero no hace falta zlib y Excel los
 * abre igual.
 *
 * Uso:
 *     $excel = new ExcelSimple();
 *     $excel->agregarHoja('Ventas', ['Folio', 'Total'], [[1, 99.5], [2, 40.0]]);
 *     $excel->descargar('ventas.xlsx');
 *
 * Los valores int se escriben como numero entero, los float como moneda con
 * dos decimales, y todo lo demas como texto. Al ser celdas de texto reales
 * (no CSV), un nombre que empiece con "=" nunca se interpreta como formula.
 */
class ExcelSimple
{
    /** @var array<int, array{nombre: string, encabezados: array, filas: array, anchos: array}> */
    private array $hojas = [];

    public function agregarHoja(string $nombre, array $encabezados, array $filas, array $anchos = []): void
    {
        $this->hojas[] = [
            // Excel no admite estos caracteres en el nombre de una pestaña.
            'nombre'      => mb_substr(str_replace(['\\', '/', '*', '?', ':', '[', ']'], '', $nombre), 0, 31),
            'encabezados' => $encabezados,
            'filas'       => $filas,
            'anchos'      => $anchos,
        ];
    }

    public function descargar(string $archivo): void
    {
        $contenido = $this->construir();

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $archivo . '"');
        header('Content-Length: ' . strlen($contenido));
        header('Cache-Control: no-store');

        echo $contenido;
    }

    // ------------------------------------------------------------------
    // Armado del paquete
    // ------------------------------------------------------------------

    private function construir(): string
    {
        $partes = [
            '[Content_Types].xml'      => $this->contentTypes(),
            '_rels/.rels'              => $this->relsPrincipales(),
            'xl/workbook.xml'          => $this->workbook(),
            'xl/_rels/workbook.xml.rels' => $this->relsWorkbook(),
            'xl/styles.xml'            => $this->estilos(),
        ];

        foreach ($this->hojas as $i => $hoja) {
            $partes['xl/worksheets/sheet' . ($i + 1) . '.xml'] = $this->hoja($hoja);
        }

        return $this->zip($partes);
    }

    private function contentTypes(): string
    {
        $hojas = '';
        foreach ($this->hojas as $i => $_) {
            $hojas .= '<Override PartName="/xl/worksheets/sheet' . ($i + 1) . '.xml"'
                    . ' ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . $hojas
            . '</Types>';
    }

    private function relsPrincipales(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';
    }

    private function workbook(): string
    {
        $hojas = '';
        foreach ($this->hojas as $i => $hoja) {
            $hojas .= '<sheet name="' . $this->x($hoja['nombre']) . '" sheetId="' . ($i + 1) . '" r:id="rId' . ($i + 1) . '"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets>' . $hojas . '</sheets></workbook>';
    }

    private function relsWorkbook(): string
    {
        $rels = '';
        foreach ($this->hojas as $i => $_) {
            $rels .= '<Relationship Id="rId' . ($i + 1) . '"'
                   . ' Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet"'
                   . ' Target="worksheets/sheet' . ($i + 1) . '.xml"/>';
        }

        $rels .= '<Relationship Id="rId' . (count($this->hojas) + 1) . '"'
               . ' Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles"'
               . ' Target="styles.xml"/>';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . $rels . '</Relationships>';
    }

    /**
     * Paleta igual a la de la pagina web: encabezado en gris carbon con letra
     * blanca, y filas alternando blanco con el mismo verde muy claro.
     */
    private function estilos(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<numFmts count="1"><numFmt numFmtId="164" formatCode="&quot;$&quot;#,##0.00"/></numFmts>'
            . '<fonts count="2">'
                . '<font><sz val="11"/><name val="Calibri"/></font>'
                . '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'
            . '</fonts>'
            . '<fills count="4">'
                . '<fill><patternFill patternType="none"/></fill>'
                . '<fill><patternFill patternType="gray125"/></fill>'
                . '<fill><patternFill patternType="solid"><fgColor rgb="FF34373C"/><bgColor indexed="64"/></patternFill></fill>'
                . '<fill><patternFill patternType="solid"><fgColor rgb="FFF4F6F4"/><bgColor indexed="64"/></patternFill></fill>'
            . '</fills>'
            . '<borders count="2">'
                . '<border><left/><right/><top/><bottom/><diagonal/></border>'
                . '<border><left/><right/><top/><bottom style="thin"><color rgb="FFDDDDDD"/></bottom><diagonal/></border>'
            . '</borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="8">'
                // 0 general
                . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
                // 1 encabezado
                . '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1">'
                    . '<alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
                // 2 texto, fila blanca
                . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1">'
                    . '<alignment vertical="center"/></xf>'
                // 3 texto, fila clara
                . '<xf numFmtId="0" fontId="0" fillId="3" borderId="1" xfId="0" applyFill="1" applyBorder="1" applyAlignment="1">'
                    . '<alignment vertical="center"/></xf>'
                // 4 moneda, fila blanca
                . '<xf numFmtId="164" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1"/>'
                // 5 moneda, fila clara
                . '<xf numFmtId="164" fontId="0" fillId="3" borderId="1" xfId="0" applyNumberFormat="1" applyFill="1" applyBorder="1"/>'
                // 6 entero, fila blanca
                . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1"/>'
                // 7 entero, fila clara
                . '<xf numFmtId="0" fontId="0" fillId="3" borderId="1" xfId="0" applyFill="1" applyBorder="1"/>'
            . '</cellXfs>'
            // El estilo "Normal" va despues de cellXfs (asi lo pide el esquema).
            // Sin el, algunas versiones de Excel avisan de "contenido ilegible".
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>';
    }

    private function hoja(array $hoja): string
    {
        $columnas = count($hoja['encabezados']);

        $cols = '';
        if ($hoja['anchos'] !== []) {
            $cols = '<cols>';
            foreach ($hoja['anchos'] as $i => $ancho) {
                $cols .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . $ancho . '" customWidth="1"/>';
            }
            $cols .= '</cols>';
        }

        // Encabezado
        $filas = '<row r="1" ht="28" customHeight="1">';
        foreach ($hoja['encabezados'] as $i => $titulo) {
            $filas .= '<c r="' . $this->columna($i) . '1" s="1" t="inlineStr"><is><t>'
                    . $this->x((string) $titulo) . '</t></is></c>';
        }
        $filas .= '</row>';

        // Datos, alternando blanco y color claro
        foreach ($hoja['filas'] as $n => $fila) {
            $numero = $n + 2;
            $clara  = ($n % 2) === 1;

            $filas .= '<row r="' . $numero . '">';

            foreach (array_values($fila) as $i => $valor) {
                $ref = $this->columna($i) . $numero;

                if (is_int($valor)) {
                    $estilo = $clara ? 7 : 6;
                    $filas .= '<c r="' . $ref . '" s="' . $estilo . '"><v>' . $valor . '</v></c>';
                } elseif (is_float($valor)) {
                    $estilo = $clara ? 5 : 4;
                    $filas .= '<c r="' . $ref . '" s="' . $estilo . '"><v>' . round($valor, 2) . '</v></c>';
                } else {
                    $estilo = $clara ? 3 : 2;
                    $filas .= '<c r="' . $ref . '" s="' . $estilo . '" t="inlineStr"><is><t xml:space="preserve">'
                            . $this->x((string) $valor) . '</t></is></c>';
                }
            }

            $filas .= '</row>';
        }

        $ultima = $this->columna($columnas - 1);
        $total  = count($hoja['filas']) + 1;

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<dimension ref="A1:' . $ultima . $total . '"/>'
            // Encabezado congelado: al bajar en la lista siguen viendose los titulos.
            . '<sheetViews><sheetView workbookViewId="0">'
                . '<pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/>'
            . '</sheetView></sheetViews>'
            . '<sheetFormatPr defaultRowHeight="15"/>'
            . $cols
            . '<sheetData>' . $filas . '</sheetData>'
            . '<autoFilter ref="A1:' . $ultima . '1"/>'
            . '</worksheet>';
    }

    private function columna(int $indice): string
    {
        $letra = '';
        $indice++;

        while ($indice > 0) {
            $resto  = ($indice - 1) % 26;
            $letra  = chr(65 + $resto) . $letra;
            $indice = (int) (($indice - $resto - 1) / 26);
        }

        return $letra;
    }

    private function x(string $texto): string
    {
        return htmlspecialchars($texto, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    // ------------------------------------------------------------------
    // ZIP sin extensiones: cabecera local + directorio central + EOCD
    // ------------------------------------------------------------------

    private function zip(array $partes): string
    {
        $ahora   = getdate();
        $hora    = ($ahora['hours'] << 11) | ($ahora['minutes'] << 5) | (int) ($ahora['seconds'] / 2);
        $fecha   = (($ahora['year'] - 1980) << 9) | ($ahora['mon'] << 5) | $ahora['mday'];

        $datos      = '';
        $central    = '';
        $desplazado = 0;

        foreach ($partes as $nombre => $contenido) {
            $crc  = crc32($contenido);
            $tam  = strlen($contenido);

            $cabecera = pack('V', 0x04034b50)   // firma
                      . pack('v', 20)            // version necesaria
                      . pack('v', 0)             // banderas
                      . pack('v', 0)             // metodo: sin comprimir
                      . pack('v', $hora)
                      . pack('v', $fecha)
                      . pack('V', $crc)
                      . pack('V', $tam)          // tamaño comprimido
                      . pack('V', $tam)          // tamaño real
                      . pack('v', strlen($nombre))
                      . pack('v', 0)             // extra
                      . $nombre;

            $datos .= $cabecera . $contenido;

            $central .= pack('V', 0x02014b50)
                      . pack('v', 20)            // version que lo creo
                      . pack('v', 20)
                      . pack('v', 0)
                      . pack('v', 0)
                      . pack('v', $hora)
                      . pack('v', $fecha)
                      . pack('V', $crc)
                      . pack('V', $tam)
                      . pack('V', $tam)
                      . pack('v', strlen($nombre))
                      . pack('v', 0)             // extra
                      . pack('v', 0)             // comentario
                      . pack('v', 0)             // disco
                      . pack('v', 0)             // atributos internos
                      . pack('V', 32)            // atributos externos
                      . pack('V', $desplazado)
                      . $nombre;

            $desplazado += strlen($cabecera) + $tam;
        }

        $fin = pack('V', 0x06054b50)
             . pack('v', 0)
             . pack('v', 0)
             . pack('v', count($partes))
             . pack('v', count($partes))
             . pack('V', strlen($central))
             . pack('V', $desplazado)
             . pack('v', 0);

        return $datos . $central . $fin;
    }
}
