<?php

declare(strict_types=1);

namespace PrestaCloud\Infra;

use PDO;
use PrestaCloud\Core\Calificacion;
use PrestaCloud\Core\Reglas;
use PrestaCloud\Core\ReglasInvalidas;

/**
 * Guarda y lee las reglas de negocio de UNA cuenta (tablas config_*). Es el único camino a esas tablas:
 * toda consulta lleva tenant_id, así que una cuenta nunca ve ni cambia las reglas de otra.
 */
final class ReglasRepositorio
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** Reglas de la cuenta. Si todavía no configuró nada, devuelve las de una cuenta nueva (`Reglas::vacia()`). */
    public function paraTenant(int $tenantId): Reglas
    {
        $q = fn (string $sql): array => $this->consultar($sql, $tenantId);

        $modalidades = array_map(fn (array $r): array => [
            'codigo' => $r['codigo'], 'cada' => (int) $r['cada'], 'unidad' => $r['unidad'], 'diaFijo' => (int) $r['dia_fijo'],
        ], $q('SELECT codigo, cada, unidad, dia_fijo FROM config_modalidades WHERE tenant_id = ? ORDER BY orden, codigo'));

        $tramos = array_map(fn (array $r): array => [
            'modalidad' => $r['modalidad'], 'desde' => (int) $r['desde'], 'tasaBp' => (int) $r['tasa_bp'], 'nCuotas' => (int) $r['n_cuotas'], 'redondeo' => (int) $r['redondeo'],
        ], $q('SELECT modalidad, desde, tasa_bp, n_cuotas, redondeo FROM config_tramos WHERE tenant_id = ? ORDER BY modalidad, desde'));

        $base = Reglas::vacia()->aArray();
        $tiposCaja = [];
        foreach ($q('SELECT tipo, signo FROM config_tipos_caja WHERE tenant_id = ? ORDER BY tipo') as $r) {
            $tiposCaja[$r['tipo']] = (int) $r['signo'];
        }
        $calificacion = $base['calificacion'];
        foreach ($q('SELECT calificacion, aumento, renueva FROM config_calificacion WHERE tenant_id = ?') as $r) {
            $calificacion[$r['calificacion']] = ['aumento' => (int) $r['aumento'], 'renueva' => (bool) $r['renueva']];
        }
        $puntualidad = $base['puntualidad'];
        foreach ($q('SELECT hora_maxima, tolerancia_dias, n_hora, n_fecha, n_no_renovar FROM config_puntualidad WHERE tenant_id = ?') as $r) {
            $puntualidad = [
                'horaMaxima' => $r['hora_maxima'], 'toleranciaDias' => (int) $r['tolerancia_dias'],
                'nHora' => (int) $r['n_hora'], 'nFecha' => (int) $r['n_fecha'], 'nNoRenovar' => (int) $r['n_no_renovar'],
            ];
        }

        return Reglas::desdeArray([
            'version' => Reglas::VERSION,
            'modalidades' => $modalidades,
            'tramos' => $tramos,
            'tiposCaja' => $tiposCaja !== [] ? $tiposCaja : $base['tiposCaja'],
            'calificacion' => $calificacion,
            'puntualidad' => $puntualidad,
        ]);
    }

    /**
     * Reemplaza las reglas de la cuenta por las indicadas, en una sola transacción.
     * Los préstamos ya entregados no cambian: guardan su propio plan.
     *
     * @throws ReglasInvalidas si la configuración no es coherente (no se toca nada)
     */
    public function guardar(int $tenantId, Reglas $reglas): void
    {
        $problemas = $reglas->problemas();
        if ($problemas !== []) {
            throw new ReglasInvalidas($problemas);
        }
        $this->pdo->beginTransaction();
        try {
            // Tramos primero: dependen de las modalidades por clave foránea.
            foreach (['config_tramos', 'config_modalidades', 'config_tipos_caja', 'config_calificacion', 'config_puntualidad'] as $tabla) {
                $this->pdo->prepare("DELETE FROM $tabla WHERE tenant_id = ?")->execute([$tenantId]);
            }
            $mod = $this->pdo->prepare('INSERT INTO config_modalidades (tenant_id, codigo, cada, unidad, dia_fijo, orden) VALUES (?, ?, ?, ?, ?, ?)');
            foreach (array_values($reglas->modalidades) as $i => $m) {
                $mod->execute([$tenantId, $m->codigo, $m->cada, $m->unidad, $m->diaFijo, $i]);
            }
            $tr = $this->pdo->prepare('INSERT INTO config_tramos (tenant_id, modalidad, desde, tasa_bp, n_cuotas, redondeo) VALUES (?, ?, ?, ?, ?, ?)');
            foreach ($reglas->tramos as $t) {
                $tr->execute([$tenantId, $t->modalidad, $t->desde, $t->tasaBp, $t->nCuotas, $t->redondeo]);
            }
            $caja = $this->pdo->prepare('INSERT INTO config_tipos_caja (tenant_id, tipo, signo) VALUES (?, ?, ?)');
            foreach ($reglas->tiposCaja as $tipo => $signo) {
                $caja->execute([$tenantId, (string) $tipo, $signo]);
            }
            $cal = $this->pdo->prepare('INSERT INTO config_calificacion (tenant_id, calificacion, aumento, renueva) VALUES (?, ?, ?, ?)');
            foreach (Calificacion::cases() as $c) {
                $cal->execute([$tenantId, $c->value, $reglas->aumentoPara($c), $reglas->renuevaCon($c) ? 1 : 0]);
            }
            $this->pdo->prepare('INSERT INTO config_puntualidad (tenant_id, hora_maxima, tolerancia_dias, n_hora, n_fecha, n_no_renovar) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([$tenantId, $reglas->horaMaxima, $reglas->toleranciaDias, $reglas->nHora, $reglas->nFecha, $reglas->nNoRenovar]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /** Alta de una cuenta nueva: reglas vacías (sin modalidades ni tasas) listas para que el prestamista configure las suyas. */
    public function inicializar(int $tenantId): void
    {
        $this->guardar($tenantId, Reglas::vacia());
    }

    /** @return list<array<string, mixed>> */
    private function consultar(string $sql, int $tenantId): array
    {
        $s = $this->pdo->prepare($sql);
        $s->execute([$tenantId]);

        return $s->fetchAll();
    }
}
