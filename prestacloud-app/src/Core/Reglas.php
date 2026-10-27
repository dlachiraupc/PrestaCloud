<?php

declare(strict_types=1);

namespace PrestaCloud\Core;

/**
 * Reglas de negocio de UNA cuenta (cada prestamista las define: modalidades, tasas, cuotas, puntualidad,
 * calificación y tipos de caja). Equivale a la hoja CONFIG del Excel, pero por cuenta.
 *
 * El motor nunca lee reglas de ningún sitio global: todas las funciones reciben las de la cuenta que están
 * calculando. Aquí no hay valores «de la casa»; una cuenta nueva empieza con `vacia()` y configura las suyas.
 *
 * Importes en céntimos. Objeto inmutable: los `con…` devuelven una copia.
 */
final class Reglas
{
    public const VERSION = 1;

    /** @var array<string, Modalidad> */
    private array $porCodigo = [];

    /**
     * @param list<Modalidad>     $modalidades
     * @param list<Tramo>         $tramos
     * @param array<string, int>  $tiposCaja   tipo de movimiento => signo (1 suma, -1 resta, 0 solo se anota)
     * @param array<string, int>  $aumentos    valor de Calificacion => aumento sugerido al renovar (céntimos)
     * @param array<string, bool> $renueva     valor de Calificacion => ¿se le renueva?
     */
    public function __construct(
        public readonly array $modalidades,
        public readonly array $tramos,
        public readonly array $tiposCaja,
        public readonly array $aumentos,
        public readonly array $renueva,
        public readonly string $horaMaxima = '17:00',
        public readonly int $toleranciaDias = 0,
        public readonly int $nHora = 1,
        public readonly int $nFecha = 1,
        public readonly int $nNoRenovar = 3,
    ) {
        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $horaMaxima)) {
            throw new \InvalidArgumentException("Hora máxima inválida: $horaMaxima (se espera HH:MM)");
        }
        foreach ($modalidades as $m) {
            $this->porCodigo[$m->codigo] = $m;
        }
    }

    /**
     * Punto de partida de una cuenta nueva: sin modalidades ni tasas (el prestamista las define en su alta),
     * con los tipos de caja y la calificación habituales y la hora máxima de las 5 pm.
     */
    public static function vacia(): self
    {
        return new self(
            modalidades: [],
            tramos: [],
            tiposCaja: [
                'SALDO INICIAL' => 1, 'APORTE CAPITAL' => 1, 'OTRO INGRESO' => 1,
                'RETIRO' => -1, 'GASTO' => -1, 'PAGO A INVERSIONISTA' => -1, 'POR CONFIRMAR' => 0,
            ],
            aumentos: [
                Calificacion::PUNTUAL->value => 10000,
                Calificacion::DESPUES_DE_LA_HORA->value => 0,
                Calificacion::DESPUES_DE_LA_FECHA->value => 0,
                Calificacion::NO_RENOVAR->value => 0,
            ],
            renueva: [
                Calificacion::PUNTUAL->value => true,
                Calificacion::DESPUES_DE_LA_HORA->value => true,
                Calificacion::DESPUES_DE_LA_FECHA->value => false,
                Calificacion::NO_RENOVAR->value => false,
            ],
        );
    }

    /** @param list<Modalidad> $modalidades */
    public function conModalidades(array $modalidades): self
    {
        return $this->con(['modalidades' => $modalidades]);
    }

    /** @param list<Tramo> $tramos */
    public function conTramos(array $tramos): self
    {
        return $this->con(['tramos' => $tramos]);
    }

    /** @param array<string, int> $tiposCaja */
    public function conTiposCaja(array $tiposCaja): self
    {
        return $this->con(['tiposCaja' => $tiposCaja]);
    }

    public function conTolerancia(int $dias): self
    {
        return $this->con(['toleranciaDias' => $dias]);
    }

    public function conHoraMaxima(string $hhmm): self
    {
        return $this->con(['horaMaxima' => $hhmm]);
    }

    public function conUmbrales(int $nHora, int $nFecha, int $nNoRenovar): self
    {
        return $this->con(['nHora' => $nHora, 'nFecha' => $nFecha, 'nNoRenovar' => $nNoRenovar]);
    }

    /** @param array<string, int> $aumentos @param array<string, bool> $renueva */
    public function conCalificacion(array $aumentos, array $renueva): self
    {
        return $this->con(['aumentos' => $aumentos + $this->aumentos, 'renueva' => $renueva + $this->renueva]);
    }

    public function modalidad(string $codigo): Modalidad
    {
        return $this->porCodigo[$codigo] ?? throw new SinTramo("No existe la modalidad «{$codigo}» en la configuración.");
    }

    /** Tramo de la modalidad con el mayor «desde» que no pase el monto prestado. */
    public function tramoPara(string $modalidad, int $monto): ?Tramo
    {
        $mejor = null;
        foreach ($this->tramos as $t) {
            if ($t->modalidad === $modalidad && $t->desde <= $monto && ($mejor === null || $t->desde > $mejor->desde)) {
                $mejor = $t;
            }
        }

        return $mejor;
    }

    public function signoDeTipo(string $tipo): int
    {
        return $this->tiposCaja[$tipo] ?? 0;
    }

    public function aumentoPara(Calificacion $c): int
    {
        return $this->aumentos[$c->value] ?? 0;
    }

    public function renuevaCon(Calificacion $c): bool
    {
        return $this->renueva[$c->value] ?? false;
    }

    /** ¿La cuenta ya puede entregar préstamos? Necesita al menos una modalidad y que la configuración sea coherente. */
    public function puedeEntregarPrestamos(): bool
    {
        return $this->modalidades !== [] && $this->problemas() === [];
    }

    /**
     * Revisa que la configuración sea coherente.
     *
     * @return list<string> mensajes para el prestamista; vacío si todo está bien
     */
    public function problemas(): array
    {
        $p = [];
        $codigos = [];
        foreach ($this->modalidades as $m) {
            if (isset($codigos[$m->codigo])) {
                $p[] = "La modalidad «{$m->codigo}» está repetida.";
            }
            $codigos[$m->codigo] = true;
        }
        $vistos = [];
        $tieneDesdeCero = [];
        foreach ($this->tramos as $t) {
            if (!isset($codigos[$t->modalidad])) {
                $p[] = "Un tramo usa la modalidad «{$t->modalidad}», que no existe.";
            }
            $clave = $t->modalidad . '|' . $t->desde;
            if (isset($vistos[$clave])) {
                $p[] = "«{$t->modalidad}» tiene dos tramos que empiezan en el mismo monto.";
            }
            $vistos[$clave] = true;
            if ($t->desde === 0) {
                $tieneDesdeCero[$t->modalidad] = true;
            }
            if ($t->nCuotas > 120) {
                $p[] = "«{$t->modalidad}»: máximo 120 cuotas.";
            }
            if ($t->tasaBp > 100000) {
                $p[] = "«{$t->modalidad}»: la tasa no puede pasar de 1,000 %.";
            }
            if ($t->redondeo > 1000) {
                $p[] = "«{$t->modalidad}»: el redondeo es demasiado grande.";
            }
        }
        foreach (array_keys($codigos) as $codigo) {
            if (!isset($tieneDesdeCero[$codigo])) {
                $p[] = "«{$codigo}» necesita un tramo que empiece en S/ 0 para cubrir cualquier monto.";
            }
        }
        foreach ($this->tiposCaja as $tipo => $signo) {
            if ($tipo === '' || strlen((string) $tipo) > 30) {
                $p[] = 'Hay un tipo de movimiento de caja sin nombre o demasiado largo.';
            }
            if (!in_array($signo, [-1, 0, 1], true)) {
                $p[] = "El tipo de caja «{$tipo}» debe sumar (1), restar (-1) o solo anotarse (0).";
            }
        }
        foreach ($this->aumentos as $c => $aumento) {
            if ($aumento < 0) {
                $p[] = "El aumento sugerido de «{$c}» no puede ser negativo.";
            }
        }
        if ($this->toleranciaDias < 0 || $this->toleranciaDias > 30) {
            $p[] = 'Los días de tolerancia van de 0 a 30.';
        }
        if ($this->nHora < 1 || $this->nFecha < 1 || $this->nNoRenovar < 1) {
            $p[] = 'Los umbrales de calificación deben ser de 1 pago o más.';
        }
        if ($this->nNoRenovar < $this->nFecha) {
            $p[] = 'NO RENOVAR necesita igual o más pagos tarde que DESPUÉS DE LA FECHA; si no, nunca se vería esa calificación.';
        }

        return $p;
    }

    /**
     * Forma serializable de la configuración, la que se guarda por cuenta (versionada).
     *
     * @return array<string, mixed>
     */
    public function aArray(): array
    {
        $calificacion = [];
        foreach (Calificacion::cases() as $c) {
            $calificacion[$c->value] = ['aumento' => $this->aumentoPara($c), 'renueva' => $this->renuevaCon($c)];
        }
        // Orden canónico: dos configuraciones iguales se serializan igual, vengan de donde vengan.
        $tiposCaja = $this->tiposCaja;
        ksort($tiposCaja);
        $posicion = array_flip(array_map(static fn (Modalidad $m): string => $m->codigo, $this->modalidades));
        $tramos = $this->tramos;
        usort($tramos, static fn (Tramo $a, Tramo $b): int => [$posicion[$a->modalidad] ?? PHP_INT_MAX, $a->desde] <=> [$posicion[$b->modalidad] ?? PHP_INT_MAX, $b->desde]);

        return [
            'version' => self::VERSION,
            'modalidades' => array_map(
                static fn (Modalidad $m): array => ['codigo' => $m->codigo, 'cada' => $m->cada, 'unidad' => $m->unidad, 'diaFijo' => $m->diaFijo],
                $this->modalidades,
            ),
            'tramos' => array_map(
                static fn (Tramo $t): array => ['modalidad' => $t->modalidad, 'desde' => $t->desde, 'tasaBp' => $t->tasaBp, 'nCuotas' => $t->nCuotas, 'redondeo' => $t->redondeo],
                $tramos,
            ),
            'tiposCaja' => $tiposCaja,
            'calificacion' => $calificacion,
            'puntualidad' => [
                'horaMaxima' => $this->horaMaxima,
                'toleranciaDias' => $this->toleranciaDias,
                'nHora' => $this->nHora,
                'nFecha' => $this->nFecha,
                'nNoRenovar' => $this->nNoRenovar,
            ],
        ];
    }

    /**
     * Reconstruye la configuración de una cuenta desde su forma serializada, validándola entera.
     *
     * @param array<string, mixed> $d
     *
     * @throws ReglasInvalidas con todos los problemas encontrados
     */
    public static function desdeArray(array $d): self
    {
        $e = [];
        if (($d['version'] ?? null) !== self::VERSION) {
            $e[] = 'Versión de configuración no soportada.';
        }
        $modalidades = [];
        foreach (self::lista($d['modalidades'] ?? []) as $i => $m) {
            try {
                $modalidades[] = new Modalidad((string) ($m['codigo'] ?? ''), self::entero($m['cada'] ?? null), self::entero($m['diaFijo'] ?? 0), (string) ($m['unidad'] ?? Modalidad::DIAS));
            } catch (\InvalidArgumentException $x) {
                $e[] = 'Modalidad #' . ($i + 1) . ': ' . $x->getMessage();
            }
        }
        $tramos = [];
        foreach (self::lista($d['tramos'] ?? []) as $i => $t) {
            try {
                $tramos[] = new Tramo((string) ($t['modalidad'] ?? ''), self::entero($t['desde'] ?? null), self::entero($t['tasaBp'] ?? null), self::entero($t['nCuotas'] ?? null), self::entero($t['redondeo'] ?? 0));
            } catch (\InvalidArgumentException $x) {
                $e[] = 'Tramo #' . ($i + 1) . ': ' . $x->getMessage();
            }
        }
        $tiposCaja = [];
        $dt = $d['tiposCaja'] ?? [];
        foreach (is_array($dt) ? $dt : [] as $tipo => $signo) {
            $tiposCaja[(string) $tipo] = self::entero($signo);
        }
        $base = self::vacia();
        $aumentos = [];
        $renueva = [];
        $dc = $d['calificacion'] ?? [];
        foreach (Calificacion::cases() as $c) {
            $fila = is_array($dc) && isset($dc[$c->value]) && is_array($dc[$c->value]) ? $dc[$c->value] : [];
            $aumentos[$c->value] = array_key_exists('aumento', $fila) ? self::entero($fila['aumento']) : $base->aumentoPara($c);
            $renueva[$c->value] = array_key_exists('renueva', $fila) ? (bool) $fila['renueva'] : $base->renuevaCon($c);
        }
        $p = is_array($d['puntualidad'] ?? null) ? $d['puntualidad'] : [];
        $hora = (string) ($p['horaMaxima'] ?? '17:00');
        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $hora)) {
            $e[] = "Hora máxima inválida: $hora (se espera HH:MM).";
            $hora = '17:00';
        }
        if ($e !== []) {
            throw new ReglasInvalidas($e);
        }
        $reglas = new self(
            $modalidades, $tramos, $tiposCaja, $aumentos, $renueva, $hora,
            self::entero($p['toleranciaDias'] ?? 0), self::entero($p['nHora'] ?? 1), self::entero($p['nFecha'] ?? 1), self::entero($p['nNoRenovar'] ?? 3),
        );
        $problemas = $reglas->problemas();
        if ($problemas !== []) {
            throw new ReglasInvalidas($problemas);
        }

        return $reglas;
    }

    /** @return list<array<string, mixed>> */
    private static function lista(mixed $v): array
    {
        return is_array($v) ? array_values(array_filter($v, 'is_array')) : [];
    }

    private static function entero(mixed $v): int
    {
        return is_int($v) ? $v : (is_string($v) && preg_match('/^-?\d+$/', $v) ? (int) $v : -1);
    }

    /** @param array<string, mixed> $cambios */
    private function con(array $cambios): self
    {
        return new self(...array_merge([
            'modalidades' => $this->modalidades,
            'tramos' => $this->tramos,
            'tiposCaja' => $this->tiposCaja,
            'aumentos' => $this->aumentos,
            'renueva' => $this->renueva,
            'horaMaxima' => $this->horaMaxima,
            'toleranciaDias' => $this->toleranciaDias,
            'nHora' => $this->nHora,
            'nFecha' => $this->nFecha,
            'nNoRenovar' => $this->nNoRenovar,
        ], $cambios));
    }
}
