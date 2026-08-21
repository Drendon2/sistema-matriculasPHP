<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Expulsa en el acto a quien tenga la cuenta desactivada.
 *
 * `activo` entra en las credenciales del login (`LoginController::entrar`), asi
 * que una cuenta desactivada no puede ENTRAR. Pero eso solo cierra la puerta:
 * sin esto, quien ya estaba dentro conservaba acceso completo hasta que la
 * sesion caducara —dos horas con SESSION_LIFETIME=120, y renovandose con cada
 * clic, asi que en la practica indefinidamente mientras siguiera navegando—.
 *
 * El original expulsa de inmediato, y no con una comprobacion escrita a mano:
 * desactivar pone `is_active = False` en el User de Django
 * (`views_gestion.py:1393`), y el `ModelBackend.get_user()` de Django mira
 * `is_active` en CADA peticion, asi que la sesion se vuelve anonima sola y
 * `@login_required` la manda al login. Laravel no trae equivalente: su
 * `SessionGuard` solo busca al usuario por id. Esto repone esa regla.
 *
 * Va en el grupo `web` y no dentro de `RequiereRol` a proposito: /post-login y
 * /mi-perfil estan solo tras `auth`, sin rol —es donde espera quien todavia no
 * tiene uno asignado—, y por ahi se colaria.
 *
 * No deja mensaje, igual que el original: `@login_required` redirige al login y
 * nada mas. Es ademas coherente con el mensaje deliberadamente vago del propio
 * login, que no distingue una clave mala de una cuenta desactivada para no
 * confirmarle a un extrano que el usuario existe.
 */
class CuentaActiva
{
    public function handle(Request $request, Closure $next): Response
    {
        $usuario = $request->user();

        if ($usuario !== null && ! $usuario->activo) {
            // Se cierra igual que en `LoginController::salir`. `Auth::logout()`
            // por si solo no basta: sin invalidar la sesion queda el resto de
            // su contenido, y sin renovar el token el formulario siguiente
            // fallaria por CSRF en vez de por falta de sesion. El logout tambien
            // borra la cookie de "recuerdame", que si no volveria a entrarla.
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login');
        }

        return $next($request);
    }
}
