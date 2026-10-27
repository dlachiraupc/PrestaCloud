<?php

declare(strict_types=1);

namespace PrestaCloud\Core;

final class RelojFijo implements Reloj
{
    public function __construct(private readonly Fecha $hoy, private readonly string $hora = '12:00')
    {
    }

    public function hoy(): Fecha
    {
        return $this->hoy;
    }

    public function hora(): string
    {
        return $this->hora;
    }
}
