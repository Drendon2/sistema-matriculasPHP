<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

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
            'rol' => \App\Http\Middleware\RequiereRol::class,
        ]);

        // Sin sesion, todo lleva al login (Laravel apunta por defecto a una
        // ruta 'login' que aqui si existe, pero se deja explicito).
        $middleware->redirectGuestsTo(fn () => route('login'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
