<?php

declare(strict_types=1);

namespace PrestaCloud\Tests\Paridad;

use PHPUnit\Framework\TestCase;
use PrestaCloud\Core\Asignador;
use PrestaCloud\Core\Caja;
use PrestaCloud\Core\Calificacion;
use PrestaCloud\Core\Calificador;
use PrestaCloud\Core\Fecha;
use PrestaCloud\Core\Modalidad;
use PrestaCloud\Core\MovimientoCaja;
use PrestaCloud\Core\Pago;
use PrestaCloud\Core\Panel;
use PrestaCloud\Core\Prestamo;
use PrestaCloud\Core\Reglas;
use PrestaCloud\Core\ResumenCliente;
use PrestaCloud\Core\ResumenPrestamo;
use PrestaCloud\Core\Tramo;

/**
 * Paridad con el libro PRESTAMOS_v13.xlsx.
 *
 * Compara el motor con los VALORES GUARDADOS del Excel (tests/fixtures/v13_golden.json, generado con
 * tools/generar_golden_v13.mjs) usando como "hoy" el 30/09/2026, que es la fecha con la que el libro
 * calculó por última vez. Cada celda comparada está nombrada con su columna del Excel.
 *
 * Desviaciones intencionales respecto al Excel: ver DOCUMENTOS/REGLAS_DE_NEGOCIO.md §Desviaciones.
 */
final class ParidadV13Test extends TestCase
{
    /** @var array<string, mixed> */
    private static array $g;
    private static Reglas $reglas;
    private static Fecha $hoy;
    /** @var array<string, Prestamo> */
    private static array $prestamos = [];
    /** @var list<Pago> */
    private static array $pagos = [];
    /** @var list<MovimientoCaja> */
    private static array $movimientos = [];

    public static function setUpBeforeClass(): void
    {
        self::$g = json_decode((string) file_get_contents(__DIR__ . '/../fixtures/v13_golden.json'), true, 512, JSON_THROW_ON_ERROR);
        $c = self::$g['config'];

        $aumentos = [];
        $renueva = [];
        foreach ($c['calificacion'] as $fila) {
            $cal = Calificacion::desdeEtiqueta($fila['etiqueta']);
            $aumentos[$cal->value] = $fila['aumento'];
            $renueva[$cal->value] = $fila['renueva'];
        }
        self::$reglas = new Reglas(
            modalidades: array_map(fn ($m) => new Modalidad($m['codigo'], $m['cadaDias'], $m['diaFijo']), $c['modalidades']),
            tramos: array_map(fn ($t) => new Tramo($t['modalidad'], $t['desde'], $t['tasaBp'], $t['nCuotas'], $t['redondeo']), $c['tramos']),
            tiposCaja: $c['tiposCaja'],
            aumentos: $aumentos,
            renueva: $renueva,
            horaMaxima: $c['puntualidad']['horaMaximaEnCelda'],
            toleranciaDias: $c['puntualidad']['toleranciaDias'],
            nHora: $c['puntualidad']['nHora'],
            nFecha: $c['puntualidad']['nFecha'],
            nNoRenovar: $c['puntualidad']['nNoRenovar'],
        );
        self::$hoy = Fecha::desde(self::$g['meta']['hoy']);

        foreach (self::$g['prestamos'] as $p) {
            self::$prestamos[$p['codigo']] = Prestamo::crear(self::$reglas, $p['codigo'], $p['cliente'], $p['modalidad'], Fecha::desde($p['fecha']), $p['prestado']);
        }
        foreach (self::$g['pagos'] as $p) {
            self::$pagos[] = new Pago($p['fila'], $p['prestamo'], Fecha::desde($p['fecha']), $p['monto'], null, $p['despuesDeHora']);
        }
        foreach (self::$g['caja'] as $m) {
            self::$movimientos[] = new MovimientoCaja(Fecha::desde($m['fecha']), $m['tipo'], $m['monto']);
        }
    }

    public function test_el_dataset_tiene_lo_esperado(): void
    {
        $this->assertSame('2026-09-30', self::$g['meta']['hoy'], 'fecha con la que el libro calculó sus valores');
        $this->assertSame(['clientes' => 13, 'prestamos' => 17, 'pagos' => 46, 'movimientosCaja' => 4], self::$g['meta']['conteos']);
    }

    public function test_prestamos_columnas_I_a_AD_de_la_hoja_PRESTAMOS(): void
    {
        $orden = [];
        foreach (self::$g['prestamos'] as $fila) {
            $e = $fila['esperado'];
            $cod = $fila['codigo'];
            $p = self::$prestamos[$cod];
            $r = ResumenPrestamo::calcular($p, self::$pagos, self::$hoy);
            $plan = $p->plan;

            // I · CICLO: n.º de préstamo del cliente en la misma modalidad, contando hacia arriba en la hoja
            $clave = $p->clienteCodigo . '|' . $p->modalidad;
            $orden[$clave] = ($orden[$clave] ?? 0) + 1;
            $this->assertSame($e['ciclo'], $orden[$clave], "$cod I ciclo");

            $this->assertSame($e['tasaBp'], $plan->tasaBp, "$cod J tasa");
            $this->assertSame($e['nCuotas'], $plan->nCuotas, "$cod K n.º de cuotas");
            $this->assertSame($e['total'], $plan->total, "$cod L total a cobrar");
            $this->assertSame($e['cuotaPrimera'], $plan->cuotaPrimera, "$cod M 1.ª cuota");
            $this->assertSame($e['cuotaNormal'], $plan->cuotaNormal, "$cod N cuota normal");
            $this->assertSame($e['plan'], $plan->descripcion(), "$cod O plan de pago");
            $this->assertSame($e['fechaPrimera'], $plan->fechaPrimera()->iso(), "$cod P fecha 1.ª cuota");
            $this->assertSame($e['fechaFin'], $plan->fechaFin()->iso(), "$cod Q fecha término");
            $this->assertSame($e['cobrado'], $r->cobrado, "$cod R cobrado");
            $this->assertSame($e['saldo'], $r->saldo, "$cod S saldo");
            $this->assertSame($e['cuotasPagadas'], $r->cuotasPagadas, "$cod T cuotas pagadas");
            $this->assertSame($e['cuotasVencidas'], $r->cuotasVencidas, "$cod U cuotas vencidas");
            $this->assertSame($e['atraso'], $r->atraso, "$cod V atraso");
            $this->assertSame($e['proximaFecha'], $r->proxima?->iso(), "$cod W próxima fecha");
            $this->assertSame($e['diasAtraso'], $r->diasAtraso, "$cod X días de atraso");
            $this->assertSame($e['estado'], $r->estado->etiqueta(), "$cod Y estado");
            $this->assertSame($e['gananciaTotal'], $r->gananciaTotal, "$cod Z ganancia total");
            $this->assertSame($e['gananciaCobrada'], $r->gananciaCobrada, "$cod AA ganancia cobrada");
            $this->assertEqualsWithDelta($e['capitalEnLaCalle'], $r->capitalEnLaCalle, 1, "$cod AB capital en la calle");

            $cal = $this->calificacionUsada($p->clienteCodigo);
            $this->assertSame($e['calificacion'], $cal?->etiqueta(), "$cod AC calificación");
            $this->assertSame($e['renovacionSugerida'], Calificador::renovacionSugerida($cal, $p->monto, self::$reglas), "$cod AD renovación sugerida");
        }
    }

    public function test_pagos_columnas_H_a_N_y_S_de_la_hoja_PAGOS(): void
    {
        $porPrestamo = [];
        foreach (self::$g['prestamos'] as $fila) {
            $porPrestamo[$fila['codigo']] = Asignador::asignar(self::$prestamos[$fila['codigo']], self::$pagos, self::$reglas);
        }
        $porFila = [];
        foreach ($porPrestamo as $asignados) {
            foreach ($asignados as $a) {
                $porFila[$a->pago->id] = $a;
            }
        }

        $this->assertCount(46, $porFila);
        foreach (self::$g['pagos'] as $fila) {
            $e = $fila['esperado'];
            $a = $porFila[$fila['fila']];
            $ref = "pago fila {$fila['fila']} ({$fila['prestamo']} {$fila['fecha']})";

            $this->assertSame($e['puntualidad'], $a->puntualidad->etiqueta(), "$ref H puntualidad");
            $this->assertSame($e['diasTarde'] ?? 0, $a->diasTarde, "$ref I días tarde");
            $this->assertSame($e['cuota'], $a->cuota, "$ref J cuota N.º");
            $this->assertSame($e['vencia'], $a->vence->iso(), "$ref K vencía el");
            $this->assertSame($e['cuotaEsperada'], $a->cuotaEsperada, "$ref L cuota esperada");
            $this->assertSame($e['saldoTrasPago'] ?? 0, $a->saldoTras, "$ref M saldo tras el pago");
            $this->assertSame($e['control'], $a->control->etiqueta(), "$ref N control del monto");
            $this->assertSame($e['pagadoAntes'] ?? 0, $a->pagadoAntes, "$ref S pagado antes");
        }
    }

    public function test_clientes_columnas_I_a_U_de_la_hoja_CLIENTES(): void
    {
        foreach (self::$g['clientes'] as $fila) {
            $e = $fila['esperado'];
            $c = ResumenCliente::calcular($fila['codigo'], array_values(self::$prestamos), self::$pagos, self::$hoy, self::$reglas);
            $ref = $fila['codigo'];

            $this->assertSame($e['nPrestamos'], $c->nPrestamos, "$ref I n.º de préstamos");
            $this->assertSame($e['vigentes'], $c->vigentes, "$ref J vigentes");
            $this->assertSame($e['deuda'] ?? 0, $c->deuda, "$ref K deuda actual");
            $this->assertSame($e['atraso'] ?? 0, $c->atraso, "$ref L en atraso");
            $this->assertSame($e['pagado'] ?? 0, $c->pagado, "$ref M total pagado");
            $this->assertSame($e['situacion'], $c->situacion->etiqueta(), "$ref N situación");
            $this->assertSame($e['puntuales'], $c->puntuales, "$ref Q pagos puntuales (incluye adelantados)");
            $this->assertSame($e['despuesHora'], $c->despuesDeLaHora, "$ref R después de la hora");
            $this->assertSame($e['despuesFecha'], $c->despuesDeLaFecha, "$ref S después de la fecha");
            $this->assertSame($e['calificacionAuto'], $c->calificacionAuto?->etiqueta(), "$ref T calificación automática");
            $this->assertSame($e['calificacionUsada'], $c->calificacionUsada?->etiqueta(), "$ref U calificación que se usa");
        }
    }

    public function test_panel_INICIO_y_control_de_caja(): void
    {
        $i = self::$g['inicio'];
        $panel = Panel::resumen(array_values(self::$prestamos), self::$pagos, self::$movimientos, self::$hoy, self::$reglas);

        $this->assertSame($i['cajaHoy'], $panel->cajaHoy, 'C6 caja disponible hoy');
        $this->assertSame($i['cobradoHoy'] ?? 0, $panel->cobradoHoy, 'C8 cobrado hoy');
        $this->assertSame($i['prestamosAtrasados'], $panel->prestamosAtrasados, 'C9 préstamos atrasados');
        $this->assertSame($i['montoAtraso'], $panel->montoAtraso, 'C10 monto en atraso');
        $this->assertSame($i['prestamosVigentes'], $panel->prestamosVigentes, 'F6 préstamos vigentes');
        $this->assertSame($i['porCobrar'], $panel->porCobrar, 'F7 por cobrar');
        $this->assertEqualsWithDelta($i['capitalEnLaCalle'], $panel->capitalEnLaCalle, 2, 'F8 capital en la calle');
        $this->assertSame($i['gananciaCobrada'], $panel->gananciaCobrada, 'F9 ganancia ya cobrada');
        $this->assertSame($i['programados'] ?? 0, $panel->programados, 'F10 programados');
        $this->assertEqualsWithDelta($i['patrimonio'], $panel->patrimonio, 2, 'F11 patrimonio aproximado');

        $per = $i['periodo'];
        $p = Caja::periodo(Fecha::desde($per['desde']), Fecha::desde($per['hasta']), self::$movimientos, self::$pagos, array_values(self::$prestamos), self::$reglas);
        $this->assertSame($per['saldoInicial'] ?? 0, $p->saldoInicial, 'C16 saldo inicial');
        $this->assertSame($per['cobros'], $p->cobros, 'C17 cobros de cuotas');
        $this->assertSame($per['aportes'], $p->ingresos, 'C18 aportes y otros ingresos');
        $this->assertSame($per['prestamos'], $p->prestamosEntregados, 'C19 préstamos entregados');
        $this->assertSame($per['retiros'] ?? 0, $p->retiros, 'C20 retiros y gastos');
        $this->assertSame($per['saldoFinal'], $p->saldoFinal, 'C21 saldo final');
        $this->assertSame($per['control'] ?? 0, $p->control, 'C22 control (debe ser 0)');
    }

    public function test_la_caja_negativa_del_v13_es_aritmetica_no_un_error_del_modelo(): void
    {
        // 1000 + 500 + 500 + 75 (caja) + 1,502.50 (cobros) − 4,000 (préstamos) = −422.50
        $this->assertSame(-42250, 207500 + 150250 - 400000);
        $panel = Panel::resumen(array_values(self::$prestamos), self::$pagos, self::$movimientos, self::$hoy, self::$reglas);
        $this->assertSame(-42250, $panel->cajaHoy);
    }

    private function calificacionUsada(string $cliente): ?Calificacion
    {
        $c = ResumenCliente::calcular($cliente, array_values(self::$prestamos), self::$pagos, self::$hoy, self::$reglas);

        return $c->calificacionUsada;
    }
}
