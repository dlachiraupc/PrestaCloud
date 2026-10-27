<?php

declare(strict_types=1);

namespace PrestaCloud\Tests\Fixtures;

use PrestaCloud\Core\Calificacion;
use PrestaCloud\Core\Modalidad;
use PrestaCloud\Core\Reglas;
use PrestaCloud\Core\Tramo;

/**
 * Las reglas del libro PRESTAMOS v13, como DATO DE PRUEBA.
 *
 * Son las reglas de UNA cuenta (la de Daniel). El motor no las conoce: solo existen aquí, para comprobar
 * que el motor reproduce el Excel, y más adelante como configuración de esa cuenta cuando se migre.
 */
final class ReglasV13
{
    public static function crear(): Reglas
    {
        return new Reglas(
            modalidades: [new Modalidad('C3D', 3, 0), new Modalidad('SEMANAL', 7, 7)],
            tramos: [
                new Tramo('C3D', 0, 2000, 8, 0),
                new Tramo('SEMANAL', 0, 4000, 8, 0),
                new Tramo('SEMANAL', 50000, 4500, 12, 1),
            ],
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
}
