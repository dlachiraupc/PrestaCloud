<?php

declare(strict_types=1);

namespace PrestaCloud\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PrestaCloud\Core\Asignador;
use PrestaCloud\Core\ControlMonto;
use PrestaCloud\Core\Puntualidad;
use PrestaCloud\Core\Reglas;
use PrestaCloud\Tests\Fixtures\ReglasV13;
use PrestaCloud\Core\ResumenPrestamo;
use PrestaCloud\Tests\Ayudas;

/** Casos T7–T14: asignación de pagos a cuotas (FIFO derivado) y puntualidad. */
final class AsignacionPagosTest extends TestCase
{
    use Ayudas;

    public function test_t07_pagos_de_un_semanal_de_500_son_cuotas_1_2_3_puntuales(): void
    {
        $p = $this->prestamo('P003', 'SEMANAL', '2026-09-07', 500);
        $pagos = [
            $this->pago('P003', '2026-09-12', 65),
            $this->pago('P003', '2026-09-19', 60),
            $this->pago('P003', '2026-09-26', 60),
        ];

        $filas = Asignador::asignar($p, $pagos, ReglasV13::crear());

        $this->assertSame([1, 2, 3], array_map(fn ($a) => $a->cuota, $filas));
        $this->assertSame([6500, 6000, 6000], array_map(fn ($a) => $a->cuotaEsperada, $filas));
        $this->assertSame([ControlMonto::OK, ControlMonto::OK, ControlMonto::OK], array_map(fn ($a) => $a->control, $filas));
        $this->assertSame([Puntualidad::PUNTUAL, Puntualidad::PUNTUAL, Puntualidad::PUNTUAL], array_map(fn ($a) => $a->puntualidad, $filas));
        $this->assertSame(72500 - 6500 - 6000 - 6000, $filas[2]->saldoTras);
    }

    public function test_t08_pago_dos_dias_tarde_es_despues_de_la_fecha(): void
    {
        $p = $this->prestamo('P001', 'C3D', '2026-09-05', 200);
        $a = Asignador::asignar($p, [$this->pago('P001', '2026-09-10', 30)], ReglasV13::crear())[0];

        $this->assertSame(Puntualidad::DESPUES_DE_LA_FECHA, $a->puntualidad);
        $this->assertSame(2, $a->diasTarde);
        $this->assertSame('2026-09-08', $a->vence->iso());
    }

    public function test_t09_el_mismo_dia_despues_de_la_hora_maxima_es_despues_de_la_hora(): void
    {
        $p = $this->prestamo('P001', 'C3D', '2026-09-05', 200);
        $r = ReglasV13::crear();

        $marcado = Asignador::asignar($p, [$this->pago('P001', '2026-09-08', 30, null, true)], $r)[0];
        $this->assertSame(Puntualidad::DESPUES_DE_LA_HORA, $marcado->puntualidad, 'marca manual del Excel (columna E = SÍ)');

        $porHora = fn (string $hora) => Asignador::asignar($p, [$this->pago('P001', '2026-09-08', 30, $hora)], $r)[0]->puntualidad;
        $this->assertSame(Puntualidad::DESPUES_DE_LA_HORA, $porHora('17:30'));
        $this->assertSame(Puntualidad::DESPUES_DE_LA_HORA, $porHora('17:01'));
        $this->assertSame(Puntualidad::PUNTUAL, $porHora('17:00'), 'justo a la hora máxima todavía es puntual');
        $this->assertSame(Puntualidad::PUNTUAL, $porHora('11:42'));
    }

    public function test_t10_pago_antes_del_vencimiento_es_adelantado(): void
    {
        $p = $this->prestamo('P001', 'C3D', '2026-09-05', 200);
        $a = Asignador::asignar($p, [$this->pago('P001', '2026-09-07', 30)], ReglasV13::crear())[0];

        $this->assertSame(Puntualidad::ADELANTADO, $a->puntualidad);
        $this->assertSame(0, $a->diasTarde);
    }

    public function test_t11_la_tolerancia_convierte_un_dia_tarde_en_despues_de_la_hora(): void
    {
        $p = $this->prestamo('P001', 'C3D', '2026-09-05', 200);
        $pago = $this->pago('P001', '2026-09-09', 30);

        $this->assertSame(Puntualidad::DESPUES_DE_LA_FECHA, Asignador::asignar($p, [$pago], ReglasV13::crear())[0]->puntualidad);
        $this->assertSame(Puntualidad::DESPUES_DE_LA_HORA, Asignador::asignar($p, [$pago], ReglasV13::crear()->conTolerancia(1))[0]->puntualidad);
        $this->assertSame(1, Asignador::asignar($p, [$pago], ReglasV13::crear()->conTolerancia(1))[0]->diasTarde, 'los días tarde se cuentan igual');
    }

    public function test_t12_pago_parcial_la_siguiente_espera_el_resto_de_la_misma_cuota(): void
    {
        $p = $this->prestamo('P001', 'C3D', '2026-09-05', 200);
        [$a, $b] = Asignador::asignar($p, [$this->pago('P001', '2026-09-08', 20), $this->pago('P001', '2026-09-08', 10)], ReglasV13::crear());

        $this->assertSame(ControlMonto::DISTINTO, $a->control);
        $this->assertSame(1, $a->cuota);
        $this->assertSame(3000, $a->cuotaEsperada);
        $this->assertSame(1, $b->cuota, 'sigue siendo la cuota 1');
        $this->assertSame(1000, $b->cuotaEsperada, 'solo faltan S/ 10');
        $this->assertSame(ControlMonto::OK, $b->control);
    }

    public function test_t13_dos_cuotas_en_un_solo_pago(): void
    {
        $p = $this->prestamo('P001', 'C3D', '2026-09-05', 200);
        $pagos = [$this->pago('P001', '2026-09-08', 60)];
        $a = Asignador::asignar($p, $pagos, ReglasV13::crear())[0];

        $this->assertSame(1, $a->cuota, 'se toma la cuota más antigua');
        $this->assertSame(18000, $a->saldoTras);
        $this->assertSame(ControlMonto::DISTINTO, $a->control);
        $this->assertSame(2, ResumenPrestamo::calcular($p, $pagos, $this->f('2026-09-08'))->cuotasPagadas);
    }

    public function test_t14_sobrepago_se_marca(): void
    {
        $p = $this->prestamo('P001', 'C3D', '2026-09-05', 200);
        $a = Asignador::asignar($p, [$this->pago('P001', '2026-09-08', 250)], ReglasV13::crear())[0];

        $this->assertSame(ControlMonto::EXCEDE, $a->control);
        $this->assertSame(-1000, $a->saldoTras);
    }

    public function test_el_orden_de_la_lista_no_cambia_el_resultado_pagos_fuera_de_orden(): void
    {
        $p = $this->prestamo('P003', 'SEMANAL', '2026-09-07', 500);
        $ordenados = [$this->pago('P003', '2026-09-12', 65), $this->pago('P003', '2026-09-19', 60), $this->pago('P003', '2026-09-26', 60)];
        $revueltos = [$ordenados[2], $ordenados[0], $ordenados[1]];

        $a = Asignador::asignar($p, $ordenados, ReglasV13::crear());
        $b = Asignador::asignar($p, $revueltos, ReglasV13::crear());

        $this->assertSame(
            array_map(fn ($x) => [$x->pago->id, $x->cuota, $x->puntualidad], $a),
            array_map(fn ($x) => [$x->pago->id, $x->cuota, $x->puntualidad], $b),
        );
    }

    public function test_anular_un_pago_recalcula_la_asignacion_de_los_siguientes(): void
    {
        $p = $this->prestamo('P001', 'C3D', '2026-09-05', 200);
        $uno = $this->pago('P001', '2026-09-08', 30);
        $dos = $this->pago('P001', '2026-09-11', 30);
        $tres = $this->pago('P001', '2026-09-14', 30);

        $this->assertSame([1, 2, 3], array_map(fn ($x) => $x->cuota, Asignador::asignar($p, [$uno, $dos, $tres], ReglasV13::crear())));

        $anulado = $this->pago('P001', '2026-09-08', 30, null, null, true);
        $pagos = [$anulado, $dos, $tres];
        $filas = Asignador::asignar($p, $pagos, ReglasV13::crear());
        $this->assertCount(2, $filas, 'el anulado no cuenta');
        $this->assertSame([1, 2], array_map(fn ($x) => $x->cuota, $filas), 'las siguientes pasan a cubrir las cuotas anteriores');
        $this->assertSame(Puntualidad::DESPUES_DE_LA_FECHA, $filas[0]->puntualidad, 'pagar el 11/09 la cuota del 08/09 es tarde');
    }

    public function test_la_suma_de_lo_asignado_es_la_suma_de_los_pagos(): void
    {
        $p = $this->prestamo('P003', 'SEMANAL', '2026-09-07', 500);
        $pagos = [$this->pago('P003', '2026-09-12', 40), $this->pago('P003', '2026-09-13', 25.5), $this->pago('P003', '2026-09-19', 100)];
        $filas = Asignador::asignar($p, $pagos, ReglasV13::crear());

        $this->assertSame(16550, $filas[2]->pagadoAntes + $filas[2]->pago->monto);
        $this->assertSame(72500 - 16550, $filas[2]->saldoTras);
    }
}
