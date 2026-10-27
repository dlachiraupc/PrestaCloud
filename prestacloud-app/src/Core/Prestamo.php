<?php

declare(strict_types=1);

namespace PrestaCloud\Core;

/** Un préstamo entregado, con el plan de pago que se calculó al entregarlo. */
final class Prestamo
{
    public function __construct(
        public readonly string $codigo,
        public readonly string $clienteCodigo,
        public readonly string $modalidad,
        public readonly Fecha $fecha,
        public readonly int $monto,
        public readonly PlanDePago $plan,
    ) {
    }

    /** Crea el préstamo calculando su plan con las reglas vigentes. */
    public static function crear(Reglas $reglas, string $codigo, string $clienteCodigo, string $modalidad, Fecha $fecha, int $monto): self
    {
        return new self($codigo, $clienteCodigo, $modalidad, $fecha, $monto, GeneradorPlan::generar($reglas, $modalidad, $fecha, $monto));
    }
}
