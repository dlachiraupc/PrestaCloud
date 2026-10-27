<?php

declare(strict_types=1);

namespace PrestaCloud\Core;

enum EstadoPrestamo: string
{
    case AL_DIA = 'AL_DIA';
    case ATRASADO = 'ATRASADO';
    case CANCELADO = 'CANCELADO';
    case PROGRAMADO = 'PROGRAMADO';

    public function etiqueta(): string
    {
        return match ($this) {
            self::AL_DIA => 'AL DÍA',
            self::ATRASADO => 'ATRASADO',
            self::CANCELADO => 'CANCELADO',
            self::PROGRAMADO => 'PROGRAMADO',
        };
    }

    /** Préstamo con saldo por cobrar y ya entregado. */
    public function esVigente(): bool
    {
        return $this === self::AL_DIA || $this === self::ATRASADO;
    }
}
