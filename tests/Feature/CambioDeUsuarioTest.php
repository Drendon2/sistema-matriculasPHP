<?php

namespace Tests\Feature;

use App\Models\Perfil;
use App\Models\User;
use App\Support\GestionAsistida;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Cambiarse el propio NOMBRE DE USUARIO, desde «Mi perfil».
 *
 * Lo pidio el usuario el 10/09/2026 y hasta ese dia no se podia por ningun
 * camino que no fuera un administrador. La razon de que importe no es de
 * simetria: en este sistema el `username` no es un apodo tecnico, y medido en
 * produccion 179 personas usan su correo y 239 su nombre con espacios. Un
 * nombre mal tecleado el dia de inscribirse se queda tambien en la credencial.
 *
 * LO QUE ESTE ARCHIVO VIGILA:
 *
 * 1. Que se cambie de verdad, y que se ENTRE con el nuevo. Guardar la fila y
 *    que el login siguiera pidiendo el viejo serian dos cosas distintas.
 * 2. Que pida la contrasena ACTUAL, que es la unica barrera que tiene: cambiar
 *    el usuario de otro no le quita la cuenta, le quita la forma de entrar en
 *    ella, y aqui se llega con una sesion abierta en un celular prestado.
 * 3. Que NO se pueda pisar el usuario de otra persona.
 * 4. Que la regla del campo siga siendo ANCHA. Es la que mas caro sale de
 *    estrechar: escrita como `/^[A-Za-z0-9._-]+$/` dejaba fuera a 435 de las
 *    841 cuentas de produccion. Hay dos pruebas de espacios, tilde y arroba.
 * 5. Que desde una gestion asistida SI se pueda, que es la decision del 10/09
 *    y la unica asimetria con el cambio de contrasena.
 */
class CambioDeUsuarioTest extends TestCase
{
    use RefreshDatabase;

    private const CLAVE = 'ClaveDeAna2026';

    private Perfil $yo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->yo = $this->perfil('ana', 'estudiante', self::CLAVE);
    }

    /** El camino bueno: se cambia la fila. */
    public function test_se_cambia_el_propio_nombre_de_usuario(): void
    {
        $this->actingAs($this->yo->user)
            ->post(route('mi-perfil.guardar'), $this->formulario('ana.maria', self::CLAVE))
            ->assertRedirect(route('mi-perfil'))
            ->assertSessionHas('success');

        $this->assertSame('ana.maria', $this->yo->user->fresh()->username);
    }

    /**
     * Y SE ENTRA CON EL NUEVO, que es la mitad que de verdad se pidio.
     *
     * Sin esta, guardar la fila y que la pantalla de entrar siguiera pidiendo el
     * viejo pasarian las dos por buenas.
     *
     * El `logout()` no sobra: la ruta de entrar va detras de `guest`, asi que
     * con la sesion de `actingAs` puesta la peticion se va redirigida y no
     * comprueba nada. Es la trampa que ya costo un rato en `ClaveOlvidadaTest`.
     */
    public function test_se_entra_con_el_nombre_nuevo_y_no_con_el_viejo(): void
    {
        $this->actingAs($this->yo->user)
            ->post(route('mi-perfil.guardar'), $this->formulario('ana.maria', self::CLAVE));

        auth()->logout();

        $this->post(route('login.entrar'), ['username' => 'ana', 'password' => self::CLAVE])
            ->assertSessionHasErrors('username');
        $this->assertGuest();

        $this->post(route('login.entrar'), ['username' => 'ana.maria', 'password' => self::CLAVE]);
        $this->assertAuthenticatedAs($this->yo->user->fresh());
    }

    /**
     * SIN LA CONTRASENA ACTUAL NO SE CAMBIA.
     *
     * Es la unica barrera de esta pantalla, y por eso son dos pruebas: una con
     * la clave equivocada y otra sin mandarla.
     */
    public function test_sin_la_contrasena_actual_no_se_cambia(): void
    {
        $this->actingAs($this->yo->user)
            ->post(route('mi-perfil.guardar'), $this->formulario('ana.maria', 'MeLaInvente2026'))
            ->assertSessionHasErrors('clave_usuario');

        $this->assertSame('ana', $this->yo->user->fresh()->username);
    }

    /**
     * UN RECHAZO NO SE LLEVA LO QUE ACABABAS DE TECLEAR, Y NO LLEVA LA CLAVE.
     *
     * Las dos mitades salieron de abrir la pantalla el 10/09/2026, no de una
     * prueba. Sin `withInput()` el campo volvia al nombre que hay en la base, o
     * sea que quien se equivoca de contrasena tiene que volver a escribir el
     * usuario — y encima eran dos rechazos distintos, porque el de formato o
     * repetido si lo conserva (`validate()` reenvia la entrada solo).
     *
     * Y la segunda mitad es la que muerde: con el `withInput()` pelado se
     * flashea TODO lo que llego. La lista que Laravel excluye trae `password` y
     * `current_password`, no `clave_usuario`, asi que la contrasena en claro
     * acabaria en la sesion — que en produccion es una tabla de la base.
     */
    public function test_un_rechazo_conserva_el_usuario_tecleado_y_no_la_clave(): void
    {
        $this->actingAs($this->yo->user)
            ->post(route('mi-perfil.guardar'), $this->formulario('ana.maria', 'MeLaInvente2026'))
            ->assertSessionHasErrors('clave_usuario');

        $this->assertSame('ana.maria', session()->getOldInput('username'), 'se perdio lo tecleado.');
        $this->assertNull(session()->getOldInput('clave_usuario'), 'la contrasena viaja a la sesion.');
    }

    /** Ni sin mandarla siquiera. */
    public function test_sin_mandar_la_contrasena_no_se_cambia(): void
    {
        $this->actingAs($this->yo->user)
            ->post(route('mi-perfil.guardar'), ['accion' => 'usuario', 'username' => 'ana.maria'])
            ->assertSessionHasErrors('clave_usuario');

        $this->assertSame('ana', $this->yo->user->fresh()->username);
    }

    /** El usuario de otra persona no se puede pisar. */
    public function test_no_se_puede_tomar_el_usuario_de_otra_persona(): void
    {
        $this->perfil('beto', 'profesor', 'LoQueSea2026');

        $this->actingAs($this->yo->user)
            ->post(route('mi-perfil.guardar'), $this->formulario('beto', self::CLAVE))
            ->assertSessionHasErrors('username');

        $this->assertSame('ana', $this->yo->user->fresh()->username);
    }

    /**
     * Guardar el que ya tenias no es un error, y tampoco un cambio.
     *
     * La regla de unicidad ignora la propia fila; sin ese `ignore` esta prueba
     * se pone roja diciendo que el usuario ya existe, que seria uno mismo.
     */
    public function test_guardar_el_mismo_no_falla(): void
    {
        $this->actingAs($this->yo->user)
            ->post(route('mi-perfil.guardar'), $this->formulario('ana', self::CLAVE))
            ->assertRedirect(route('mi-perfil'))
            ->assertSessionHasNoErrors();

        $this->assertSame('ana', $this->yo->user->fresh()->username);
    }

    /** Los angulos no, que son la via de inyeccion que `SIN_MARCAS` corta. */
    public function test_no_se_admiten_angulos(): void
    {
        $this->actingAs($this->yo->user)
            ->post(route('mi-perfil.guardar'), $this->formulario('<script>', self::CLAVE))
            ->assertSessionHasErrors('username');

        $this->assertSame('ana', $this->yo->user->fresh()->username);
    }

    /*
     * LAS DOS DE ABAJO SON CAMINOS BUENOS, y estan aqui para que sigan verdes.
     *
     * La regla de este campo es ANCHA a proposito y es la que mas caro sale de
     * estrechar «para que sea consistente con el telefono»: escrita como
     * `/^[A-Za-z0-9._-]+$/` dejaba fuera a 435 de las 841 cuentas de
     * produccion. Quien la estreche no lo vera con su propio usuario, que si
     * cumpliria; lo vera aqui.
     */

    /** Un correo de nombre de usuario: lo hacen 179 personas en produccion. */
    public function test_se_admite_un_correo_como_nombre_de_usuario(): void
    {
        $this->actingAs($this->yo->user)
            ->post(route('mi-perfil.guardar'), $this->formulario('ana@correo.com', self::CLAVE))
            ->assertSessionHasNoErrors();

        $this->assertSame('ana@correo.com', $this->yo->user->fresh()->username);
    }

    /** Y el nombre con espacios y tilde: 239 y 17 personas. */
    public function test_se_admite_un_nombre_con_espacios_y_tilde(): void
    {
        $this->actingAs($this->yo->user)
            ->post(route('mi-perfil.guardar'), $this->formulario('Ainhoa Dávila', self::CLAVE))
            ->assertSessionHasNoErrors();

        $this->assertSame('Ainhoa Dávila', $this->yo->user->fresh()->username);
    }

    /**
     * DESDE UNA GESTION ASISTIDA SI SE PUEDE, y es la decision del 10/09/2026.
     *
     * Es la unica asimetria con el cambio de contrasena, que ahi si esta
     * cortado. Se tomo con la objecion delante: la barrera de esta pantalla es
     * saberse la contrasena de la persona, asi que un corte aparte no anadiria
     * nada. Esta prueba existe para que nadie «arregle la inconsistencia»
     * poniendo el corte sin volver a preguntarlo.
     */
    public function test_desde_una_gestion_asistida_se_puede_si_se_sabe_la_clave(): void
    {
        $admin = $this->perfil('jefa', 'administrador', 'LoQueSea2026');
        $profe = $this->perfil('profe', 'profesor', self::CLAVE);

        $this->actingAs($admin->user)->post(route('gestion-asistida-iniciar', $profe));
        $this->assertTrue(GestionAsistida::activa(), 'la sonda no vale: no entro en la asistida.');

        $this->post(route('mi-perfil.guardar'), $this->formulario('profe.nuevo', self::CLAVE))
            ->assertSessionHasNoErrors();

        $this->assertSame('profe.nuevo', $profe->user->fresh()->username);
    }

    /** Y sin saberla, tampoco desde ahi. */
    public function test_una_gestion_asistida_sin_la_clave_no_lo_cambia(): void
    {
        $admin = $this->perfil('jefa', 'administrador', 'LoQueSea2026');
        $profe = $this->perfil('profe', 'profesor', self::CLAVE);

        $this->actingAs($admin->user)->post(route('gestion-asistida-iniciar', $profe));

        $this->post(route('mi-perfil.guardar'), $this->formulario('profe.nuevo', 'LoQueSea2026'))
            ->assertSessionHasErrors('clave_usuario');

        $this->assertSame('profe', $profe->user->fresh()->username);
    }

    /**
     * LA PANTALLA LO OFRECE, Y PINTA EL QUE HAY.
     *
     * Lo segundo no sobra: no se puede corregir lo que no se ve, y hasta hoy
     * esta pantalla no ensenaba el usuario en ninguna parte.
     */
    public function test_mi_perfil_ofrece_cambiar_el_usuario_y_pinta_el_actual(): void
    {
        $html = $this->actingAs($this->yo->user)->get(route('mi-perfil'))->assertOk()->getContent();

        $this->assertStringContainsString('name="username"', $html);
        $this->assertStringContainsString('name="clave_usuario"', $html);
        $this->assertStringContainsString('value="ana"', $html, 'no pinta el usuario que hay.');
        $this->assertStringContainsString('js/ver-clave.js', $html, 'su campo de clave se queda sin ojo.');
    }

    /**
     * @return array<string, string>
     */
    private function formulario(string $username, string $clave): array
    {
        return [
            'accion' => 'usuario',
            'username' => $username,
            'clave_usuario' => $clave,
        ];
    }

    private function perfil(string $username, string $rol, string $clave): Perfil
    {
        $user = User::create(['username' => $username, 'password' => $clave, 'activo' => true]);

        return Perfil::create([
            'user_id' => $user->id,
            'rol' => $rol,
            'nombre_completo' => ucfirst($username),
            'fecha_nacimiento' => Carbon::today()->subYears(30)->toDateString(),
            'telefono' => '3000000000',
        ]);
    }
}
