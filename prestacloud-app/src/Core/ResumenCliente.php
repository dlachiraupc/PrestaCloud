<?php

declare(strict_types=1);

namespace PrestaCloud\Core;

/** Resumen de un cliente a una fecha (equivale a las columnas I–U de la hoja CLIENTES). */
final class ResumenCliente
{
    private function __construct(
        public readonly int $nPrestamos,
        public readonly int $vigentes,
        public readonly int $deuda,
        public readonly int $atraso,
        public readonly int $pagado,
        public readonly Situacion $situacion,
        /** Pagos puntuales, contando también los adelantados. */
        public readonly int $puntuales,
        public readonly int $despuesDeLaHora,
        public readonly int $despuesDeLaFecha,
        public readonly ?Calificacion $calificacionAuto,
        public readonly ?Calificacion $calificacionUsada,
    ) {
    }

    /**
     * @param list<Prestamo> $prestamos préstamos de todos los clientes; se filtran los de $clienteCodigo
     * @param list<Pago>     $pagos
     */
    public static function calcular(string $clienteCodigo, array $prestamos, array $pagos, Fecha $hoy, Reglas $reglas, ?Calificacion $manual = null): self
    {
        $n = 0;
        $vigentes = 0;
        $deuda = 0;
        $atraso = 0;
        $pagado = 0;
        $asignados = [];
        foreach ($prestamos as $p) {
            if ($p->clienteCodigo !== $clienteCodigo) {
                continue;
            }
            $n++;
            $r = ResumenPrestamo::calcular($p, $pagos, $hoy);
            if ($r->estado->esVigente()) {
                $vigentes++;
                $deuda += $r->saldo;
            }
            $atraso += $r->atraso;
            $pagado += $r->cobrado;
            array_push($asignados, ...Asignador::asignar($p, $pagos, $reglas, $hoy));
        }

        $cuenta = ['p' => 0, 'h' => 0, 'f' => 0];
        foreach ($asignados as $a) {
            match ($a->puntualidad) {
                Puntualidad::PUNTUAL, Puntualidad::ADELANTADO => $cuenta['p']++,
                Puntualidad::DESPUES_DE_LA_HORA => $cuenta['h']++,
                Puntualidad::DESPUES_DE_LA_FECHA => $cuenta['f']++,
            };
        }
        $auto = Calificador::auto($asignados, $reglas);

        return new self(
            nPrestamos: $n,
            vigentes: $vigentes,
            deuda: $deuda,
            atraso: $atraso,
            pagado: $pagado,
            situacion: $atraso > 0 ? Situacion::CON_ATRASO : ($vigentes > 0 ? Situacion::ACTIVO : Situacion::SIN_DEUDA),
            puntuales: $cuenta['p'],
            despuesDeLaHora: $cuenta['h'],
            despuesDeLaFecha: $cuenta['f'],
            calificacionAuto: $auto,
            calificacionUsada: Calificador::usada($auto, $manual),
        );
    }
}
