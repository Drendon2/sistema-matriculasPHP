<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Inicio y cierre de sesion.
 *
 * Se autentica por `username`, no por correo: el sistema original nunca pide
 * uno (ver la migracion de la tabla `users`).
 */
class LoginController extends Controller
{
    public function mostrar(): View
    {
        return view('auth.login');
    }

    public function entrar(Request $request): RedirectResponse
    {
        $credenciales = $request->validate([
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
        ], [], [
            'username' => 'usuario',
            'password' => 'contraseña',
        ]);

        // `activo` entra en las credenciales, no se comprueba despues: asi una
        // cuenta desactivada ni siquiera llega a abrir sesion, y el mensaje es
        // el mismo que el de una clave mala — decir "esa cuenta existe pero
        // esta desactivada" le confirmaria a un extrano que el usuario existe.
        if (! Auth::attempt([...$credenciales, 'activo' => true], $request->boolean('remember'))) {
            throw ValidationException::withMessages([
                'username' => 'Usuario o contraseña incorrectos.',
            ]);
        }

        $request->session()->regenerate();

        return redirect()->intended(route('post-login'));
    }

    public function salir(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
