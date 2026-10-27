<?php

declare(strict_types=1);

namespace PrestaCloud\Core;

/**
 * Día calendario sin hora ni zona: número de días desde 1970-01-01.
 * La hora local (America/Lima) se resuelve en el Reloj; aquí solo se hace aritmética de días.
 */
final class Fecha implements \Stringable
{
    private function __construct(public readonly int $dias)
    {
    }

    public static function desde(string $iso): self
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $iso, new \DateTimeZone('UTC'));
        if ($d === false || $d->format('Y-m-d') !== $iso) {
            throw new \InvalidArgumentException("Fecha inválida: $iso");
        }

        return new self(intdiv($d->getTimestamp(), 86400));
    }

    public static function deDias(int $dias): self
    {
        return new self($dias);
    }

    public function sumar(int $dias): self
    {
        return new self($this->dias + $dias);
    }

    /** Suma meses de calendario conservando el día; si el mes destino es más corto, usa su último día. */
    public function sumarMeses(int $meses): self
    {
        if ($meses < 0) {
            throw new \InvalidArgumentException('Solo se suman meses hacia adelante.');
        }
        [$anio, $mes, $dia] = array_map('intval', explode('-', $this->iso()));
        $total = $anio * 12 + ($mes - 1) + $meses;
        $nuevoAnio = intdiv($total, 12);
        $nuevoMes = $total % 12 + 1;
        $ultimo = (int) gmdate('t', (int) gmmktime(0, 0, 0, $nuevoMes, 1, $nuevoAnio));

        return self::desde(sprintf('%04d-%02d-%02d', $nuevoAnio, $nuevoMes, min($dia, $ultimo)));
    }

    /** Días entre dos fechas: $this − $otra. */
    public function diferencia(self $otra): int
    {
        return $this->dias - $otra->dias;
    }

    /** Convención de WEEKDAY() en Excel: 1 = domingo … 7 = sábado. */
    public function diaSemanaExcel(): int
    {
        return (int) gmdate('w', $this->dias * 86400) + 1;
    }

    public function esAntesDe(self $otra): bool
    {
        return $this->dias < $otra->dias;
    }

    public function esDespuesDe(self $otra): bool
    {
        return $this->dias > $otra->dias;
    }

    public function igualA(self $otra): bool
    {
        return $this->dias === $otra->dias;
    }

    public function iso(): string
    {
        return gmdate('Y-m-d', $this->dias * 86400);
    }

    public function __toString(): string
    {
        return $this->iso();
    }
}
