<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * La llave de la API del agente de voz.
 *
 * Sin huella configurada la API no existe (404, no 401): en una instalacion que
 * no la usa no hay nada que anunciar. Con huella, el token viaja como
 * `Authorization: Bearer ...` y se compara su SHA-256 con `hash_equals`, que no
 * deja medir por tiempos cuanto del token se acerto.
 *
 * Es un solo cliente —el servidor del agente— y por eso no hace falta Sanctum
 * ni una tabla de tokens.
 */
class TokenDelAgente
{
    public function handle(Request $request, Closure $next): Response
    {
        $huella = (string) config('agente.token_sha256');

        abort_if($huella === '', 404);

        $token = (string) $request->bearerToken();

        if ($token === '' || ! hash_equals(strtolower($huella), hash('sha256', $token))) {
            return response()->json(['error' => 'token_invalido'], 401);
        }

        return $next($request);
    }
}
