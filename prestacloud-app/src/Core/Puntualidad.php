<?php

declare(strict_types=1);

namespace PrestaCloud\Core;

/** Cómo llegó un pago respecto a la cuota que cubre. Cuatro estados, como en el Excel v13. */
enum Puntualidad: string
{
    case PUNTUAL = 'PUNTUAL';
    case ADELANTADO = 'ADELANTADO';
    case DESPUES_DE_LA_HORA = 'DESPUES_DE_LA_HORA';
    case DESPUES_DE_LA_FECHA = 'DESPUES_DE_LA_FECHA';

    public function etiqueta(): string
    {
        return match ($this) {
            self::PUNTUAL => 'PUNTUAL',
            self::ADELANTADO => 'ADELANTADO',
            self::DESPUES_DE_LA_HORA => 'DESPUÉS DE LA HORA',
            self::DESPUES_DE_LA_FECHA => 'DESPUÉS DE LA FECHA',
        };
    }
}
