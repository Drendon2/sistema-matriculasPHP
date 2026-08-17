<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Clase;
use App\Models\ConfiguracionInstitucion;
use App\Models\CupoPromotoria;
use App\Models\DatosEstudiante;
use App\Models\Grupo;
use App\Models\Matricula;
use App\Models\Perfil;
use App\Models\Periodo;
use App\Models\Promotoria;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * El Panel: la contraparte de lo que ve el estudiante.
 *
 * Lo que se prueba aqui, en orden de importancia: que confirmar y rechazar
 * cierren de verdad el ciclo de una solicitud, que el limite de promotorias se
 * reaplique en la confirmacion (y no solo al matricularse), y que un profesor no
 * pueda tocar promotorias que no dicta.
 */
class PanelTest extends TestCase
{
    use RefreshDatabase;

    private Periodo $periodo;
    private Promotoria $violin;
    private Promotoria $danza;
    private Perfil $profesor;
    private Perfil $director;
    private Perfil $estudiante;

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

        $this->profesor = $this->crearPerfil('profe', 'profesor');
        $this->director = $this->crearPerfil('dire', 'director');
        $this->estudiante = $this->crearEstudiante('ana');

        $this->violin = Promotoria::create([
            'nombre' => 'Violin',
            'area_id' => $area->id,
            'profesor_id' => $this->profesor->id,
        ]);

        // Sin profesor asignado: es la que el profesor NO puede tocar.
        $this->danza = Promotoria::create(['nombre' => 'Danza', 'area_id' => $area->id]);
    }

    private function crearPerfil(string $username, string $rol): Perfil
    {
        $user = User::create(['username' => $username, 'password' => 'x', 'activo' => true]);

        return Perfil::create([
            'user_id' => $user->id,
            'rol' => $rol,
            'nombre_completo' => ucfirst($username),
            'fecha_nacimiento' => Carbon::today()->subYears(35)->toDateString(),
            'telefono' => '3000000000',
        ]);
    }

    private function crearEstudiante(string $username): Perfil
    {
        $perfil = $this->crearPerfil($username, 'estudiante');

        DatosEstudiante::create([
            'perfil_id' => $perfil->id,
            'documento_identidad' => '1'.$perfil->id,
        ]);

        return $perfil;
    }

    private function matricular(Promotoria $promotoria, ?Perfil $estudiante = null, string $estado = Matricula::PENDIENTE): Matricula
    {
        $matricula = new Matricula([
            'estudiante_id' => ($estudiante ?? $this->estudiante)->id,
            'promotoria_id' => $promotoria->id,
            'periodo_id' => $this->periodo->id,
            'estado' => $estado,
        ]);
        $matricula->save();

        return $matricula;
    }

    // -----------------------------------------------------------------------
    // Puerta de entrada
    // -----------------------------------------------------------------------

    public function test_un_estudiante_no_entra_al_panel(): void
    {
        $this->actingAs($this->estudiante->user)
            ->get(route('panel'))
            ->assertRedirect(route('post-login'));
    }

    public function test_el_profesor_solo_ve_las_promotorias_que_dicta(): void
    {
        $this->matricular($this->violin);
        $this->matricular($this->danza, $this->crearEstudiante('samu'));

        $respuesta = $this->actingAs($this->profesor->user)->get(route('panel'));

        $respuesta->assertOk();
        $respuesta->assertSee('Violin');
        $respuesta->assertDontSee('Danza');
    }

    public function test_el_director_ve_todas(): void
    {
        $respuesta = $this->actingAs($this->director->user)->get(route('panel'));

        $respuesta->assertOk();
        $respuesta->assertSee('Violin');
        $respuesta->assertSee('Danza');
    }

    /**
     * El indice manda solo los titulos: el cuerpo de cada promotoria llega al
     * desplegarla. Antes iba todo dentro y un director descargaba el catalogo
     * entero para ver una lista plegada.
     */
    public function test_el_indice_no_trae_el_cuerpo_de_las_promotorias(): void
    {
        $this->matricular($this->violin);

        $respuesta = $this->actingAs($this->director->user)->get(route('panel'));

        $respuesta->assertOk();
        $respuesta->assertSee('Violin');
        // Ni los estudiantes ni los controles: eso vive en el cuerpo.
        $respuesta->assertDontSee('Pendientes de confirmación', false);
        $respuesta->assertDontSee('Ana');
    }

    public function test_el_cuerpo_trae_lo_que_el_indice_ya_no_manda(): void
    {
        $this->matricular($this->violin);

        $this->actingAs($this->director->user)
            ->get(route('panel-promotoria-cuerpo', $this->violin))
            ->assertOk()
            ->assertSee('Pendientes de confirmación', false)
            ->assertSee('Ana')
            ->assertSee('Confirmar');
    }

    /**
     * Esconder una promotoria de la lista no cierra su URL: la puerta del cuerpo
     * tiene que ser la misma que la del indice.
     */
    public function test_un_profesor_no_lee_el_cuerpo_de_una_promotoria_ajena(): void
    {
        $this->matricular($this->danza, $this->crearEstudiante('samu'));

        $this->actingAs($this->profesor->user)
            ->get(route('panel-promotoria-cuerpo', $this->danza))
            ->assertNotFound();
    }

    public function test_un_estudiante_no_lee_el_cuerpo(): void
    {
        $this->actingAs($this->estudiante->user)
            ->get(route('panel-promotoria-cuerpo', $this->violin))
            ->assertRedirect(route('post-login'));
    }

    // -----------------------------------------------------------------------
    // Confirmar y rechazar
    // -----------------------------------------------------------------------

    public function test_confirmar_activa_la_matricula(): void
    {
        $matricula = $this->matricular($this->violin);

        $this->actingAs($this->profesor->user)
            ->post(route('panel-confirmar-matricula', $matricula))
            ->assertRedirect(route('panel'))
            ->assertSessionHas('success');

        $this->assertSame(Matricula::ACTIVA, $matricula->fresh()->estado);
    }

    public function test_rechazar_la_deja_retirada(): void
    {
        $matricula = $this->matricular($this->violin);

        $this->actingAs($this->profesor->user)
            ->post(route('panel-rechazar-matricula', $matricula))
            ->assertRedirect(route('panel'));

        $this->assertSame(Matricula::RETIRADA, $matricula->fresh()->estado);
    }

    /**
     * Rechazar libera la ranura: es lo que le permite al estudiante volver a
     * pedir otra promotoria despues de que le digan que no.
     */
    public function test_rechazar_libera_el_cupo_del_estudiante(): void
    {
        $matricula = $this->matricular($this->violin);

        $this->actingAs($this->profesor->user)
            ->post(route('panel-rechazar-matricula', $matricula));

        $this->assertSame(
            0,
            Matricula::promotoriasOcupadas($this->estudiante->id, $this->periodo->id)
        );
    }

    public function test_una_matricula_ya_activa_no_se_vuelve_a_confirmar(): void
    {
        $matricula = $this->matricular($this->violin, estado: Matricula::ACTIVA);

        $this->actingAs($this->profesor->user)
            ->post(route('panel-confirmar-matricula', $matricula))
            ->assertSessionMissing('success');

        $this->assertSame(Matricula::ACTIVA, $matricula->fresh()->estado);
    }

    /**
     * Entre la solicitud y la confirmacion pueden pasar cosas: que el estudiante
     * llene su cupo por otro lado, o que bajen el limite. Confirmar sin mirar lo
     * dejaria por encima del tope.
     */
    public function test_no_se_confirma_si_el_estudiante_ya_esta_en_el_tope(): void
    {
        // Las dos matriculas nacen con el limite en 2, que es cuando el
        // estudiante pudo pedirlas. Bajarlo DESPUES es justo el caso: la
        // solicitud ya existe y confirmarla lo dejaria por encima del tope.
        $this->matricular($this->danza, estado: Matricula::ACTIVA);
        $pendiente = $this->matricular($this->violin);

        $configuracion = \App\Models\ConfiguracionInstitucion::actual();
        $configuracion->limite_promotorias_por_periodo = 1;
        $configuracion->save();

        $this->actingAs($this->director->user)
            ->post(route('panel-confirmar-matricula', $pendiente))
            ->assertSessionHas('error');

        $this->assertSame(Matricula::PENDIENTE, $pendiente->fresh()->estado);
    }

    public function test_un_profesor_no_confirma_matriculas_de_otra_promotoria(): void
    {
        $matricula = $this->matricular($this->danza);

        $this->actingAs($this->profesor->user)
            ->post(route('panel-confirmar-matricula', $matricula))
            ->assertSessionHas('error');

        $this->assertSame(Matricula::PENDIENTE, $matricula->fresh()->estado);
    }

    // -----------------------------------------------------------------------
    // Cupo de la promotoria
    // -----------------------------------------------------------------------

    public function test_fijar_el_cupo_crea_la_fila(): void
    {
        $this->actingAs($this->profesor->user)
            ->post(route('panel-cupo-promotoria', $this->violin), ['cupo_maximo' => '12'])
            ->assertSessionHas('success');

        $this->assertSame(12, $this->violin->cupoEn($this->periodo));
    }

    public function test_dejar_el_cupo_en_blanco_quita_el_tope(): void
    {
        CupoPromotoria::create([
            'promotoria_id' => $this->violin->id,
            'periodo_id' => $this->periodo->id,
            'cupo_maximo' => 3,
        ]);

        $this->actingAs($this->profesor->user)
            ->post(route('panel-cupo-promotoria', $this->violin), ['cupo_maximo' => '']);

        $this->assertNull($this->violin->fresh()->cupoEn($this->periodo));
    }

    /**
     * Bajar el cupo por debajo de lo ocupado es legitimo y no retira a nadie:
     * solo cierra la puerta. Se avisa porque la cifra queda en "2 / 1" y sin
     * explicacion parece un error del sistema.
     */
    public function test_bajar_el_cupo_por_debajo_de_lo_ocupado_avisa_pero_no_retira(): void
    {
        $this->matricular($this->violin, estado: Matricula::ACTIVA);
        $this->matricular($this->violin, $this->crearEstudiante('samu'), Matricula::ACTIVA);

        $this->actingAs($this->profesor->user)
            ->post(route('panel-cupo-promotoria', $this->violin), ['cupo_maximo' => '1'])
            ->assertSessionHas('error');

        $this->assertSame(2, $this->violin->ocupadosEn($this->periodo));
    }

    public function test_el_cupo_no_admite_negativos(): void
    {
        $this->actingAs($this->profesor->user)
            ->post(route('panel-cupo-promotoria', $this->violin), ['cupo_maximo' => '-3'])
            ->assertSessionHas('error');

        $this->assertNull($this->violin->cupoEn($this->periodo));
    }

    // -----------------------------------------------------------------------
    // Grupos
    // -----------------------------------------------------------------------

    public function test_se_crea_un_grupo(): void
    {
        $this->actingAs($this->profesor->user)
            ->post(route('panel-grupo-nuevo', $this->violin), [
                'nivel' => 'basico',
                'horario' => 'Martes 4 p. m.',
                'salon' => 'A1',
                'cupo_maximo' => 10,
            ])
            ->assertSessionHas('success');

        $this->assertSame(1, $this->violin->grupos()->count());
    }

    public function test_no_hay_dos_grupos_del_mismo_nivel(): void
    {
        Grupo::create([
            'promotoria_id' => $this->violin->id,
            'nivel' => 'basico',
            'horario' => 'Lunes',
            'salon' => 'A1',
            'cupo_maximo' => 5,
        ]);

        $this->actingAs($this->profesor->user)
            ->post(route('panel-grupo-nuevo', $this->violin), [
                'nivel' => 'basico',
                'horario' => 'Martes',
                'salon' => 'A2',
                'cupo_maximo' => 5,
            ])
            ->assertSessionHasErrors('nivel');

        $this->assertSame(1, $this->violin->grupos()->count());
    }

    /**
     * La matricula apunta al grupo con RESTRICT: borrar un horario no puede
     * llevarse por delante la inscripcion de nadie.
     */
    public function test_no_se_elimina_un_grupo_con_estudiantes(): void
    {
        $grupo = Grupo::create([
            'promotoria_id' => $this->violin->id,
            'nivel' => 'basico',
            'horario' => 'Lunes',
            'salon' => 'A1',
            'cupo_maximo' => 5,
        ]);

        $matricula = $this->matricular($this->violin, estado: Matricula::ACTIVA);
        $matricula->grupo_id = $grupo->id;
        $matricula->save();

        $this->actingAs($this->profesor->user)
            ->post(route('panel-grupo-eliminar', $grupo))
            ->assertSessionHas('error');

        $this->assertNotNull($grupo->fresh());
    }

    // -----------------------------------------------------------------------
    // Reparto en grupos
    // -----------------------------------------------------------------------

    private function crearGrupo(int $cupo = 5, string $nivel = 'basico'): Grupo
    {
        return Grupo::create([
            'promotoria_id' => $this->violin->id,
            'nivel' => $nivel,
            'horario' => 'Lunes 4 p. m.',
            'salon' => 'A1',
            'cupo_maximo' => $cupo,
        ]);
    }

    public function test_se_asigna_un_estudiante_a_un_grupo(): void
    {
        $grupo = $this->crearGrupo();
        $matricula = $this->matricular($this->violin, estado: Matricula::ACTIVA);

        $this->actingAs($this->profesor->user)
            ->post(route('panel-asignar-grupo', $matricula), ['grupo_id' => $grupo->id])
            ->assertSessionHas('success');

        $this->assertSame($grupo->id, $matricula->fresh()->grupo_id);
    }

    public function test_asignar_sin_grupo_lo_saca_del_horario(): void
    {
        $grupo = $this->crearGrupo();
        $matricula = $this->matricular($this->violin, estado: Matricula::ACTIVA);
        $matricula->grupo_id = $grupo->id;
        $matricula->save();

        $this->actingAs($this->profesor->user)
            ->post(route('panel-asignar-grupo', $matricula), ['grupo_id' => '']);

        $this->assertNull($matricula->fresh()->grupo_id);
        $this->assertSame(Matricula::ACTIVA, $matricula->fresh()->estado);
    }

    public function test_no_se_asigna_a_un_grupo_lleno(): void
    {
        $grupo = $this->crearGrupo(cupo: 1);

        $primera = $this->matricular($this->violin, estado: Matricula::ACTIVA);
        $primera->grupo_id = $grupo->id;
        $primera->save();

        $segunda = $this->matricular($this->violin, $this->crearEstudiante('samu'), Matricula::ACTIVA);

        $this->actingAs($this->profesor->user)
            ->post(route('panel-asignar-grupo', $segunda), ['grupo_id' => $grupo->id])
            ->assertSessionHas('error');

        $this->assertNull($segunda->fresh()->grupo_id);
    }

    /**
     * El lote es todo o nada: dejar a unos dentro y a otros fuera obliga a
     * averiguar a mano cuales entraron, que es justo el trabajo que el lote
     * viene a evitar.
     */
    public function test_el_lote_que_no_cabe_no_asigna_a_nadie(): void
    {
        $grupo = $this->crearGrupo(cupo: 2);

        $matriculas = [$this->matricular($this->violin, estado: Matricula::ACTIVA)];

        foreach (['samu', 'beto'] as $nombre) {
            $matriculas[] = $this->matricular(
                $this->violin,
                $this->crearEstudiante($nombre),
                Matricula::ACTIVA
            );
        }

        $this->actingAs($this->profesor->user)
            ->post(route('panel-asignar-grupo-lote', $this->violin), [
                'grupo_id' => $grupo->id,
                'matricula_ids' => array_map(fn (Matricula $m) => $m->id, $matriculas),
            ])
            ->assertSessionHas('error');

        foreach ($matriculas as $matricula) {
            $this->assertNull($matricula->fresh()->grupo_id);
        }
    }

    public function test_el_lote_que_cabe_los_asigna_a_todos(): void
    {
        $grupo = $this->crearGrupo(cupo: 5);

        $matriculas = [
            $this->matricular($this->violin, estado: Matricula::ACTIVA),
            $this->matricular($this->violin, $this->crearEstudiante('samu'), Matricula::ACTIVA),
        ];

        $this->actingAs($this->profesor->user)
            ->post(route('panel-asignar-grupo-lote', $this->violin), [
                'grupo_id' => $grupo->id,
                'matricula_ids' => array_map(fn (Matricula $m) => $m->id, $matriculas),
            ])
            ->assertSessionHas('success');

        foreach ($matriculas as $matricula) {
            $this->assertSame($grupo->id, $matricula->fresh()->grupo_id);
        }
    }

    // -----------------------------------------------------------------------
    // Pendientes en lote
    // -----------------------------------------------------------------------

    public function test_confirmar_en_lote_activa_las_marcadas(): void
    {
        $matriculas = [
            $this->matricular($this->violin),
            $this->matricular($this->violin, $this->crearEstudiante('samu')),
            $this->matricular($this->violin, $this->crearEstudiante('beto')),
        ];

        $this->actingAs($this->profesor->user)
            ->post(route('panel-pendientes-lote', $this->violin), [
                'decision' => 'confirmar',
                'matricula_ids' => array_map(fn (Matricula $m) => $m->id, $matriculas),
            ])
            ->assertSessionHas('success');

        foreach ($matriculas as $matricula) {
            $this->assertSame(Matricula::ACTIVA, $matricula->fresh()->estado);
        }
    }

    public function test_rechazar_en_lote_las_retira(): void
    {
        $matriculas = [
            $this->matricular($this->violin),
            $this->matricular($this->violin, $this->crearEstudiante('samu')),
        ];

        $this->actingAs($this->profesor->user)
            ->post(route('panel-pendientes-lote', $this->violin), [
                'decision' => 'rechazar',
                'matricula_ids' => array_map(fn (Matricula $m) => $m->id, $matriculas),
            ])
            ->assertSessionHas('success');

        foreach ($matriculas as $matricula) {
            $this->assertSame(Matricula::RETIRADA, $matricula->fresh()->estado);
        }
    }

    /**
     * A diferencia del reparto por grupo, esto NO es todo o nada.
     *
     * Cada matricula falla por su cuenta y por un motivo que se puede nombrar,
     * asi que deshacer las que si valian para castigar a la que no seria peor
     * que resolverlas. Se confirma lo que se puede y se dice quien quedo fuera.
     */
    /**
     * El escenario tiene que montarse BAJANDO el limite, y eso importa: mientras
     * el limite no cambie, el indice unico sobre la ranura impide siquiera crear
     * la solicitud que sobra. La unica forma de llegar a una pendiente que no se
     * puede confirmar es que el administrador recorte el cupo despues —que es
     * justo el caso que el `confirmar` de a uno ya contemplaba.
     */
    public function test_confirmar_en_lote_salta_a_quien_no_tiene_cupo_y_sigue(): void
    {
        // Con el limite en 2, este alcanza a pedir dos promotorias.
        $lleno = $this->crearEstudiante('samu');
        $suyaViolin = $this->matricular($this->violin, $lleno);
        $this->matricular($this->danza, $lleno);

        $libre = $this->matricular($this->violin, $this->crearEstudiante('beto'));

        // Y ahora direccion lo baja a una: las dos suyas dejan de caber.
        ConfiguracionInstitucion::actual()->update(['limite_promotorias_por_periodo' => 1]);

        $respuesta = $this->actingAs($this->profesor->user)
            ->post(route('panel-pendientes-lote', $this->violin), [
                'decision' => 'confirmar',
                'matricula_ids' => [$suyaViolin->id, $libre->id],
            ]);

        // La que cabia entro; la otra sigue esperando, no se perdio.
        $this->assertSame(Matricula::ACTIVA, $libre->fresh()->estado);
        $this->assertSame(Matricula::PENDIENTE, $suyaViolin->fresh()->estado);

        // Y el aviso dice a QUIEN, no solo cuantos: con veinte filas, «1 de 2»
        // obliga a comparar la lista a ojo.
        $respuesta->assertSessionHas(
            'error',
            fn (string $mensaje) => str_contains($mensaje, $lleno->nombre_completo)
        );
    }

    /** Lo que no sea de esta promotoria o ya este resuelto no entra en el lote. */
    public function test_el_lote_de_pendientes_ignora_lo_ajeno_y_lo_ya_resuelto(): void
    {
        $ajena = $this->matricular($this->danza, $this->crearEstudiante('samu'));
        $yaActiva = $this->matricular($this->violin, $this->crearEstudiante('beto'), Matricula::ACTIVA);
        $buena = $this->matricular($this->violin);

        $this->actingAs($this->profesor->user)
            ->post(route('panel-pendientes-lote', $this->violin), [
                'decision' => 'rechazar',
                'matricula_ids' => [$ajena->id, $yaActiva->id, $buena->id],
            ]);

        $this->assertSame(Matricula::PENDIENTE, $ajena->fresh()->estado);
        $this->assertSame(Matricula::ACTIVA, $yaActiva->fresh()->estado);
        $this->assertSame(Matricula::RETIRADA, $buena->fresh()->estado);
    }

    public function test_un_profesor_ajeno_no_resuelve_pendientes_en_lote(): void
    {
        $otro = $this->crearPerfil('otro', 'profesor');
        $matricula = $this->matricular($this->violin);

        $this->actingAs($otro->user)
            ->post(route('panel-pendientes-lote', $this->violin), [
                'decision' => 'confirmar',
                'matricula_ids' => [$matricula->id],
            ])
            ->assertSessionHas('error');

        $this->assertSame(Matricula::PENDIENTE, $matricula->fresh()->estado);
    }

    // -----------------------------------------------------------------------
    // Clases y asistencia
    // -----------------------------------------------------------------------

    /**
     * Registrar una clase es de quien la dicta, no de quien administra el
     * catalogo: un registro que puede reescribir alguien que no dio la clase
     * deja de ser evidencia de lo que paso.
     */
    public function test_el_director_no_abre_clases_de_una_promotoria_ajena(): void
    {
        $grupo = $this->crearGrupo();

        $this->actingAs($this->director->user)
            ->post(route('panel-clase-nueva', $grupo))
            ->assertSessionHas('error');

        $this->assertSame(0, Clase::count());
    }

    public function test_iniciar_clase_lleva_a_pasar_lista(): void
    {
        $grupo = $this->crearGrupo();

        $this->actingAs($this->profesor->user)
            ->post(route('panel-clase-nueva', $grupo))
            ->assertRedirect(route('clase-asistencia', Clase::first()));

        $this->assertSame(1, Clase::count());
    }

    /**
     * Dos registros el mismo dia son casi siempre el mismo boton pulsado dos
     * veces, y partir la asistencia del dia en dos listas a medias es peor que
     * cualquier caso raro que esto impida.
     */
    public function test_dos_clases_el_mismo_dia_son_la_misma(): void
    {
        $grupo = $this->crearGrupo();

        $this->actingAs($this->profesor->user)->post(route('panel-clase-nueva', $grupo));
        $this->actingAs($this->profesor->user)->post(route('panel-clase-nueva', $grupo));

        $this->assertSame(1, Clase::count());
    }

    public function test_se_guarda_la_asistencia(): void
    {
        $grupo = $this->crearGrupo();
        $matricula = $this->matricular($this->violin, estado: Matricula::ACTIVA);
        $matricula->grupo_id = $grupo->id;
        $matricula->save();

        $clase = Clase::abrir($grupo, $this->periodo, $this->profesor);

        $this->actingAs($this->profesor->user)
            ->post(route('clase-asistencia', $clase), ["estado_{$matricula->id}" => 'asistio'])
            ->assertSessionHas('success');

        $this->assertSame('asistio', $clase->asistencias()->first()->estado);
    }

    /** Se puede corregir cuantas veces haga falta: es el caso normal. */
    public function test_la_asistencia_se_puede_corregir(): void
    {
        $grupo = $this->crearGrupo();
        $matricula = $this->matricular($this->violin, estado: Matricula::ACTIVA);
        $matricula->grupo_id = $grupo->id;
        $matricula->save();

        $clase = Clase::abrir($grupo, $this->periodo, $this->profesor);

        $this->actingAs($this->profesor->user)
            ->post(route('clase-asistencia', $clase), ["estado_{$matricula->id}" => 'falto']);
        $this->actingAs($this->profesor->user)
            ->post(route('clase-asistencia', $clase), ["estado_{$matricula->id}" => 'excusa']);

        $this->assertSame(1, $clase->asistencias()->count());
        $this->assertSame('excusa', $clase->asistencias()->first()->estado);
    }

    public function test_el_director_ve_la_asistencia_pero_no_la_escribe(): void
    {
        $grupo = $this->crearGrupo();
        $matricula = $this->matricular($this->violin, estado: Matricula::ACTIVA);
        $matricula->grupo_id = $grupo->id;
        $matricula->save();

        $clase = Clase::abrir($grupo, $this->periodo, $this->profesor);

        $this->actingAs($this->director->user)
            ->get(route('clase-asistencia', $clase))
            ->assertOk()
            ->assertSee('Un registro que puede reescribir alguien que no dio la clase', false);

        $this->actingAs($this->director->user)
            ->post(route('clase-asistencia', $clase), ["estado_{$matricula->id}" => 'asistio']);

        $this->assertSame(0, $clase->asistencias()->count());
    }

    public function test_la_pantalla_de_clases_del_grupo_abre(): void
    {
        $grupo = $this->crearGrupo();
        Clase::abrir($grupo, $this->periodo, $this->profesor);

        $this->actingAs($this->profesor->user)
            ->get(route('grupo-clases', $grupo))
            ->assertOk();
    }

    // -----------------------------------------------------------------------
    // Fichas
    // -----------------------------------------------------------------------

    public function test_un_profesor_no_abre_la_ficha_de_otro_profesor(): void
    {
        $otro = $this->crearPerfil('otra', 'profesor');

        $this->actingAs($this->profesor->user)
            ->get(route('detalle-usuario', $otro))
            ->assertRedirect(route('panel'))
            ->assertSessionHas('error');
    }

    public function test_el_profesor_abre_la_ficha_de_un_estudiante(): void
    {
        $this->matricular($this->violin, estado: Matricula::ACTIVA);

        $this->actingAs($this->profesor->user)
            ->get(route('detalle-usuario', $this->estudiante))
            ->assertOk()
            ->assertSee('Ana');
    }

    /**
     * El panel de asistencia de la ficha solo se pinta cuando hay algo que
     * contar, y por eso ninguna prueba lo habia renderizado: un error de
     * compilacion de Blade vivio ahi sin que nada lo delatara.
     */
    public function test_la_ficha_con_asistencia_abre(): void
    {
        $grupo = $this->crearGrupo();
        $matricula = $this->matricular($this->violin, estado: Matricula::ACTIVA);
        $matricula->grupo_id = $grupo->id;
        $matricula->save();

        $clase = Clase::abrir($grupo, $this->periodo, $this->profesor);
        \App\Models\Asistencia::create([
            'clase_id' => $clase->id,
            'matricula_id' => $matricula->id,
            'estado' => 'asistio',
        ]);

        $this->actingAs($this->director->user)
            ->get(route('detalle-usuario', $this->estudiante))
            ->assertOk()
            ->assertSee('Asistencia')
            ->assertSee('Racha');
    }

    /**
     * Que un profesor pueda abrir la ficha no le da los datos de contacto de
     * cualquiera: solo de quien cursa alguna de sus promotorias.
     */
    public function test_el_profesor_no_ve_el_contacto_de_un_estudiante_ajeno(): void
    {
        $ajeno = $this->crearEstudiante('beto');
        $this->matricular($this->danza, $ajeno, Matricula::ACTIVA);

        $this->actingAs($this->profesor->user)
            ->get(route('detalle-usuario', $ajeno))
            ->assertOk()
            ->assertDontSee('3000000000');
    }

    public function test_solo_el_administrador_abre_la_ficha_con_documento(): void
    {
        $this->actingAs($this->director->user)
            ->get(route('detalle-estudiante', $this->estudiante))
            ->assertRedirect(route('post-login'));

        $admin = $this->crearPerfil('admin', 'administrador');

        $this->actingAs($admin->user)
            ->get(route('detalle-estudiante', $this->estudiante))
            ->assertOk();
    }

    public function test_el_historial_lo_ve_todo_el_personal(): void
    {
        $this->matricular($this->danza, estado: Matricula::ACTIVA);

        $this->actingAs($this->profesor->user)
            ->get(route('historial-estudiante', $this->estudiante))
            ->assertOk()
            // El historial es ENTERO a proposito: saber que lleva periodos en
            // otra disciplina es lo que sirve para ubicarlo en un nivel.
            ->assertSee('Danza');
    }
}
