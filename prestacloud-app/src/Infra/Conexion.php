<?php

declare(strict_types=1);

namespace PrestaCloud\Infra;

use PDO;

/** Conexión PDO a MySQL 5.7. Una sola por petición; hora de Lima en la sesión. */
final class Conexion
{
    private static ?PDO $instancia = null;

    /** @throws \RuntimeException sin exponer credenciales ni el mensaje del servidor */
    public static function conectar(): PDO
    {
        if (self::$instancia !== null) {
            return self::$instancia;
        }
        Env::cargar();
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            Env::get('DB_HOST', 'localhost'),
            (int) Env::get('DB_PORT', '3306'),
            Env::get('DB_NAME', ''),
            Env::get('DB_CHARSET', 'utf8mb4'),
        );
        try {
            self::$instancia = new PDO($dsn, Env::get('DB_USER', '') ?? '', Env::get('DB_PASS', '') ?? '', [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_PERSISTENT => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '-05:00'",
            ]);
        } catch (\PDOException $e) {
            error_log('[Conexion] No se pudo conectar. SQLSTATE: ' . $e->getCode());
            throw new \RuntimeException('No se pudo conectar a la base de datos.');
        }

        return self::$instancia;
    }

    public static function reiniciar(): void
    {
        self::$instancia = null;
    }
}
