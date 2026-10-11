<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lo que el agente de voz dice de una persona solo existe en la demo.
 *
 * Decision del usuario del 10/10/2026: la voz y la transcripcion pasan por
 * proveedores fuera del pais, y la politica de datos no lo cubre. Apagado, 404:
 * la ruta no existe.
 */
class DatosPersonalesDelAgente
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless((bool) config('agente.datos_personales'), 404);

        return $next($request);
    }
}
