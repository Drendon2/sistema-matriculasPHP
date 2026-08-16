<?php

namespace App\Support;

use Illuminate\Database\QueryException;

/**
 * Lee QUE restriccion rechazo una escritura.
 *
 * Es el equivalente de `_constraint_violada` del original, que en PostgreSQL
 * sacaba el nombre del diagnostico de psycopg. MariaDB no expone un campo con
 * el nombre: viaja dentro del texto del error, asi que aqui se extrae de ahi.
 *
 * A esto solo se llega en una CARRERA real. La validacion del modelo ya
 * comprobo cupo y limite antes de escribir; si el motor rechaza la operacion es
 * porque entre la comprobacion y el guardado entro otra peticion. Por eso vale
 * la pena distinguir el motivo: "se llenó mientras enviabas" y "ya tienes una
 * en esa promotoría" son cosas muy distintas para quien lo lee.
 */
class ErrorDeBaseDeDatos
{
    /**
     * SQLSTATE que usa el trigger de cupo (`SIGNAL SQLSTATE '45000'`).
     *
     * Es el estado generico de "error definido por el usuario": lo unico que
     * MariaDB ofrece para un SIGNAL propio, asi que no basta con el codigo y hay
     * que mirar tambien el mensaje.
     */
    private const SQLSTATE_SIGNAL = '45000';

    /** ¿La escritura la rechazo el trigger de cupo de promotoria? */
    public static function esCupoAgotado(QueryException $e): bool
    {
        return ($e->getCode() === self::SQLSTATE_SIGNAL)
            && str_contains($e->getMessage(), 'no tiene cupos disponibles');
    }

    /**
     * Nombre del indice unico que rechazo la escritura, o null.
     *
     * MariaDB lo escribe como: Duplicate entry 'x-y' for key 'nombre_del_indice'
     */
    public static function indiceViolado(QueryException $e): ?string
    {
        if (preg_match("/Duplicate entry '.*' for key '(.+?)'/", $e->getMessage(), $coincidencias) === 1) {
            return $coincidencias[1];
        }

        return null;
    }

    /** ¿El estudiante ya ocupaba todas sus ranuras del periodo? */
    public static function esRanuraOcupada(QueryException $e): bool
    {
        return self::indiceViolado($e) === 'una_matricula_por_ranura_y_periodo';
    }

    /** ¿Ya existia una matricula suya en esa promotoria y periodo? */
    public static function esMatriculaRepetida(QueryException $e): bool
    {
        return self::indiceViolado($e) === 'unica_matricula_por_periodo';
    }
}
