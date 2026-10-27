<?php

declare(strict_types=1);

namespace PrestaCloud\Core;

enum Situacion: string
{
    case CON_ATRASO = 'CON_ATRASO';
    case ACTIVO = 'ACTIVO';
    case SIN_DEUDA = 'SIN_DEUDA';

    public function etiqueta(): string
    {
        return match ($this) {
            self::CON_ATRASO => 'CON ATRASO',
            self::ACTIVO => 'ACTIVO',
            self::SIN_DEUDA => 'SIN DEUDA',
        };
    }
}
