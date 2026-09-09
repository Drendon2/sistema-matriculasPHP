<?php

namespace Tests\Feature;

use App\Mail\EnlaceParaLaClave;
use App\Models\Perfil;
use App\Models\User;
use App\Support\RestablecerClave;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * «Olvidé mi contraseña»: el enlace por correo.
 *
 * Hasta el 09/09/2026 no habia forma de recuperar una cuenta por uno mismo: o
 * un administrador tecleaba una contrasena temporal —que entonces sabian dos
 * personas— o no se entraba. Y para pedirselo habia que localizarlo por
 * WhatsApp.
 *
 * LO QUE VIGILA ESTE ARCHIVO, y por que cada cosa:
 *
 * 1. QUE LA RESPUESTA SEA LA MISMA PASE LO QUE PASE. Hay cuatro desenlaces
 *    detras —no existe, esta desactivada, no tiene correo, se mando— y
 *    distinguirlos convierte una pantalla publica en una forma de averiguar
 *    quien tiene cuenta aqui. Es lo mismo que ya sostiene el «usuario o
 *    contraseña incorrectos» del login. Se afirma sobre los CUATRO, porque el
 *    que se escapa es el que delata.
 *
 * 2. QUE EL CORREO SE MANDE SOLO CUANDO TOCA. La mitad de arriba dice que la
 *    pantalla no distingue; esta dice que el sistema por dentro SI. Sin ella,
 *    un controlador que no mandara nunca nada pasaria la primera en verde.
 *
 * 3. QUE UN ENLACE SE GASTE Y CADUQUE. Un enlace de recuperacion reutilizable
 *    es una contrasena permanente escrita en el historial de un correo.
 *
 * 4. QUE SE PUEDA ENTRAR CON LA CLAVE NUEVA Y NO CON LA VIEJA. Es la unica
 *    afirmacion que comprueba que esto sirve para algo.
 *
 * 5. QUE UN SMTP CAIDO NO ROMPA LA PANTALLA. Un 500 aqui no solo es una pagina
 *    rota: frente a la respuesta normal, es la diferencia que delata que esa
 *    cuenta existe.
 */
class ClaveOlvidadaTest extends TestCase
{
    use RefreshDatabase;

    /** Lo que se contesta pase lo que pase. */
    private const RESPUESTA = 'Si esa cuenta existe y tiene un correo registrado';

    private User $conCorreo;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->conCorreo = $this->cuenta('ana', 'ana@example.com');
    }

    // ------------------------------------------------------------------
    // 1. La misma respuesta en los cuatro casos
    // ------------------------------------------------------------------

    #[DataProvider('losCuatroCasos')]
    public function test_siempre_se_contesta_lo_mismo(string $caso, string $escrito): void
    {
        $this->prepararCaso($caso);

        $this->post(route('clave-olvidada.enviar'), ['cuenta' => $escrito])
            ->assertRedirect(route('clave-olvidada'))
            ->assertSessionHas('success', fn (string $aviso) => str_contains($aviso, self::RESPUESTA));
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function losCuatroCasos(): array
    {
        return [
            'la cuenta no existe' => ['nada', 'no.existe@example.com'],
            'existe y esta desactivada' => ['desactivada', 'apagada'],
            'existe y no tiene correo' => ['sin correo', 'pedro'],
            'existe y se le manda' => ['nada', 'ana'],
        ];
    }

    // ------------------------------------------------------------------
    // 2. Por dentro si se distingue
    // ------------------------------------------------------------------

    public function test_se_manda_el_correo_a_quien_lo_tiene(): void
    {
        $this->post(route('clave-olvidada.enviar'), ['cuenta' => 'ana']);

        Mail::assertSent(
            EnlaceParaLaClave::class,
            fn (EnlaceParaLaClave $correo) => $correo->hasTo('ana@example.com')
        );
    }

    /** Y el mismo campo acepta las dos cosas: el usuario o el correo. */
    public function test_se_encuentra_por_correo_ademas_de_por_usuario(): void
    {
        $this->post(route('clave-olvidada.enviar'), ['cuenta' => 'ana@example.com']);

        Mail::assertSent(EnlaceParaLaClave::class);
    }

    public function test_no_se_manda_nada_a_una_cuenta_desactivada(): void
    {
        $this->cuenta('apagada', 'apagada@example.com', activo: false);

        $this->post(route('clave-olvidada.enviar'), ['cuenta' => 'apagada']);

        Mail::assertNothingSent();
    }

    public function test_no_se_manda_nada_a_una_cuenta_sin_correo(): void
    {
        $this->cuenta('pedro', null);

        $this->post(route('clave-olvidada.enviar'), ['cuenta' => 'pedro']);

        Mail::assertNothingSent();
    }

    /**
     * Y no se crea el enlace tampoco.
     *
     * Es la mitad que no se ve: un token creado para una cuenta a la que no se
     * le manda nada es una fila muerta, y ademas invalidaria un enlace bueno
     * anterior — o sea que bastaria con pedirlo para el usuario de otro, con la
     * cuenta ya desactivada, para tumbarle el suyo.
     */
    public function test_a_quien_no_recibe_correo_no_se_le_crea_enlace(): void
    {
        $this->cuenta('pedro', null);

        $this->post(route('clave-olvidada.enviar'), ['cuenta' => 'pedro']);

        $this->assertSame(0, DB::table('restablecimientos_clave')->count());
    }

    // ------------------------------------------------------------------
    // 3. El enlace: uno solo, se gasta y caduca
    // ------------------------------------------------------------------

    public function test_pedir_otro_enlace_invalida_el_anterior(): void
    {
        $primero = RestablecerClave::crear($this->conCorreo);
        $segundo = RestablecerClave::crear($this->conCorreo);

        $this->assertNull(RestablecerClave::cuentaDelEnlace($primero));
        $this->assertNotNull(RestablecerClave::cuentaDelEnlace($segundo));
        $this->assertSame(1, DB::table('restablecimientos_clave')->count());
    }

    public function test_el_enlace_se_gasta_al_usarlo(): void
    {
        $token = RestablecerClave::crear($this->conCorreo);

        $this->post(route('clave-nueva.guardar', ['token' => $token]), [
            'password' => 'claveNueva123',
            'password_confirmation' => 'claveNueva123',
        ])->assertRedirect(route('login'));

        $this->assertSame(0, DB::table('restablecimientos_clave')->count());

        $this->get(route('clave-nueva', ['token' => $token]))
            ->assertRedirect(route('clave-olvidada'))
            ->assertSessionHas('error');
    }

    public function test_el_enlace_caduca(): void
    {
        $token = RestablecerClave::crear($this->conCorreo);

        $this->travel(RestablecerClave::VALIDEZ_MINUTOS + 1)->minutes();

        $this->get(route('clave-nueva', ['token' => $token]))
            ->assertRedirect(route('clave-olvidada'))
            ->assertSessionHas('error');

        // Y guardar por la puerta de atras tampoco: esconder el formulario no
        // cierra la peticion.
        $this->post(route('clave-nueva.guardar', ['token' => $token]), [
            'password' => 'claveNueva123',
            'password_confirmation' => 'claveNueva123',
        ])->assertRedirect(route('clave-olvidada'));

        $this->assertTrue(Hash::check('laDeSiempre1', $this->conCorreo->fresh()->password));
    }

    /**
     * Un enlace inventado no da 404, devuelve a pedir otro.
     *
     * Un 404 le dice a quien abre su correo tarde «esta pagina no existe», que
     * suena a sistema roto. Y no delata nada: el token es de quien lo tiene.
     */
    public function test_un_enlace_inventado_manda_a_pedir_otro(): void
    {
        $this->get(route('clave-nueva', ['token' => 'esto-no-es-un-token']))
            ->assertRedirect(route('clave-olvidada'))
            ->assertSessionHas('error');
    }

    /**
     * Una cuenta desactivada DESPUES de pedir el enlace ya no puede usarlo.
     *
     * Entre pedirlo y abrirlo pasa hasta una hora, y en ese rato un
     * administrador puede haber cerrado esa cuenta. Sin esta comprobacion el
     * enlace le devolveria el paso a quien se lo acaban de quitar.
     */
    public function test_desactivar_la_cuenta_invalida_el_enlace(): void
    {
        $token = RestablecerClave::crear($this->conCorreo);

        $this->conCorreo->update(['activo' => false]);

        $this->get(route('clave-nueva', ['token' => $token]))
            ->assertRedirect(route('clave-olvidada'));
    }

    // ------------------------------------------------------------------
    // 4. Que sirva para algo
    // ------------------------------------------------------------------

    public function test_con_la_clave_nueva_se_entra_y_con_la_vieja_no(): void
    {
        $token = RestablecerClave::crear($this->conCorreo);

        $this->post(route('clave-nueva.guardar', ['token' => $token]), [
            'password' => 'claveNueva123',
            'password_confirmation' => 'claveNueva123',
        ])->assertRedirect(route('login'))->assertSessionHas('success');

        $this->post(route('login.entrar'), ['username' => 'ana', 'password' => 'laDeSiempre1'])
            ->assertSessionHasErrors('username');

        $this->assertGuest();

        $this->post(route('login.entrar'), ['username' => 'ana', 'password' => 'claveNueva123']);

        $this->assertAuthenticatedAs($this->conCorreo->fresh());
    }

    public function test_la_clave_nueva_tiene_que_repetirse_y_cumplir_el_minimo(): void
    {
        $token = RestablecerClave::crear($this->conCorreo);

        $this->post(route('clave-nueva.guardar', ['token' => $token]), [
            'password' => 'claveNueva123',
            'password_confirmation' => 'otraDistinta1',
        ])->assertSessionHasErrors('password');

        $this->post(route('clave-nueva.guardar', ['token' => $token]), [
            'password' => 'corta',
            'password_confirmation' => 'corta',
        ])->assertSessionHasErrors('password');

        // Ninguno de los dos rechazos gasta el enlace: quien se equivoca al
        // teclear tiene que poder reintentar sin volver a pedir el correo.
        $this->assertNotNull(RestablecerClave::cuentaDelEnlace($token));
        $this->assertTrue(Hash::check('laDeSiempre1', $this->conCorreo->fresh()->password));
    }

    // ------------------------------------------------------------------
    // 5. El SMTP caido
    // ------------------------------------------------------------------

    /**
     * Si el envio revienta, la pantalla contesta lo de siempre.
     *
     * Se provoca con un `Mail::fake()` al que se le manda a un buzon imposible;
     * lo que se afirma es que la excepcion no sale del controlador.
     */
    public function test_un_fallo_de_envio_no_rompe_la_pantalla(): void
    {
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('SMTP no contesta'));

        $this->post(route('clave-olvidada.enviar'), ['cuenta' => 'ana'])
            ->assertRedirect(route('clave-olvidada'))
            ->assertSessionHas('success', fn (string $aviso) => str_contains($aviso, self::RESPUESTA));
    }

    // ------------------------------------------------------------------
    // El freno
    // ------------------------------------------------------------------

    /**
     * Insistir sobre la MISMA cuenta se frena.
     *
     * El abuso realista no es adivinar nada: es pulsar el boton veinte veces
     * sobre la cuenta de otro para llenarle el buzon de correos que no pidio.
     */
    public function test_insistir_sobre_la_misma_cuenta_se_frena(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->post(route('clave-olvidada.enviar'), ['cuenta' => 'ana'])->assertRedirect();
        }

        // Y lo que se ve NO es un 429 pelado: este proyecto convierte esa
        // excepcion en un aviso en la pantalla (ver `bootstrap/app.php`), asi
        // que lo que hay que afirmar es el aviso. Comprobar el codigo daria rojo
        // con el codigo bueno.
        $this->post(route('clave-olvidada.enviar'), ['cuenta' => 'ana'])
            ->assertSessionHas('error', fn (string $aviso) => str_contains($aviso, 'Demasiados intentos'));

        Mail::assertSentCount(3);
    }

    /**
     * Y eso NO deja fuera a quien comparte la IP.
     *
     * Es la mitad que justifica que el limitador cuente por cuenta y no solo
     * por IP: en produccion hay un CDN delante y este proyecto no configura
     * `TrustProxies`, asi que la IP que se ve puede ser la del borde del CDN —
     * la misma para toda la institucion. Con un limite solo por IP, tres
     * intentos de una persona dejarian sin recuperacion a las demas.
     */
    public function test_frenar_una_cuenta_no_frena_a_las_demas(): void
    {
        $this->cuenta('luis', 'luis@example.com');

        for ($i = 0; $i < 4; $i++) {
            $this->post(route('clave-olvidada.enviar'), ['cuenta' => 'ana']);
        }

        // Se afirma sobre el CORREO y no sobre la redireccion: el aviso de
        // «demasiados intentos» tambien redirige —`bootstrap/app.php` convierte
        // el 429 en un `back()`— asi que un `assertRedirect()` aqui pasaria en
        // verde con el limitador contando solo por IP, que es justo el fallo
        // que esta prueba existe para cazar. Comprobado quitandolo.
        $this->post(route('clave-olvidada.enviar'), ['cuenta' => 'luis']);

        Mail::assertSent(
            EnlaceParaLaClave::class,
            fn (EnlaceParaLaClave $correo) => $correo->hasTo('luis@example.com')
        );
    }

    // ------------------------------------------------------------------
    // La puerta
    // ------------------------------------------------------------------

    public function test_la_pantalla_de_entrar_lleva_el_enlace(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee(route('clave-olvidada'))
            ->assertSee('Olvidé mi contraseña');
    }

    /** Quien ya entro no pasa por aqui: para cambiar su clave tiene Mi perfil. */
    public function test_con_la_sesion_abierta_no_se_entra(): void
    {
        $this->actingAs($this->conCorreo)
            ->get(route('clave-olvidada'))
            ->assertRedirect();
    }

    // ------------------------------------------------------------------
    // Andamiaje
    // ------------------------------------------------------------------

    private function prepararCaso(string $caso): void
    {
        match ($caso) {
            'desactivada' => $this->cuenta('apagada', 'apagada@example.com', activo: false),
            'sin correo' => $this->cuenta('pedro', null),
            default => null,
        };
    }

    private function cuenta(string $username, ?string $correo, bool $activo = true): User
    {
        $user = User::create([
            'username' => $username,
            'email' => $correo,
            'password' => 'laDeSiempre1',
            'activo' => $activo,
        ]);

        Perfil::create([
            'user_id' => $user->id,
            'nombre_completo' => ucfirst($username).' Pérez',
            'rol' => 'estudiante',
            'telefono' => '3001112233',
            'fecha_nacimiento' => Carbon::today()->subYears(30),
        ]);

        return $user;
    }
}
