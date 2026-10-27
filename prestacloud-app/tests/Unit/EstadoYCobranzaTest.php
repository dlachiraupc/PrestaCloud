<?php

declare(strict_types=1);

namespace PrestaCloud\Tests\Unit;

use PrestaCloud\Tests\Fixtures\ReglasV13;
use PHPUnit\Framework\TestCase;
use PrestaCloud\Core\Cobranza;
use PrestaCloud\Core\EstadoCobro;
use PrestaCloud\Core\EstadoPrestamo;
use PrestaCloud\Core\Panel;
use PrestaCloud\Core\ResumenPrestamo;
use PrestaCloud\Tests\Ayudas;

/** Casos T15–T17 y T22: estado del préstamo y cobranza del día. */
final class EstadoYCobranzaTest extends TestCase
{
    use Ayudas;

    public function test_t15_prestamo_pagado_completo_queda_cancelado_y_sale_de_la_cobranza(): void
    {
        $p = $this->prestamo('P001', 'C3D', '2026-09-05', 200);
        $pagos = [];
        foreach (['08', '11', '14', '17', '20', '23', '26', '29'] as $dia) {
            $pagos[] = $this->pago('P001', "2026-09-$dia", 30);
        }

        $r = ResumenPrestamo::calcular($p, $pagos, $this->f('2026-10-05'));
        $this->assertSame(EstadoPrestamo::CANCELADO, $r->estado);
        $this->assertSame(0, $r->saldo);
        $this->assertNull($r->proxima);
        $this->assertSame(0, $r->capitalEnLaCalle);
        $this->assertSame(4000, $r->gananciaCobrada);

        $this->assertSame([], Cobranza::filas([$p], $pagos, $this->f('2026-10-05')), 'ni en el día de un vencimiento pasado');
    }

    public function test_t16_prestamo_futuro_es_programado_no_resta_de_la_caja_y_se_cuenta_aparte(): void
    {
        $futuro = $this->prestamo('P001', 'C3D', '2026-10-10', 200);
        $hoy = $this->f('2026-10-03');

        $this->assertSame(EstadoPrestamo::PROGRAMADO, ResumenPrestamo::calcular($futuro, [], $hoy)->estado);

        $panel = Panel::resumen([$futuro], [], [], $hoy, ReglasV13::crear());
        $this->assertSame(0, $panel->cajaHoy, 'todavía no se entregó');
        $this->assertSame(20000, $panel->programados);
        $this->assertSame(0, $panel->prestamosVigentes);
        $this->assertSame([], Cobranza::filas([$futuro], [], $hoy));
    }

    public function test_t17_atraso_es_la_primera_cuota_mas_las_normales_vencidas_menos_lo_cobrado(): void
    {
        $p = $this->prestamo('P003', 'SEMANAL', '2026-09-07', 500);
        // Cuotas del 12, 19 y 26/09 vencidas el 27/09; no hay pagos.
        $r = ResumenPrestamo::calcular($p, [], $this->f('2026-09-27'));

        $this->assertSame(EstadoPrestamo::ATRASADO, $r->estado);
        $this->assertSame(3, $r->cuotasVencidas);
        $this->assertSame(6500 + 2 * 6000, $r->atraso, '1ra + 2 × normal');
        $this->assertSame('2026-09-12', $r->proxima?->iso());
        $this->assertSame(15, $r->diasAtraso);

        $filas = Cobranza::filas([$p], [], $this->f('2026-09-27'));
        $this->assertCount(1, $filas, 'aparece en la cobranza por su atraso');
        $this->assertSame(EstadoCobro::ATRASADO, $filas[0]->estado);
    }

    public function test_la_cuota_que_vence_hoy_todavia_no_cuenta_como_vencida(): void
    {
        $p = $this->prestamo('P001', 'C3D', '2026-09-05', 200);

        $this->assertSame(0, ResumenPrestamo::calcular($p, [], $this->f('2026-09-08'))->cuotasVencidas);
        $this->assertSame(EstadoPrestamo::AL_DIA, ResumenPrestamo::calcular($p, [], $this->f('2026-09-08'))->estado);
        $this->assertSame(1, ResumenPrestamo::calcular($p, [], $this->f('2026-09-09'))->cuotasVencidas);
        $this->assertSame(EstadoPrestamo::ATRASADO, ResumenPrestamo::calcular($p, [], $this->f('2026-09-09'))->estado);
    }

    public function test_kpis_capital_en_la_calle_y_ganancia_cobrada_son_proporcionales(): void
    {
        $p = $this->prestamo('P001', 'C3D', '2026-09-05', 200);
        $pagos = [$this->pago('P001', '2026-09-08', 30), $this->pago('P001', '2026-09-11', 30)];
        $r = ResumenPrestamo::calcular($p, $pagos, $this->f('2026-09-12'));

        $this->assertSame(18000, $r->saldo);
        $this->assertSame(15000, $r->capitalEnLaCalle, 'saldo ÷ (1 + tasa)');
        $this->assertSame(1000, $r->gananciaCobrada, 'cobrado × tasa ÷ (1 + tasa)');
        $this->assertSame(4000, $r->gananciaTotal);
    }

    public function test_t22_la_cobranza_de_un_dia_lista_lo_que_toca_y_marca_lo_cobrado(): void
    {
        $c3d = $this->prestamo('P001', 'C3D', '2026-09-05', 200);
        $sem = $this->prestamo('P002', 'SEMANAL', '2026-09-14', 300, 'C002');
        $dia = $this->f('2026-09-11');

        // Sin pagos: la cuota 1 (08/09) está atrasada y hoy vence la 2.
        $filas = Cobranza::filas([$c3d, $sem], [], $dia);
        $this->assertCount(1, $filas, 'el semanal aún no tiene cuotas');
        $this->assertSame('P001', $filas[0]->prestamo->codigo);
        $this->assertSame(6000, $filas[0]->total);
        $this->assertSame(3000, $filas[0]->atraso);
        $this->assertSame(3000, $filas[0]->cuotaDelDia);
        $this->assertSame(2, $filas[0]->cuotaNumero);
        $this->assertSame(EstadoCobro::ATRASADO, $filas[0]->estado);

        // Cobra la de hoy con fecha de hoy: queda parcial; con las dos, cobrado.
        $parcial = Cobranza::filas([$c3d, $sem], [$this->pago('P001', '2026-09-11', 30)], $dia)[0];
        $this->assertSame(EstadoCobro::PARCIAL, $parcial->estado);
        $this->assertSame(3000, $parcial->falta);
        $this->assertSame(3000, $parcial->cobradoHoy);

        $completo = Cobranza::filas([$c3d, $sem], [$this->pago('P001', '2026-09-11', 60)], $dia)[0];
        $this->assertSame(EstadoCobro::COBRADO, $completo->estado);
        $this->assertSame(0, $completo->falta);
    }

    public function test_la_cobranza_incluye_un_pago_adelantado_del_dia_aunque_no_toque_cuota(): void
    {
        $p = $this->prestamo('P003', 'SEMANAL', '2026-09-07', 500);
        $pagos = [$this->pago('P003', '2026-09-12', 65), $this->pago('P003', '2026-09-14', 60)];

        $filas = Cobranza::filas([$p], $pagos, $this->f('2026-09-14'));
        $this->assertCount(1, $filas);
        $this->assertSame(0, $filas[0]->total);
        $this->assertSame(6000, $filas[0]->cobradoHoy);
        $this->assertSame(EstadoCobro::COBRADO, $filas[0]->estado);
    }

    public function test_cobranza_en_dias_sin_cuotas_esta_vacia(): void
    {
        $p = $this->prestamo('P001', 'C3D', '2026-09-05', 200);
        $this->assertSame([], Cobranza::filas([$p], [$this->pago('P001', '2026-09-08', 30)], $this->f('2026-09-09')));
    }
}
