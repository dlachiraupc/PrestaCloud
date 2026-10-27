<?php

declare(strict_types=1);

namespace PrestaCloud\Core;

/** Calificación de un cliente según la puntualidad de todos sus pagos, y renovación sugerida. */
final class Calificador
{
    /**
     * Calificación automática: la peor que corresponda según los umbrales de la cuenta.
     *
     * @param list<PagoAsignado> $pagosDelCliente los pagos asignados de todos sus préstamos
     *
     * @return ?Calificacion null si todavía no tiene pagos
     */
    public static function auto(array $pagosDelCliente, Reglas $reglas): ?Calificacion
    {
        if ($pagosDelCliente === []) {
            return null;
        }
        $tardeHora = 0;
        $tardeFecha = 0;
        foreach ($pagosDelCliente as $a) {
            match ($a->puntualidad) {
                Puntualidad::DESPUES_DE_LA_HORA => $tardeHora++,
                Puntualidad::DESPUES_DE_LA_FECHA => $tardeFecha++,
                default => null,
            };
        }

        return match (true) {
            $tardeFecha >= $reglas->nNoRenovar => Calificacion::NO_RENOVAR,
            $tardeFecha >= $reglas->nFecha => Calificacion::DESPUES_DE_LA_FECHA,
            $tardeHora >= $reglas->nHora => Calificacion::DESPUES_DE_LA_HORA,
            default => Calificacion::PUNTUAL,
        };
    }

    /** La calificación manual del prestamista, si la puso, manda sobre la automática. */
    public static function usada(?Calificacion $auto, ?Calificacion $manual): ?Calificacion
    {
        return $manual ?? $auto;
    }

    /** Monto sugerido para renovar: el mismo si no hay historial, 0 si no se renueva, o monto + aumento. */
    public static function renovacionSugerida(?Calificacion $calificacion, int $montoPrestamo, Reglas $reglas): int
    {
        if ($calificacion === null) {
            return $montoPrestamo;
        }

        return $reglas->renuevaCon($calificacion) ? $montoPrestamo + $reglas->aumentoPara($calificacion) : 0;
    }
}
