<?php

declare(strict_types=1);

namespace PrestaCloud\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PrestaCloud\Core\Asignador;
use PrestaCloud\Core\Calificacion;
use PrestaCloud\Core\Calificador;
use PrestaCloud\Core\Reglas;
use PrestaCloud\Tests\Fixtures\ReglasV13;
use PrestaCloud\Core\Situacion;
use PrestaCloud\Core\ResumenCliente;
use PrestaCloud\Core\ValidadorCliente;
use PrestaCloud\Tests\Ayudas;

/** Casos T18–T20: calificación, renovación sugerida y datos del cliente. */
final class CalificacionTest extends TestCase
{
    use Ayudas;

    /** @return list<\PrestaCloud\Core\PagoAsignado> */
    private function pagosConRetraso(int $tardes, int $enHora = 0): array
    {
        $p = $this->prestamo('P001', 'C3D', '2026-09-05', 200);
        $pagos = [];
        $dues = ['08', '11', '14', '17', '20'];
        for ($i = 0; $i < $tardes; $i++) {
            $pagos[] = $this->pago('P001', '2026-09-' . str_pad((string) ((int) $dues[$i] + 2), 2, '0', STR_PAD_LEFT), 30);
        }
        for ($i = 0; $i < $enHora; $i++) {
            $pagos[] = $this->pago('P001', '2026-09-' . $dues[$tardes + $i], 30, '18:00');
        }

        return Asignador::asignar($p, $pagos, ReglasV13::crear());
    }

    public function test_t18_tres_pagos_despues_de_la_fecha_es_no_renovar_y_la_manual_prevalece(): void
    {
        $r = ReglasV13::crear();
        $auto = Calificador::auto($this->pagosConRetraso(3), $r);

        $this->assertSame(Calificacion::NO_RENOVAR, $auto);
        $this->assertSame(0, Calificador::renovacionSugerida($auto, 20000, $r));

        $usada = Calificador::usada($auto, Calificacion::PUNTUAL);
        $this->assertSame(Calificacion::PUNTUAL, $usada);
        $this->assertSame(30000, Calificador::renovacionSugerida($usada, 20000, $r), 'monto + S/ 100');
    }

    public function test_escalera_de_calificacion(): void
    {
        $r = ReglasV13::crear();
        $this->assertNull(Calificador::auto([], $r), 'sin pagos no hay calificación');
        $this->assertSame(Calificacion::PUNTUAL, Calificador::auto($this->puntuales(), $r));
        $this->assertSame(Calificacion::DESPUES_DE_LA_HORA, Calificador::auto($this->pagosConRetraso(0, 1), $r));
        $this->assertSame(Calificacion::DESPUES_DE_LA_FECHA, Calificador::auto($this->pagosConRetraso(1), $r));
        $this->assertSame(Calificacion::DESPUES_DE_LA_FECHA, Calificador::auto($this->pagosConRetraso(2), $r));
        $this->assertSame(Calificacion::NO_RENOVAR, Calificador::auto($this->pagosConRetraso(3), $r));
        $this->assertSame(Calificacion::DESPUES_DE_LA_FECHA, Calificador::auto($this->pagosConRetraso(1, 2), $r), 'la fecha pesa más que la hora');
    }

    /** @return list<\PrestaCloud\Core\PagoAsignado> */
    private function puntuales(): array
    {
        $p = $this->prestamo('P001', 'C3D', '2026-09-05', 200);

        return Asignador::asignar($p, [$this->pago('P001', '2026-09-07', 30), $this->pago('P001', '2026-09-11', 30, '10:00')], ReglasV13::crear());
    }

    public function test_renovacion_sugerida_segun_la_calificacion(): void
    {
        $r = ReglasV13::crear();
        $this->assertSame(20000, Calificador::renovacionSugerida(null, 20000, $r), 'sin historial: el mismo monto');
        $this->assertSame(30000, Calificador::renovacionSugerida(Calificacion::PUNTUAL, 20000, $r));
        $this->assertSame(20000, Calificador::renovacionSugerida(Calificacion::DESPUES_DE_LA_HORA, 20000, $r), 'renueva sin aumento');
        $this->assertSame(0, Calificador::renovacionSugerida(Calificacion::DESPUES_DE_LA_FECHA, 20000, $r));
        $this->assertSame(0, Calificador::renovacionSugerida(Calificacion::NO_RENOVAR, 20000, $r));
    }

    public function test_el_umbral_de_no_renovar_es_configurable(): void
    {
        $r = ReglasV13::crear()->conUmbrales(nHora: 1, nFecha: 1, nNoRenovar: 2);
        $this->assertSame(Calificacion::NO_RENOVAR, Calificador::auto($this->pagosConRetraso(2), $r));
    }

    public function test_t19_cambiar_el_diminutivo_no_cambia_ningun_resultado(): void
    {
        $this->markTestSkipped('T19 es de la capa de datos (Fase 1): los cálculos usan códigos, no nombres. Se prueba con la base real.');
    }

    public function test_t20_diminutivos_repetidos_se_detectan_sin_distinguir_mayusculas(): void
    {
        $this->assertSame(['JOSE'], ValidadorCliente::diminutivosRepetidos(['JOSE', 'ANA', 'jose', 'LUIS']));
        $this->assertSame([], ValidadorCliente::diminutivosRepetidos(['JOSE', 'ANA']));
        $this->assertSame([], ValidadorCliente::diminutivosRepetidos([]));
    }

    public function test_situacion_y_datos_del_cliente(): void
    {
        $r = ReglasV13::crear();
        $a = $this->prestamo('P001', 'C3D', '2026-09-05', 200, 'C001');
        $b = $this->prestamo('P002', 'C3D', '2026-09-05', 200, 'C002');
        $pagos = [
            $this->pago('P001', '2026-09-08', 30),
            $this->pago('P002', '2026-09-08', 30),
            $this->pago('P002', '2026-09-11', 30),
        ];
        $hoy = $this->f('2026-09-12');

        $c1 = ResumenCliente::calcular('C001', [$a, $b], $pagos, $hoy, $r);
        $this->assertSame(1, $c1->nPrestamos);
        $this->assertSame(1, $c1->vigentes);
        $this->assertSame(Situacion::CON_ATRASO, $c1->situacion, 'le falta la cuota del 11/09');
        $this->assertSame(3000, $c1->atraso);
        $this->assertSame(21000, $c1->deuda);
        $this->assertSame(3000, $c1->pagado);

        $c2 = ResumenCliente::calcular('C002', [$a, $b], $pagos, $hoy, $r);
        $this->assertSame(Situacion::ACTIVO, $c2->situacion);
        $this->assertSame(6000, $c2->pagado);
        $this->assertSame(Calificacion::PUNTUAL, $c2->calificacionAuto);

        $nuevo = ResumenCliente::calcular('C999', [$a, $b], $pagos, $hoy, $r);
        $this->assertSame(Situacion::SIN_DEUDA, $nuevo->situacion);
        $this->assertNull($nuevo->calificacionAuto);
        $this->assertSame(0, $nuevo->nPrestamos);
    }
}
