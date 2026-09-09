<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Mail\EnlaceParaLaClave;
use App\Models\User;
use App\Support\Auditoria;
use App\Support\CorreoDeLaInstitucion;
use App\Support\RestablecerClave;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;
use Throwable;

/**
 * «Olvidé mi contraseña»: el enlace por correo.
 *
 * Hasta el 09/09/2026 no habia forma de recuperar una cuenta por uno mismo. La
 * unica salida era que un administrador entrara a Gestion → Usuarios y tecleara
 * una contrasena temporal, o sea que la supieran dos personas; y para pedirlo
 * habia que localizar a esa persona por WhatsApp, que es lo que de verdad
 * pasaba.
 *
 * ─── LA MISMA RESPUESTA SIEMPRE ────────────────────────────────────────────
 *
 * Se conteste lo que se conteste, la pantalla dice lo mismo. Hay CUATRO
 * desenlaces detras —no existe la cuenta, existe pero esta desactivada, existe
 * pero no tiene correo, y se mando— y distinguirlos convertiria este formulario
 * publico en una forma de averiguar quien tiene cuenta aqui. Es el mismo
 * razonamiento que ya sostiene el «usuario o contraseña incorrectos» de
 * `LoginController`, y por eso la frase se escribe UNA vez, en una constante:
 * dos copias acaban discrepando y la que discrepe delata.
 *
 * ESO INCLUYE EL FALLO DE ENVIO. Si el servidor de correo no contesta, la
 * excepcion se captura, se deja en el registro y la persona ve la misma frase.
 * Sin eso, un SMTP caido da un 500 en una pantalla publica, y ademas un 500
 * frente a una pagina normal ya es la diferencia que delata.
 *
 * ─── QUE ALCANCE TIENE ESTO DE VERDAD ──────────────────────────────────────
 *
 * En produccion el correo lo tienen 32 personas de 885, asi que a dia de hoy
 * esto no recupera a casi nadie: es una puerta que se abre y se va llenando a
 * medida que la gente escriba su correo en «Mi perfil». Por eso la pantalla
 * dice, para todo el mundo y no solo para quien falle, que si no hay correo
 * registrado hay que pedirlo en la institucion. El camino del administrador NO
 * se cierra ni se toca.
 */
class RestablecerClaveController extends Controller
{
    /**
     * Lo que se contesta pase lo que pase. Ver el docblock de la clase.
     *
     * Esta redactada para ser verdad en los cuatro casos: no promete que se
     * haya mandado nada.
     */
    private const RESPUESTA = 'Si esa cuenta existe y tiene un correo registrado, '
        .'acabamos de enviarle el enlace para cambiar la contraseña. '
        .'Revisa también la carpeta de correo no deseado.';

    public function pedir(): View
    {
        return view('auth.clave-olvidada');
    }

    public function enviar(Request $request): RedirectResponse
    {
        $datos = $request->validate([
            'cuenta' => ['required', 'string', 'max:255'],
        ], [
            'cuenta.required' => 'Escribe tu usuario o tu correo.',
        ], ['cuenta' => 'usuario o correo']);

        $usuario = RestablecerClave::cuentaDe($datos['cuenta']);

        if ($usuario !== null) {
            $this->mandar($usuario);
        }

        return redirect()->route('clave-olvidada')->with('success', self::RESPUESTA);
    }

    /**
     * La pantalla donde se escribe la contrasena nueva.
     *
     * Un enlace que no vale devuelve a pedir otro CON UN AVISO, en vez de dar
     * un 404. La diferencia importa porque el caso corriente no es un ataque
     * sino alguien que abre el correo al dia siguiente: un 404 le dice «esta
     * pagina no existe» y le deja creyendo que el sistema esta roto. Y no delata
     * nada — el token es de quien lo tiene, no de quien lo mira.
     */
    public function formulario(string $token): View|RedirectResponse
    {
        if (RestablecerClave::cuentaDelEnlace($token) === null) {
            return $this->enlaceQueNoVale();
        }

        return view('auth.clave-nueva', ['token' => $token]);
    }

    public function guardar(Request $request, string $token): RedirectResponse
    {
        // La validacion va ANTES de mirar el token a proposito: si no, quien
        // teclea dos contrasenas distintas en un enlace ya caducado recibiria el
        // aviso del enlace y no el de las contrasenas, y volveria a pedirlo sin
        // saber que ademas se equivoco.
        $request->validate([
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ], [], ['password' => 'contraseña']);

        $usuario = RestablecerClave::cuentaDelEnlace($token);

        if ($usuario === null) {
            return $this->enlaceQueNoVale();
        }

        // El modelo la castea a `hashed`, asi que se asigna en claro.
        //
        // Y aqui NO hay que preocuparse por la trampa de `$perfil->user` que
        // documenta `MiPerfilController::guardarClave()`: aquella existe porque
        // ahi hay una sesion abierta y `AuthenticateSession` compara su hash con
        // el de la instancia de `Auth`. Quien llega hasta esta linea no tiene
        // sesion — la ruta va detras de `guest`.
        //
        // Lo que si hace ese cambio de hash, y es deseado, es EXPULSAR a
        // cualquier otra sesion abierta de esa cuenta. Quien recupera su clave
        // porque se la quitaron necesita justamente eso.
        $usuario->password = $request->input('password');
        $usuario->save();

        RestablecerClave::consumir($usuario);

        Auditoria::registrar('clave.restablecida', [], $usuario->perfil);

        return redirect()->route('login')->with(
            'success',
            'Tu contraseña quedó cambiada. Ya puedes iniciar sesión con ella.'
        );
    }

    /**
     * Manda el correo, y si no sale lo deja en el registro.
     *
     * NO SE PROPAGA EL FALLO, y las dos mitades importan. Hacia fuera, la
     * persona ve la misma frase que si hubiera salido: un 500 aqui delata que
     * esa cuenta existe, ademas de romper una pantalla publica. Hacia dentro
     * queda escrito, porque un SMTP mal configurado y un correo que nadie pidio
     * no son lo mismo, y sin esta linea el fallo se quedaria escondido detras de
     * un mensaje tranquilizador — nadie volveria a mirar.
     */
    private function mandar(User $usuario): void
    {
        $token = RestablecerClave::crear($usuario);

        try {
            // El remitente lo elige `CorreoDeLaInstitucion` y no `Mail::` a
            // secas: manda lo que la entidad haya escrito en Gestion →
            // Institucion, y si no hay nada, lo del `.env`.
            CorreoDeLaInstitucion::remitenteQueEnvia()
                ->to($usuario->email)
                ->send(new EnlaceParaLaClave($usuario, route('clave-nueva', ['token' => $token])));
        } catch (Throwable $e) {
            Log::error('No se pudo enviar el correo para restablecer la contraseña', [
                'usuario' => $usuario->username,
                'motivo' => $e->getMessage(),
            ]);
        }
    }

    private function enlaceQueNoVale(): RedirectResponse
    {
        return redirect()->route('clave-olvidada')->with(
            'error',
            'Ese enlace ya no sirve: se usó o pasó de '.RestablecerClave::VALIDEZ_MINUTOS
            .' minutos desde que se pidió. Pide uno nuevo aquí.'
        );
    }
}
