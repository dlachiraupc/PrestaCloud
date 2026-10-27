<?php

declare(strict_types=1);

/**
 * Estado del servicio para un monitor externo (UptimeRobot, Healthchecks…) y para verificar cada despliegue.
 * Público y sin datos sensibles. 200 si la base responde y el núcleo de cálculo funciona en este servidor;
 * 503 si no.
 */
require __DIR__ . '/../vendor/autoload.php';

use PrestaCloud\Core\Fecha;
use PrestaCloud\Core\GeneradorPlan;
use PrestaCloud\Core\Modalidad;
use PrestaCloud\Core\Reglas;
use PrestaCloud\Core\Tramo;
use PrestaCloud\Infra\Conexion;
use PrestaCloud\Infra\Env;

Env::cargar(dirname(__DIR__));
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$estado = ['bd' => false, 'migraciones' => null, 'nucleo' => false, 'php' => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION];
$versionArchivo = dirname(__DIR__) . '/VERSION';
$estado['version'] = is_file($versionArchivo) ? trim((string) file_get_contents($versionArchivo)) : 'desarrollo';

try {
    $pdo = Conexion::conectar();
    $pdo->query('SELECT 1')->fetchColumn();
    $estado['bd'] = true;
    try {
        $estado['migraciones'] = (int) $pdo->query('SELECT COUNT(*) FROM pc_migraciones')->fetchColumn();
    } catch (\Throwable) {
        $estado['migraciones'] = null; // la tabla aún no existe: no es una caída de la base
    }
} catch (\Throwable $e) {
    error_log('[salud] ' . $e->getMessage());
}

// Autocomprobación del núcleo EN ESTE servidor: PHP 8.2, enums, aritmética entera y fechas.
try {
    $reglas = Reglas::vacia()
        ->conModalidades([new Modalidad('SEMANAL', 7, 7)])
        ->conTramos([new Tramo('SEMANAL', 0, 4000, 8, 0), new Tramo('SEMANAL', 50000, 4500, 12, 1)]);
    $plan = GeneradorPlan::generar($reglas, 'SEMANAL', Fecha::desde('2026-10-26'), 50000);
    $estado['nucleo'] = $plan->total === 72500
        && $plan->descripcion() === '1 × 65.00 + 11 × 60.00'
        && $plan->fechaPrimera()->iso() === '2026-10-31';
} catch (\Throwable $e) {
    error_log('[salud] núcleo: ' . $e->getMessage());
}

$ok = $estado['bd'] && $estado['nucleo'];
http_response_code($ok ? 200 : 503);
echo json_encode(['ok' => $ok] + $estado);
