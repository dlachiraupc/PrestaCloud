<?php

declare(strict_types=1);

namespace PrestaCloud\Core;

/** Una fila de la cobranza de un día. */
final class FilaCobranza
{
    public function __construct(
        public readonly Prestamo $prestamo,
        /** Lo que había que cobrar ese día: cuota del día más atrasos. */
        public readonly int $total,
        public readonly int $atraso,
        public readonly int $cuotaDelDia,
        /** Lo ya cobrado ese día (también si fue un pago adelantado). */
        public readonly int $cobradoHoy,
        public readonly int $falta,
        public readonly int $cuotaNumero,
        public readonly EstadoCobro $estado,
    ) {
    }
}
