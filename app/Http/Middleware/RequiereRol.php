<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Exige sesion iniciada y que el Perfil tenga uno de los roles dados.
 *
 * Es el equivalente del decorador `@requiere_rol(...)` del original. Se usa en
 * las rutas asi:
 *
 *     Route::get(...)->middleware('rol:estudiante');
 *     Route::get(...)->middleware('rol:administrador,director');
 *
 * Deja el Perfil resuelto en el request (`$request->perfil`) para que el
 * controlador no tenga que volver a buscarlo.
 *
 * No decide nada por si mismo sobre QUE ve cada rol dentro de la pantalla: eso
 * es la matriz de visibilidad por campo, y va en cada vista. Esto solo cierra
 * la puerta.
 */
class RequiereRol
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $usuario = $request->user();

        if ($usuario === null) {
            return redirect()->route('login');
        }

        $perfil = $usuario->perfil;

        if ($perfil === null) {
            return redirect()->route('login')->with(
                'error',
                'Tu cuenta no tiene un perfil asociado. Contacta al administrador.'
            );
        }

        if (! in_array($perfil->rol, $roles, true)) {
            return redirect()->route('post-login')->with(
                'error',
                'No tienes acceso a esta seccion.'
            );
        }

        $request->attributes->set('perfil', $perfil);

        return $next($request);
    }
}
