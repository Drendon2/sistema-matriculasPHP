<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Grupo;
use App\Models\Perfil;
use App\Models\Periodo;
use App\Models\Promotoria;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Volver al Panel desde el formulario de un grupo, sin perder donde se estaba.
 *
 * ─── LO REPORTO EL USUARIO PROBANDO, EL 10/09/2026 ──────────────────────────
 *
 * «Cuando creo un grupo la pagina se recarga toda y me devuelve al panel con
 * todo cerrado, ademas cuando intento desplegar la promotoria se queda
 * cargando... pero nunca carga, toca volver a cargar la pagina.»
 *
 * Eran DOS fallos distintos con una causa comun, y ninguno se ve desde PHP:
 *
 * 1. El Panel volvia plegado del todo, asi que lo primero era buscar otra vez
 *    donde se estaba. Eso es lo que arregla este archivo: el `abrir` de la URL.
 *
 * 2. Y al desplegar se quedaba en «Cargando…» PARA SIEMPRE. La causa esta en
 *    `acciones.js` y se arreglo alli: el formulario del grupo vive dentro de
 *    `<main>`, asi que el envio se intercepta y se repinta SOLO `<main>` — pero
 *    `panel.js` vive FUERA, en la pila de scripts, y la pagina de destino se
 *    quedaba sin el. La URL era la del Panel, el contenido el del Panel, y los
 *    scripts los de la pagina anterior. **Eso no lo puede ver ninguna prueba de
 *    PHP**: pasa en el navegador, despues de la respuesta. Se comprobo abriendo
 *    la pagina.
 */
class VolverAlPanelTest extends TestCase
{
    use RefreshDatabase;

    private Promotoria $violin;

    private Promotoria $danza;

    private Perfil $profesor;

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

        $area = Area::create(['nombre' => 'Musica']);
        $otraArea = Area::create(['nombre' => 'Danza']);
        $this->profesor = $this->perfil('profe', 'profesor');

        $this->violin = Promotoria::create([
            'nombre' => 'Violin', 'area_id' => $area->id, 'profesor_id' => $this->profesor->id,
        ]);
        $this->danza = Promotoria::create([
            'nombre' => 'Contemporanea', 'area_id' => $otraArea->id, 'profesor_id' => $this->profesor->id,
        ]);
    }

    /** Crear un grupo devuelve al Panel APUNTANDO a su promotoria. */
    public function test_crear_un_grupo_vuelve_al_panel_por_su_promotoria(): void
    {
        $this->actingAs($this->profesor->user)
            ->post(route('panel-grupo-nuevo', $this->violin), $this->formulario('Lunes tarde'))
            ->assertRedirect(route('panel', ['abrir' => $this->violin->id]));
    }

    /** Y editarlo, tambien. */
    public function test_editar_un_grupo_vuelve_al_panel_por_su_promotoria(): void
    {
        $grupo = $this->grupo($this->violin, 'Lunes tarde');

        $this->actingAs($this->profesor->user)
            ->post(route('panel-grupo-editar', $grupo), $this->formulario('Martes tarde'))
            ->assertRedirect(route('panel', ['abrir' => $this->violin->id]));
    }

    /** Y eliminarlo: la promotoria sigue existiendo aunque el grupo no. */
    public function test_eliminar_un_grupo_vuelve_al_panel_por_su_promotoria(): void
    {
        $grupo = $this->grupo($this->violin, 'Lunes tarde');

        $this->actingAs($this->profesor->user)
            ->post(route('panel-grupo-eliminar', $grupo))
            ->assertRedirect(route('panel', ['abrir' => $this->violin->id]));
    }

    /**
     * EL PANEL ABRE ESA PROMOTORIA, Y SU DEPARTAMENTO CON ELLA.
     *
     * Lo segundo no sobra: sin el, la promotoria queda abierta dentro de un
     * plegado cerrado —o sea invisible— y eso es PEOR que dejarla cerrada,
     * porque parece que la orden no se obedecio.
     */
    public function test_el_panel_abre_la_promotoria_pedida_y_su_departamento(): void
    {
        $html = $this->actingAs($this->profesor->user)
            ->get(route('panel', ['abrir' => $this->violin->id]))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/id="promotoria-'.$this->violin->id.'"[^>]*\sopen/s',
            $this->sinSaltos($html),
            'no abrio la promotoria que se le pidio.'
        );
        $this->assertMatchesRegularExpression(
            '/id="departamento-musica"[^>]*\sopen/s',
            $this->sinSaltos($html),
            'la dejo abierta dentro de un departamento cerrado, o sea invisible.'
        );
    }

    /** Y NO abre las demas: se vuelve a una, no se despliega el Panel entero. */
    public function test_no_abre_las_otras_promotorias(): void
    {
        $html = $this->sinSaltos(
            $this->actingAs($this->profesor->user)
                ->get(route('panel', ['abrir' => $this->violin->id]))
                ->assertOk()->getContent()
        );

        $this->assertDoesNotMatchRegularExpression(
            '/id="promotoria-'.$this->danza->id.'"[^>]*\sopen/s',
            $html
        );
    }

    /** Sin `abrir`, el Panel se comporta como siempre. */
    public function test_sin_abrir_no_cambia_nada(): void
    {
        $html = $this->sinSaltos(
            $this->actingAs($this->profesor->user)->get(route('panel'))->assertOk()->getContent()
        );

        $this->assertDoesNotMatchRegularExpression(
            '/id="promotoria-'.$this->violin->id.'"[^>]*\sopen/s',
            $html
        );
    }

    // --------------------------------------------------------------------

    /** El atributo puede caer en otra linea que el id: se aplanan los saltos. */
    private function sinSaltos(string $html): string
    {
        return (string) preg_replace('/\s+/', ' ', $html);
    }

    /**
     * @return array<string, mixed>
     */
    private function formulario(string $nombre): array
    {
        return [
            'nombre' => $nombre,
            'nivel' => 'basico',
            'salon' => 'A1',
            'cupo_maximo' => 10,
            'sesiones' => [
                1 => ['activo' => '1', 'desde' => '16:00', 'hasta' => '18:00'],
            ],
        ];
    }

    private function grupo(Promotoria $promotoria, string $nombre): Grupo
    {
        return Grupo::create([
            'promotoria_id' => $promotoria->id,
            'nombre' => $nombre,
            'nivel' => 'basico',
            'salon' => 'A1',
            'cupo_maximo' => 10,
        ]);
    }

    private function perfil(string $nombre, string $rol): Perfil
    {
        $user = User::create(['username' => $nombre, 'password' => 'demo1234', 'activo' => true]);

        return Perfil::create([
            'user_id' => $user->id,
            'rol' => $rol,
            'nombre_completo' => ucfirst($nombre),
            'fecha_nacimiento' => Carbon::today()->subYears(30)->toDateString(),
            'telefono' => '3000000000',
        ]);
    }
}
