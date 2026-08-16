<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Corta la transaccion del reparto por lote cuando uno de los estudiantes no
 * cabe en el grupo.
 *
 * Existe solo para deshacer el lote entero: Laravel revierte la transaccion al
 * salir por excepcion, y el original hacia lo mismo con `set_rollback(True)`.
 * Es una excepcion propia y no una `ValidationException` reutilizada para poder
 * atraparla sin tragarse tambien la del cupo, que es la que lleva el motivo.
 *
 * No llega nunca al usuario: quien la lanza la atrapa en la linea siguiente.
 */
class LoteNoCabe extends RuntimeException
{
}
