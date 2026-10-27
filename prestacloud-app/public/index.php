<?php

declare(strict_types=1);

/**
 * Único punto de entrada web de PrestaCloud (Fase 0: página de estado).
 * Las pantallas llegan en la Fase 1; todo pasará por este archivo.
 */
require __DIR__ . '/../vendor/autoload.php';

use PrestaCloud\Infra\Env;

Env::cargar(dirname(__DIR__));
date_default_timezone_set('America/Lima');

// Fase 0: solo existe la página de estado. Cualquier otra ruta es 404 (también las de archivos internos).
$ruta = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if ($ruta !== '/' && $ruta !== '/index.php') {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('No encontrado');
}

$entorno = Env::get('APP_ENV', 'local');
$versionArchivo = dirname(__DIR__) . '/VERSION';
$version = is_file($versionArchivo) ? trim((string) file_get_contents($versionArchivo)) : 'desarrollo';
$h = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
if ($entorno !== 'prod') {
    header('X-Robots-Tag: noindex, nofollow');
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>PrestaCloud</title>
    <style>
        :root { color-scheme: light dark; --bg:#F2F5F2; --ink:#14201D; --ink2:#46544F; --brand:#0B6B5D; --card:#fff; --line:#D1DAD5; }
        @media (prefers-color-scheme: dark) { :root { --bg:#0D1412; --ink:#E8F0EC; --ink2:#A6B7B0; --brand:#3FB59F; --card:#151F1C; --line:#2B3A35; } }
        body { margin:0; min-height:100vh; display:grid; place-items:center; background:var(--bg); color:var(--ink); font:16px/1.5 system-ui,-apple-system,'Segoe UI',sans-serif; }
        main { width:min(100% - 32px, 440px); background:var(--card); border:1px solid var(--line); border-radius:16px; padding:28px; }
        h1 { margin:0 0 4px; font-size:28px; color:var(--brand); }
        p { margin:0 0 12px; color:var(--ink2); }
        dl { display:grid; grid-template-columns:auto 1fr; gap:6px 16px; margin:16px 0 0; font-size:14px; }
        dt { color:var(--ink2); } dd { margin:0; font-variant-numeric:tabular-nums; }
    </style>
</head>
<body>
<main>
    <h1>PrestaCloud</h1>
    <p>Gestión de préstamos y cobranza. Un servicio de EmpresasCloud.</p>
    <p>En construcción: todavía no hay pantallas disponibles.</p>
    <dl>
        <dt>Entorno</dt><dd><?= $h($entorno ?? 'local') ?></dd>
        <dt>Versión</dt><dd><?= $h($version) ?></dd>
        <dt>Estado</dt><dd><a href="/salud.php" style="color:var(--brand)">salud.php</a></dd>
    </dl>
</main>
</body>
</html>
