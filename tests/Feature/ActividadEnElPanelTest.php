<?php

namespace Tests\Feature;

use App\Models\Actividad;
use App\Models\Perfil;
use App\Models\Periodo;
use App\Models\SesionActividad;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * A la asistencia de un curso, taller o grupo de proyeccion se llega DESDE EL
 * PANEL, igual que a la de un grupo de promotoria.
 *
 * ─── Por que existe ────────────────────────────────────────────────────────
 *
 * Hasta el 06/09/2026 no se llegaba. Un grupo de promotoria tiene su enlace
 * «clases» dentro de la promotoria desplegada —un clic desde el Panel—; una
 * actividad estaba detras de un boton suelto y a dos pantallas mas: la lista de
 * actividades, la ficha de una, y ahi la sesion. Tres pantallas contra una.
 *
 * Palabras del usuario: «desde el perfil de administrador no se puede ingresar
 * facilmente a la asistencia de cursos y talleres o de los grupos de
 * proyeccion», y pidio que fuera «como los grupos de las promotorias, que se
 * ingresa haciendo clic en el nombre del grupo y aparece la opcion de ver las
 * clases».
 *
 * ─── LO QUE VIGILA ESTE ARCHIVO ────────────────────────────────────────────
 *
 * 1. Que el enlace a pasar lista este EN LA PORTADA, que es la peticion entera.
 * 2. Que cada quien vea las suyas y solo las suyas.
 * 3. Que sin actividades no se pinte nada: la portada del Panel es la pantalla
 *    mas usada del sistema y una seccion vacia ahi es ruido para todos.
 */
class ActividadEnElPanelTest extends TestCase
{
    use RefreshDatabase;

    private Perfil $admin;

    private Perfil $profesor;

    private Perfil $otroProfesor;

    protected function setUp(): void
    {
        parent::setUp();

        Periodo::create([
            'nombre' => '2026-1',
            'fecha_inicio' => Carbon::today()->subMonth()->toDateString(),
            'fecha_fin' => Carbon::today()->addMonths(4)->toDateString(),
            'activo' => true,
            'matriculas_abiertas' => true,
        ]);

        $this->admin = $this->perfil('jefa', 'administrador');
        $this->profesor = $this->perfil('profe', 'profesor');
        $this->otroProfesor = $this->perfil('otra', 'profesor');
    }

    /**
     * EL ENLACE A PASAR LISTA ESTA EN LA PORTADA DEL PANEL.
     *
     * Es la peticion entera: sin esto hay que pasar por la lista de
     * actividades, la ficha y la sesion.
     */
    public function test_desde_el_panel_se_llega_a_la_lista_de_una_sesion(): void
    {
        $taller = $this->actividad('Taller de cerámica');
        $sesion = $this->sesion($taller, iniciada: true);

        $html = $this->panel($this->admin);

        $this->assertStringContainsString('Taller de cerámica', $html);
        $this->assertStringContainsString(
            route('panel-actividad-lista', $sesion),
            $html,
            'desde el Panel no se llega a pasar lista: hay que dar tres pantallas.'
        );
    }

    /**
     * Una sesion SIN INICIAR no ofrece la lista, y eso es correcto: no hay a
     * quien marcarle nada todavia. Se enseña igual, para que se vea que existe.
     */
    public function test_una_sesion_sin_iniciar_se_ve_pero_no_ofrece_lista(): void
    {
        $taller = $this->actividad('Taller de cerámica');
        $sesion = $this->sesion($taller, iniciada: false);

        $html = $this->panel($this->admin);

        $this->assertStringContainsString('Sin iniciar', $html);
        $this->assertStringNotContainsString(route('panel-actividad-lista', $sesion), $html);
    }

    /** Cada actividad dice de qué tipo es, que es lo que las distingue. */
    public function test_cada_actividad_dice_su_tipo(): void
    {
        $this->actividad('Taller de cerámica', 'taller');
        $this->actividad('Orquesta', 'proyeccion');

        $html = $this->panel($this->admin);

        $this->assertStringContainsString('tipo-chip', $html);
        $this->assertStringContainsString('Taller de cerámica', $html);
        $this->assertStringContainsString('Orquesta', $html);
    }

    /**
     * UN PROFESOR VE LAS SUYAS Y NO LAS DE OTRO.
     *
     * Es la misma puerta que ya tenía la lista de actividades, y hay que
     * comprobarla otra vez aquí: llevar algo a otra pantalla es la forma
     * corriente de dejarse un filtro por el camino.
     */
    public function test_un_profesor_solo_ve_las_suyas(): void
    {
        $this->actividad('Lo mío', responsable: $this->profesor);
        $this->actividad('Lo de otra', responsable: $this->otroProfesor);

        $html = $this->panel($this->profesor);

        $this->assertStringContainsString('Lo mío', $html);
        $this->assertStringNotContainsString('Lo de otra', $html);
    }

    /** Y dirección las ve todas. */
    public function test_direccion_las_ve_todas(): void
    {
        $this->actividad('Lo mío', responsable: $this->profesor);
        $this->actividad('Lo de otra', responsable: $this->otroProfesor);

        $html = $this->panel($this->admin);

        $this->assertStringContainsString('Lo mío', $html);
        $this->assertStringContainsString('Lo de otra', $html);
    }

    /**
     * SIN ACTIVIDADES NO SE PINTA NADA.
     *
     * La portada del Panel es la pantalla más usada del sistema, y mientras la
     * institución no use cursos ni grupos de proyección esta sección no sería
     * más que ruido para todo el mundo. Era ya la regla del botón que había
     * antes y se conserva.
     */
    public function test_sin_actividades_no_se_pinta_la_seccion(): void
    {
        $html = $this->panel($this->admin);

        $this->assertStringNotContainsString('bloque-actividades', $html);
        $this->assertStringNotContainsString('Cursos, talleres y grupos de proyección', $html);
    }

    // ------------------------------------------------------------------
    // Andamiaje
    // ------------------------------------------------------------------

    private function panel(Perfil $quien): string
    {
        return (string) $this->actingAs($quien->user)
            ->get(route('panel'))
            ->assertOk()
            ->getContent();
    }

    private function actividad(string $nombre, string $tipo = 'taller', ?Perfil $responsable = null): Actividad
    {
        return Actividad::create([
            'nombre' => $nombre,
            'tipo' => $tipo,
            'responsable_id' => ($responsable ?? $this->profesor)->id,
            'cupo_maximo' => 20,
        ]);
    }

    private function sesion(Actividad $actividad, bool $iniciada): SesionActividad
    {
        return SesionActividad::create([
            'actividad_id' => $actividad->id,
            'fecha' => Carbon::today()->toDateString(),
            'iniciada_en' => $iniciada ? Carbon::now() : null,
        ]);
    }

    private function perfil(string $username, string $rol): Perfil
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
}
