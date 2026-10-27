<?php

declare(strict_types=1);

namespace PrestaCloud\Core;

/**
 * No hay un tramo de tasa aplicable. En el Excel esto salía como «⚠ SIN TRAMO» en la hoja;
 * en la aplicación el préstamo no se puede crear hasta corregir la configuración.
 */
final class SinTramo extends \DomainException
{
}
