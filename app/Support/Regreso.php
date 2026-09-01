<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * A donde se vuelve despues de borrar algo desde un listado.
 *
 * Existe por un fallo concreto: eliminar a alguien devolvia al listado PELADO.
 * Quien estaba filtrando por «Pendiente de rol» en la pagina 3 aparecia en la
 * lista entera y por el principio, asi que borrar a cinco personas seguidas
 * obligaba a volver a filtrar cinco veces. `UsuarioController::alternarActivo`
 * ya lo tenia resuelto con `back()` y lo explica en su comentario; el borrado
 * llego despues y no lo siguio.
 *
 * `back()` no sirve AQUI, y por eso hay una clase en vez de una llamada: entre
 * el listado y el borrado hay una pagina de por medio —la de confirmacion—, asi
 * que la anterior no es la lista sino la pregunta. Volver ahi es volver a una
 * pagina que habla de algo que ya no existe.
 *
 * Lo que viaja es SOLO la cadena de consulta, nunca una URL. Un campo oculto con
 * una direccion entera dentro seria una redireccion abierta de manual: bastaria
 * componer el formulario a mano para que el sistema mandara a quien lo enviara a
 * donde quisiera el atacante, y encima desde una pantalla en la que la persona
 * acaba de escribir su contrasena.
 *
 * Lo que impide eso es que el destino base lo ponga SIEMPRE el servidor, y no
 * `limpiar()`. Conviene tenerlo claro porque es facil creer lo contrario: la
 * primera version de este comentario se lo atribuia a la limpieza, y la prueba
 * que iba a demostrarlo seguia verde con la limpieza quitada — porque lo que
 * protege es la otra mitad. `limpiar()` hace algo mas modesto y comprobable:
 * garantiza que lo que se pega detras del `?` sea una cadena de consulta de
 * verdad y no cualquier texto. Si algun dia se cambia `url()` para aceptar el
 * destino de fuera, la garantia desaparece entera.
 */
class Regreso
{
    /**
     * Los filtros con los que se estaba mirando el listado, si es que se venia
     * de el.
     *
     * Se lee del referente al PINTAR la confirmacion, que es el momento en que
     * la pagina anterior todavia es la lista.
     */
    public static function consulta(Request $request, string $urlDelListado): string
    {
        $anterior = $request->headers->get('referer');

        if (! is_string($anterior) || $anterior === '') {
            return '';
        }

        // Solo si se venia de ESE listado. Llegar por un enlace de fuera, o
        // recargar la confirmacion a pelo, no arrastra filtros de ninguna parte.
        if (parse_url($anterior, PHP_URL_PATH) !== parse_url($urlDelListado, PHP_URL_PATH)) {
            return '';
        }

        return self::limpiar(parse_url($anterior, PHP_URL_QUERY) ?: '');
    }

    /** El listado otra vez, con los filtros que traiga el formulario. */
    public static function url(string $urlDelListado, mixed $consulta): string
    {
        $limpia = self::limpiar(is_string($consulta) ? $consulta : '');

        return $limpia === '' ? $urlDelListado : $urlDelListado.'?'.$limpia;
    }

    /**
     * Deja una cadena de consulta y nada mas.
     *
     * Deshacerla y volverla a montar no es dar un rodeo: es lo que garantiza que
     * lo que salga sea una cadena de consulta y no un «//otro-sitio.com» ni un
     * fragmento con JavaScript dentro. Lo que no encaje se cae por el camino.
     */
    private static function limpiar(string $consulta): string
    {
        parse_str($consulta, $partes);

        return http_build_query($partes);
    }
}
