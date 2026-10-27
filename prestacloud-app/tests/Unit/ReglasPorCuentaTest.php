<?php

declare(strict_types=1);

namespace PrestaCloud\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PrestaCloud\Core\Calificacion;
use PrestaCloud\Core\Calificador;
use PrestaCloud\Core\Fecha;
use PrestaCloud\Core\GeneradorPlan;
use PrestaCloud\Core\Modalidad;
use PrestaCloud\Core\Reglas;
use PrestaCloud\Core\ReglasInvalidas;
use PrestaCloud\Core\SinTramo;
use PrestaCloud\Core\Tramo;
use PrestaCloud\Tests\Ayudas;
use PrestaCloud\Tests\Fixtures\ReglasV13;

/**
 * Las reglas son configuración de CADA cuenta: ninguna vive en el código del motor.
 * Estas pruebas fijan esa propiedad.
 */
final class ReglasPorCuentaTest extends TestCase
{
    use Ayudas;

    /** Prestamista A: cobra cada semana, 10 % en 4 cuotas. */
    private function cuentaA(): Reglas
    {
        return Reglas::vacia()
            ->conModalidades([new Modalidad('SEMANAL', 7, 0)])
            ->conTramos([new Tramo('SEMANAL', 0, 1000, 4, 0)]);
    }

    /** Prestamista B: cobra a diario, 30 % en 6 cuotas, y quiere 1 día de tolerancia. */
    private function cuentaB(): Reglas
    {
        return Reglas::vacia()
            ->conModalidades([new Modalidad('DIARIA', 1, 0), new Modalidad('SEMANAL', 7, 6)])
            ->conTramos([new Tramo('DIARIA', 0, 3000, 6, 0), new Tramo('SEMANAL', 0, 5000, 5, 0)])
            ->conTolerancia(1);
    }

    public function test_el_mismo_prestamo_da_planes_distintos_segun_las_reglas_de_cada_cuenta(): void
    {
        $entrega = $this->f('2026-10-26'); // lunes
        $a = GeneradorPlan::generar($this->cuentaA(), 'SEMANAL', $entrega, 20000);
        $b = GeneradorPlan::generar($this->cuentaB(), 'SEMANAL', $entrega, 20000);

        $this->assertSame([1000, 4, 22000], [$a->tasaBp, $a->nCuotas, $a->total]);
        $this->assertSame([5000, 5, 30000], [$b->tasaBp, $b->nCuotas, $b->total]);
        $this->assertSame('2026-11-02', $a->fechaPrimera()->iso(), 'A: cada 7 días desde la entrega');
        $this->assertSame('2026-10-30', $b->fechaPrimera()->iso(), 'B: día fijo 6 (viernes): el próximo viernes tras el lunes 26/10');
    }

    public function test_calcular_con_las_reglas_de_una_cuenta_no_deja_rastro_en_la_otra(): void
    {
        $entrega = $this->f('2026-10-26');
        $primero = GeneradorPlan::generar($this->cuentaA(), 'SEMANAL', $entrega, 20000);
        GeneradorPlan::generar($this->cuentaB(), 'SEMANAL', $entrega, 20000);
        GeneradorPlan::generar($this->cuentaB(), 'DIARIA', $entrega, 5000);
        $despues = GeneradorPlan::generar($this->cuentaA(), 'SEMANAL', $entrega, 20000);

        $this->assertEquals($primero, $despues, 'no hay estado global entre cuentas');
    }

    public function test_una_modalidad_de_una_cuenta_no_existe_en_otra(): void
    {
        $this->expectException(SinTramo::class);
        $this->expectExceptionMessage('«DIARIA»');
        GeneradorPlan::generar($this->cuentaA(), 'DIARIA', $this->f('2026-10-26'), 5000);
    }

    public function test_la_tolerancia_y_la_calificacion_tambien_son_de_cada_cuenta(): void
    {
        $r = $this->cuentaB()->conCalificacion([Calificacion::PUNTUAL->value => 5000], [Calificacion::DESPUES_DE_LA_FECHA->value => true]);

        $this->assertSame(1, $r->toleranciaDias);
        $this->assertSame(5000, Calificador::renovacionSugerida(Calificacion::PUNTUAL, 20000, $r) - 20000);
        $this->assertSame(20000, Calificador::renovacionSugerida(Calificacion::DESPUES_DE_LA_FECHA, 20000, $r), 'esta cuenta SÍ renueva a quien paga tarde');
        $this->assertSame(0, Calificador::renovacionSugerida(Calificacion::DESPUES_DE_LA_FECHA, 20000, Reglas::vacia()), 'la base de otra cuenta no cambió');
    }

    public function test_una_cuenta_nueva_no_puede_entregar_prestamos_hasta_configurar_sus_reglas(): void
    {
        $nueva = Reglas::vacia();
        $this->assertFalse($nueva->puedeEntregarPrestamos());
        $this->assertTrue($this->cuentaA()->puedeEntregarPrestamos());

        $this->expectException(SinTramo::class);
        GeneradorPlan::generar($nueva, 'SEMANAL', $this->f('2026-10-26'), 10000);
    }

    public function test_cada_cuenta_define_sus_propios_tipos_de_caja(): void
    {
        $r = Reglas::vacia()->conTiposCaja(['APORTE SOCIO' => 1, 'COMISION' => -1]);
        $this->assertSame(1, $r->signoDeTipo('APORTE SOCIO'));
        $this->assertSame(-1, $r->signoDeTipo('COMISION'));
        $this->assertSame(0, $r->signoDeTipo('GASTO'), 'un tipo que esta cuenta no definió no mueve la caja');
    }

    public function test_se_guardan_y_recuperan_sin_perder_nada(): void
    {
        foreach ([ReglasV13::crear(), $this->cuentaA(), $this->cuentaB(), Reglas::vacia()] as $original) {
            $json = json_encode($original->aArray(), JSON_THROW_ON_ERROR);
            $vuelta = Reglas::desdeArray(json_decode($json, true, 512, JSON_THROW_ON_ERROR));
            $this->assertSame($original->aArray(), $vuelta->aArray());
        }
    }

    public function test_la_configuracion_de_una_cuenta_se_valida_entera_y_se_avisa_de_todo_junto(): void
    {
        $mala = [
            'version' => 1,
            'modalidades' => [['codigo' => 'SEMANAL', 'cada' => 7, 'unidad' => 'DIAS', 'diaFijo' => 0], ['codigo' => 'SEMANAL', 'cada' => 7, 'unidad' => 'DIAS', 'diaFijo' => 0]],
            'tramos' => [
                ['modalidad' => 'SEMANAL', 'desde' => 50000, 'tasaBp' => 4500, 'nCuotas' => 12, 'redondeo' => 1],
                ['modalidad' => 'MENSUAL', 'desde' => 0, 'tasaBp' => 1000, 'nCuotas' => 3, 'redondeo' => 0],
            ],
            'puntualidad' => ['horaMaxima' => '17:00', 'toleranciaDias' => 99, 'nHora' => 1, 'nFecha' => 3, 'nNoRenovar' => 2],
        ];
        try {
            Reglas::desdeArray($mala);
            $this->fail('Debió rechazarla');
        } catch (ReglasInvalidas $e) {
            $texto = implode(' | ', $e->errores());
            $this->assertStringContainsString('repetida', $texto);
            $this->assertStringContainsString('«MENSUAL», que no existe', $texto);
            $this->assertStringContainsString('necesita un tramo que empiece en S/ 0', $texto);
            $this->assertStringContainsString('tolerancia', $texto);
            $this->assertStringContainsString('NO RENOVAR necesita', $texto);
            $this->assertGreaterThanOrEqual(5, count($e->errores()));
        }
    }

    public function test_rechaza_valores_mal_formados(): void
    {
        foreach ([
            ['version' => 2],
            ['version' => 1, 'modalidades' => [['codigo' => 'minusculas', 'cada' => 7]]],
            ['version' => 1, 'modalidades' => [['codigo' => 'X', 'cada' => 0]]],
            ['version' => 1, 'modalidades' => [['codigo' => 'M', 'cada' => 1, 'unidad' => 'MESES', 'diaFijo' => 3]]],
            ['version' => 1, 'tramos' => [['modalidad' => 'X', 'desde' => 0, 'tasaBp' => -1, 'nCuotas' => 4]]],
            ['version' => 1, 'puntualidad' => ['horaMaxima' => '25:99']],
        ] as $i => $entrada) {
            try {
                Reglas::desdeArray($entrada);
                $this->fail("Entrada $i debió rechazarse");
            } catch (ReglasInvalidas) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_modalidad_diaria_y_quincenal_en_dias(): void
    {
        $r = Reglas::vacia()
            ->conModalidades([new Modalidad('DIARIA', 1), new Modalidad('QUINCENAL', 15)])
            ->conTramos([new Tramo('DIARIA', 0, 2000, 24, 0), new Tramo('QUINCENAL', 0, 1500, 4, 0)]);

        $diaria = GeneradorPlan::generar($r, 'DIARIA', $this->f('2026-10-26'), 24000);
        $this->assertSame('2026-10-27', $diaria->fechaPrimera()->iso());
        $this->assertSame('2026-11-19', $diaria->fechaFin()->iso(), '24 días seguidos');
        $this->assertSame(28800, $diaria->total);
        $this->assertSame(1200, $diaria->cuotaNormal);

        $quincenal = GeneradorPlan::generar($r, 'QUINCENAL', $this->f('2026-10-26'), 10000);
        $this->assertSame(['2026-11-10', '2026-11-25', '2026-12-10', '2026-12-25'], array_map(fn (Fecha $f) => $f->iso(), $quincenal->fechas));
    }

    public function test_modalidad_mensual_por_calendario_conserva_el_dia_y_recorta_en_meses_cortos(): void
    {
        $r = Reglas::vacia()->conModalidades([new Modalidad('MENSUAL', 1, 0, Modalidad::MESES)])->conTramos([new Tramo('MENSUAL', 0, 1000, 5, 0)]);

        $fechas = fn (string $entrega) => array_map(fn (Fecha $f) => $f->iso(), GeneradorPlan::generar($r, 'MENSUAL', $this->f($entrega), 100000)->fechas);

        $this->assertSame(['2026-02-28', '2026-03-31', '2026-04-30', '2026-05-31', '2026-06-30'], $fechas('2026-01-31'), 'no se arrastra el recorte de febrero');
        $this->assertSame(['2028-02-29', '2028-03-31', '2028-04-30', '2028-05-31', '2028-06-30'], $fechas('2028-01-31'), 'año bisiesto');
        $this->assertSame(['2026-11-15', '2026-12-15', '2027-01-15', '2027-02-15', '2027-03-15'], $fechas('2026-10-15'));
    }

    public function test_cada_dos_meses_y_cruce_de_anio(): void
    {
        $r = Reglas::vacia()->conModalidades([new Modalidad('BIMESTRAL', 2, 0, Modalidad::MESES)])->conTramos([new Tramo('BIMESTRAL', 0, 800, 3, 0)]);
        $p = GeneradorPlan::generar($r, 'BIMESTRAL', $this->f('2026-11-30'), 60000);

        $this->assertSame(['2027-01-30', '2027-03-30', '2027-05-30'], array_map(fn (Fecha $f) => $f->iso(), $p->fechas));
    }

    public function test_un_monto_que_deja_la_cuota_en_cero_se_rechaza(): void
    {
        $r = Reglas::vacia()->conModalidades([new Modalidad('SEMANAL', 7)])->conTramos([new Tramo('SEMANAL', 0, 1000, 12, 10)]);

        $this->expectException(SinTramo::class);
        GeneradorPlan::generar($r, 'SEMANAL', $this->f('2026-10-26'), 5000);
    }
}
