<?php

namespace App\Http\Controllers\Gestion;

use App\Http\Controllers\Controller;
use App\Models\Perfil;
use App\Support\GestionAsistida;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Entrar y salir de la gestion asistida.
 *
 * Dos acciones y nada mas: lo que se puede hacer DENTRO no vive aqui, vive en
 * las pantallas de siempre. Esa es toda la idea — el administrador no usa una
 * copia del sistema para profesores, usa el sistema.
 *
 * Las rutas van separadas a proposito y con puertas distintas. Entrar es de
 * administrador; SALIR tiene que poder cualquiera con sesion, porque en cuanto
 * la asistencia empieza el administrador ya no es administrador para el
 * middleware: es el profesor al que esta asistiendo. Con la puerta de
 * administrador en el boton de salir, quien entra se queda dentro.
 */
class AsistidaController extends Controller
{
    public function iniciar(Request $request, Perfil $usuario): RedirectResponse
    {
        /** @var Perfil $perfil */
        $perfil = $request->attributes->get('perfil');

        if (! GestionAsistida::iniciar($perfil, $usuario)) {
            return redirect()->route('usuario-lista')->with(
                'error',
                'No se puede asistir a esa cuenta. Solo se asiste a profesores y directores, '
                .'y no estando ya en una gestión asistida.'
            );
        }

        // Al Panel y no a Gestion: es la pantalla de quien se esta asistiendo, y
        // lo que se viene a hacer es ver lo que esa persona ve.
        return redirect()->route('panel')->with(
            'success',
            "Estás gestionando como {$usuario->nombre_completo}. Todo lo que hagas queda "
            .'registrado a tu nombre.'
        );
    }

    public function terminar(): RedirectResponse
    {
        $admin = GestionAsistida::terminar();

        if ($admin === null) {
            return redirect()->route('post-login');
        }

        return redirect()->route('usuario-lista')->with(
            'success',
            'Volviste a tu cuenta.'
        );
    }
}
