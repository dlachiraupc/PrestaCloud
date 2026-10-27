<?php

declare(strict_types=1);

namespace PrestaCloud\Core;

/**
 * Movimiento manual de caja (aporte, retiro, gasto…). Los cobros y los desembolsos NO se registran aquí:
 * la caja los toma de los pagos y de los préstamos, así no se anotan dos veces.
 * $monto siempre positivo y en céntimos; el signo sale del tipo (Reglas::signoDeTipo).
 */
final class MovimientoCaja
{
    public function __construct(
        public readonly Fecha $fecha,
        public readonly string $tipo,
        public readonly int $monto,
        public readonly bool $anulado = false,
    ) {
        if ($monto < 0) {
            throw new \InvalidArgumentException('El monto del movimiento se registra en positivo; el signo lo da el tipo.');
        }
    }
}
