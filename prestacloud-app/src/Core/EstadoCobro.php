<?php

declare(strict_types=1);

namespace PrestaCloud\Core;

/** Estado de una fila de la cobranza del día. */
enum EstadoCobro: string
{
    case PENDIENTE = 'PENDIENTE';
    case ATRASADO = 'ATRASADO';
    case PARCIAL = 'PARCIAL';
    case COBRADO = 'COBRADO';
}
