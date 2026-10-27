<?php

declare(strict_types=1);

namespace PrestaCloud\Core;

/** Estado de un préstamo a una fecha: lo cobrado, el saldo, el atraso y su estado. */
final class ResumenPrestamo
{
    private function __construct(
        public readonly int $cobrado,
        public readonly int $saldo,
        public readonly int $cuotasPagadas,
        public readonly int $cuotasVencidas,
        public readonly int $atraso,
        public readonly ?Fecha $proxima,
        public readonly int $diasAtraso,
        public readonly EstadoPrestamo $estado,
        public readonly int $gananciaTotal,
        public readonly int $gananciaCobrada,
        public readonly int $capitalEnLaCalle,
    ) {
    }

    /** @param list<Pago> $pagos pagos de cualquier préstamo; se cuentan los vigentes de este hasta $hoy */
    public static function calcular(Prestamo $prestamo, array $pagos, Fecha $hoy): self
    {
        $plan = $prestamo->plan;
        $cobrado = 0;
        foreach ($pagos as $p) {
            if ($p->prestamoCodigo === $prestamo->codigo && !$p->anulado && $p->fecha->dias <= $hoy->dias) {
                $cobrado += $p->monto;
            }
        }
        $saldo = $plan->total - $cobrado;

        // Una cuota que vence hoy todavía no está vencida: pasa a estarlo mañana.
        $vencidas = count(array_filter($plan->fechas, static fn (Fecha $f): bool => $f->dias < $hoy->dias));
        $atraso = max(0, $plan->acumulado($vencidas) - $cobrado);
        $pagadas = self::cuotasPagadasCon($plan, $cobrado);

        $estado = match (true) {
            $saldo <= 0 => EstadoPrestamo::CANCELADO,
            $hoy->esAntesDe($prestamo->fecha) => EstadoPrestamo::PROGRAMADO,
            $atraso > 0 => EstadoPrestamo::ATRASADO,
            default => EstadoPrestamo::AL_DIA,
        };
        $proxima = $saldo <= 0 ? null : $plan->fechaTras($pagadas);
        $base = 10000 + $plan->tasaBp;

        return new self(
            cobrado: $cobrado,
            saldo: $saldo,
            cuotasPagadas: $pagadas,
            cuotasVencidas: $vencidas,
            atraso: $atraso,
            proxima: $proxima,
            diasAtraso: ($atraso > 0 && $proxima !== null) ? $hoy->diferencia($proxima) : 0,
            estado: $estado,
            gananciaTotal: $plan->ganancia(),
            // Proporcional, como el Excel: cada sol cobrado trae consigo su parte de interés.
            gananciaCobrada: intdiv($cobrado * $plan->tasaBp * 2 + $base, 2 * $base),
            capitalEnLaCalle: in_array($estado, [EstadoPrestamo::CANCELADO, EstadoPrestamo::PROGRAMADO], true)
                ? 0
                : intdiv($saldo * 20000 + $base, 2 * $base),
        );
    }

    /** Cuotas completas que cubre lo cobrado (la 1.ª cuota puede ser distinta de las demás). */
    public static function cuotasPagadasCon(PlanDePago $plan, int $cobrado): int
    {
        if ($cobrado < $plan->cuotaPrimera) {
            return 0;
        }

        return min($plan->nCuotas, 1 + intdiv($cobrado - $plan->cuotaPrimera, $plan->cuotaNormal));
    }
}
