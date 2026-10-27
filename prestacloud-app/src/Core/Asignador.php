<?php

declare(strict_types=1);

namespace PrestaCloud\Core;

/**
 * Reparte los pagos de un préstamo entre sus cuotas, de la más antigua a la más reciente (FIFO),
 * y decide la puntualidad de cada pago. El resultado se recalcula siempre desde los pagos vigentes:
 * anular un pago hace que los siguientes pasen a cubrir las cuotas anteriores.
 */
final class Asignador
{
    /**
     * @param list<Pago> $pagos pagos de cualquier préstamo; aquí se filtran los de $prestamo
     *
     * @return list<PagoAsignado> en orden cronológico (fecha, hora y, a igualdad, el orden de la lista)
     */
    public static function asignar(Prestamo $prestamo, array $pagos, Reglas $reglas, ?Fecha $hasta = null): array
    {
        $propios = array_values(array_filter(
            $pagos,
            static fn (Pago $p): bool => $p->prestamoCodigo === $prestamo->codigo
                && !$p->anulado
                && ($hasta === null || $p->fecha->dias <= $hasta->dias),
        ));
        // usort es estable desde PHP 8.0: a igual fecha y hora se respeta el orden de registro.
        usort($propios, static fn (Pago $a, Pago $b): int => [$a->fecha->dias, $a->hora ?? ''] <=> [$b->fecha->dias, $b->hora ?? '']);

        $plan = $prestamo->plan;
        $acumulado = 0;
        $filas = [];
        foreach ($propios as $pago) {
            $cuota = self::cuotaQueCubre($plan, $acumulado);
            $vence = $plan->fechaCuota($cuota);
            $esperada = min($plan->total - $acumulado, $plan->acumulado($cuota) - $acumulado);
            $saldoTras = $plan->total - $acumulado - $pago->monto;
            $diff = $pago->fecha->diferencia($vence);

            $filas[] = new PagoAsignado(
                pago: $pago,
                cuota: $cuota,
                vence: $vence,
                cuotaEsperada: $esperada,
                puntualidad: self::puntualidad($pago, $diff, $reglas),
                diasTarde: max(0, $diff),
                pagadoAntes: $acumulado,
                saldoTras: $saldoTras,
                control: $saldoTras < 0 ? ControlMonto::EXCEDE : ($pago->monto !== $esperada ? ControlMonto::DISTINTO : ControlMonto::OK),
            );
            $acumulado += $pago->monto;
        }

        return $filas;
    }

    /** N.º de la cuota (1 a n) que cubre el siguiente céntimo, dado lo ya pagado. */
    public static function cuotaQueCubre(PlanDePago $plan, int $pagado): int
    {
        if ($pagado < $plan->cuotaPrimera) {
            return 1;
        }

        return min($plan->nCuotas, 2 + intdiv($pagado - $plan->cuotaPrimera, $plan->cuotaNormal));
    }

    private static function puntualidad(Pago $pago, int $diff, Reglas $reglas): Puntualidad
    {
        if ($diff > $reglas->toleranciaDias) {
            return Puntualidad::DESPUES_DE_LA_FECHA;
        }
        $despuesDeHora = $pago->despuesDeHora ?? ($pago->hora !== null && $pago->hora > $reglas->horaMaxima);
        if ($diff > 0 || ($diff === 0 && $despuesDeHora)) {
            return Puntualidad::DESPUES_DE_LA_HORA;
        }

        return $diff < 0 ? Puntualidad::ADELANTADO : Puntualidad::PUNTUAL;
    }
}
