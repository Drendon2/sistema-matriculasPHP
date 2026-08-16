<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * A donde va cada quien justo despues de iniciar sesion, y la pantalla de la
 * cuenta que todavia no tiene rol.
 */
class PostLoginController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $perfil = $request->user()->perfil;

        if ($perfil === null) {
            return redirect()->route('login')->with(
                'error',
                'Tu cuenta no tiene un perfil asociado. Contacta al administrador.'
            );
        }

        // Cuenta recien autorregistrada: existe, pero no tiene acceso a nada
        // hasta que un director o administrador le asigne rol.
        if ($perfil->rol === '') {
            return redirect()->route('pendiente-aprobacion');
        }

        if ($perfil->rol === 'estudiante') {
            return redirect()->route('promotorias-disponibles');
        }

        return redirect()->route('panel');
    }

    public function pendienteAprobacion(Request $request): View|RedirectResponse
    {
        $perfil = $request->user()->perfil;

        // Si ya le asignaron rol mientras esperaba, esta pantalla deja de tener
        // sentido y se le manda a donde le corresponde.
        if ($perfil !== null && $perfil->rol !== '') {
            return redirect()->route('post-login');
        }

        return view('auth.pendiente-aprobacion');
    }
}
