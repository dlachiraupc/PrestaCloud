<?php

declare(strict_types=1);

namespace PrestaCloud\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PrestaCloud\Core\GeneradorPlan;
use PrestaCloud\Core\Reglas;
use PrestaCloud\Tests\Fixtures\ReglasV13;
use PrestaCloud\Core\SinTramo;
use PrestaCloud\Core\Tea;
use PrestaCloud\Core\Tramo;
use PrestaCloud\Tests\Ayudas;

/** Casos T1–T6 y T23 del documento de validación del Excel v13. */
final class PlanDePagoTest extends TestCase
{
    use Ayudas;

    public function test_t01_c3d_200(): void
    {
        $plan = $this->prestamo('P001', 'C3D', '2026-09-05', 200)->plan;

        $this->assertSame(2000, $plan->tasaBp);
        $this->assertSame(8, $plan->nCuotas);
        $this->assertSame(24000, $plan->total);
        $this->assertSame(3000, $plan->cuotaPrimera);
        $this->assertSame(3000, $plan->cuotaNormal);
        $this->assertSame('2026-09-08', $plan->fechaPrimera()->iso());
        $this->assertSame('2026-09-29', $plan->fechaFin()->iso());
        $this->assertSame('8 × 30.00', $plan->descripcion());
    }

    public function test_t02_semanal_300_un_lunes(): void
    {
        $plan = $this->prestamo('P002', 'SEMANAL', '2026-09-14', 300)->plan;

        $this->assertSame(4000, $plan->tasaBp);
        $this->assertSame(8, $plan->nCuotas);
        $this->assertSame(42000, $plan->total);
        $this->assertSame(5250, $plan->cuotaNormal);
        $this->assertSame('2026-09-19', $plan->fechaPrimera()->iso(), 'primer sábado');
        $this->assertSame('2026-11-07', $plan->fechaFin()->iso());
        $this->assertSame('8 × 52.50', $plan->descripcion());
    }

    public function test_t03_semanal_500_tramo_del_45_con_cuotas_enteras(): void
    {
        $plan = $this->prestamo('P003', 'SEMANAL', '2026-09-07', 500)->plan;

        $this->assertSame(4500, $plan->tasaBp);
        $this->assertSame(12, $plan->nCuotas);
        $this->assertSame(72500, $plan->total);
        $this->assertSame(6500, $plan->cuotaPrimera, 'lo que sobra por redondeo se suma a la 1ra cuota');
        $this->assertSame(6000, $plan->cuotaNormal);
        $this->assertSame('2026-11-28', $plan->fechaFin()->iso());
        $this->assertSame('1 × 65.00 + 11 × 60.00', $plan->descripcion());
        $this->assertSame(72500, $plan->acumulado(12));
    }

    public function test_t04_semanal_600_con_redondeo_entero_y_de_cinco(): void
    {
        $entero = $this->prestamo('P004', 'SEMANAL', '2026-09-07', 600)->plan;
        $this->assertSame(87000, $entero->total);
        $this->assertSame(7800, $entero->cuotaPrimera);
        $this->assertSame(7200, $entero->cuotaNormal);

        $multiplo5 = ReglasV13::crear()->conTramos([
            new Tramo('C3D', 0, 2000, 8, 0),
            new Tramo('SEMANAL', 0, 4000, 8, 0),
            new Tramo('SEMANAL', 50000, 4500, 12, 5),
        ]);
        $plan = $this->prestamo('P004', 'SEMANAL', '2026-09-07', 600, 'C001', $multiplo5)->plan;
        $this->assertSame(10000, $plan->cuotaPrimera);
        $this->assertSame(7000, $plan->cuotaNormal);
        $this->assertSame(87000, $plan->acumulado(12));
    }

    public function test_t05_frontera_del_tramo_499_99_usa_el_de_menos_de_500(): void
    {
        $plan = $this->prestamo('P005', 'SEMANAL', '2026-09-07', 499.99)->plan;
        $this->assertSame(4000, $plan->tasaBp);
        $this->assertSame(8, $plan->nCuotas);

        $this->assertSame(4500, $this->prestamo('P006', 'SEMANAL', '2026-09-07', 500)->plan->tasaBp, 'S/ 500 ya entra en el tramo del 45 %');
    }

    public function test_t06_prestamo_en_sabado_la_primera_cuota_es_el_sabado_siguiente(): void
    {
        $plan = $this->prestamo('P007', 'SEMANAL', '2026-09-19', 300)->plan;
        $this->assertSame('2026-09-26', $plan->fechaPrimera()->iso(), 'no el mismo día');
    }

    public function test_la_suma_de_las_cuotas_siempre_es_el_total(): void
    {
        foreach (['C3D', 'SEMANAL'] as $modalidad) {
            foreach ([50, 137.35, 200, 333.33, 499.99, 500, 777, 1250.5] as $monto) {
                $plan = $this->prestamo('P', $modalidad, '2026-10-26', $monto)->plan;
                $suma = 0;
                for ($k = 1; $k <= $plan->nCuotas; $k++) {
                    $suma += $plan->montoCuota($k);
                }
                $this->assertSame($plan->total, $suma, "$modalidad $monto");
                $this->assertGreaterThanOrEqual($plan->cuotaNormal, $plan->cuotaPrimera);
            }
        }
    }

    public function test_t23_sin_tramo_para_la_modalidad_falla_con_una_excepcion_clara(): void
    {
        $sinC3d = ReglasV13::crear()->conTramos([new Tramo('SEMANAL', 0, 4000, 8, 0)]);

        $this->expectException(SinTramo::class);
        GeneradorPlan::generar($sinC3d, 'C3D', $this->f('2026-09-05'), 20000);
    }

    public function test_un_monto_menor_al_primer_tramo_tambien_es_sin_tramo(): void
    {
        $desde100 = ReglasV13::crear()->conTramos([new Tramo('C3D', 10000, 2000, 8, 0)]);

        $this->expectException(SinTramo::class);
        GeneradorPlan::generar($desde100, 'C3D', $this->f('2026-09-05'), 5000);
    }

    public function test_tea_equivalente_de_los_tramos_del_v13(): void
    {
        $entrega = $this->f('2026-10-26');
        $this->assertEqualsWithDelta(155.28, Tea::anual(GeneradorPlan::generar(ReglasV13::crear(), 'C3D', $entrega, 20000), $entrega), 0.05);
        $this->assertEqualsWithDelta(79.86, Tea::anual(GeneradorPlan::generar(ReglasV13::crear(), 'SEMANAL', $entrega, 30000), $entrega), 0.05);
        $this->assertEqualsWithDelta(27.36, Tea::anual(GeneradorPlan::generar(ReglasV13::crear(), 'SEMANAL', $entrega, 50000), $entrega), 0.05);
    }
}
