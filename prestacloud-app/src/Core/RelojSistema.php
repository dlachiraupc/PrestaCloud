<?php

declare(strict_types=1);

namespace PrestaCloud\Core;

/** Reloj real, siempre en la hora de Lima (el servidor del hosting puede estar en otra zona). */
final class RelojSistema implements Reloj
{
    public function __construct(private readonly string $zona = 'America/Lima')
    {
    }

    private function ahora(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone($this->zona));
    }

    public function hoy(): Fecha
    {
        return Fecha::desde($this->ahora()->format('Y-m-d'));
    }

    public function hora(): string
    {
        return $this->ahora()->format('H:i');
    }
}
