<?php

namespace Tests\Feature;

use App\Models\Acudiente;
use App\Models\Area;
use App\Models\ConfiguracionInstitucion;
use App\Models\CupoPromotoria;
use App\Models\DatosEstudiante;
use App\Models\Matricula;
use App\Models\Perfil;
use App\Models\Periodo;
use App\Models\Promotoria;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Catalogo del estudiante y el boton "Matricularme".
 */
class CatalogoTest extends TestCase
{
    use RefreshDatabase;

    private Periodo $periodo;
    private Promotoria $violin;
    private Promotoria $danza;
    private Promotoria $teatro;
    private User $user;
    private Perfil $perfil;

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

        $area = Area::create(['nombre' => 'Musica']);
        $this->violin = Promotoria::create(['nombre' => 'Violin', 'area_id' => $area->id]);
        $this->danza = Promotoria::create(['nombre' => 'Danza', 'area_id' => $area->id]);
        $this->teatro = Promotoria::create(['nombre' => 'Teatro', 'area_id' => $area->id]);

        [$this->user, $this->perfil] = $this->crearEstudiante('ana', '1111');
    }

    /** @return array{0: User, 1: Perfil} */
    private function crearEstudiante(string $username, string $documento, ?string $nacimiento = null, bool $conAcudiente = false): array
    {
        $user = User::create(['username' => $username, 'password' => 'x', 'activo' => true]);

        $perfil = Perfil::create([
            'user_id' => $user->id,
            'rol' => 'estudiante',
            'nombre_completo' => ucfirst($username),
            'fecha_nacimiento' => $nacimiento ?? Carbon::today()->subYears(25)->toDateString(),
            'telefono' => '3000000000',
        ]);

        DatosEstudiante::create([
            'perfil_id' => $perfil->id,
            'documento_identidad' => $documento,
            'acudiente_id' => $conAcudiente
                ? Acudiente::create(['nombre' => 'Tutor', 'telefono' => '300'])->id
                : null,
        ]);

        return [$user, $perfil->refresh()];
    }

    // -----------------------------------------------------------------------
    // Acceso
    // -----------------------------------------------------------------------

    public function test_el_estudiante_ve_el_catalogo(): void
    {
        $this->actingAs($this->user)
            ->get(route('promotorias-disponibles'))
            ->assertOk()
            ->assertSee('Violin')
            ->assertSee('Danza')
            ->assertSee('Matricularme');
    }

    /**
     * Apagar el catalogo cierra la URL, no solo el enlace del menu: esconder el
     * enlace dejaria entrar a quien la tenga guardada.
     */
    public function test_con_el_catalogo_apagado_la_url_tambien_se_cierra(): void
    {
        ConfiguracionInstitucion::actual()->update(['promotorias_visibles_para_estudiantes' => false]);

        $this->actingAs($this->user)
            ->get(route('promotorias-disponibles'))
            ->assertRedirect(route('mis-matriculas'))
            ->assertSessionHas('error');
    }

    public function test_el_personal_no_entra_al_catalogo(): void
    {
        $profe = User::create(['username' => 'profe', 'password' => 'x', 'activo' => true]);
        Perfil::create([
            'user_id' => $profe->id, 'rol' => 'profesor', 'nombre_completo' => 'Profe',
            'fecha_nacimiento' => '1980-01-01', 'telefono' => '300',
        ]);

        $this->actingAs($profe)
            ->get(route('promotorias-disponibles'))
            ->assertRedirect(route('post-login'));
    }

    // -----------------------------------------------------------------------
    // Matricularse
    // -----------------------------------------------------------------------

    public function test_matricularse_deja_la_matricula_pendiente(): void
    {
        $this->actingAs($this->user)
            ->post(route('matricular', $this->violin))
            ->assertRedirect(route('promotorias-disponibles'))
            ->assertSessionHas('success');

        $matricula = Matricula::first();
        $this->assertSame(Matricula::PENDIENTE, $matricula->estado);
        $this->assertNull($matricula->grupo_id);
    }

    public function test_con_matriculas_cerradas_no_se_matricula(): void
    {
        $this->periodo->update(['matriculas_abiertas' => false]);

        $this->actingAs($this->user)
            ->post(route('matricular', $this->violin))
            ->assertSessionHas('error');

        $this->assertSame(0, Matricula::count());
    }

    public function test_pasado_el_limite_no_se_matricula_en_otra(): void
    {
        $this->actingAs($this->user)->post(route('matricular', $this->violin));
        $this->actingAs($this->user)->post(route('matricular', $this->danza));

        $this->actingAs($this->user)
            ->post(route('matricular', $this->teatro))
            ->assertSessionHas('error');

        $this->assertSame(2, Matricula::count());
    }

    /** Con el cupo propio lleno, el catalogo lo dice en vez de ofrecer el boton. */
    public function test_sin_cupo_propio_el_boton_desaparece(): void
    {
        $this->actingAs($this->user)->post(route('matricular', $this->violin));
        $this->actingAs($this->user)->post(route('matricular', $this->danza));

        $this->actingAs($this->user)
            ->get(route('promotorias-disponibles'))
            ->assertSee('Sin cupo libre')
            ->assertSee('2 de 2');
    }

    public function test_una_promotoria_llena_se_marca_como_llena(): void
    {
        CupoPromotoria::create([
            'promotoria_id' => $this->violin->id,
            'periodo_id' => $this->periodo->id,
            'cupo_maximo' => 1,
        ]);

        [$otroUser] = $this->crearEstudiante('beto', '2222');
        $this->actingAs($otroUser)->post(route('matricular', $this->violin));

        $this->actingAs($this->user)
            ->get(route('promotorias-disponibles'))
            ->assertSee('Promotoría llena', false);
    }

    public function test_no_se_puede_forzar_la_matricula_en_una_promotoria_llena(): void
    {
        CupoPromotoria::create([
            'promotoria_id' => $this->violin->id,
            'periodo_id' => $this->periodo->id,
            'cupo_maximo' => 1,
        ]);

        [$otroUser] = $this->crearEstudiante('beto', '2222');
        $this->actingAs($otroUser)->post(route('matricular', $this->violin));

        $this->actingAs($this->user)
            ->post(route('matricular', $this->violin))
            ->assertSessionHas('error');

        $this->assertSame(1, Matricula::where('promotoria_id', $this->violin->id)->count());
    }

    /**
     * Volver a una promotoria de la que se retiro REACTIVA la matricula: el
     * indice unico no admite una segunda fila, asi que sin esto el boton no
     * llevaria a ninguna parte.
     */
    public function test_volver_a_una_promotoria_retirada_reactiva_la_matricula(): void
    {
        $this->actingAs($this->user)->post(route('matricular', $this->violin));

        $matricula = Matricula::first();
        $fechaOriginal = $matricula->fecha;
        $matricula->update(['estado' => Matricula::RETIRADA]);

        $this->actingAs($this->user)
            ->post(route('matricular', $this->violin))
            ->assertSessionHas('success');

        // Una sola fila, no dos.
        $this->assertSame(1, Matricula::count());

        $matricula->refresh();
        $this->assertSame(Matricula::PENDIENTE, $matricula->estado);
        // La fecha original se conserva.
        $this->assertEquals($fechaOriginal, $matricula->fecha);
    }

    // -----------------------------------------------------------------------
    // Requisitos del estudiante
    // -----------------------------------------------------------------------

    /** Un menor sin acudiente no se puede matricular por su cuenta. */
    public function test_un_menor_sin_acudiente_no_se_matricula(): void
    {
        [$menorUser] = $this->crearEstudiante(
            'nino', '3333', Carbon::today()->subYears(12)->toDateString()
        );

        $this->actingAs($menorUser)
            ->post(route('matricular', $this->violin))
            ->assertSessionHas('error');

        $this->assertSame(0, Matricula::count());
    }

    public function test_un_menor_con_acudiente_si_se_matricula(): void
    {
        [$menorUser] = $this->crearEstudiante(
            'nina', '4444', Carbon::today()->subYears(12)->toDateString(), conAcudiente: true
        );

        $this->actingAs($menorUser)
            ->post(route('matricular', $this->violin))
            ->assertSessionHas('success');

        $this->assertSame(1, Matricula::count());
    }

    /** Sin datos de estudiante el registro esta incompleto. */
    public function test_sin_datos_de_estudiante_no_se_matricula(): void
    {
        $suelto = User::create(['username' => 'suelto', 'password' => 'x', 'activo' => true]);
        Perfil::create([
            'user_id' => $suelto->id, 'rol' => 'estudiante', 'nombre_completo' => 'Suelto',
            'fecha_nacimiento' => '2000-01-01', 'telefono' => '300',
        ]);

        $this->actingAs($suelto)
            ->post(route('matricular', $this->violin))
            ->assertSessionHas('error');

        $this->assertSame(0, Matricula::count());
    }

    // -----------------------------------------------------------------------
    // Renovacion
    // -----------------------------------------------------------------------

    /** Quien curso un periodo anterior ve la llamada a renovar. */
    public function test_un_estudiante_antiguo_ve_la_llamada_a_renovar(): void
    {
        $anterior = Periodo::create([
            'nombre' => '2025-2',
            'fecha_inicio' => '2025-07-01',
            'fecha_fin' => '2025-12-15',
            'activo' => false,
        ]);

        Matricula::create([
            'estudiante_id' => $this->perfil->id,
            'promotoria_id' => $this->violin->id,
            'periodo_id' => $anterior->id,
            'estado' => Matricula::ACTIVA,
        ]);

        $this->actingAs($this->user)
            ->get(route('promotorias-disponibles'))
            ->assertSee('Renovar mi matrícula', false)
            ->assertSee('2025-2');
    }

    public function test_un_estudiante_nuevo_no_ve_la_llamada_a_renovar(): void
    {
        $this->actingAs($this->user)
            ->get(route('promotorias-disponibles'))
            ->assertDontSee('Renovar mi matrícula', false);
    }

    /**
     * El catalogo NO hace una consulta por promotoria.
     *
     * Es la pantalla a la que cae todo estudiante al iniciar sesion, y en los
     * dias de matricula es cuando mas gente entra a la vez. Antes, los ocupados
     * de cada fila salian de un COUNT propio, asi que abrirla costaba tantas
     * consultas como promotorias hubiera en el catalogo.
     *
     * La prueba no fija un numero —eso se rompe cada vez que alguien toca algo
     * de la pantalla y no dice nada— sino que compara el MISMO catalogo con tres
     * promotorias y con dieciocho. Lo que tiene que quedar plano es la
     * diferencia: si vuelve a haber un COUNT por fila, crece con las filas y
     * esto se pone rojo.
     */
    public function test_el_catalogo_no_consulta_una_vez_por_promotoria(): void
    {
        // Una peticion de calentamiento antes de medir: la primera de todas crea
        // la fila de configuracion de la institucion, y esas consultas de una
        // sola vez ensucian la comparacion.
        $this->consultasDelCatalogo();

        $conTres = $this->consultasDelCatalogo();

        $area = Area::create(['nombre' => 'Artes']);

        for ($i = 1; $i <= 15; $i++) {
            $promotoria = Promotoria::create(['nombre' => "Taller {$i}", 'area_id' => $area->id]);

            // Con matriculas de verdad: si el mapa agrupado se quedara vacio, la
            // prueba pasaria sin haber ejercitado el camino que importa.
            CupoPromotoria::create([
                'promotoria_id' => $promotoria->id,
                'periodo_id' => $this->periodo->id,
                'cupo_maximo' => 10,
            ]);

            [, $otroPerfil] = $this->crearEstudiante("alumno{$i}", "9{$i}00");

            Matricula::create([
                'estudiante_id' => $otroPerfil->id,
                'promotoria_id' => $promotoria->id,
                'periodo_id' => $this->periodo->id,
                'estado' => Matricula::ACTIVA,
            ]);
        }

        $conDieciocho = $this->consultasDelCatalogo();

        $this->assertSame(
            $conTres,
            $conDieciocho,
            "Abrir el catalogo costo {$conTres} consultas con 3 promotorias y "
            ."{$conDieciocho} con 18: hay una consulta por fila."
        );
    }

    /** Cuantas consultas cuesta abrir el catalogo una vez. */
    private function consultasDelCatalogo(): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->actingAs($this->user)
            ->get(route('promotorias-disponibles'))
            ->assertOk();

        $consultas = count(DB::getQueryLog());

        DB::disableQueryLog();

        return $consultas;
    }
}
