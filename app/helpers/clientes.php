<?php
/**
 * Saldo a favor de un cliente.
 *
 * No se guarda en ninguna columna: se calcula a partir de sus ventas.
 *   generado  = lo que pago de mas   = SUM(max(cobrado + credito_aplicado - total, 0))
 *   aplicado  = lo que ya gasto      = SUM(credito_aplicado)
 *   disponible = generado - aplicado (nunca negativo)
 *
 * Solo cuentan las ventas ligadas al cliente (cliente_id); las de nombre
 * tecleado a mano no tienen liga y no acumulan nada.
 */
function saldoFavorCliente(PDO $pdo, int $clienteId): float
{
    if ($clienteId <= 0) {
        return 0.0;
    }

    $stmt = $pdo->prepare(
        'SELECT COALESCE(SUM(GREATEST(monto_cobrado + credito_aplicado - total, 0)), 0)
              - COALESCE(SUM(credito_aplicado), 0) AS saldo
           FROM ventas
          WHERE cliente_id = :id'
    );
    $stmt->execute(['id' => $clienteId]);

    $saldo = (float) $stmt->fetchColumn();

    return $saldo > 0 ? round($saldo, 2) : 0.0;
}
