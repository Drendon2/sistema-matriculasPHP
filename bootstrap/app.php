<?php

use App\Http\Middleware\CuentaActiva;
use App\Http\Middleware\RequiereRol;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\AuthenticateSession;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // El equivalente del decorador `@requiere_rol(...)` del original.
        // Se usa como 'rol:estudiante' o 'rol:administrador,director'.
        $middleware->alias([
            'rol' => RequiereRol::class,
        ]);

        // Cambiar una contrasena tiene que cerrar las demas sesiones de esa
        // cuenta. Sin esto, un administrador puede resetear la clave de alguien
        // desde Gestion -> Usuarios y esa persona sigue dentro donde ya
        // estuviera: es el unico camino por el que se cambia una contrasena en
        // este sistema, asi que era justo el caso que importaba.
        //
        // Guarda el hash en la sesion y compara en cada peticion. Dos cosas que
        // suelen frenar este cambio y que aqui NO aplican:
        //
        // - No cierra las sesiones ya abiertas al desplegarlo: si el hash falta
        //   en la sesion lo GUARDA, y solo echa a quien lo tenga y no cuadre.
        // - No toca «recuerdame»: el formulario de entrada no ofrece la
        //   casilla, asi que `viaRemember()` nunca es cierto.
        //
        // Va antes que CuentaActiva por orden de coste: si la sesion ya no vale,
        // no hace falta consultar si la cuenta sigue activa.
        //
        // Desactivar una cuenta tiene que echar tambien a quien ya esta dentro,
        // que es lo que hace el original. Va en el grupo entero y no pegado a
        // 'rol' porque /post-login y /mi-perfil no llevan rol.
        $middleware->web(append: [
            AuthenticateSession::class,
            CuentaActiva::class,
        ]);

        // Sin sesion, todo lleva al login (Laravel apunta por defecto a una
        // ruta 'login' que aqui si existe, pero se deja explicito).
        $middleware->redirectGuestsTo(fn () => route('login'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        /**
         * Quedarse sin intentos devuelve al formulario, no a la pagina 429.
         *
         * La pagina que trae Laravel dice "Too Many Attempts." en ingles y sin
         * ninguna salida: quien la ve no sabe si se rompio el sistema, si el
         * usuario estaba mal o cuanto tiene que esperar. Aqui se vuelve al mismo
         * formulario con el aviso puesto, que es lo que hace el resto de la
         * aplicacion con cualquier otro error.
         *
         * El mensaje va como `error` de sesion y no pegado al campo `username`:
         * quedarse sin intentos no es un problema del valor que se escribio —la
         * regla de `partials/mensajes` es justamente esa—. Y no dice si el
         * usuario existe, por la misma razon que no lo dice el mensaje de clave
         * incorrecta.
         *
         * Solo aplica a peticiones de formulario. Una peticion que pida JSON
         * sigue recibiendo su 429 con la cabecera `Retry-After`, que es lo
         * correcto para algo que no es un navegador.
         */
        $exceptions->render(function (ThrottleRequestsException $e, Request $request) {
            if ($request->expectsJson()) {
                return null;
            }

            $segundos = (int) ($e->getHeaders()['Retry-After'] ?? 60);
            $minutos = (int) ceil($segundos / 60);

            $espera = $segundos <= 60
                ? "{$segundos} segundos"
                : $minutos.($minutos === 1 ? ' minuto' : ' minutos');

            return back()
                ->withInput($request->except(['password', 'password_confirmation']))
                ->with(
                    'error',
                    "Demasiados intentos seguidos. Espera {$espera} y vuelve a intentarlo."
                );
        });
    })->create();
