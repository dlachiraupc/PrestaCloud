<?php

declare(strict_types=1);

namespace PrestaCloud\Core;

/** Caja de un período (bloque «Caja por período» del panel del Excel). */
final class PeriodoCaja
{
    public function __construct(
        public readonly int $saldoInicial,
        public readonly int $cobros,
        public readonly int $ingresos,
        public readonly int $prestamosEntregados,
        public readonly int $retiros,
        public readonly int $saldoFinal,
        /** Saldo final armado por partes menos la caja calculada de un tirón: debe ser 0. */
        public readonly int $control,
    ) {
    }
}
