<?php

declare(strict_types=1);

namespace PrestaCloud\Core;

/** Fuente de "hoy" y de la hora. Se inyecta para poder probar fechas sin tocar el reloj real. */
interface Reloj
{
    public function hoy(): Fecha;

    /** Hora local en formato HH:MM (24 h). */
    public function hora(): string;
}
