<?php

namespace App\Http\Controllers;

use App\Support\Tema;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Guarda si este aparato quiere claro u oscuro.
 *
 * Un POST de formulario y no una llamada de JavaScript, y eso es deliberado:
 * este proyecto sostiene que sus pantallas funcionan sin JavaScript, y el boton
 * del menu es un `<form>` de verdad. Sin guion se envia, el servidor pone la
 * galleta y devuelve a la misma pagina, que vuelve ya pintada del otro color.
 *
 * CON guion la ruta se sigue usando IGUAL, y eso no es redundante: `tema.js`
 * cambia el atributo del `<html>` para que el color se vea en el acto y manda
 * este POST por detras, porque la galleta va CIFRADA y JavaScript no puede
 * escribirla. El detalle esta en `Support\Tema::galleta()`.
 *
 * NO va detras de `auth`. La preferencia se guarda en el aparato —ver el porque
 * en `Support\Tema`— asi que sobrevive al cierre de sesion, y quien eligio
 * oscuro no debe encontrarse la pantalla de entrar en blanco. Hoy el boton solo
 * vive en el shell con sesion (decision del 12/09/2026), pero la ruta no lo
 * exige: el dia que se ponga tambien en `entrar` funciona sin tocarla.
 */
class TemaController extends Controller
{
    public function __invoke(Request $peticion): RedirectResponse
    {
        $datos = $peticion->validate([
            'tema' => ['required', Rule::in(Tema::OPCIONES)],
        ]);

        // `back()` y no una ruta fija: el boton vive en el menu, o sea en TODAS
        // las pantallas con sesion, asi que hay que volver a la que estaba
        // abierta. Con `fallback` porque una peticion sin `Referer` —un
        // navegador que no lo manda— dejaria el `back()` sin destino, y sigue
        // siendo `mi-perfil` aunque el selector ya no viva ahi: para una
        // peticion sin sesion cualquier destino rebota al login igual, asi que
        // cambiarlo no gana nada.
        return back(fallback: route('mi-perfil'))
            ->withCookie(Tema::galleta($datos['tema']));
    }
}
