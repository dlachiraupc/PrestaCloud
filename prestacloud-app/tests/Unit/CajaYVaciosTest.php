<?php

declare(strict_types=1);

namespace PrestaCloud\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PrestaCloud\Core\Caja;
use PrestaCloud\Core\Cobranza;
use PrestaCloud\Core\MovimientoCaja;
use PrestaCloud\Core\Panel;
use PrestaCloud\Core\Reglas;
use PrestaCloud\Tests\Fixtures\ReglasV13;
use PrestaCloud\Core\ResumenCliente;
use PrestaCloud\Core\Situacion;
use PrestaCloud\Tests\Ayudas;

/** Casos T16, T21 y T24: caja, períodos y listas vacías. */
final class CajaYVaciosTest extends TestCase
{
    use Ayudas;

    /** @return array{0: list<MovimientoCaja>, 1: list<\PrestaCloud\Core\Pago>, 2: list<\PrestaCloud\Core\Prestamo>} */
    private function escenario(): array
    {
        $movs = [
            new MovimientoCaja($this->f('2026-09-05'), 'SALDO INICIAL', $this->c(1000)),
            new MovimientoCaja($this->f('2026-09-15'), 'APORTE CAPITAL', $this->c(500)),
            new MovimientoCaja($this->f('2026-09-20'), 'GASTO', $this->c(25)),
            new MovimientoCaja($this->f('2026-09-21'), 'POR CONFIRMAR', $this->c(99.99)),
        ];
        $pagos = [$this->pago('P001', '2026-09-08', 30), $this->pago('P001', '2026-09-11', 30)];
        $prestamos = [$this->prestamo('P001', 'C3D', '2026-09-05', 200)];

        return [$movs, $pagos, $prestamos];
    }

    public function test_la_caja_es_movimientos_mas_pagos_menos_prestamos(): void
    {
        [$movs, $pagos, $prestamos] = $this->escenario();
        $r = ReglasV13::crear();

        $this->assertSame(83000, Caja::saldoHasta($this->f('2026-09-10'), $movs, $pagos, $prestamos, $r), '1000 + 30 − 200');
        $this->assertSame(133500, Caja::saldoHasta($this->f('2026-09-30'), $movs, $pagos, $prestamos, $r), 'POR CONFIRMAR solo se anota');
    }

    public function test_un_pago_anulado_no_entra_en_la_caja(): void
    {
        [$movs, , $prestamos] = $this->escenario();
        $pagos = [$this->pago('P001', '2026-09-08', 30, null, null, true)];

        $this->assertSame(80000, Caja::saldoHasta($this->f('2026-09-10'), $movs, $pagos, $prestamos, ReglasV13::crear()));
    }

    public function test_t21_el_saldo_inicial_del_periodo_es_la_caja_del_dia_anterior_y_el_control_da_cero(): void
    {
        [$movs, $pagos, $prestamos] = $this->escenario();
        $r = ReglasV13::crear();

        $p = Caja::periodo($this->f('2026-09-15'), $this->f('2026-09-30'), $movs, $pagos, $prestamos, $r);
        $this->assertSame(Caja::saldoHasta($this->f('2026-09-14'), $movs, $pagos, $prestamos, $r), $p->saldoInicial);
        $this->assertSame(86000, $p->saldoInicial);
        $this->assertSame(0, $p->cobros);
        $this->assertSame(50000, $p->ingresos);
        $this->assertSame(0, $p->prestamosEntregados);
        $this->assertSame(2500, $p->retiros);
        $this->assertSame(133500, $p->saldoFinal);
        $this->assertSame(0, $p->control);

        $primero = Caja::periodo($this->f('2026-09-01'), $this->f('2026-09-10'), $movs, $pagos, $prestamos, $r);
        $this->assertSame([0, 3000, 100000, 20000, 83000, 0], [$primero->saldoInicial, $primero->cobros, $primero->ingresos, $primero->prestamosEntregados, $primero->saldoFinal, $primero->control]);
    }

    public function test_el_signo_de_cada_tipo_sale_de_la_configuracion(): void
    {
        $r = ReglasV13::crear();
        $this->assertSame(1, $r->signoDeTipo('APORTE CAPITAL'));
        $this->assertSame(-1, $r->signoDeTipo('PAGO A INVERSIONISTA'));
        $this->assertSame(0, $r->signoDeTipo('POR CONFIRMAR'));
    }

    public function test_un_tipo_de_movimiento_desconocido_no_suma_ni_resta(): void
    {
        $r = ReglasV13::crear();
        $movs = [new MovimientoCaja($this->f('2026-09-05'), 'INVENTADO', 5000)];
        $this->assertSame(0, Caja::saldoHasta($this->f('2026-09-30'), $movs, [], [], $r));
    }

    public function test_t24_sin_datos_no_hay_errores_y_todo_vale_cero(): void
    {
        $r = ReglasV13::crear();
        $hoy = $this->f('2026-10-26');

        $this->assertSame([], Cobranza::filas([], [], $hoy));
        $this->assertSame(0, Caja::saldoHasta($hoy, [], [], [], $r));

        $panel = Panel::resumen([], [], [], $hoy, $r);
        $this->assertSame([0, 0, 0, 0, 0, 0, 0, 0, 0, 0], [
            $panel->cajaHoy, $panel->cobradoHoy, $panel->prestamosVigentes, $panel->porCobrar, $panel->capitalEnLaCalle,
            $panel->gananciaCobrada, $panel->prestamosAtrasados, $panel->montoAtraso, $panel->programados, $panel->patrimonio,
        ]);

        $c = ResumenCliente::calcular('C001', [], [], $hoy, $r);
        $this->assertSame(Situacion::SIN_DEUDA, $c->situacion);
        $this->assertNull($c->calificacionAuto);
    }

    public function test_un_pago_de_un_prestamo_que_no_existe_se_ignora(): void
    {
        $p = $this->prestamo('P001', 'C3D', '2026-09-05', 200);
        $pagos = [$this->pago('P999', '2026-09-08', 30)];

        $this->assertSame([], \PrestaCloud\Core\Asignador::asignar($p, $pagos, ReglasV13::crear()));
        $this->assertSame(0, \PrestaCloud\Core\ResumenPrestamo::calcular($p, $pagos, $this->f('2026-09-09'))->cobrado);
    }
}
