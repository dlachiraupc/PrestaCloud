<?php

declare(strict_types=1);

namespace PrestaCloud\Core;

/** Un pago ya asignado a la cuota que cubre, con su puntualidad. Es un valor derivado: nadie lo edita. */
final class PagoAsignado
{
    public function __construct(
        public readonly Pago $pago,
        /** N.º de la cuota más antigua que este pago cubre (1 a n). */
        public readonly int $cuota,
        public readonly Fecha $vence,
        /** Lo que faltaba para completar esa cuota. */
        public readonly int $cuotaEsperada,
        public readonly Puntualidad $puntualidad,
        public readonly int $diasTarde,
        public readonly int $pagadoAntes,
        public readonly int $saldoTras,
        public readonly ControlMonto $control,
    ) {
    }
}
