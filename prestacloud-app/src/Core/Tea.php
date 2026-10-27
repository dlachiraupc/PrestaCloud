<?php

declare(strict_types=1);

namespace PrestaCloud\Core;

/** Tasa efectiva anual equivalente de un préstamo, para mostrarla como referencia. */
final class Tea
{
    /**
     * Resuelve la tasa diaria que iguala el monto entregado con el valor presente de las cuotas
     * (cada cuota descontada por los días que pasan desde la entrega) y la lleva a 365 días.
     *
     * @return float proporción: 27.36 equivale a 2,736 %
     */
    public static function anual(PlanDePago $plan, Fecha $entrega): float
    {
        $flujos = [];
        foreach ($plan->fechas as $i => $f) {
            $flujos[] = [$f->diferencia($entrega), $plan->montoCuota($i + 1)];
        }
        $bajo = 0.0;
        $alto = 1.0;
        for ($i = 0; $i < 100; $i++) {
            $medio = ($bajo + $alto) / 2;
            $valorPresente = 0.0;
            foreach ($flujos as [$dias, $cuota]) {
                $valorPresente += $cuota / (1 + $medio) ** $dias;
            }
            if ($valorPresente > $plan->monto) {
                $bajo = $medio;
            } else {
                $alto = $medio;
            }
        }

        return (1 + ($bajo + $alto) / 2) ** 365 - 1;
    }
}
