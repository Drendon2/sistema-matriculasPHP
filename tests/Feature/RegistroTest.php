<?php

namespace Tests\Feature;

use App\Models\Perfil;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Autorregistro publico de profesor.
 */
class RegistroTest extends TestCase
{
    use RefreshDatabase;

    private array $datos = [
        'username' => 'profe.nuevo',
        'password' => 'secreto123',
        'password_confirmation' => 'secreto123',
        'nombre_completo' => 'Marta Solis',
        'fecha_nacimiento' => '1985-03-12',
        'telefono' => '3001234567',
    ];

    /** La cuenta nace SIN rol: el acceso lo abre despues un director. */
    public function test_la_cuenta_creada_no_tiene_rol(): void
    {
        $this->post(route('registro.guardar'), $this->datos)
            ->assertRedirect(route('login'))
            ->assertSessionHas('success');

        $user = User::where('username', 'profe.nuevo')->first();

        $this->assertNotNull($user);
        $this->assertTrue($user->activo);
        $this->assertSame('', $user->perfil->rol);
        $this->assertSame('Marta Solis', $user->perfil->nombre_completo);
    }

    public function test_el_usuario_repetido_se_rechaza(): void
    {
        $this->post(route('registro.guardar'), $this->datos);

        $this->post(route('registro.guardar'), $this->datos)
            ->assertSessionHasErrors('username');

        $this->assertSame(1, User::where('username', 'profe.nuevo')->count());
    }

    public function test_las_contrasenas_deben_coincidir(): void
    {
        $this->post(route('registro.guardar'), [...$this->datos, 'password_confirmation' => 'otra'])
            ->assertSessionHasErrors('password');

        $this->assertSame(0, User::count());
    }

    /**
     * Cuenta y perfil nacen juntos o no nacen: una cuenta sin perfil no puede
     * ni pasar por la redireccion posterior al login.
     */
    public function test_no_queda_cuenta_suelta_si_falla_la_validacion(): void
    {
        $this->post(route('registro.guardar'), [...$this->datos, 'nombre_completo' => '']);

        $this->assertSame(0, User::count());
        $this->assertSame(0, Perfil::count());
    }

    /**
     * Los formularios publicos sin sesion no aceptan archivos: es una decision
     * de seguridad del original, no una simplificacion.
     *
     * Se busca el elemento `<input type="file"`, no la cadena `type="file"` a
     * secas: el CSS del layout lleva un selector `input[type="file"]` para las
     * pantallas que si suben archivos, y buscar la cadena suelta daria un falso
     * positivo contra la hoja de estilos.
     */
    public function test_el_registro_no_pide_foto(): void
    {
        $this->get(route('registro'))
            ->assertOk()
            ->assertDontSee('<input type="file"', false)
            ->assertSee('Mi perfil');
    }
}
