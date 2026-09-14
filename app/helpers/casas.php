<?php
/**
 * Cómo se muestran las casas en pantalla.
 *
 * El código interno de la base (BNS01...BNS04) NO se toca: de él dependen los
 * 27,818 códigos de producto (BNS01-00001), el detalle de las ventas ya hechas
 * y el historial de precios. Aquí solo vive la traducción a lo que ve el
 * usuario: la etiqueta corta y el orden en que aparecen las casas.
 *
 * Para renombrar o reordenar una casa basta con editar este archivo.
 */

/** Etiqueta visible por código interno de casa. */
const CASAS_ETIQUETA = [
    'BNS02' => 'C1-MC',          // Marcus
    'BNS01' => 'C2-DP',          // DIPLOMEX
    'BNS04' => 'C3-CHOLULITA',   // Ferra Cholulita
    'BNS03' => 'C4-JD',          // La Jaladera
];

/** Posición en la que se listan (1 = primera). */
const CASAS_ORDEN = [
    'BNS02' => 1,
    'BNS01' => 2,
    'BNS04' => 3,
    'BNS03' => 4,
];

/**
 * Etiqueta corta de una casa. Si apareciera un código no registrado, se
 * devuelve tal cual para que se note en pantalla en vez de salir vacío.
 */
function etiquetaCasa(?string $codigoCasa): string
{
    return CASAS_ETIQUETA[$codigoCasa] ?? (string) $codigoCasa;
}

function ordenCasa(?string $codigoCasa): int
{
    // Las no registradas se van al final.
    return CASAS_ORDEN[$codigoCasa] ?? 99;
}

/**
 * Ordena una lista de casas y le agrega la etiqueta a cada una.
 *
 * @param array $casas Filas con la clave 'codigo_casa'.
 */
function ordenarCasas(array $casas): array
{
    foreach ($casas as &$casa) {
        $casa['etiqueta'] = etiquetaCasa($casa['codigo_casa'] ?? null);
    }
    unset($casa);

    usort($casas, fn($a, $b) => ordenCasa($a['codigo_casa'] ?? null) <=> ordenCasa($b['codigo_casa'] ?? null));

    return $casas;
}
