<?php

declare(strict_types=1);

namespace PrestaCloud\Core;

enum Calificacion: string
{
    case PUNTUAL = 'PUNTUAL';
    case DESPUES_DE_LA_HORA = 'DESPUES_DE_LA_HORA';
    case DESPUES_DE_LA_FECHA = 'DESPUES_DE_LA_FECHA';
    case NO_RENOVAR = 'NO_RENOVAR';

    /** Texto tal como lo muestra el Excel v13. */
    public function etiqueta(): string
    {
        return match ($this) {
            self::PUNTUAL => 'PUNTUAL',
            self::DESPUES_DE_LA_HORA => 'DESPUÉS DE LA HORA',
            self::DESPUES_DE_LA_FECHA => 'DESPUÉS DE LA FECHA',
            self::NO_RENOVAR => 'NO RENOVAR',
        };
    }

    public static function desdeEtiqueta(string $etiqueta): self
    {
        foreach (self::cases() as $caso) {
            if ($caso->etiqueta() === $etiqueta) {
                return $caso;
            }
        }
        throw new \InvalidArgumentException("Calificación desconocida: $etiqueta");
    }
}
