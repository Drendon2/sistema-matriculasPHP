<?php

namespace App\Support;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Responder con el CONTENIDO de <main> en vez de con la pagina entera.
 *
 * El problema que resuelve son los VIAJES, no los bytes. `acciones.js`
 * intercepta el envio y pide la respuesta por `fetch`; como el controlador
 * guarda y REDIRIGE, esa peticion son dos: el POST y el GET al que lleva la
 * redireccion. El segundo vuelve a montar la pagina de cero. En el servidor eso
 * cuesta 35 ms y no se nota; desde un celular cuesta un viaje de ida y vuelta
 * entero, que es lo unico que de verdad se siente.
 *
 * La forma es la de siempre en este proyecto y NO convierte nada en una API: se
 * renderiza LA MISMA vista con los MISMOS datos, cambiando solo el envoltorio
 * por uno que no trae <html>, cabecera ni navegacion. El servidor sigue siendo
 * la unica fuente de verdad y sigue re-renderizando de cero, asi que no hay una
 * segunda manera de pintar la misma pantalla que pueda separarse de la primera.
 *
 * SIN JAVASCRIPT NO PASA NADA: la cabecera la manda `acciones.js` y nadie mas,
 * asi que un formulario enviado por el navegador entra por la rama de siempre y
 * recibe su redireccion. Esa rama es la de por defecto y no un remiendo.
 *
 * Y quien responda con un fragmento solo tiene que hacerlo en el camino BUENO:
 * un rechazo o una falta de permiso puede seguir redirigiendo como siempre. La
 * respuesta que llega entonces no lleva la cabecera de vuelta, `acciones.js` se
 * encuentra un <main> normal y hace lo que hacia. Degrada solo.
 */
class Fragmento
{
    /**
     * Va en las DOS direcciones: la peticion la manda para pedirlo y la
     * respuesta la devuelve para decir que eso es lo que trae.
     *
     * La de vuelta importa tanto como la de ida. Sin ella `acciones.js` tendria
     * que adivinar si lo que recibio es medio documento, y la forma obvia de
     * adivinar —"no trae <main>, luego es un fragmento"— es justo la que falla
     * en el caso peor: si la sesion caduca, lo que llega es el login, y pegarlo
     * dentro de <main> dejaria la pantalla con dos cabeceras y un formulario de
     * contrasena incrustado en medio del panel.
     */
    public const CABECERA = 'X-Fragmento';

    /**
     * El envoltorio sin pagina: los mensajes y el contenido, que es exactamente
     * lo que hay dentro de <main>.
     */
    public const DISPOSICION = 'layouts.fragmento';

    public static function loPide(Request $peticion): bool
    {
        return $peticion->header(self::CABECERA) === '1';
    }

    /**
     * La vista de siempre con sus datos de siempre, sin la pagina alrededor.
     *
     * Las vistas que pueden responder asi declaran su envoltorio como
     * `@extends($disposicion ?? 'layouts.app')`: por defecto son la pagina
     * completa y solo cambian cuando alguien se lo pide.
     *
     * @param  array<string, mixed>  $datos
     */
    public static function responder(string $vista, array $datos): Response
    {
        /** @var View $renderizada */
        $renderizada = view($vista, $datos + ['disposicion' => self::DISPOSICION]);

        return response($renderizada->render())->header(self::CABECERA, '1');
    }
}
