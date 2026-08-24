<?php

namespace Tests\Feature;

use App\Models\Actividad;
use App\Models\Perfil;
use App\Models\Periodo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Cursos, talleres y grupos de proyeccion.
 *
 * Lo que se prueba aqui no es que la pantalla pinte, sino las tres cosas que la
 * separan del catalogo academico: que cada boton administre lo suyo y no lo del
 * otro, que el enlace nazca solo y sea distinto en cada actividad, y que el
 * cupo en blanco signifique "sin tope" en vez de "cero".
 */
class ActividadTest extends TestCase
{
    use RefreshDatabase;

    private Perfil $admin;

    private Perfil $director;

    private Perfil $profesor;

    private Perfil $estudiante;

    private Periodo $periodo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->periodo = Periodo::create([
            'nombre' => '2026-1',
            'fecha_inicio' => '2026-01-15',
            'fecha_fin' => '2026-06-30',
            'activo' => true,
            'matriculas_abiertas' => true,
        ]);

        $this->admin = $this->crearPerfil('admin', 'administrador');
        $this->director = $this->crearPerfil('dire', 'director');
        $this->profesor = $this->crearPerfil('profe', 'profesor');
        $this->estudiante = $this->crearPerfil('ana', 'estudiante');
    }

    private function crearPerfil(string $username, string $rol): Perfil
    {
        $user = User::create(['username' => $username, 'password' => 'x', 'activo' => true]);

        return Perfil::create([
            'user_id' => $user->id,
            'rol' => $rol,
            'nombre_completo' => ucfirst($username),
            'fecha_nacimiento' => Carbon::today()->subYears(30)->toDateString(),
            'telefono' => '3000000000',
        ]);
    }

    /** @param  array<string, mixed>  $extra */
    private function crearActividad(string $tipo, string $nombre, array $extra = []): Actividad
    {
        return Actividad::create([
            'tipo' => $tipo,
            'nombre' => $nombre,
            'responsable_id' => $this->profesor->id,
            'periodo_id' => $this->periodo->id,
            ...$extra,
        ]);
    }

    // -----------------------------------------------------------------------
    // Puertas
    // -----------------------------------------------------------------------

    public function test_un_profesor_no_administra_actividades(): void
    {
        $this->actingAs($this->profesor->user)
            ->get(route('actividad-curso-lista'))
            ->assertRedirect(route('post-login'));
    }

    public function test_un_estudiante_tampoco(): void
    {
        $this->actingAs($this->estudiante->user)
            ->get(route('actividad-proyeccion-lista'))
            ->assertRedirect(route('post-login'));
    }

    public function test_el_director_si(): void
    {
        $this->actingAs($this->director->user)
            ->get(route('actividad-curso-lista'))
            ->assertOk();
    }

    // -----------------------------------------------------------------------
    // Cada boton administra lo suyo
    // -----------------------------------------------------------------------

    public function test_los_cursos_y_la_proyeccion_no_se_mezclan_en_pantalla(): void
    {
        $this->crearActividad(Actividad::TALLER, 'Taller de cajón');
        $this->crearActividad(Actividad::PROYECCION, 'Banda sinfónica');

        $this->actingAs($this->admin->user)
            ->get(route('actividad-curso-lista'))
            ->assertSee('Taller de cajón')
            ->assertDontSee('Banda sinfónica');

        $this->actingAs($this->admin->user)
            ->get(route('actividad-proyeccion-lista'))
            ->assertSee('Banda sinfónica')
            ->assertDontSee('Taller de cajón');
    }

    public function test_la_pantalla_de_cursos_no_edita_un_grupo_de_proyeccion(): void
    {
        $banda = $this->crearActividad(Actividad::PROYECCION, 'Banda sinfónica');

        // Sin esto, el desplegable de tipo de esa pantalla —que solo ofrece
        // curso y taller— convertiria la banda en otra cosa al guardar.
        $this->actingAs($this->admin->user)
            ->get(route('actividad-curso-editar', $banda))
            ->assertNotFound();
    }

    public function test_la_pantalla_de_proyeccion_crea_siempre_proyeccion(): void
    {
        // El tipo no se ofrece en ese formulario. Mandarlo a mano no lo cambia.
        $this->actingAs($this->admin->user)->post(route('actividad-proyeccion-nueva'), [
            'nombre' => 'Coro institucional',
            'responsable_id' => $this->director->id,
            'cupo_maximo' => '',
            'tipo' => Actividad::CURSO,
        ])->assertRedirect(route('actividad-proyeccion-lista'));

        $this->assertSame(Actividad::PROYECCION, Actividad::firstWhere('nombre', 'Coro institucional')->tipo);
    }

    public function test_un_curso_se_crea_con_su_tipo_y_su_responsable(): void
    {
        $this->actingAs($this->admin->user)->post(route('actividad-curso-nueva'), [
            'tipo' => Actividad::CURSO,
            'nombre' => 'Iniciación a la guitarra',
            'responsable_id' => $this->profesor->id,
            'cupo_maximo' => '20',
        ])->assertRedirect(route('actividad-curso-lista'));

        $curso = Actividad::firstWhere('nombre', 'Iniciación a la guitarra');

        $this->assertSame(Actividad::CURSO, $curso->tipo);
        $this->assertSame($this->profesor->id, $curso->responsable_id);
        $this->assertSame(20, $curso->cupo_maximo);
        // El periodo en curso queda anotado sin preguntarlo.
        $this->assertSame($this->periodo->id, $curso->periodo_id);
    }

    public function test_un_estudiante_no_puede_quedar_a_cargo(): void
    {
        $this->actingAs($this->admin->user)->post(route('actividad-curso-nueva'), [
            'tipo' => Actividad::TALLER,
            'nombre' => 'Taller de cajón',
            'responsable_id' => $this->estudiante->id,
            'cupo_maximo' => '',
        ])->assertSessionHasErrors('responsable_id');

        $this->assertSame(0, Actividad::count());
    }

    // -----------------------------------------------------------------------
    // El cupo y el enlace
    // -----------------------------------------------------------------------

    public function test_el_cupo_en_blanco_es_sin_tope_y_no_cero(): void
    {
        $this->actingAs($this->admin->user)->post(route('actividad-curso-nueva'), [
            'tipo' => Actividad::TALLER,
            'nombre' => 'Taller de cajón',
            'responsable_id' => $this->profesor->id,
            'cupo_maximo' => '',
        ])->assertRedirect(route('actividad-curso-lista'));

        $this->assertNull(Actividad::firstWhere('nombre', 'Taller de cajón')->cupo_maximo);
    }

    public function test_el_formulario_no_exige_el_cupo(): void
    {
        // La prueba de arriba NO cubre esto: manda el POST directamente, asi que
        // pasaria igual con el campo marcado como obligatorio en el HTML. Y con
        // `required` puesto, el navegador no deja ni enviar el formulario — el
        // "sin tope" seria inalcanzable desde la pantalla aunque la regla de
        // validacion lo admita.
        $html = $this->actingAs($this->admin->user)
            ->get(route('actividad-curso-nueva'))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression('/<input type="number"[^>]*id="cupo_maximo"(?![^>]*required)/', $html);
        // Y el nombre, que si lo es, sigue marcado.
        $this->assertMatchesRegularExpression('/<input type="text"[^>]*id="nombre"[^>]*required/', $html);
    }

    public function test_un_cupo_de_cero_se_rechaza(): void
    {
        // Cero no es "sin tope": es una actividad a la que nadie puede entrar.
        // Para eso esta el interruptor de cerrar el enlace.
        $this->actingAs($this->admin->user)->post(route('actividad-curso-nueva'), [
            'tipo' => Actividad::TALLER,
            'nombre' => 'Taller de cajón',
            'responsable_id' => $this->profesor->id,
            'cupo_maximo' => '0',
        ])->assertSessionHasErrors('cupo_maximo');
    }

    public function test_cada_actividad_nace_con_su_propio_enlace(): void
    {
        $uno = $this->crearActividad(Actividad::TALLER, 'Taller de cajón');
        $otro = $this->crearActividad(Actividad::PROYECCION, 'Banda sinfónica');

        $this->assertSame(32, strlen($uno->token));
        $this->assertNotSame($uno->token, $otro->token);
    }

    public function test_una_actividad_nace_abierta(): void
    {
        // El defecto lo pone la base, y el modelo en memoria no lo ha leido:
        // sin el `$attributes` del modelo, esto responderia null en la misma
        // peticion que la crea.
        $this->assertTrue($this->crearActividad(Actividad::TALLER, 'Taller de cajón')->abierta);
    }

    public function test_se_puede_montar_un_taller_sin_ningun_periodo_en_curso(): void
    {
        // Es de las primeras cosas que hace quien estrena el sistema.
        $this->periodo->update(['activo' => false]);

        $this->actingAs($this->admin->user)->post(route('actividad-curso-nueva'), [
            'tipo' => Actividad::TALLER,
            'nombre' => 'Taller de cajón',
            'responsable_id' => $this->profesor->id,
            'cupo_maximo' => '',
        ])->assertRedirect(route('actividad-curso-lista'));

        $this->assertNull(Actividad::firstWhere('nombre', 'Taller de cajón')->periodo_id);
    }
}
