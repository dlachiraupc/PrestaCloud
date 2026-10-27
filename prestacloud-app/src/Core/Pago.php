<?php

declare(strict_types=1);

namespace PrestaCloud\Core;

/**
 * Un pago recibido. Nunca se borra: se anula.
 *
 * $hora en HH:MM (24 h) o null si no se conoce (pagos migrados del Excel).
 * $despuesDeHora es una marca manual que, si existe, manda sobre la hora (columna E del Excel).
 */
final class Pago
{
    public function __construct(
        public readonly int|string $id,
        public readonly string $prestamoCodigo,
        public readonly Fecha $fecha,
        public readonly int $monto,
        public readonly ?string $hora = null,
        public readonly ?bool $despuesDeHora = null,
        public readonly bool $anulado = false,
    ) {
        if ($hora !== null && !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $hora)) {
            throw new \InvalidArgumentException("Hora inválida: $hora (se espera HH:MM)");
        }
    }
}
