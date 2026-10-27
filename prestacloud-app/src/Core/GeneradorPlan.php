<?php

declare(strict_types=1);

namespace PrestaCloud\Core;

final class GeneradorPlan
{
    /**
     * Calcula el plan de pago de un préstamo.
     *
     *  - total = monto × (1 + tasa), redondeado al céntimo.
     *  - cuota normal = total ÷ n hacia abajo, a céntimos (redondeo 0) o a múltiplos de N soles (redondeo N).
     *  - 1.ª cuota = total − (n − 1) × cuota normal: lo que sobra por redondeo se suma a la primera.
     *
     * Todo con enteros (céntimos): sin errores de coma flotante.
     *
     * @throws SinTramo si la modalidad no existe o ningún tramo cubre el monto
     */
    public static function generar(Reglas $reglas, string $modalidad, Fecha $entrega, int $monto): PlanDePago
    {
        if ($monto <= 0) {
            throw new \InvalidArgumentException('El monto a prestar debe ser mayor a cero.');
        }
        $mod = $reglas->modalidad($modalidad);
        $tramo = $reglas->tramoPara($modalidad, $monto)
            ?? throw new SinTramo("No hay un tramo de $modalidad que cubra ese monto: agrega uno «desde 0» en la configuración.");

        $total = intdiv($monto * (10000 + $tramo->tasaBp) + 5000, 10000);
        $unidad = $tramo->redondeo > 0 ? $tramo->redondeo * 100 : 1;
        $normal = intdiv($total, $tramo->nCuotas * $unidad) * $unidad;
        if ($normal <= 0) {
            throw new SinTramo('Con ese redondeo la cuota sale en cero: baja el redondeo o sube el monto.');
        }
        $primera = $total - ($tramo->nCuotas - 1) * $normal;

        $fechas = $mod->fechasDeCuotas($entrega, $tramo->nCuotas);

        return new PlanDePago($monto, $tramo->tasaBp, $tramo->nCuotas, $total, $primera, $normal, $fechas);
    }
}
