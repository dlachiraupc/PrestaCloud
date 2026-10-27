<?php

declare(strict_types=1);

namespace PrestaCloud\Core;

final class ValidadorCliente
{
    /**
     * Diminutivos que aparecen más de una vez, sin distinguir mayúsculas (como el COUNTIF del Excel).
     *
     * @param list<string> $diminutivos
     *
     * @return list<string> en mayúsculas, una vez cada uno, en el orden en que se repiten
     */
    public static function diminutivosRepetidos(array $diminutivos): array
    {
        $mayus = static fn (string $s): string => function_exists('mb_strtoupper') ? mb_strtoupper(trim($s)) : strtoupper(trim($s));
        $vistos = [];
        $repetidos = [];
        foreach ($diminutivos as $d) {
            $k = $mayus($d);
            if ($k === '') {
                continue;
            }
            if (isset($vistos[$k]) && !in_array($k, $repetidos, true)) {
                $repetidos[] = $k;
            }
            $vistos[$k] = true;
        }

        return $repetidos;
    }
}
