<?php

declare(strict_types=1);

namespace PrestaCloud\Core;

/**
 * Cada cuánto se paga. Es configuración de CADA cuenta: cada prestamista define las suyas.
 *
 *  - unidad DIAS: cada N días (diaria N=1, cada 3 días N=3, quincenal N=15, semanal N=7…), con un día
 *    fijo de la semana opcional ($diaFijo, convención de WEEKDAY del Excel: 0 = ninguno, 1 = domingo … 7 = sábado).
 *  - unidad MESES: cada N meses de calendario, el mismo día del mes de la entrega (si el mes es más corto,
 *    el último día de ese mes).
 */
final class Modalidad
{
    public const DIAS = 'DIAS';
    public const MESES = 'MESES';

    public function __construct(
        public readonly string $codigo,
        public readonly int $cada,
        public readonly int $diaFijo = 0,
        public readonly string $unidad = self::DIAS,
    ) {
        if (!preg_match('/^[A-Z0-9_]{1,30}$/', $codigo)) {
            throw new \InvalidArgumentException("Código de modalidad inválido: «{$codigo}» (solo A–Z, 0–9 y _, hasta 30).");
        }
        if ($unidad !== self::DIAS && $unidad !== self::MESES) {
            throw new \InvalidArgumentException("La unidad de $codigo debe ser DIAS o MESES.");
        }
        if ($unidad === self::DIAS && ($cada < 1 || $cada > 365)) {
            throw new \InvalidArgumentException("$codigo: cada N días, con N entre 1 y 365.");
        }
        if ($unidad === self::MESES && ($cada < 1 || $cada > 12)) {
            throw new \InvalidArgumentException("$codigo: cada N meses, con N entre 1 y 12.");
        }
        if ($diaFijo < 0 || $diaFijo > 7 || ($unidad === self::MESES && $diaFijo !== 0)) {
            throw new \InvalidArgumentException("$codigo: el día fijo va de 0 (ninguno) a 7 (sábado) y solo aplica a modalidades en días.");
        }
    }

    /**
     * Fechas de las $n cuotas de un préstamo entregado en $entrega.
     *
     * DIAS sin día fijo: entrega + N, entrega + 2N…
     * DIAS con día fijo: la primera es el próximo día indicado ESTRICTAMENTE posterior a la entrega
     *   (un préstamo en sábado empieza el sábado siguiente); las demás, cada N días desde ahí.
     * MESES: entrega + N meses, + 2N meses… siempre contados desde la fecha de entrega (no se acumula el recorte
     *   de los meses cortos: 31/01 → 28/02 → 31/03).
     *
     * @return list<Fecha>
     */
    public function fechasDeCuotas(Fecha $entrega, int $n): array
    {
        $fechas = [];
        if ($this->unidad === self::MESES) {
            for ($i = 1; $i <= $n; $i++) {
                $fechas[] = $entrega->sumarMeses($i * $this->cada);
            }

            return $fechas;
        }
        $primera = $this->diaFijo === 0
            ? $entrega->sumar($this->cada)
            : $entrega->sumar(((($this->diaFijo - $entrega->diaSemanaExcel() - 1) % 7) + 7) % 7 + 1);
        for ($i = 0; $i < $n; $i++) {
            $fechas[] = $primera->sumar($i * $this->cada);
        }

        return $fechas;
    }
}
