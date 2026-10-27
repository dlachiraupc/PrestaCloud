<?php

declare(strict_types=1);

namespace PrestaCloud\Tests;

use PrestaCloud\Core\Fecha;
use PrestaCloud\Core\Pago;
use PrestaCloud\Core\Prestamo;
use PrestaCloud\Core\Reglas;
use PrestaCloud\Tests\Fixtures\ReglasV13;

/**
 * Constructores cortos para las pruebas. Los importes se escriben en soles y se pasan al motor en céntimos.
 */
trait Ayudas
{
    private int $siguientePago = 1;

    protected function f(string $iso): Fecha
    {
        return Fecha::desde($iso);
    }

    /** Soles (int|float|string) a céntimos enteros, sin errores de coma flotante. */
    protected function c(int|float|string $soles): int
    {
        return (int) round(((float) $soles) * 100);
    }

    protected function prestamo(string $codigo, string $modalidad, string $fecha, int|float $soles, string $cliente = 'C001', ?Reglas $reglas = null): Prestamo
    {
        return Prestamo::crear($reglas ?? ReglasV13::crear(), $codigo, $cliente, $modalidad, $this->f($fecha), $this->c($soles));
    }

    protected function pago(string $prestamo, string $fecha, int|float $soles, ?string $hora = null, ?bool $despuesDeHora = null, bool $anulado = false): Pago
    {
        return new Pago($this->siguientePago++, $prestamo, $this->f($fecha), $this->c($soles), $hora, $despuesDeHora, $anulado);
    }
}
