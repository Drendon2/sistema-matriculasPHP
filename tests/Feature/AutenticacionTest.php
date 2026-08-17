<?php

namespace Tests\Feature;

use App\Models\Perfil;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Entrada al sistema: quien puede iniciar sesion y a donde llega cada rol.
 */
class AutenticacionTest extends TestCase
{
    use RefreshDatabase;

    private function crearCuenta(string $rol, string $username = 'ana', bool $activo = true): User
    {
        $user = User::create([
            'username' => $username,
            'password' => 'secreto123',
            'activo' => $activo,
        ]);

        Perfil::create([
            'user_id' => $user->id,
            'rol' => $rol,
            'nombre_completo' => 'Ana Ruiz',
            'fecha_nacimiento' => '2000-05-01',
            'telefono' => '3000000000',
        ]);

        return $user->refresh();
    }

    public function test_se_entra_con_usuario_y_contrasena(): void
    {
        $this->crearCuenta('estudiante');

        $respuesta = $this->post(route('login.entrar'), [
            'username' => 'ana',
            'password' => 'secreto123',
        ]);

        $respuesta->assertRedirect(route('post-login'));
        $this->assertAuthenticated();
    }

    public function test_una_contrasena_mala_no_entra(): void
    {
        $this->crearCuenta('estudiante');

        $respuesta = $this->post(route('login.entrar'), [
            'username' => 'ana',
            'password' => 'equivocada',
        ]);

        $respuesta->assertSessionHasErrors('username');
        $this->assertGuest();
    }

    /**
     * Una cuenta desactivada no entra, y el mensaje es el MISMO que el de una
     * clave mala: decir "existe pero esta desactivada" le confirmaria a un
     * extrano que ese usuario existe.
     */
    public function test_una_cuenta_desactivada_no_entra(): void
    {
        $this->crearCuenta('profesor', 'beto', activo: false);

        $respuesta = $this->post(route('login.entrar'), [
            'username' => 'beto',
            'password' => 'secreto123',
        ]);

        $respuesta->assertSessionHasErrors('username');
        $this->assertGuest();
    }

    public function test_el_estudiante_aterriza_en_el_catalogo(): void
    {
        $user = $this->crearCuenta('estudiante');

        $this->actingAs($user)
            ->get(route('post-login'))
            ->assertRedirect(route('promotorias-disponibles'));
    }

    public function test_el_personal_aterriza_en_el_panel(): void
    {
        foreach (['administrador', 'director', 'profesor'] as $indice => $rol) {
            $user = $this->crearCuenta($rol, "usuario{$indice}");

            $this->actingAs($user)
                ->get(route('post-login'))
                ->assertRedirect(route('panel'));
        }
    }

    /**
     * La cuenta recien autorregistrada existe pero no tiene rol: entra, y lo
     * unico que ve es la pantalla de espera.
     */
    public function test_sin_rol_se_va_a_pendiente_de_aprobacion(): void
    {
        $user = $this->crearCuenta('');

        $this->actingAs($user)
            ->get(route('post-login'))
            ->assertRedirect(route('pendiente-aprobacion'));
    }

    /** Si le asignan el rol mientras esperaba, esa pantalla deja de aplicar. */
    public function test_con_rol_ya_asignado_la_pantalla_de_espera_redirige(): void
    {
        $user = $this->crearCuenta('profesor');

        $this->actingAs($user)
            ->get(route('pendiente-aprobacion'))
            ->assertRedirect(route('post-login'));
    }

    public function test_se_cierra_sesion(): void
    {
        $user = $this->crearCuenta('estudiante');

        $this->actingAs($user)
            ->post(route('logout'))
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    // -----------------------------------------------------------------------
    // Limite de intentos
    // -----------------------------------------------------------------------

    /**
     * Al sexto intento en el mismo minuto se corta.
     *
     * Cinco fallos y el sexto ya no llega al controlador: sin esto, probar
     * contrasenas contra una cuenta no tiene ningun freno.
     */
    public function test_el_login_se_corta_tras_cinco_intentos_fallidos(): void
    {
        $this->crearCuenta('estudiante');

        for ($intento = 1; $intento <= 5; $intento++) {
            $this->post(route('login.entrar'), [
                'username' => 'ana',
                'password' => 'equivocada',
            ])->assertSessionHasErrors('username');
        }

        $this->post(route('login.entrar'), [
            'username' => 'ana',
            'password' => 'equivocada',
        ])->assertSessionHas('error');

        $this->assertGuest();
    }

    /**
     * Agotar los intentos de una cuenta cierra ESA cuenta, no la direccion.
     *
     * Es la prueba de la decision de fondo: el contador va por usuario+IP. Una
     * escuela entera comparte una sola IP, asi que si el limite fuera por
     * direccion, bastaria con que alguien machacara una cuenta para dejar sin
     * entrar a los demas desde la misma sala.
     */
    public function test_agotar_una_cuenta_no_bloquea_a_las_demas_desde_la_misma_ip(): void
    {
        $this->crearCuenta('estudiante', 'ana');
        $this->crearCuenta('profesor', 'beto');

        for ($intento = 1; $intento <= 6; $intento++) {
            $this->post(route('login.entrar'), [
                'username' => 'ana',
                'password' => 'equivocada',
            ]);
        }

        // Misma IP, otra cuenta, credenciales buenas: entra sin enterarse.
        $this->post(route('login.entrar'), [
            'username' => 'beto',
            'password' => 'secreto123',
        ])->assertRedirect(route('post-login'));

        $this->assertAuthenticated();
    }

    /** Escribirlo con otras mayusculas no estrena contador. */
    public function test_el_contador_no_distingue_mayusculas(): void
    {
        $this->crearCuenta('estudiante');

        for ($intento = 1; $intento <= 5; $intento++) {
            $this->post(route('login.entrar'), [
                'username' => 'ana',
                'password' => 'equivocada',
            ]);
        }

        $this->post(route('login.entrar'), [
            'username' => 'ANA',
            'password' => 'equivocada',
        ])->assertSessionHas('error');
    }

    /**
     * Quedarse sin intentos no dice si la cuenta existe.
     *
     * Misma razon que el mensaje de clave incorrecta: un aviso distinto para un
     * usuario real le confirmaria a un extrano cuales existen.
     */
    public function test_el_aviso_de_bloqueo_no_revela_si_la_cuenta_existe(): void
    {
        for ($intento = 1; $intento <= 6; $intento++) {
            $respuesta = $this->post(route('login.entrar'), [
                'username' => 'fantasma',
                'password' => 'loquesea',
            ]);
        }

        $respuesta->assertSessionHas('error');
        $respuesta->assertSessionMissing('username');
    }
}
