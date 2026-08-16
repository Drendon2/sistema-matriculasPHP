<?php

namespace App\Support;

use App\Models\Perfil;
use App\Models\Promotoria;

/**
 * Quien puede hacer que sobre una promotoria.
 *
 * El middleware `rol:` solo cierra la puerta de la pantalla. Esto es la capa de
 * dentro: dos personas con el mismo rol entran al mismo Panel pero no pueden
 * tocar las mismas promotorias.
 *
 * Puerto de los ayudantes sueltos de `matriculas/views.py`. Aqui son una clase
 * porque tambien los necesitan las plantillas, y repetir la regla de roles en
 * Blade es como acaban separandose el enlace y la vista que protege: se pintan
 * botones que al pulsarlos rebotan.
 */
class Permisos
{
    /**
     * ¿Puede administrar el catalogo de esta promotoria?
     *
     * Crear grupos, fijar el cupo, confirmar o rechazar matriculas. Direccion
     * sobre cualquiera; el profesor solo sobre las que dicta.
     *
     * Lo que se mira en el caso del profesor es el VINCULO
     * (`Promotoria::profesor`), no solo el rol. Un director que ademas dicta su
     * propia promotoria es un caso real, y por eso el rol de direccion abre la
     * puerta por su cuenta.
     */
    public static function puedeGestionarPromotoria(Perfil $perfil, Promotoria $promotoria): bool
    {
        return in_array($perfil->rol, ['director', 'administrador'], true)
            || ($perfil->rol === 'profesor' && $promotoria->profesor_id === $perfil->id);
    }

    /**
     * ¿Es esta persona quien DICTA la promotoria?
     *
     * Regla mas estrecha que la anterior, y la diferencia esta donde tiene que
     * estar: gestionar el catalogo —crear grupos, fijar cupos, confirmar
     * matriculas— es tarea de direccion, pero registrar una clase y pasar lista
     * son actos de quien estuvo en el salon.
     *
     * Direccion sigue VIENDO toda la asistencia; lo que no hace es escribirla en
     * promotorias ajenas. Un registro que puede reescribir alguien que no dio la
     * clase deja de ser evidencia de lo que paso, y es justamente la evidencia
     * lo que la confirmacion de los estudiantes esta sosteniendo.
     *
     * Lo que se mira es el VINCULO, no el rol. Un director que ademas dicta su
     * propia promotoria es un caso real, y exigiendole el rol "profesor" quedaba
     * en el peor sitio posible: veia su propio grupo en solo lectura, sin poder
     * registrar su asistencia y sin que nadie pudiera hacerlo por el.
     *
     * Contrapartida asumida: quien edita el catalogo puede asignarse a si mismo
     * una promotoria y con eso escribir su asistencia. No es un agujero
     * silencioso —el panel ensena quien es el profesor de cada promotoria— y la
     * clase la siguen verificando los estudiantes, que es donde vive la garantia
     * de verdad.
     *
     * Consecuencia asumida: una promotoria SIN nadie asignado no puede registrar
     * clases hasta que se le asigne alguien. Es correcto —sin profesor no hay
     * quien de la clase— y el mensaje de error lo dice.
     */
    public static function dictaLaPromotoria(Perfil $perfil, Promotoria $promotoria): bool
    {
        return $promotoria->profesor_id !== null && $promotoria->profesor_id === $perfil->id;
    }

    /**
     * ¿Puede abrir la ficha de otra persona? Se mira hacia abajo, no hacia los
     * lados.
     *
     * Administrador y director abren la de cualquiera. El profesor solo la de
     * estudiantes — ni la de otro profesor, ni la de un director. Un estudiante
     * no abre ninguna: sus pantallas ensenan los nombres como texto.
     */
    public static function puedeVerFicha(?Perfil $solicitante, ?Perfil $objetivo): bool
    {
        if ($solicitante === null || $objetivo === null) {
            return false;
        }

        if (in_array($solicitante->rol, ['administrador', 'director'], true)) {
            return true;
        }

        if ($solicitante->rol === 'profesor') {
            return $objetivo->rol === 'estudiante';
        }

        return false;
    }
}
