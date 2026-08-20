<?php

namespace App\Support;

use App\Models\Matricula;
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
     * ¿Puede descargar el certificado de ESTA matricula?
     *
     * El propio estudiante siempre; direccion sobre cualquiera; quien dicta la
     * promotoria sobre las suyas. El profesor entra por el VINCULO y no por el
     * rol, igual que en el resto del proyecto.
     *
     * Que el personal pueda bajarlo no es comodidad: media matricula de una casa
     * de la cultura son menores y adultos mayores que piden la constancia en
     * ventanilla y no van a entrar al sistema a buscarla.
     */
    public static function puedeCertificarMatricula(?Perfil $solicitante, Matricula $matricula): bool
    {
        if ($solicitante === null) {
            return false;
        }

        if ($solicitante->id === $matricula->estudiante_id) {
            return true;
        }

        if (in_array($solicitante->rol, ['administrador', 'director'], true)) {
            return true;
        }

        return $solicitante->rol === 'profesor'
            && $matricula->promotoria !== null
            && self::dictaLaPromotoria($solicitante, $matricula->promotoria);
    }

    /**
     * ¿Puede descargar el certificado que reune TODAS las matriculas vigentes de
     * un estudiante?
     *
     * Regla mas estrecha que la anterior a proposito, y la diferencia es el
     * profesor: el certificado reunido lista todas las promotorias que esa
     * persona cursa, y la ficha le esconde deliberadamente las que no dicta
     * —lo mismo hace el panel de asistencia—. Dejarselo bajar entregaria en un
     * PDF justo lo que la matriz de visibilidad le niega en pantalla.
     *
     * Le queda el certificado de la matricula suya, que es el que le pueden
     * pedir a el.
     */
    public static function puedeCertificarTodo(?Perfil $solicitante, Perfil $estudiante): bool
    {
        if ($solicitante === null) {
            return false;
        }

        return $solicitante->id === $estudiante->id
            || in_array($solicitante->rol, ['administrador', 'director'], true);
    }

    /**
     * Que roles puede REPARTIR esta persona.
     *
     * El administrador reparte cualquiera, incluido el suyo. El director reparte
     * todo MENOS administrador, y ahi esta el fondo del asunto: las rutas de
     * usuarios estan abiertas a los dos, pero el enrutado reserva al
     * administrador tres pantallas —la configuracion de la institucion, las
     * estadisticas con la encuesta demografica y la descarga de copias de
     * documentos de identidad—. Si un director pudiera repartir el rol de
     * administrador, se lo daria a si mismo y esas tres puertas dejarian de
     * significar nada: la restriccion se saltaria sola, sin forzar nada.
     *
     * @return list<string>
     */
    public static function rolesAsignablesPor(Perfil $solicitante): array
    {
        $todos = array_keys(Perfil::ROLES);

        if ($solicitante->rol === 'administrador') {
            return $todos;
        }

        return array_values(array_filter($todos, fn (string $rol) => $rol !== 'administrador'));
    }

    /**
     * ¿Puede tocar la cuenta de esta persona?
     *
     * La otra mitad de lo anterior, y hace falta las dos. Limitar solo los roles
     * que se reparten deja abierto el camino corto: un director no se asciende,
     * pero le cambia la contrasena al administrador y entra como el. Editar la
     * cuenta de alguien es poder suplantarlo, asi que la cuenta de un
     * administrador solo la toca otro administrador.
     *
     * Cubre las tres formas de tocarla —editar, cambiar el rol y activar o
     * desactivar—, porque las tres pasan por el mismo formulario o por el mismo
     * listado y dejar una fuera es dejarla entera fuera.
     *
     * Nadie queda encerrado: un administrador siempre puede editar a otro, y a
     * si mismo.
     */
    public static function puedeEditarUsuario(Perfil $solicitante, Perfil $objetivo): bool
    {
        if ($solicitante->rol === 'administrador') {
            return true;
        }

        return $objetivo->rol !== 'administrador';
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
