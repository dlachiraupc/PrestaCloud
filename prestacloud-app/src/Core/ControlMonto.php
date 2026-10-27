<?php

declare(strict_types=1);

namespace PrestaCloud\Core;

enum ControlMonto: string
{
    case OK = 'OK';
    case DISTINTO = 'DISTINTO';
    case EXCEDE = 'EXCEDE';

    public function etiqueta(): string
    {
        return match ($this) {
            self::OK => '✔ Monto OK',
            self::DISTINTO => 'Monto distinto a la cuota',
            self::EXCEDE => '⚠ Pagó más que el saldo',
        };
    }
}
