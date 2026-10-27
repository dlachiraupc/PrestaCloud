<?php

declare(strict_types=1);

namespace PrestaCloud\Core;

/** La configuración de una cuenta tiene errores. Trae TODOS los problemas, no solo el primero, para mostrarlos juntos. */
final class ReglasInvalidas extends \InvalidArgumentException
{
    /** @param list<string> $errores */
    public function __construct(private readonly array $errores)
    {
        parent::__construct('Configuración inválida: ' . implode(' · ', $errores));
    }

    /** @return list<string> */
    public function errores(): array
    {
        return $this->errores;
    }
}
