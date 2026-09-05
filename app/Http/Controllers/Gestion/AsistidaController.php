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
                'No se puede asistir a esa cuenta. No se asiste a un administrador, '
                .'ni estando ya en una gestión asistida.'
            );
        }

        // EL DESTINO ES EL DE QUIEN SE ASISTE, y va resuelto aqui aunque
        // `post-login` ya sepa hacerlo. Mandar a todo el mundo al Panel era lo
        // que habia y fallaba con los estudiantes, que ahi no entran: rebotaban
        // en `RequiereRol` hacia `post-login` y de ahi al catalogo. Se aterrizaba
        // en el sitio bueno por accidente y SIN NINGUN AVISO, porque un `with()`
        // dura UNA peticion y el rebote se gasta dos antes de pintar nada.
        //
        // Por eso no vale con redirigir a `post-login`: es una peticion mas, y
        // se come el mensaje igual. Hay que apuntar al destino final.
        //
        // Un administrador no aparece aqui: `puedeAsistirA()` no deja asistir a
        // uno, asi que solo llegan estudiante, profesor y director.
        $destino = $usuario->rol === 'estudiante' ? 'promotorias-disponibles' : 'panel';

        // Es la pantalla de quien se esta asistiendo, y lo que se viene a hacer
        // es ver lo que esa persona ve.
        return redirect()->route($destino)->with(
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
