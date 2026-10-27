<?php

declare(strict_types=1);

namespace PrestaCloud\Core;

/** Lista de cobros de un día: préstamos con cuota ese día o con atraso, y los que ya pagaron. */
final class Cobranza
{
    /**
     * @param list<Prestamo> $prestamos
     * @param list<Pago>     $pagos
     *
     * @return list<FilaCobranza> atrasados primero, luego pendientes y parciales, al final los cobrados
     */
    public static function filas(array $prestamos, array $pagos, Fecha $dia): array
    {
        $filas = [];
        foreach ($prestamos as $l) {
            if ($dia->esAntesDe($l->fecha)) {
                continue;
            }
            $plan = $l->plan;
            $antes = 0;
            $hoy = 0;
            foreach ($pagos as $p) {
                if ($p->prestamoCodigo !== $l->codigo || $p->anulado) {
                    continue;
                }
                if ($p->fecha->dias < $dia->dias) {
                    $antes += $p->monto;
                } elseif ($p->fecha->dias === $dia->dias) {
                    $hoy += $p->monto;
                }
            }
            $hasta = 0;
            $antesDe = 0;
            $indiceDia = null;
            foreach ($plan->fechas as $i => $f) {
                if ($f->dias <= $dia->dias) {
                    $hasta++;
                }
                if ($f->dias < $dia->dias) {
                    $antesDe++;
                }
                if ($f->dias === $dia->dias) {
                    $indiceDia = $i;
                }
            }
            $total = max(0, $plan->acumulado($hasta) - $antes);
            $atraso = max(0, $plan->acumulado($antesDe) - $antes);
            if ($total <= 0 && $hoy <= 0) {
                continue;
            }
            $falta = max(0, $plan->acumulado($hasta) - ($antes + $hoy));
            $numero = $indiceDia !== null ? $indiceDia + 1 : min($plan->nCuotas, ResumenPrestamo::cuotasPagadasCon($plan, $antes + $hoy) + 1);
            $estado = match (true) {
                $hoy > 0 && $falta > 0 => EstadoCobro::PARCIAL,
                $hoy > 0 => EstadoCobro::COBRADO,
                $atraso > 0 => EstadoCobro::ATRASADO,
                default => EstadoCobro::PENDIENTE,
            };
            $filas[] = new FilaCobranza($l, $total, $atraso, max(0, $total - $atraso), $hoy, $falta, $numero, $estado);
        }

        $orden = static fn (EstadoCobro $e): int => match ($e) {
            EstadoCobro::ATRASADO => 0,
            EstadoCobro::PENDIENTE, EstadoCobro::PARCIAL => 1,
            EstadoCobro::COBRADO => 2,
        };
        usort($filas, static fn (FilaCobranza $a, FilaCobranza $b): int => [$orden($a->estado), -$a->atraso, $a->prestamo->codigo] <=> [$orden($b->estado), -$b->atraso, $b->prestamo->codigo]);

        return $filas;
    }
}
