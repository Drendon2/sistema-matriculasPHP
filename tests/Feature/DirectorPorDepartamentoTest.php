<?php

namespace Tests\Feature;

use App\Models\Actividad;
use App\Models\Area;
use App\Models\Grupo;
use App\Models\Matricula;
use App\Models\Perfil;
use App\Models\Periodo;
use App\Models\Promotoria;
use App\Models\User;
use App\Support\Permisos;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * UN DIRECTOR SOLO VE SUS DEPARTAMENTOS — 12/09/2026.
 *
 * Lo pidio el usuario: «el director en este momento puede ver todo, pero solo
 * deberia ver las areas a las que se le asigne desde la administracion; si es
 * director de musica solo veria las promotorias de musica».
 *
 * Hasta ese dia el rol `director` era un administrador con menos pantallas: en
 * `PanelController::visiblesPara()` el unico recorte era para el profesor, y en
 * todo lo demas director y administrador iban en el mismo `in_array`.
 *
 * ─── LO QUE VE, PANTALLA POR PANTALLA (pedido asi por el usuario) ──────────
 *
 * - Panel: las promotorias de sus departamentos, y los cursos, talleres y
 *   grupos de proyeccion que se le asignen COMO RESPONSABLE.
 * - Gestion → Alertas: solo las de sus departamentos.
 * - Gestion → Programas: solo sus departamentos.
 * - Gestion → Usuarios: no entra. A las personas llega por los grupos de sus
 *   departamentos.
 * - El informe descargable: solo la informacion de sus departamentos.
 *
 * ─── CADA PRUEBA AFIRMA LAS DOS MITADES ────────────────────────────────────
 *
 * Que vea lo suyo Y que no vea lo ajeno. Sin la segunda, un recorte roto —o
 * quitado— daria verde igual, que es como se pierde una barrera sin enterarse.
 */
class DirectorPorDepartamentoTest extends TestCase
{
    use RefreshDatabase;

    private Perfil $director;

    private Perfil $admin;

    private Area $musica;

    private Area $danza;

    private Promotoria $piano;

    private Promotoria $ballet;

    private Periodo $periodo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->periodo = Periodo::create([
            'nombre' => '2026-2',
            'fecha_inicio' => Carbon::today()->subMonth()->toDateString(),
            'fecha_fin' => Carbon::today()->addMonths(3)->toDateString(),
            'activo' => true,
            'matriculas_abiertas' => true,
        ]);

        $this->musica = Area::create(['nombre' => 'Música']);
        $this->danza = Area::create(['nombre' => 'Danza']);

        $this->piano = Promotoria::create(['nombre' => 'Piano', 'area_id' => $this->musica->id]);
        $this->ballet = Promotoria::create(['nombre' => 'Ballet', 'area_id' => $this->danza->id]);

        $this->admin = $this->perfil('jefa', 'administrador');

        // DIRIGE MUSICA Y NO DANZA: es el caso del encargo, «director de musica».
        $this->director = $this->perfil('dire', 'director');
        $this->dirige($this->director, $this->musica);
    }

    private function perfil(string $username, string $rol): Perfil
    {
        $user = User::create(['username' => $username, 'password' => 'demo1234', 'activo' => true]);

        return Perfil::create([
            'user_id' => $user->id,
            'rol' => $rol,
            'nombre_completo' => ucfirst($username).' Ruiz',
            'fecha_nacimiento' => Carbon::today()->subYears(35)->toDateString(),
            'telefono' => '3001112233',
        ]);
    }

    // ------------------------------------------------------------------
    // La regla, en su unica casa
    // ------------------------------------------------------------------

    /**
     * SIN DEPARTAMENTOS NO VE NADA, y el array vacio no es `null`.
     *
     * Es la mitad que cierra en falso. Si «ninguno asignado» devolviera `null`
     * —«sin recorte»—, el primer director que se cree sin asignarle nada lo veria
     * TODO, que es justo lo que este cambio viene a impedir.
     */
    public function test_sin_departamentos_un_director_no_ve_ninguna_promotoria(): void
    {
        $huerfano = $this->perfil('nuevo', 'director');

        $this->assertSame([], Permisos::areasVisiblesPara($huerfano));
        $this->assertFalse(Permisos::veLaPromotoria($huerfano, $this->piano));

        // Y el administrador sigue sin recorte: `null` y no una lista.
        $this->assertNull(Permisos::areasVisiblesPara($this->admin));
        $this->assertTrue(Permisos::veLaPromotoria($this->admin, $this->ballet));
    }

    /** Ve la suya y no la ajena. Las dos mitades. */
    public function test_solo_gestiona_las_promotorias_de_sus_departamentos(): void
    {
        $this->assertTrue(Permisos::puedeGestionarPromotoria($this->director, $this->piano));
        $this->assertFalse(Permisos::puedeGestionarPromotoria($this->director, $this->ballet));
    }

    // ------------------------------------------------------------------
    // Panel
    // ------------------------------------------------------------------

    public function test_el_panel_solo_trae_sus_promotorias(): void
    {
        $html = (string) $this->actingAs($this->director->user)
            ->get(route('panel'))->assertOk()->getContent();

        $this->assertStringContainsString('Piano', $html);
        $this->assertStringNotContainsString('Ballet', $html, 've una promotoría de otro departamento');
    }

    /**
     * Y NO LLEGA A LA AJENA NI POR URL.
     *
     * Esconder el enlace no cierra la puerta: es regla de esta casa, y sin esta
     * prueba el recorte seria solo cosmetico.
     */
    public function test_no_alcanza_una_promotoria_ajena_por_url(): void
    {
        $this->actingAs($this->director->user)
            ->get(route('panel-promotoria-cuerpo', $this->ballet))
            ->assertNotFound();
    }

    // ------------------------------------------------------------------
    // Gestion
    // ------------------------------------------------------------------

    public function test_programas_solo_lista_sus_departamentos(): void
    {
        $html = (string) $this->actingAs($this->director->user)
            ->get(route('gestion-programas'))->assertOk()->getContent();

        $this->assertStringContainsString('Música', $html);
        $this->assertStringNotContainsString('Danza', $html, 've un departamento que no administra');
    }

    public function test_usuarios_es_solo_del_administrador(): void
    {
        $this->actingAs($this->director->user)
            ->get(route('usuario-lista'))
            ->assertRedirect();

        $this->actingAs($this->admin->user)
            ->get(route('usuario-lista'))
            ->assertOk();
    }

    /**
     * Lo GLOBAL tampoco es suyo: crear o borrar un departamento y abrir o cerrar
     * la ventana de matriculas de toda la casa.
     *
     * Un director de Musica que puede borrar el departamento de Danza no esta
     * acotado a nada.
     */
    public function test_lo_global_es_solo_del_administrador(): void
    {
        foreach (['area-nueva', 'periodo-nuevo', 'gestion-matriculas'] as $ruta) {
            $this->actingAs($this->director->user)
                ->get(route($ruta))
                ->assertRedirect();
        }
    }

    // ------------------------------------------------------------------
    // El informe descargable
    // ------------------------------------------------------------------

    /**
     * EL INFORME SOLO TRAE LO SUYO, y esta es la prueba que mas pesa.
     *
     * Ahi no se esconde un nombre en una lista: se descarga un CSV con el
     * telefono, el acudiente y el documento de cada persona. Un recorte que se
     * olvidara aqui reparte datos de menores de un departamento ajeno.
     */
    public function test_el_informe_no_trae_a_los_de_otro_departamento(): void
    {
        $this->matricular('ana', $this->piano);
        $this->matricular('beto', $this->ballet);

        $csv = $this->descargar(route('informe-estudiantes'));

        $this->assertStringContainsString('Ana', $csv);
        $this->assertStringNotContainsString('Beto', $csv, 'el informe trae a alguien de otro departamento');
    }

    /** Y el administrador sigue bajandolos a todos: la contraparte. */
    public function test_el_administrador_sigue_bajando_el_informe_entero(): void
    {
        $this->matricular('ana', $this->piano);
        $this->matricular('beto', $this->ballet);

        $csv = $this->descargar(route('informe-estudiantes'), $this->admin);

        $this->assertStringContainsString('Ana', $csv);
        $this->assertStringContainsString('Beto', $csv);
    }

    // ------------------------------------------------------------------
    // Y NO SE LLEGA POR URL. Lo que encontro el navegador.
    // ------------------------------------------------------------------

    /**
     * EL RECORTE NO PUEDE SER SOLO DE LISTADOS.
     *
     * Al probarlo en el navegador aparecio el agujero: los listados ya no
     * enseñaban lo ajeno, pero `/gestion/promotorias/12/editar` de un
     * departamento que no administra respondia 200 — y con el sus grupos y el
     * borrado. Esconder el enlace no cierra la puerta, que es regla escrita de
     * esta casa, y aqui la puerta daba a editar y borrar el catalogo de otro.
     *
     * No lo vio ninguna prueba y no lo habria visto leyendo el codigo: el
     * recorte estaba puesto en las consultas de los listados, que es donde uno
     * mira. Lo que faltaba era `buscar()`, por donde pasan editar, actualizar y
     * eliminar.
     *
     * 404 y no 403 en todas: que exista o no lo de otro departamento tampoco es
     * asunto de quien pregunta.
     */
    public function test_no_toca_lo_ajeno_por_url(): void
    {
        $grupoAjeno = Grupo::create([
            'promotoria_id' => $this->ballet->id,
            'nombre' => 'Grupo A',
            'nivel' => 'basico',
            'salon' => 'B1',
            'cupo_maximo' => 20,
        ]);

        $cerradas = [
            route('promotoria-editar', $this->ballet),
            route('promotoria-eliminar', $this->ballet),
            route('grupos-por-promotoria', $this->ballet),
            route('grupo-editar', $grupoAjeno),
            route('grupo-eliminar', $grupoAjeno),
            route('promotorias-por-area', $this->danza),
            route('panel-promotoria-cuerpo', $this->ballet),
        ];

        foreach ($cerradas as $url) {
            // `assertSame` y no `assertNotFound`: el mensaje es lo que dice CUAL
            // de las siete URL se quedo abierta, y `assertNotFound` no admite
            // uno. Sin el, la prueba enrojece sin decir por donde.
            $this->assertSame(
                404,
                $this->actingAs($this->director->user)->get($url)->getStatusCode(),
                "{$url} sigue abierta para un departamento ajeno"
            );
        }
    }

    /**
     * Y LO SUYO SIGUE ABRIENDO, que es la mitad que evita pasarse de vueltas.
     *
     * Un recorte que de paso cerrara lo propio seria peor que el problema: el
     * director se quedaria sin poder trabajar y nadie lo veria hasta que
     * llamara.
     */
    public function test_lo_suyo_sigue_abriendo(): void
    {
        $grupoSuyo = Grupo::create([
            'promotoria_id' => $this->piano->id,
            'nombre' => 'Grupo A',
            'nivel' => 'basico',
            'salon' => 'A1',
            'cupo_maximo' => 20,
        ]);

        $abiertas = [
            route('promotoria-editar', $this->piano),
            route('grupos-por-promotoria', $this->piano),
            route('grupo-editar', $grupoSuyo),
            route('promotorias-por-area', $this->musica),
            route('panel-promotoria-cuerpo', $this->piano),
        ];

        foreach ($abiertas as $url) {
            $this->assertSame(
                200,
                $this->actingAs($this->director->user)->get($url)->getStatusCode(),
                "{$url} se le cerro al director de su propio departamento"
            );
        }
    }

    // ------------------------------------------------------------------
    // Cursos, talleres y grupos de proyeccion
    // ------------------------------------------------------------------

    /**
     * SOLO VE LAS QUE DIRIGE, y el recorte aqui NO es por departamento.
     *
     * Una actividad no cuelga de un departamento: vive en su propia tabla y lo
     * que tiene es una PERSONA responsable. Asi que «asignarle» un curso a un
     * director es ponerlo de responsable — decision del usuario el 12/09/2026,
     * con la alternativa delante de darle un `area_id` a la actividad.
     *
     * Y SE AFIRMAN LAS DOS MITADES. Sin la de abajo, quitar el recorte daria
     * verde igual.
     */
    public function test_solo_ve_las_actividades_que_dirige(): void
    {
        $suya = Actividad::create([
            'tipo' => Actividad::TALLER,
            'nombre' => 'Taller suyo',
            'responsable_id' => $this->director->id,
            'periodo_id' => $this->periodo->id,
        ]);

        $ajena = Actividad::create([
            'tipo' => Actividad::TALLER,
            'nombre' => 'Taller ajeno',
            'responsable_id' => $this->admin->id,
            'periodo_id' => $this->periodo->id,
        ]);

        $html = (string) $this->actingAs($this->director->user)
            ->get(route('actividad-curso-lista'))->assertOk()->getContent();

        $this->assertStringContainsString('Taller suyo', $html);
        $this->assertStringNotContainsString('Taller ajeno', $html);

        // Y tampoco por URL: el listado acotado no basta si `buscar()` no lo
        // esta. Es el mismo agujero que aparecio en las promotorias.
        $this->assertSame(
            404,
            $this->actingAs($this->director->user)
                ->get(route('actividad-curso-editar', $ajena))->getStatusCode(),
            'edita por URL una actividad que no dirige'
        );

        // La suya si la gestiona: no se le cierra lo propio.
        $this->actingAs($this->director->user)
            ->get(route('actividad-curso-editar', $suya))
            ->assertOk();
    }

    /**
     * NO LAS CREA, y esto salio de probarlo con un director de verdad.
     *
     * El formulario le ofrecia poner de responsable a cualquiera de las 29
     * personas, y en cuanto ponia a otro la actividad DESAPARECIA de su vista:
     * no la veia, no la editaba, no la borraba. Crear algo y perderlo en el
     * mismo gesto es peor que no poder crearlo.
     *
     * Decision del usuario el 12/09/2026, con la alternativa delante —dejar que
     * solo se las creara a si mismo—.
     */
    public function test_no_crea_actividades(): void
    {
        foreach (['actividad-curso-nueva', 'actividad-proyeccion-nueva'] as $ruta) {
            $this->actingAs($this->director->user)
                ->get(route($ruta))
                ->assertRedirect();
        }

        $this->actingAs($this->director->user)
            ->post(route('actividad-curso-nueva'), [
                'nombre' => 'Colado',
                'responsable_id' => $this->director->id,
                'clases' => 1,
            ])
            ->assertRedirect();

        $this->assertNull(Actividad::where('nombre', 'Colado')->first());

        // Y el administrador si: la contraparte.
        $this->actingAs($this->admin->user)
            ->get(route('actividad-curso-nueva'))
            ->assertOk();
    }

    /** Y el boton tampoco se le pinta: un boton que rebota es un boton roto. */
    public function test_no_se_le_pinta_el_boton_de_crear_actividades(): void
    {
        $html = (string) $this->actingAs($this->director->user)
            ->get(route('gestion-programas'))->assertOk()->getContent();

        $this->assertStringNotContainsString(route('actividad-curso-nueva'), $html);
        $this->assertStringNotContainsString(route('actividad-proyeccion-nueva'), $html);

        $delAdmin = (string) $this->actingAs($this->admin->user)
            ->get(route('gestion-programas'))->assertOk()->getContent();

        $this->assertStringContainsString(route('actividad-curso-nueva'), $delAdmin);
    }

    private function matricular(string $username, Promotoria $promotoria): Matricula
    {
        $estudiante = $this->perfil($username, 'estudiante');

        $matricula = new Matricula([
            'estudiante_id' => $estudiante->id,
            'promotoria_id' => $promotoria->id,
            'periodo_id' => $this->periodo->id,
            'estado' => Matricula::ACTIVA,
        ]);
        $matricula->save();

        return $matricula;
    }

    private function descargar(string $url, ?Perfil $quien = null): string
    {
        $respuesta = $this->actingAs(($quien ?? $this->director)->user)->get($url);
        $respuesta->assertOk();

        return $respuesta->streamedContent();
    }
}
