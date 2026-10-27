<?php

declare(strict_types=1);

namespace PrestaCloud\Infra;

/** Variables de entorno: el .env de cada instalación (nunca versionado) y, si no, las del proceso. */
final class Env
{
    public static function cargar(?string $dir = null): void
    {
        $dir ??= dirname(__DIR__, 2);
        if (is_file($dir . '/.env') && class_exists(\Dotenv\Dotenv::class)) {
            \Dotenv\Dotenv::createImmutable($dir)->safeLoad();
        }
    }

    public static function get(string $clave, ?string $porDefecto = null): ?string
    {
        $v = $_ENV[$clave] ?? getenv($clave);

        return ($v === false || $v === null || $v === '') ? $porDefecto : (string) $v;
    }
}
