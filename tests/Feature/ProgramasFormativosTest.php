<?php

namespace Tests\Feature;

use App\Models\Actividad;
use App\Models\Area;
use App\Models\Perfil;
use App\Models\Periodo;
use App\Models\Promotoria;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * La reorganizacion de Gestion del 01/09/2026.
 *
 * La portada tenia DOCE fichas y cinco llevaban al mismo sitio por caminos
 * distintos: «Departamentos», «Promotorias» y «Grupos» son tres puertas al mismo
 * arbol —el descenso ya existia, con sus migas—, y «Cursos y talleres» y
 * «Grupos de proyeccion» son la otra mitad de lo mismo. «Periodos» era ademas
 * una ficha aparte de «Iniciar / finalizar matriculas», cuando crear un periodo
 * es el primer paso de abrir uno.
 *
 * Lo que estas pruebas vigilan no es el aspecto, que ninguna prueba puede ver,
 * sino las dos cosas que se romperian sin ruido:
 *
 * 1. Que «Programas formativos» siga enseñando las TRES listas. Se arma con
 *    `seccion()` de tres controladores distintos; si uno deja de pasar lo suyo,
 *    la pantalla sigue devolviendo 200 con una seccion vacia.
 * 2. Que al guardar se vuelva ALLI y no al listado plano de cada catalogo. Es lo
 *    que hace que el modal cierre sobre la pantalla desde la que se abrio; con
 *    el destino viejo la pagina se cambia debajo sin que nada falle.
 */
class ProgramasFormativosTest extends TestCase
{
    use RefreshDatabase;

    private Perfil $admin;

    private Area $musica;

    protected function setUp(): void
    {
        parent::setUp();

        Periodo::create([
            'nombre' => '2026-1',
            'fecha_inicio' => '2026-01-15',
            'fecha_fin' => '2026-06-30',
            'activo' => true,
            'matriculas_abiertas' => true,
        ]);

        $this->musica = Area::create(['nombre' => 'Musica']);
        $this->admin = $this->crearPerfil('admin', 'administrador');
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

    // -----------------------------------------------------------------------
    // La pantalla

    public function test_programas_formativos_ensena_las_tres_listas(): void
    {
        Promotoria::create([
            'nombre' => 'Violin',
            'area_id' => $this->musica->id,
            'profesor_id' => $this->admin->id,
        ]);
        Actividad::create([
            'tipo' => Actividad::CURSO, 'nombre' => 'Iniciación a la guitarra',
            'responsable_id' => $this->admin->id, 'abierta' => true,
        ]);
        Actividad::create([
            'tipo' => Actividad::PROYECCION, 'nombre' => 'Orquesta',
            'responsable_id' => $this->admin->id, 'abierta' => true,
        ]);

        $html = $this->actingAs($this->admin->user)
            ->get(route('gestion-programas'))->assertOk()->getContent();

        // Una por sección, y la de departamentos con su conteo de hijos: eso
        // solo sale si `AreaController::seccion()` trajo lo suyo entero.
        $this->assertStringContainsString('Musica', $html);
        $this->assertStringContainsString('1 promotoría', $html);
        $this->assertStringContainsString('Iniciación a la guitarra', $html);
        $this->assertStringContainsString('Orquesta', $html);
    }

    /**
     * El árbol se recorre desde aquí, y las dos listas planas se alcanzan por
     * un enlace: la de grupos es el único sitio donde se filtra por profesor, y
     * por el árbol se llega a los grupos de UNA promotoría.
     */
    public function test_las_listas_planas_siguen_teniendo_puerta(): void
    {
        $html = $this->actingAs($this->admin->user)
            ->get(route('gestion-programas'))->assertOk()->getContent();

        $this->assertStringContainsString(route('promotoria-lista'), $html);
        $this->assertStringContainsString(route('grupo-lista'), $html);
    }

    public function test_un_profesor_no_entra(): void
    {
        $this->actingAs($this->crearPerfil('profe', 'profesor')->user)
            ->get(route('gestion-programas'))
            ->assertRedirect(route('post-login'));
    }

    // -----------------------------------------------------------------------
    // Volver donde estabas

    public function test_al_crear_un_departamento_se_vuelve_a_programas(): void
    {
        $this->actingAs($this->admin->user)
            ->post(route('area-nueva'), ['nombre' => 'Teatro'])
            ->assertRedirect(route('gestion-programas'));
    }

    public function test_al_crear_un_grupo_de_proyeccion_se_vuelve_a_programas(): void
    {
        $this->actingAs($this->admin->user)
            ->post(route('actividad-proyeccion-nueva'), [
                'nombre' => 'Banda',
                'responsable_id' => $this->admin->id,
            ])
            ->assertRedirect(route('gestion-programas'));
    }

    // -----------------------------------------------------------------------
    // La portada, que es de donde salio todo esto

    /**
     * Las cinco fichas que se agruparon ya no estan sueltas en la portada. Se
     * comprueba por la URL y no por el texto: «Departamentos» sigue apareciendo
     * en la portada, dentro del renglon que dice que hay en Programas.
     */
    public function test_la_portada_ya_no_lleva_las_fichas_agrupadas(): void
    {
        $html = $this->actingAs($this->admin->user)
            ->get(route('gestion-inicio'))->assertOk()->getContent();

        foreach ([
            'area-lista', 'promotoria-lista', 'grupo-lista', 'periodo-lista',
            'actividad-curso-lista', 'actividad-proyeccion-lista', 'gestion-cupos',
        ] as $ruta) {
            $this->assertStringNotContainsString(
                'href="'.route($ruta).'"',
                $html,
                "La portada de Gestión todavía lleva una ficha a «{$ruta}»."
            );
        }

        // Y sí lleva las que quedan.
        $this->assertStringContainsString('href="'.route('gestion-programas').'"', $html);
        $this->assertStringContainsString('href="'.route('gestion-matriculas').'"', $html);
    }

    // -----------------------------------------------------------------------
    // Matriculas se lleva los periodos

    public function test_matriculas_lleva_la_lista_de_periodos_y_el_paso_a_los_cupos(): void
    {
        $html = $this->actingAs($this->admin->user)
            ->get(route('gestion-matriculas'))->assertOk()->getContent();

        // Crear un periodo, sin salir de aquí.
        $this->assertStringContainsString(route('periodo-nuevo'), $html);
        // Y repartir los cupos del que está en curso.
        $this->assertStringContainsString('gestion/cupos', $html);
    }

    /**
     * «Poner en curso» pasa de ser un desplegable a una acción de FILA. Importa
     * más de lo que parece: en el desplegable se elegía un nombre suelto
     * —«2026-2»— y había que acordarse de cuándo empezaba ese; en la fila se ve
     * la fecha de lo que se está eligiendo.
     */
    public function test_poner_en_curso_es_una_accion_de_fila(): void
    {
        $otro = Periodo::create([
            'nombre' => '2026-2',
            'fecha_inicio' => '2026-07-01',
            'fecha_fin' => '2026-12-15',
            'activo' => false,
            'matriculas_abiertas' => false,
        ]);

        $html = $this->actingAs($this->admin->user)
            ->get(route('gestion-matriculas'))->assertOk()->getContent();

        $this->assertStringContainsString('value="'.$otro->id.'"', $html);
        $this->assertStringContainsString('Poner en curso', $html);
        // El desplegable de antes ya no está: era la otra forma de hacer esto,
        // y tener las dos sería tener dos sitios donde arreglar el mismo fallo.
        $this->assertStringNotContainsString('id_periodo_en_curso', $html);

        $this->actingAs($this->admin->user)
            ->post(route('gestion-matriculas'), [
                'accion' => 'poner_en_curso',
                'periodo_id' => $otro->id,
            ])
            ->assertSessionHas('success');

        $this->assertTrue($otro->fresh()->activo);
    }

    public function test_al_crear_un_periodo_se_vuelve_a_matriculas(): void
    {
        $this->actingAs($this->admin->user)
            ->post(route('periodo-nuevo'), [
                'nombre' => '2027-1',
                'fecha_inicio' => '2027-01-15',
                'fecha_fin' => '2027-06-30',
            ])
            ->assertRedirect(route('gestion-matriculas'));
    }
}
