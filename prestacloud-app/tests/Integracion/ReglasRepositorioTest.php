<?php

declare(strict_types=1);

namespace PrestaCloud\Tests\Integracion;

use PDO;
use PHPUnit\Framework\TestCase;
use PrestaCloud\Core\Fecha;
use PrestaCloud\Core\GeneradorPlan;
use PrestaCloud\Core\Modalidad;
use PrestaCloud\Core\Reglas;
use PrestaCloud\Core\ReglasInvalidas;
use PrestaCloud\Core\Tramo;
use PrestaCloud\Infra\Conexion;
use PrestaCloud\Infra\ReglasRepositorio;

/**
 * Contra MySQL 5.7 real, con las migraciones aplicadas. Se activa con PC_TEST_DB=1 (el CI y `composer`
 * en local lo ponen); sin eso se omite, para poder correr las pruebas unitarias sin base de datos.
 */
final class ReglasRepositorioTest extends TestCase
{
    private PDO $pdo;
    private ReglasRepositorio $repo;
    /** @var list<int> */
    private array $tenants = [];

    protected function setUp(): void
    {
        if (getenv('PC_TEST_DB') !== '1') {
            $this->markTestSkipped('Pruebas con base de datos: define PC_TEST_DB=1 y aplica las migraciones.');
        }
        $this->pdo = Conexion::conectar();
        $this->repo = new ReglasRepositorio($this->pdo);
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo) || $this->tenants === []) {
            return;
        }
        foreach ($this->tenants as $id) {
            foreach (['pagos', 'cuotas', 'prestamos', 'clientes', 'contadores'] as $tabla) {
                $this->pdo->prepare("DELETE FROM $tabla WHERE tenant_id = ?")->execute([$id]);
            }
            $this->pdo->prepare('DELETE FROM tenants WHERE id = ?')->execute([$id]); // las config_* caen en cascada
        }
    }

    private function nuevaCuenta(): int
    {
        $this->pdo->prepare("INSERT INTO tenants (nombre_negocio, slug, plan_id) VALUES (?, ?, (SELECT id FROM planes WHERE codigo = 'gratis'))")
            ->execute(['Prueba ' . bin2hex(random_bytes(2)), 'test-' . bin2hex(random_bytes(5))]);
        $id = (int) $this->pdo->lastInsertId();
        $this->tenants[] = $id;

        return $id;
    }

    private function reglasA(): Reglas
    {
        return Reglas::vacia()->conModalidades([new Modalidad('SEMANAL', 7)])->conTramos([new Tramo('SEMANAL', 0, 1000, 4, 0)]);
    }

    private function reglasB(): Reglas
    {
        return Reglas::vacia()
            ->conModalidades([new Modalidad('DIARIA', 1), new Modalidad('MENSUAL', 1, 0, Modalidad::MESES)])
            ->conTramos([new Tramo('DIARIA', 0, 3000, 6, 0), new Tramo('MENSUAL', 0, 800, 3, 0), new Tramo('MENSUAL', 100000, 700, 6, 1)])
            ->conTolerancia(2)
            ->conTiposCaja(['APORTE SOCIO' => 1, 'COMISION' => -1]);
    }

    public function test_cada_cuenta_guarda_y_recupera_sus_propias_reglas(): void
    {
        $a = $this->nuevaCuenta();
        $b = $this->nuevaCuenta();
        $this->repo->guardar($a, $this->reglasA());
        $this->repo->guardar($b, $this->reglasB());

        $this->assertSame($this->reglasA()->aArray(), $this->repo->paraTenant($a)->aArray());
        $this->assertSame($this->reglasB()->aArray(), $this->repo->paraTenant($b)->aArray());
    }

    public function test_guardar_las_reglas_de_una_cuenta_no_toca_las_de_otra(): void
    {
        $a = $this->nuevaCuenta();
        $b = $this->nuevaCuenta();
        $this->repo->guardar($a, $this->reglasA());
        $this->repo->guardar($b, $this->reglasB());

        $this->repo->guardar($b, $this->reglasA()->conTolerancia(5));

        $this->assertSame($this->reglasA()->aArray(), $this->repo->paraTenant($a)->aArray(), 'la cuenta A sigue igual');
        $this->assertSame(5, $this->repo->paraTenant($b)->toleranciaDias);
    }

    public function test_una_cuenta_sin_configurar_parte_de_las_reglas_vacias(): void
    {
        $c = $this->nuevaCuenta();
        $reglas = $this->repo->paraTenant($c);

        $this->assertSame(Reglas::vacia()->aArray(), $reglas->aArray());
        $this->assertFalse($reglas->puedeEntregarPrestamos());
    }

    public function test_alta_de_cuenta_nueva_deja_sus_reglas_listas_para_configurar(): void
    {
        $c = $this->nuevaCuenta();
        $this->repo->inicializar($c);

        $n = $this->pdo->prepare('SELECT COUNT(*) FROM config_tipos_caja WHERE tenant_id = ?');
        $n->execute([$c]);
        $this->assertSame(7, (int) $n->fetchColumn());
        $this->assertSame(Reglas::vacia()->aArray(), $this->repo->paraTenant($c)->aArray());
    }

    public function test_una_configuracion_invalida_no_cambia_nada(): void
    {
        $a = $this->nuevaCuenta();
        $this->repo->guardar($a, $this->reglasA());

        $sinTramoCero = Reglas::vacia()->conModalidades([new Modalidad('SEMANAL', 7)])->conTramos([new Tramo('SEMANAL', 50000, 1000, 4, 0)]);
        try {
            $this->repo->guardar($a, $sinTramoCero);
            $this->fail('Debió rechazarla');
        } catch (ReglasInvalidas) {
            $this->assertSame($this->reglasA()->aArray(), $this->repo->paraTenant($a)->aArray());
        }
    }

    public function test_cada_cuenta_calcula_el_mismo_prestamo_con_sus_reglas_leidas_de_la_base(): void
    {
        $a = $this->nuevaCuenta();
        $b = $this->nuevaCuenta();
        $this->repo->guardar($a, $this->reglasA());
        $this->repo->guardar($b, $this->reglasB());
        $entrega = Fecha::desde('2026-10-26');

        $planA = GeneradorPlan::generar($this->repo->paraTenant($a), 'SEMANAL', $entrega, 20000);
        $planB = GeneradorPlan::generar($this->repo->paraTenant($b), 'DIARIA', $entrega, 20000);

        $this->assertSame([22000, 4], [$planA->total, $planA->nCuotas]);
        $this->assertSame([26000, 6], [$planB->total, $planB->nCuotas]);
    }

    public function test_la_base_rechaza_un_prestamo_que_mezcla_clientes_de_otra_cuenta(): void
    {
        $a = $this->nuevaCuenta();
        $b = $this->nuevaCuenta();
        $this->pdo->prepare('INSERT INTO clientes (tenant_id, codigo, nombre, diminutivo) VALUES (?, ?, ?, ?)')->execute([$a, 'C001', 'Cliente de A', 'CA']);
        $clienteDeA = (int) $this->pdo->lastInsertId();

        $this->expectException(\PDOException::class);
        // Un préstamo de la cuenta B apuntando al cliente de la cuenta A: la clave foránea compuesta lo impide.
        $this->pdo->prepare('INSERT INTO prestamos (tenant_id, codigo, cliente_id, modalidad, fecha_entrega, monto, tasa_bp, n_cuotas, total, cuota_primera, cuota_normal) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$b, 'P001', $clienteDeA, 'SEMANAL', '2026-10-26', 20000, 1000, 4, 22000, 5500, 5500]);
    }

    public function test_los_codigos_se_repiten_entre_cuentas_pero_no_dentro_de_una(): void
    {
        $a = $this->nuevaCuenta();
        $b = $this->nuevaCuenta();
        $ins = $this->pdo->prepare('INSERT INTO clientes (tenant_id, codigo, nombre, diminutivo) VALUES (?, ?, ?, ?)');
        $ins->execute([$a, 'C001', 'Uno', 'UNO']);
        $ins->execute([$b, 'C001', 'Uno', 'UNO']); // mismo código y apodo en otra cuenta: permitido

        $this->expectException(\PDOException::class);
        $ins->execute([$a, 'C001', 'Otro', 'OTRO']); // repetido dentro de la misma cuenta: rechazado
    }
}
