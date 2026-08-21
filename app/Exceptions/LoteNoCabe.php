<?php

namespace App\Exceptions;

use Illuminate\Support\Arr;
use RuntimeException;

/**
 * Corta la transaccion del reparto por lote cuando uno de los estudiantes no
 * pasa la validacion.
 *
 * Existe para deshacer el lote entero: Laravel revierte la transaccion al salir
 * por excepcion, y el original hacia lo mismo con `set_rollback(True)`. Es una
 * excepcion propia y no una `ValidationException` reutilizada para poder
 * atraparla sin tragarse tambien la del cupo, que es la que lleva el motivo.
 *
 * No llega nunca al usuario tal cual: quien la lanza la atrapa en la linea
 * siguiente y pide `explicacion()`.
 *
 * Carga el motivo REAL porque antes se perdia. El panel respondia siempre «no
 * caben N: solo quedaban X cupos», fuera cual fuera el fallo de `validar()`, y
 * acertaba de casualidad: `validar()` comprueba tres reglas, pero una matricula
 * que solo cambia de grupo no aumenta la ocupacion —`aumentaOcupacion()`
 * devuelve false—, asi que las dos ramas de promotoria se saltan solas. Es una
 * coincidencia entre dos metodos que nadie ata: el dia que `validar()` gane una
 * comprobacion nueva, el panel daria un diagnostico falso con total aplomo.
 */
class LoteNoCabe extends RuntimeException
{
    /**
     * @param  int  $cabian  Cuantos se habian asignado antes del fallo.
     * @param  array<string, array<int, string>>  $motivo  Lo que devolvio `ValidationException::errors()`.
     * @param  string|null  $culpable  Nombre de la persona en cuya matricula fallo.
     */
    public function __construct(
        public readonly int $cabian,
        public readonly array $motivo,
        public readonly ?string $culpable = null,
    ) {
        parent::__construct('El lote no cabe.');
    }

    /**
     * Que decirle a quien acaba de intentar el reparto.
     *
     * @param  int  $cuantos  Cuantos estudiantes traia el lote.
     * @param  string  $grupo  El grupo al que se les mandaba.
     */
    public function explicacion(int $cuantos, string $grupo): string
    {
        // El cupo del grupo es el caso corriente y se gana el mensaje util: lo
        // que hace falta saber para reintentar es cuantos habrian cabido.
        //
        // Que la clave 'grupo' signifique aqui «no hay cupo» y no «ese grupo es
        // de otra promotoria» NO es casualidad: quien llama acota el grupo y las
        // matriculas a la misma promotoria unas lineas antes, en el mismo
        // metodo, asi que esa otra rama no se puede alcanzar. Es una garantia
        // local y a la vista, no un acuerdo tacito entre archivos.
        if (array_key_exists('grupo', $this->motivo)) {
            $cupos = $this->cabian === 1 ? 'cupo' : 'cupos';

            return "No caben {$cuantos} en {$grupo}: solo quedaban {$this->cabian} {$cupos}. "
                .'No se asignó a nadie — manda menos estudiantes o amplíale el cupo al grupo.';
        }

        // Cualquier otro motivo se cuenta tal como lo dijo `validar()`, sin
        // inventarle una causa. Y con el nombre delante: las demas reglas son de
        // UNA matricula y no del lote, asi que sin saber en quien fallo no hay
        // forma de arreglarlo salvo ir quitando gente al azar.
        $real = trim(implode(' ', Arr::flatten($this->motivo)));

        if ($real === '') {
            $real = 'La validación lo rechazó sin dar un motivo.';
        }

        return ($this->culpable !== null ? "{$this->culpable}: " : '')
            .$real
            .' No se asignó a nadie.';
    }
}
