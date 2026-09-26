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

/**
 * Da de alta un cliente con lo minimo: su nombre y, si se tienen, los datos de
 * contacto que salen en la nota. Lo usan la pantalla de Venta (casilla "guardar
 * este cliente") y la impresion de notas, para que un nombre tecleado a mano se
 * pueda convertir en cliente del catalogo sin salir de lo que se esta haciendo.
 *
 * Si ya existe uno que se llame igual, NO se duplica: se devuelve el que hay.
 * Asi, teclear el nombre de un cliente que ya estaba registrado lo liga con el
 * de siempre en lugar de crear un segundo.
 *
 * @param  array $contacto direccion, codigo_postal y telefono (todos opcionales).
 * @param  bool  $creado   Sale en true solo si de verdad se dio de alta uno
 *                         nuevo; en false si se reuso el que ya existia.
 * @return int   Id del cliente, nuevo o existente.
 */
function altaRapidaCliente(PDO $pdo, string $nombre, array $contacto = [], ?bool &$creado = null): int
{
    $creado = false;

    $nombre = trim($nombre);

    if ($nombre === '') {
        throw new RuntimeException('Hace falta el nombre del cliente para registrarlo');
    }

    $nombre = mb_substr($nombre, 0, 150);

    // El nombre tecleado puede ser una persona o un comercio; se compara contra
    // los dos campos, sin distinguir mayusculas (lo hace la collation).
    $stmt = $pdo->prepare(
        "SELECT id FROM clientes
          WHERE nombre_comercio = :n1
             OR TRIM(CONCAT_WS(' ', nombres, apellido_paterno, apellido_materno)) = :n2
          LIMIT 1"
    );
    $stmt->execute(['n1' => $nombre, 'n2' => $nombre]);

    $existente = $stmt->fetchColumn();

    if ($existente !== false) {
        return (int) $existente;
    }

    // Se guarda en 'nombres', que es el campo obligatorio. El resto queda vacio
    // y se completa despues desde Usuarios o desde la propia nota.
    $stmt = $pdo->prepare(
        'INSERT INTO clientes (nombres, direccion, codigo_postal, telefono)
         VALUES (:nombres, :direccion, :cp, :telefono)'
    );
    $stmt->execute([
        'nombres'   => mb_substr($nombre, 0, 100),
        'direccion' => trim((string) ($contacto['direccion'] ?? '')) ?: null,
        'cp'        => trim((string) ($contacto['codigo_postal'] ?? '')) ?: null,
        'telefono'  => trim((string) ($contacto['telefono'] ?? '')) ?: null,
    ]);

    $creado = true;

    return (int) $pdo->lastInsertId();
}
