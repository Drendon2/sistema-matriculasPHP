<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Perfil;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Autorregistro publico, pensado para profesores nuevos.
 *
 * La cuenta queda SIN rol: un director o administrador se lo asigna despues
 * desde Gestion → Usuarios. Hasta entonces la persona puede entrar, pero solo
 * ve la pantalla de "cuenta pendiente".
 *
 * NO pide foto de perfil, y no es un olvido: por seguridad, los archivos no se
 * suben desde un formulario publico sin autenticar. La persona la sube despues,
 * ya con sesion, en "Mi perfil".
 */
class RegistroController extends Controller
{
    public function mostrar(): View
    {
        return view('auth.registro');
    }

    public function guardar(Request $request): RedirectResponse
    {
        $datos = $request->validate([
            'username' => ['required', 'string', 'max:150', Rule::unique('users', 'username')],
            'password' => ['required', 'string', 'confirmed'],
            'nombre_completo' => ['required', 'string', 'max:90'],
            'fecha_nacimiento' => ['required', 'date', 'before:today'],
            'telefono' => ['required', 'string', 'max:15'],
        ], [
            'username.unique' => 'Ya existe una cuenta con ese nombre de usuario.',
            'password.confirmed' => 'Las contraseñas no coinciden.',
        ], [
            'username' => 'usuario',
            'password' => 'contraseña',
            'nombre_completo' => 'nombre completo',
            'fecha_nacimiento' => 'fecha de nacimiento',
            'telefono' => 'teléfono',
        ]);

        // La cuenta y el perfil nacen juntos o no nacen: una cuenta sin perfil
        // no puede ni pasar por la redireccion posterior al login.
        DB::transaction(function () use ($datos) {
            $user = User::create([
                'username' => $datos['username'],
                'password' => $datos['password'],
                'activo' => true,
            ]);

            Perfil::create([
                'user_id' => $user->id,
                'rol' => '',
                'nombre_completo' => $datos['nombre_completo'],
                'fecha_nacimiento' => $datos['fecha_nacimiento'],
                'telefono' => $datos['telefono'],
            ]);
        });

        return redirect()->route('login')->with(
            'success',
            'Tu cuenta quedo creada. Un director o administrador debe asignarte un rol antes '
            . 'de que puedas entrar al sistema. Cuando entres, ve a "Mi perfil" para subir tu foto.'
        );
    }
}
