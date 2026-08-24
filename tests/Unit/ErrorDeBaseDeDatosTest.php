<?php

namespace Tests\Unit;

use App\Support\ErrorDeBaseDeDatos;
use Illuminate\Database\QueryException;
use PHPUnit\Framework\TestCase;

/**
 * El traductor de errores del motor.
 *
 * Existe porque de el depende una decision que no es cosmetica: que error se le
 * cuenta al usuario como "esto ya estaba hecho" y cual tiene que propagarse.
 * Confundirlos es como el formulario publico de actividades llego a responder
 * "ya estabas inscrito" ante una base caida.
 *
 * Es una prueba unitaria de verdad —sin base de datos— porque lo unico que hace
 * esta clase es leer el mensaje del motor. Las excepciones se construyen a mano
 * con los mensajes que MariaDB escribe de verdad.
 */
class ErrorDeBaseDeDatosTest extends TestCase
{
    private function excepcion(string $mensaje, int $codigo = 0): QueryException
    {
        $previa = new \PDOException($mensaje);
        $previa->errorInfo = ['HY000', $codigo, $mensaje];

        return new QueryException('mariadb', 'insert into `x` values (?)', [], $previa);
    }

    public function test_reconoce_el_unico_de_inscripcion_por_documento(): void
    {
        $e = $this->excepcion(
            "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry '3-99887766' for key 'una_inscripcion_por_documento'"
        );

        $this->assertTrue(ErrorDeBaseDeDatos::esInscripcionRepetida($e));
    }

    public function test_no_confunde_otro_unico_con_ese(): void
    {
        // Dos indices distintos de la misma familia de error. Sin mirar el
        // NOMBRE, cualquier "Duplicate entry" pasaria por una inscripcion
        // repetida.
        $e = $this->excepcion(
            "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry '1-2-3' for key 'unica_matricula_por_periodo'"
        );

        $this->assertFalse(ErrorDeBaseDeDatos::esInscripcionRepetida($e));
        $this->assertTrue(ErrorDeBaseDeDatos::esMatriculaRepetida($e));
    }

    public function test_un_fallo_que_no_es_de_indice_no_es_una_repeticion(): void
    {
        // Este es el caso que importa: la base caida, la tabla que falta, el
        // CHECK violado. Nada de eso significa "ya estaba hecho", y contarlo
        // como tal deja a alguien fuera creyendo que entro.
        foreach ([
            'SQLSTATE[HY000]: General error: 2006 MySQL server has gone away',
            "SQLSTATE[42S02]: Base table or view not found: 1146 Table 'matriculas.inscritos_actividad' doesn't exist",
            'SQLSTATE[23000]: Integrity constraint violation: 4025 CONSTRAINT `nombre_de_inscrito_no_vacio` failed',
            'SQLSTATE[HY000]: General error: 1205 Lock wait timeout exceeded',
        ] as $mensaje) {
            $this->assertFalse(
                ErrorDeBaseDeDatos::esInscripcionRepetida($this->excepcion($mensaje)),
                "Se conto como inscripcion repetida: {$mensaje}"
            );
        }
    }

    public function test_la_fila_en_uso_se_distingue_por_codigo_y_no_por_mensaje(): void
    {
        // 1451 es "todavia esta en uso"; 1452 es "apunta a algo que no existe".
        // Solo el primero significa que el borrado hay que negarlo.
        $this->assertTrue(ErrorDeBaseDeDatos::esFilaEnUso($this->excepcion('Cannot delete or update a parent row', 1451)));
        $this->assertFalse(ErrorDeBaseDeDatos::esFilaEnUso($this->excepcion('Cannot add or update a child row', 1452)));
    }
}
