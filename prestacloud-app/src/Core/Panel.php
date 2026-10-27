<?php

declare(strict_types=1);

namespace PrestaCloud\Core;

/** Cifras de la pantalla de inicio (hoja INICIO del Excel). */
final class Panel
{
    private function __construct(
        public readonly int $cajaHoy,
        public readonly int $cobradoHoy,
        public readonly int $prestamosVigentes,
        public readonly int $porCobrar,
        public readonly int $capitalEnLaCalle,
        public readonly int $gananciaCobrada,
        public readonly int $prestamosAtrasados,
        public readonly int $montoAtraso,
        /** Suma de los préstamos con fecha futura, todavía por entregar. */
        public readonly int $programados,
        /** Caja + por cobrar: aproximado, cuenta intereses que aún no se ganan. */
        public readonly int $patrimonio,
    ) {
    }

    /**
     * @param list<Prestamo>       $prestamos
     * @param list<Pago>           $pagos
     * @param list<MovimientoCaja> $movimientos
     */
    public static function resumen(array $prestamos, array $pagos, array $movimientos, Fecha $hoy, Reglas $reglas): self
    {
        $caja = Caja::saldoHasta($hoy, $movimientos, $pagos, $prestamos, $reglas);
        $cobradoHoy = 0;
        foreach ($pagos as $p) {
            if (!$p->anulado && $p->fecha->igualA($hoy)) {
                $cobradoHoy += $p->monto;
            }
        }
        $vigentes = 0;
        $porCobrar = 0;
        $capital = 0;
        $ganancia = 0;
        $atrasados = 0;
        $montoAtraso = 0;
        $programados = 0;
        foreach ($prestamos as $l) {
            $r = ResumenPrestamo::calcular($l, $pagos, $hoy);
            $ganancia += $r->gananciaCobrada;
            if ($r->estado->esVigente()) {
                $vigentes++;
                $porCobrar += $r->saldo;
                $capital += $r->capitalEnLaCalle;
            }
            if ($r->estado === EstadoPrestamo::ATRASADO) {
                $atrasados++;
                $montoAtraso += $r->atraso;
            }
            if ($r->estado === EstadoPrestamo::PROGRAMADO) {
                $programados += $l->monto;
            }
        }

        return new self($caja, $cobradoHoy, $vigentes, $porCobrar, $capital, $ganancia, $atrasados, $montoAtraso, $programados, $caja + $porCobrar);
    }
}
