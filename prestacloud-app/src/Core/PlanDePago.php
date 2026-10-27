<?php

declare(strict_types=1);

namespace PrestaCloud\Core;

/**
 * Cronograma de un préstamo, calculado una vez al entregarlo y guardado tal cual
 * (así cambiar un tramo después no recalcula préstamos ya entregados).
 */
final class PlanDePago
{
    /** @param list<Fecha> $fechas fecha de cada cuota, en orden */
    public function __construct(
        public readonly int $monto,
        public readonly int $tasaBp,
        public readonly int $nCuotas,
        public readonly int $total,
        public readonly int $cuotaPrimera,
        public readonly int $cuotaNormal,
        public readonly array $fechas,
    ) {
    }

    /** Monto de la cuota k (1 a n). */
    public function montoCuota(int $k): int
    {
        return $k <= 1 ? $this->cuotaPrimera : $this->cuotaNormal;
    }

    /** Lo que debería llevar pagado quien está al día con las primeras k cuotas. */
    public function acumulado(int $k): int
    {
        $k = min($k, $this->nCuotas);

        return $k <= 0 ? 0 : $this->cuotaPrimera + ($k - 1) * $this->cuotaNormal;
    }

    public function fechaCuota(int $k): Fecha
    {
        return $this->fechas[min(max($k, 1), $this->nCuotas) - 1];
    }

    public function fechaPrimera(): Fecha
    {
        return $this->fechas[0];
    }

    public function fechaFin(): Fecha
    {
        return $this->fechas[$this->nCuotas - 1];
    }

    /** Fecha de la próxima cuota si ya se pagaron $pagadas cuotas completas. */
    public function fechaTras(int $pagadas): Fecha
    {
        return $this->fechas[min(max($pagadas, 0), $this->nCuotas - 1)];
    }

    public function ganancia(): int
    {
        return $this->total - $this->monto;
    }

    /** «8 × 30.00» o «1 × 65.00 + 11 × 60.00». */
    public function descripcion(): string
    {
        $fmt = static fn (int $c): string => number_format($c / 100, 2, '.', '');
        if ($this->cuotaPrimera === $this->cuotaNormal) {
            return $this->nCuotas . ' × ' . $fmt($this->cuotaNormal);
        }

        return '1 × ' . $fmt($this->cuotaPrimera) . ' + ' . ($this->nCuotas - 1) . ' × ' . $fmt($this->cuotaNormal);
    }
}
