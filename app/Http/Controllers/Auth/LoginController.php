<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use RuntimeException;

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
        if (! $this->credencialesValidas($credenciales, $request->boolean('remember'))) {
            throw ValidationException::withMessages([
                'username' => 'Usuario o contraseña incorrectos.',
            ]);
        }

        $request->session()->regenerate();

        return redirect()->intended(route('post-login'));
    }

    /**
     * `Auth::attempt`, pero un hash roto NO tumba la pantalla de entrar.
     *
     * El comprobador de contrasenas LANZA cuando lo que hay guardado no es un
     * hash suyo —«This password does not use the Bcrypt algorithm»—, y esa
     * excepcion sale sin capturar hasta el navegador. O sea que una fila con la
     * contrasena en texto plano, o vacia, o importada de otro sistema, convierte
     * la pantalla mas publica del sistema en un error 500 para esa persona: ni
     * siquiera llega a leer «usuario o contraseña incorrectos».
     *
     * Se vio el 07/09/2026 en la base de desarrollo, donde hay nueve filas del
     * 24/08 con un solo caracter por contrasena. En PRODUCCION no hay ninguna
     * —885 de 885 con bcrypt de coste 12, comprobado ese mismo dia— asi que esto
     * no arregla nada que este roto hoy: cierra la puerta al dia que alguien
     * importe usuarios de otro sistema o toque la tabla a mano.
     *
     * DOS COSAS, y la segunda es la que importa. Se responde login fallido, que
     * es lo correcto para quien intenta entrar: un hash ilegible no autentica a
     * nadie. Y se DEJA EN EL REGISTRO, porque un hash roto y una contrasena
     * equivocada no son lo mismo: el primero no lo arregla la persona
     * reintentando, y sin esta linea se quedaria escondido detras de un mensaje
     * que le echa la culpa a ella.
     *
     * @param  array<string, string>  $credenciales
     */
    private function credencialesValidas(array $credenciales, bool $recordar): bool
    {
        try {
            return Auth::attempt([...$credenciales, 'activo' => true], $recordar);
        } catch (RuntimeException $e) {
            Log::warning('Una cuenta tiene la contraseña guardada en un formato que no se puede comprobar', [
                'username' => $credenciales['username'] ?? '',
                'motivo' => $e->getMessage(),
            ]);

            return false;
        }
    }

    public function salir(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
