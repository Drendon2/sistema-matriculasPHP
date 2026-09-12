<?php

namespace App\Support;

use App\Models\Clase;
use App\Models\Perfil;
use App\Models\Periodo;

/**
 * Cuantas clases estan esperando que ESTE estudiante las confirme.
 *
 * ─── POR QUE EXISTE ────────────────────────────────────────────────────────
 *
 * Confirmar una clase es la accion mas importante que tiene un estudiante en
 * este sistema y **caduca a las 48 horas**. Hasta el 12/09/2026 el unico aviso
 * vivia en «Promotorias disponibles», que es donde aterriza al entrar — y esa
 * pantalla LA PUEDE APAGAR la institucion (`promotorias_visibles_para_estudiantes`,
 * para entidades que matriculan en ventanilla). Con el interruptor apagado,
 * `CatalogoController` lo manda a «Mis matriculas», que no dice nada de clases:
 * el estudiante tenia 48 horas para hacer algo de lo que nadie le avisaba, y
 * este sistema no manda notificaciones por ningun otro canal.
 *
 * Desde el 12/09 la cifra viaja al envoltorio, asi que el aviso va en el MENU
 * —que no se puede apagar— y no depende de en que pantalla caiga.
 *
 * ─── POR QUE MEMORIZA ──────────────────────────────────────────────────────
 *
 * `Clase::porConfirmar()` no es barata: son cinco consultas con cargas ansiosas
 * y arma la lista entera. Llamarla desde el compositor del envoltorio la pondria
 * en TODAS las pantallas del estudiante, y ademas DOS veces en «Mis clases»,
 * que ya la pedia por su cuenta.
 *
 * Aqui se calcula una sola vez por peticion y se reparte. El resultado NO se
 * guarda entre peticiones a proposito: el plazo corre con el reloj y una cifra
 * cacheada diria que quedan clases por confirmar despues de que venciera el
 * plazo, o al reves.
 *
 * ─── Y POR QUE NO UNA CUENTA EN SQL ────────────────────────────────────────
 *
 * Seria mas barata y es la trampa. La regla de que una clase «espera
 * confirmacion» no es una condicion de columnas: mezcla el plazo, si ya la
 * confirmo, y si consta que falto — y ademas la lista de clases suyas manda la
 * ASISTENCIA sobre el grupo y la fecha, que es lo que arreglo el fallo del
 * 09/09. Escrita una segunda vez en SQL, las dos se separan sin que nada falle.
 * Este proyecto ya pago eso con el cupo de grupo, donde la version correcta
 * vivia en un metodo que no llamaba nadie.
 *
 * La cuenta sigue siendo la de `Clase::esperanConfirmacion()`, que es la unica.
 */
class ClasesPendientes
{
    /** @var array<int, array<int, array<string, mixed>>> */
    private static array $memoria = [];

    /**
     * Las filas de sus clases, tal como las pinta «Mis clases».
     *
     * @return array<int, array<string, mixed>>
     */
    public static function filas(Perfil $perfil, ?Periodo $periodo): array
    {
        // La clave lleva el periodo: «Mis clases» mira el que esta en curso,
        // pero nada impide que un dia se pida otro, y devolver el del anterior
        // seria un fallo mudo.
        $clave = $perfil->id * 100000 + ($periodo->id ?? 0);

        return self::$memoria[$clave] ??= Clase::porConfirmar($perfil, $periodo);
    }

    /** Cuantas esperan que las confirme, y todavia a tiempo. */
    public static function cuantas(Perfil $perfil, ?Periodo $periodo): int
    {
        return Clase::esperanConfirmacion(self::filas($perfil, $periodo));
    }

    /**
     * Solo para las pruebas: entre una y otra, la memoria de un perfil con el
     * mismo id diria lo de antes.
     */
    public static function olvidar(): void
    {
        self::$memoria = [];
    }
}
