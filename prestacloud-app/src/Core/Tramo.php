<?php

declare(strict_types=1);

namespace PrestaCloud\Core;

/**
 * Tasa y cuotas que se aplican a una modalidad desde cierto monto.
 * $desde en céntimos · $tasaBp en puntos básicos (2000 = 20 %) · $redondeo en soles (0 exacto, 1 enteros, 5 múltiplos de 5).
 */
final class Tramo
{
    public function __construct(
        public readonly string $modalidad,
        public readonly int $desde,
        public readonly int $tasaBp,
        public readonly int $nCuotas,
        public readonly int $redondeo = 0,
    ) {
        if ($nCuotas < 1) {
            throw new \InvalidArgumentException('El tramo necesita al menos una cuota.');
        }
        if ($desde < 0 || $tasaBp < 0 || $redondeo < 0) {
            throw new \InvalidArgumentException('Valores negativos en el tramo.');
        }
    }
}
