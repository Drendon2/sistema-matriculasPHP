<?php

namespace App\Http\Controllers;

use App\Support\Tema;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Guarda si esta persona quiere claro, oscuro, o lo que diga su sistema.
 *
 * Un POST de formulario y no una llamada de JavaScript, y eso es deliberado:
 * este proyecto sostiene que sus pantallas funcionan sin JavaScript, y un
 * selector de tema es de las pocas cosas que SI tiene sentido sin el. Se envia,
 * el servidor pone la galleta y devuelve a la misma pagina, que vuelve ya
 * pintada del otro color. Con JavaScript se sentiria instantaneo; sin el,
 * cuesta una recarga y funciona igual.
 *
 * NO va detras de `auth`. La preferencia se guarda en el aparato —ver el porque
 * en `Support\Tema`— asi que sobrevive al cierre de sesion, y quien eligio
 * oscuro no debe encontrarse la pantalla de entrar en blanco.
 */
class TemaController extends Controller
{
    public function __invoke(Request $peticion): RedirectResponse
    {
        $datos = $peticion->validate([
            'tema' => ['required', Rule::in(Tema::OPCIONES)],
        ]);

        // `back()` y no una ruta fija: este selector vive en Mi perfil hoy, pero
        // la galleta es del aparato y el dia que se ponga tambien en la pantalla
        // de entrar tiene que devolver alli. Con `fallback` porque una peticion
        // sin `Referer` —un navegador que no lo manda— dejaria el `back()` sin
        // destino.
        return back(fallback: route('mi-perfil'))
            ->withCookie(Tema::galleta($datos['tema']));
    }
}
