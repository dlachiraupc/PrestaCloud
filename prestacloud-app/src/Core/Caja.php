<?php

declare(strict_types=1);

namespace PrestaCloud\Core;

/**
 * La caja es un valor CALCULADO, no un libro aparte:
 *   movimientos manuales (con su signo) + pagos cobrados − préstamos entregados.
 * Anular un pago o un movimiento corrige la caja sola.
 */
final class Caja
{
    /**
     * @param list<MovimientoCaja> $movimientos
     * @param list<Pago>           $pagos
     * @param list<Prestamo>       $prestamos
     */
    public static function saldoHasta(Fecha $dia, array $movimientos, array $pagos, array $prestamos, Reglas $reglas): int
    {
        $saldo = 0;
        foreach ($movimientos as $m) {
            if (!$m->anulado && $m->fecha->dias <= $dia->dias) {
                $saldo += $m->monto * $reglas->signoDeTipo($m->tipo);
            }
        }
        foreach ($pagos as $p) {
            if (!$p->anulado && $p->fecha->dias <= $dia->dias) {
                $saldo += $p->monto;
            }
        }
        foreach ($prestamos as $l) {
            if ($l->fecha->dias <= $dia->dias) {
                $saldo -= $l->monto;
            }
        }

        return $saldo;
    }

    /**
     * Caja de un período: el saldo inicial es la caja de todo lo anterior a «desde» (nunca se arrastra a mano).
     *
     * @param list<MovimientoCaja> $movimientos
     * @param list<Pago>           $pagos
     * @param list<Prestamo>       $prestamos
     */
    public static function periodo(Fecha $desde, Fecha $hasta, array $movimientos, array $pagos, array $prestamos, Reglas $reglas): PeriodoCaja
    {
        $enRango = static fn (Fecha $f): bool => $f->dias >= $desde->dias && $f->dias <= $hasta->dias;
        $inicial = self::saldoHasta($desde->sumar(-1), $movimientos, $pagos, $prestamos, $reglas);
        $cobros = 0;
        $ingresos = 0;
        $retiros = 0;
        $entregados = 0;
        foreach ($pagos as $p) {
            if (!$p->anulado && $enRango($p->fecha)) {
                $cobros += $p->monto;
            }
        }
        foreach ($prestamos as $l) {
            if ($enRango($l->fecha)) {
                $entregados += $l->monto;
            }
        }
        foreach ($movimientos as $m) {
            if ($m->anulado || !$enRango($m->fecha)) {
                continue;
            }
            $efecto = $m->monto * $reglas->signoDeTipo($m->tipo);
            if ($efecto > 0) {
                $ingresos += $efecto;
            } else {
                $retiros -= $efecto;
            }
        }
        $final = $inicial + $cobros + $ingresos - $entregados - $retiros;

        return new PeriodoCaja($inicial, $cobros, $ingresos, $entregados, $retiros, $final, $final - self::saldoHasta($hasta, $movimientos, $pagos, $prestamos, $reglas));
    }
}
