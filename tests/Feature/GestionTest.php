<?php

namespace Tests\Feature;

use App\Http\Controllers\Gestion\GrupoController;
use App\Models\Acudiente;
use App\Models\Area;
use App\Models\Asistencia;
use App\Models\Clase;
use App\Models\ConfiguracionInstitucion;
use App\Models\ConfirmacionClase;
use App\Models\DatosEstudiante;
use App\Models\DocumentoRequerido;
use App\Models\EncuestaDemografica;
use App\Models\EncuestaSatisfaccion;
use App\Models\Grupo;
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
 * Gestion: el catalogo academico, la ventana de matriculas, las cancelaciones y
 * los usuarios.
 *
 * Lo que mas importa aqui son dos cosas que el resto del sistema da por
 * sentadas: que las cancelaciones se puedan resolver —hasta que exista esta
 * pantalla, una solicitud en tramite no tiene salida— y que no se pueda borrar
 * del catalogo nada que sostenga historial.
 */
class GestionTest extends TestCase
{
    use RefreshDatabase;

    private Periodo $periodo;

    private Area $musica;

    private Promotoria $violin;

    private Perfil $director;

    private Perfil $admin;

    private Perfil $profesor;

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

        $this->musica = Area::create(['nombre' => 'Musica']);
        $this->director = $this->crearPerfil('dire', 'director');
        $this->admin = $this->crearPerfil('admin', 'administrador');
        $this->profesor = $this->crearPerfil('profe', 'profesor');
        $this->estudiante = $this->crearEstudiante('ana');

        $this->violin = Promotoria::create([
            'nombre' => 'Violin',
            'area_id' => $this->musica->id,
            'profesor_id' => $this->profesor->id,
        ]);
    }

    private function crearPerfil(string $username, string $rol, ?string $nacimiento = null): Perfil
    {
        $user = User::create(['username' => $username, 'password' => 'x', 'activo' => true]);

        return Perfil::create([
            'user_id' => $user->id,
            'rol' => $rol,
            'nombre_completo' => ucfirst($username),
            'fecha_nacimiento' => $nacimiento ?? Carbon::today()->subYears(30)->toDateString(),
            'telefono' => '3000000000',
        ]);
    }

    private function crearEstudiante(string $username, ?string $nacimiento = null): Perfil
    {
        $perfil = $this->crearPerfil($username, 'estudiante', $nacimiento);

        DatosEstudiante::create([
            'perfil_id' => $perfil->id,
            'documento_identidad' => '1'.$perfil->id,
        ]);

        return $perfil;
    }

    private function matricular(Perfil $perfil, Promotoria $promotoria, string $estado): Matricula
    {
        $matricula = new Matricula([
            'estudiante_id' => $perfil->id,
            'promotoria_id' => $promotoria->id,
            'periodo_id' => $this->periodo->id,
            'estado' => $estado,
        ]);
        $matricula->save();

        return $matricula;
    }

    // -----------------------------------------------------------------------
    // Puertas
    // -----------------------------------------------------------------------

    public function test_un_profesor_no_entra_a_gestion(): void
    {
        $this->actingAs($this->profesor->user)
            ->get(route('gestion-inicio'))
            ->assertRedirect(route('post-login'));
    }

    public function test_el_director_no_toca_la_configuracion_de_la_institucion(): void
    {
        $this->actingAs($this->director->user)
            ->get(route('gestion-configuracion'))
            ->assertRedirect(route('post-login'));
    }

    public function test_el_director_no_ve_las_estadisticas(): void
    {
        $this->actingAs($this->director->user)
            ->get(route('gestion-estadisticas'))
            ->assertRedirect(route('post-login'));
    }

    public function test_el_administrador_si(): void
    {
        $this->actingAs($this->admin->user)->get(route('gestion-configuracion'))->assertOk();
        $this->actingAs($this->admin->user)->get(route('gestion-estadisticas'))->assertOk();
    }

    // -----------------------------------------------------------------------
    // Cancelaciones: la deuda que esta pantalla viene a cerrar
    // -----------------------------------------------------------------------

    public function test_aprobar_la_cancelacion_retira_y_libera_el_cupo(): void
    {
        $matricula = $this->matricular($this->estudiante, $this->violin, Matricula::CANCELACION_SOLICITADA);

        $this->actingAs($this->director->user)
            ->post(route('gestion-resolver-cancelacion', [$matricula, 'aprobar']))
            ->assertSessionHas('success');

        $this->assertSame(Matricula::RETIRADA, $matricula->fresh()->estado);
        $this->assertSame(0, $this->violin->ocupadosEn($this->periodo));
    }

    /**
     * Rechazar solo cabe con menores: la pausa existe para hablar con el
     * acudiente antes de que un nino se salga por su cuenta.
     */
    public function test_la_cancelacion_de_un_menor_se_puede_rechazar(): void
    {
        $menor = $this->crearEstudiante('nino', Carbon::today()->subYears(10)->toDateString());
        $matricula = $this->matricular($menor, $this->violin, Matricula::CANCELACION_SOLICITADA);

        $this->actingAs($this->director->user)
            ->post(route('gestion-resolver-cancelacion', [$matricula, 'rechazar']))
            ->assertSessionHas('success');

        $this->assertSame(Matricula::ACTIVA, $matricula->fresh()->estado);
    }

    /** A un mayor de edad no se le discute la decision de irse. */
    public function test_la_cancelacion_de_un_adulto_no_se_puede_rechazar(): void
    {
        $matricula = $this->matricular($this->estudiante, $this->violin, Matricula::CANCELACION_SOLICITADA);

        $this->actingAs($this->director->user)
            ->post(route('gestion-resolver-cancelacion', [$matricula, 'rechazar']))
            ->assertSessionHas('error');

        $this->assertSame(Matricula::CANCELACION_SOLICITADA, $matricula->fresh()->estado);
    }

    public function test_aprobar_en_lote_retira_a_todos_los_marcados(): void
    {
        $otra = Promotoria::create(['nombre' => 'Piano', 'area_id' => $this->musica->id]);

        $unas = [
            $this->matricular($this->estudiante, $this->violin, Matricula::CANCELACION_SOLICITADA),
            $this->matricular($this->crearEstudiante('otro'), $otra, Matricula::CANCELACION_SOLICITADA),
        ];

        $this->actingAs($this->director->user)
            ->post(route('gestion-cancelaciones-lote'), [
                'decision' => 'aprobar',
                'matricula_ids' => array_map(fn (Matricula $m) => $m->id, $unas),
            ])
            ->assertSessionHas('success');

        foreach ($unas as $matricula) {
            $this->assertSame(Matricula::RETIRADA, $matricula->fresh()->estado);
        }

        $this->assertSame(0, $this->violin->ocupadosEn($this->periodo));
    }

    /**
     * Rechazar en lote resuelve las de los menores y deja fuera a los mayores,
     * diciendo quienes son.
     *
     * Es la misma regla que de a una —a un mayor no se le discute la salida—
     * aplicada sobre una seleccion mezclada, que es como va a llegar de verdad:
     * quien marca «todas» no va mirando la edad fila por fila.
     */
    public function test_rechazar_en_lote_solo_toca_a_los_menores(): void
    {
        $menor = $this->crearEstudiante('nino', Carbon::today()->subYears(10)->toDateString());
        $delMenor = $this->matricular($menor, $this->violin, Matricula::CANCELACION_SOLICITADA);
        $delAdulto = $this->matricular($this->estudiante, $this->violin, Matricula::CANCELACION_SOLICITADA);

        $respuesta = $this->actingAs($this->director->user)
            ->post(route('gestion-cancelaciones-lote'), [
                'decision' => 'rechazar',
                'matricula_ids' => [$delMenor->id, $delAdulto->id],
            ]);

        $this->assertSame(Matricula::ACTIVA, $delMenor->fresh()->estado);
        $this->assertSame(Matricula::CANCELACION_SOLICITADA, $delAdulto->fresh()->estado);

        $respuesta->assertSessionHas(
            'error',
            fn (string $mensaje) => str_contains($mensaje, $this->estudiante->nombre_completo)
        );
    }

    /** Lo que ya no esta en tramite no entra en el lote. */
    public function test_el_lote_de_cancelaciones_ignora_lo_ya_resuelto(): void
    {
        $activa = $this->matricular($this->estudiante, $this->violin, Matricula::ACTIVA);
        $enTramite = $this->matricular(
            $this->crearEstudiante('otro'),
            $this->violin,
            Matricula::CANCELACION_SOLICITADA
        );

        $this->actingAs($this->director->user)
            ->post(route('gestion-cancelaciones-lote'), [
                'decision' => 'aprobar',
                'matricula_ids' => [$activa->id, $enTramite->id],
            ]);

        $this->assertSame(Matricula::ACTIVA, $activa->fresh()->estado);
        $this->assertSame(Matricula::RETIRADA, $enTramite->fresh()->estado);
    }

    public function test_la_portada_avisa_de_las_cancelaciones_pendientes(): void
    {
        $this->matricular($this->estudiante, $this->violin, Matricula::CANCELACION_SOLICITADA);

        $this->actingAs($this->director->user)
            ->get(route('gestion-inicio'))
            ->assertOk()
            ->assertSee('Cancelaciones');
    }

    // -----------------------------------------------------------------------
    // Ventana de matriculas
    // -----------------------------------------------------------------------

    public function test_se_cierran_y_se_abren_las_matriculas(): void
    {
        $this->actingAs($this->director->user)
            ->post(route('gestion-matriculas'), ['accion' => 'cerrar'])
            ->assertSessionHas('success');

        $this->assertFalse($this->periodo->fresh()->matriculas_abiertas);

        $this->actingAs($this->director->user)->post(route('gestion-matriculas'), ['accion' => 'abrir']);

        $this->assertTrue($this->periodo->fresh()->matriculas_abiertas);
    }

    /**
     * Solo puede haber un periodo en curso: el indice unico lo impone y la
     * transaccion apaga el anterior antes de encender el nuevo.
     */
    public function test_poner_otro_periodo_en_curso_apaga_el_anterior(): void
    {
        $nuevo = Periodo::create([
            'nombre' => '2026-2',
            'fecha_inicio' => '2026-07-15',
            'fecha_fin' => '2026-12-15',
            'activo' => false,
            'matriculas_abiertas' => false,
        ]);

        $this->actingAs($this->director->user)
            ->post(route('gestion-matriculas'), ['accion' => 'poner_en_curso', 'periodo_id' => $nuevo->id])
            ->assertSessionHas('success');

        $this->assertTrue($nuevo->fresh()->activo);
        $this->assertFalse($this->periodo->fresh()->activo);
        // Al que sale se le cierran tambien las matriculas.
        $this->assertFalse($this->periodo->fresh()->matriculas_abiertas);
    }

    // -----------------------------------------------------------------------
    // Cupos en lote
    // -----------------------------------------------------------------------

    public function test_se_reparten_los_cupos_de_todo_el_catalogo(): void
    {
        $danza = Promotoria::create(['nombre' => 'Danza', 'area_id' => $this->musica->id]);

        $this->actingAs($this->director->user)
            ->post(route('gestion-cupos-periodo', $this->periodo), [
                "cupo_{$this->violin->id}" => '10',
                "cupo_{$danza->id}" => '',
            ])
            ->assertSessionHas('success');

        $this->assertSame(10, $this->violin->cupoEn($this->periodo));
        $this->assertNull($danza->cupoEn($this->periodo));
    }

    /** Nada a medias: si un valor viene mal, no se guarda ninguno. */
    public function test_un_cupo_invalido_no_guarda_ninguno(): void
    {
        $danza = Promotoria::create(['nombre' => 'Danza', 'area_id' => $this->musica->id]);

        $this->actingAs($this->director->user)
            ->post(route('gestion-cupos-periodo', $this->periodo), [
                "cupo_{$this->violin->id}" => '10',
                "cupo_{$danza->id}" => 'muchos',
            ])
            ->assertSessionHas('error');

        $this->assertNull($this->violin->cupoEn($this->periodo));
    }

    /**
     * Bajar el cupo por debajo de lo ya ocupado avisa, pero guarda igual.
     *
     * El aviso se compone FUERA de la transaccion, con los ocupados leidos antes
     * de abrirla: es texto para quien acaba de guardar, no una condicion de la
     * escritura —el propio mensaje lo dice, «no se retiro a nadie»—. Esta prueba
     * fija esa doble condicion: que el aviso salga y que el cupo se guarde.
     */
    public function test_bajar_el_cupo_por_debajo_de_lo_ocupado_avisa_pero_guarda(): void
    {
        $this->matricular($this->estudiante, $this->violin, Matricula::ACTIVA);

        $respuesta = $this->actingAs($this->director->user)
            ->post(route('gestion-cupos-periodo', $this->periodo), [
                "cupo_{$this->violin->id}" => '0',
            ]);

        $respuesta->assertSessionHas('success');
        $respuesta->assertSessionHas('error', fn (string $aviso) => str_contains($aviso, 'por debajo de las 1 matrículas ya ocupando sitio')
            && str_contains($aviso, 'No se retiró a nadie'));

        $this->assertSame(0, $this->violin->cupoEn($this->periodo));
    }

    /**
     * Ni la pantalla de cupos ni el guardado consultan una vez por promotoria.
     *
     * El guardado es el que mas importa: ese COUNT por fila corria con la
     * transaccion ABIERTA, alargando el rato que las filas de
     * `cupos_promotoria` quedan bloqueadas — y esto es «abrir matriculas», que
     * se usa el dia que mas gente esta empujando contra esas mismas filas.
     *
     * Como en el catalogo, no se fija un numero: se compara el mismo catalogo
     * con 1 promotoria y con 16, y lo que tiene que quedar plano es la cifra.
     */
    public function test_los_cupos_no_consultan_una_vez_por_promotoria(): void
    {
        $this->consultasDeCupos();

        $verUna = $this->consultasDeCupos();
        $guardarUna = $this->consultasDeCupos(guardando: true);

        for ($i = 1; $i <= 15; $i++) {
            $promotoria = Promotoria::create(['nombre' => "Taller {$i}", 'area_id' => $this->musica->id]);
            $this->matricular($this->crearEstudiante("alumno{$i}"), $promotoria, Matricula::ACTIVA);
        }

        $verDieciseis = $this->consultasDeCupos();
        $guardarDieciseis = $this->consultasDeCupos(guardando: true);

        $this->assertSame(
            $verUna,
            $verDieciseis,
            "Ver los cupos costo {$verUna} lecturas con 1 promotoria y {$verDieciseis} con 16."
        );

        $this->assertSame(
            $guardarUna,
            $guardarDieciseis,
            "Guardar costo {$guardarUna} lecturas con 1 promotoria y {$guardarDieciseis} con 16."
        );
    }

    /**
     * Cuantas LECTURAS cuesta abrir la pantalla de cupos, o guardarla.
     *
     * Se cuentan solo los SELECT para que esta prueba juzgue una sola cosa: leer
     * una vez por promotoria. Las escrituras tienen la suya
     * (`test_guardar_los_cupos_no_escribe_una_vez_por_promotoria`) desde que
     * dejaron de crecer.
     *
     * Aqui decia que meterlas en el mismo saco no valia porque «guardar
     * dieciseis cupos son dieciseis escrituras, y no hay otra forma». Si la
     * habia, y es C-05: un `upsert` masivo. El comentario razonaba sobre un
     * limite que el codigo ya no tiene.
     */
    private function consultasDeCupos(bool $guardando = false): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        if ($guardando) {
            $this->actingAs($this->director->user)
                ->post(route('gestion-cupos-periodo', $this->periodo), [])
                ->assertRedirect();
        } else {
            $this->actingAs($this->director->user)
                ->get(route('gestion-cupos-periodo', $this->periodo))
                ->assertOk();
        }

        $lecturas = collect(DB::getQueryLog())
            ->filter(fn (array $registro) => str_starts_with(strtolower(ltrim((string) $registro['query'])), 'select'))
            ->count();

        DB::disableQueryLog();

        return $lecturas;
    }

    /**
     * Guardar los cupos tampoco ESCRIBE una vez por promotoria (C-05).
     *
     * Es la otra mitad de la prueba de arriba, y la que faltaba: alli se
     * sacaron los COUNT de dentro de la transaccion, aqui se sacan las
     * escrituras de una en una. Importa por lo mismo y no por el reloj: cada
     * fila que la transaccion toca queda bloqueada mientras dura, y el trigger
     * de cupos lee esas filas en cada matricula que alguien intente — o sea
     * justo el dia de abrir matriculas, cuando mas gente empuja contra ellas.
     *
     * Se comprueban los DOS caminos, porque son dos sentencias distintas y cada
     * una crecia por su cuenta: poner tope (antes un `updateOrCreate` por
     * promotoria, ahora un `upsert`) y quitarlo (antes un `delete` por
     * promotoria, ahora uno con `whereIn`).
     */
    public function test_guardar_los_cupos_no_escribe_una_vez_por_promotoria(): void
    {
        $conTopeUna = $this->escriturasDeCupos(conTope: true);
        $sinTopeUna = $this->escriturasDeCupos(conTope: false);

        for ($i = 1; $i <= 15; $i++) {
            Promotoria::create(['nombre' => "Taller {$i}", 'area_id' => $this->musica->id]);
        }

        $conTopeDieciseis = $this->escriturasDeCupos(conTope: true);
        $sinTopeDieciseis = $this->escriturasDeCupos(conTope: false);

        $this->assertSame(
            $conTopeUna,
            $conTopeDieciseis,
            "Poner tope costo {$conTopeUna} escrituras con 1 promotoria y {$conTopeDieciseis} con 16."
        );

        $this->assertSame(
            $sinTopeUna,
            $sinTopeDieciseis,
            "Quitar el tope costo {$sinTopeUna} escrituras con 1 promotoria y {$sinTopeDieciseis} con 16."
        );
    }

    /**
     * Cuantas ESCRITURAS contra `cupos_promotoria` cuesta guardar la pantalla.
     *
     * Se filtra por la tabla: lo demas que escriba la peticion no es lo que esta
     * prueba juzga, y contarlo solo haria la cifra fragil.
     */
    private function escriturasDeCupos(bool $conTope): int
    {
        $campos = Promotoria::pluck('id')
            ->mapWithKeys(fn (int $id) => ["cupo_{$id}" => $conTope ? '10' : ''])
            ->all();

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->actingAs($this->director->user)
            ->post(route('gestion-cupos-periodo', $this->periodo), $campos)
            ->assertRedirect();

        $escrituras = collect(DB::getQueryLog())
            ->filter(fn (array $registro) => ! str_starts_with(strtolower(ltrim((string) $registro['query'])), 'select'))
            ->filter(fn (array $registro) => str_contains((string) $registro['query'], 'cupos_promotoria'))
            ->count();

        DB::disableQueryLog();

        return $escrituras;
    }

    /** Un periodo que ya paso es historico: sus cupos no se editan. */
    public function test_no_se_editan_los_cupos_de_un_periodo_cerrado(): void
    {
        $viejo = Periodo::create([
            'nombre' => '2025-2',
            'fecha_inicio' => '2025-07-15',
            'fecha_fin' => '2025-12-15',
            'activo' => false,
            'matriculas_abiertas' => false,
        ]);

        $this->actingAs($this->director->user)
            ->post(route('gestion-cupos-periodo', $viejo), ["cupo_{$this->violin->id}" => '5'])
            ->assertSessionHas('error');

        $this->assertNull($this->violin->cupoEn($viejo));
    }

    // -----------------------------------------------------------------------
    // Catalogo
    // -----------------------------------------------------------------------

    public function test_se_crea_un_departamento(): void
    {
        $this->actingAs($this->director->user)
            ->post(route('area-nueva'), ['nombre' => 'Teatro'])
            ->assertRedirect(route('area-lista'));

        $this->assertNotNull(Area::where('nombre', 'Teatro')->first());
    }

    /**
     * La pantalla de confirmacion dice la verdad ANTES de preguntar: si algo lo
     * bloquea, no ofrece boton.
     */
    public function test_la_confirmacion_avisa_de_lo_que_bloquea_el_borrado(): void
    {
        $this->actingAs($this->director->user)
            ->get(route('area-eliminar', $this->musica))
            ->assertOk()
            ->assertSee('No se puede eliminar')
            ->assertSee('1 promotoría', false);
    }

    public function test_no_se_borra_una_promotoria_con_matriculas(): void
    {
        $this->matricular($this->estudiante, $this->violin, Matricula::RETIRADA);

        $this->actingAs($this->director->user)
            ->post(route('promotoria-eliminar', $this->violin))
            ->assertSessionHas('error');

        $this->assertNotNull($this->violin->fresh());
    }

    /** Un area sin promotorias si se borra, y la pantalla lo ofrece. */
    public function test_se_borra_un_departamento_vacio(): void
    {
        $vacia = Area::create(['nombre' => 'Sin nada']);

        $this->actingAs($this->director->user)
            ->get(route('area-eliminar', $vacia))
            ->assertOk()
            ->assertSee('¿Eliminar', false);

        $this->actingAs($this->director->user)->post(route('area-eliminar', $vacia));

        $this->assertNull($vacia->fresh());
    }

    /**
     * Borrar una promotoria arrastra sus grupos en cascada, y la confirmacion
     * tiene que decirlo: «no se puede deshacer» a secas se queda corto.
     */
    public function test_la_confirmacion_avisa_de_lo_que_se_arrastra(): void
    {
        $sinMatriculas = Promotoria::create(['nombre' => 'Tiple', 'area_id' => $this->musica->id]);

        Grupo::create([
            'promotoria_id' => $sinMatriculas->id,
            'nombre' => 'Lunes',
            'nivel' => 'basico',
            'salon' => 'A1',
            'cupo_maximo' => 5,
        ]);

        $this->actingAs($this->director->user)
            ->get(route('promotoria-eliminar', $sinMatriculas))
            ->assertOk()
            ->assertSee('Se llevará también');
    }

    public function test_la_promotoria_nueva_vuelve_a_su_departamento(): void
    {
        $this->actingAs($this->director->user)
            ->post(route('promotoria-nueva'), [
                'nombre' => 'Tiple',
                'area_id' => $this->musica->id,
                'profesor_id' => '',
            ])
            ->assertRedirect(route('promotorias-por-area', $this->musica));
    }

    // -----------------------------------------------------------------------
    // Usuarios
    // -----------------------------------------------------------------------

    public function test_se_crea_un_usuario_estudiante_con_sus_datos(): void
    {
        $this->actingAs($this->director->user)
            ->post(route('usuario-nuevo'), [
                'username' => 'nuevo',
                'password' => 'secreta123',
                'rol' => 'estudiante',
                'nombre_completo' => 'Nuevo Estudiante',
                'fecha_nacimiento' => Carbon::today()->subYears(20)->toDateString(),
                'telefono' => '3001112233',
                'documento_identidad' => '99999',
            ])
            ->assertRedirect(route('usuario-lista'));

        $perfil = Perfil::where('nombre_completo', 'Nuevo Estudiante')->first();

        $this->assertNotNull($perfil);
        $this->assertSame('99999', $perfil->datosEstudiante->documento_identidad);
    }

    /** La regla vive en el modelo porque la minoria de edad esta en otra tabla. */
    public function test_un_estudiante_menor_necesita_acudiente(): void
    {
        $this->actingAs($this->director->user)
            ->post(route('usuario-nuevo'), [
                'username' => 'nino',
                'password' => 'secreta123',
                'rol' => 'estudiante',
                'nombre_completo' => 'Nino Pequeno',
                'fecha_nacimiento' => Carbon::today()->subYears(9)->toDateString(),
                'telefono' => '3001112233',
                'documento_identidad' => '88888',
            ])
            ->assertSessionHasErrors('acudiente');

        $this->assertNull(User::where('username', 'nino')->first());
    }

    public function test_editar_sin_contrasena_no_la_cambia(): void
    {
        $antes = $this->estudiante->user->password;

        $this->actingAs($this->director->user)
            ->post(route('usuario-editar', $this->estudiante), [
                'username' => 'ana',
                'password' => '',
                'rol' => 'estudiante',
                'nombre_completo' => 'Ana Maria',
                'fecha_nacimiento' => $this->estudiante->fecha_nacimiento->toDateString(),
                'telefono' => '3009998877',
                'documento_identidad' => $this->estudiante->datosEstudiante->documento_identidad,
            ])
            ->assertRedirect(route('usuario-lista'));

        $this->assertSame($antes, $this->estudiante->fresh()->user->password);
        $this->assertSame('Ana Maria', $this->estudiante->fresh()->nombre_completo);
    }

    /**
     * Dejarla en blanco sigue valiendo, pero escribir una corta no.
     *
     * Es la pareja de la prueba de arriba y la razon por la que el campo se
     * queda en `nullable` en vez de pasar a `required`: son dos cosas distintas
     * —«no la toques» y «ponle esta»— y solo la segunda tiene que cumplir el
     * minimo. Si se confundieran, editar el telefono de alguien obligaria a
     * inventarle una contrasena nueva.
     */
    public function test_al_editar_una_contrasena_corta_se_rechaza(): void
    {
        $antes = $this->estudiante->user->password;

        $this->actingAs($this->director->user)
            ->post(route('usuario-editar', $this->estudiante), [
                'username' => 'ana',
                'password' => 'corta7c',
                'rol' => 'estudiante',
                'nombre_completo' => 'Ana Maria',
                'fecha_nacimiento' => $this->estudiante->fecha_nacimiento->toDateString(),
                'telefono' => '3009998877',
                'documento_identidad' => $this->estudiante->datosEstudiante->documento_identidad,
            ])
            ->assertSessionHasErrors('password');

        // Y no se cambio nada mas por el camino: el nombre sigue como estaba.
        $this->assertSame($antes, $this->estudiante->fresh()->user->password);
        $this->assertNotSame('Ana Maria', $this->estudiante->fresh()->nombre_completo);
    }

    /** Una contrasena corta tampoco crea la cuenta desde Gestion. */
    public function test_al_crear_una_contrasena_corta_se_rechaza(): void
    {
        $this->actingAs($this->director->user)
            ->post(route('usuario-nuevo'), [
                'username' => 'nuevo.corto',
                'password' => 'corta7c',
                'rol' => 'profesor',
                'nombre_completo' => 'Profe Corto',
                'fecha_nacimiento' => Carbon::today()->subYears(30)->toDateString(),
                'telefono' => '3001112233',
            ])
            ->assertSessionHasErrors('password');

        $this->assertNull(User::where('username', 'nuevo.corto')->first());
    }

    // -----------------------------------------------------------------------
    // El director no llega a administrador
    // -----------------------------------------------------------------------
    //
    // Las rutas de usuarios estan abiertas a director Y administrador, pero el
    // enrutado reserva al administrador tres pantallas: la configuracion de la
    // institucion, las estadisticas con la encuesta demografica y la descarga de
    // copias de documentos de identidad. Sin estas puertas, un director se daba
    // el rol a si mismo —o le cambiaba la clave al administrador— y esas tres
    // reservas dejaban de significar nada.

    /** @return array<string, mixed> */
    private function datosDeUsuario(array $extra = []): array
    {
        return [
            'username' => 'nuevo.usuario',
            'password' => 'secreta123',
            'rol' => 'profesor',
            'nombre_completo' => 'Nuevo Usuario',
            'fecha_nacimiento' => Carbon::today()->subYears(30)->toDateString(),
            'telefono' => '3001112233',
            ...$extra,
        ];
    }

    public function test_un_director_no_crea_administradores(): void
    {
        $this->actingAs($this->director->user)
            ->post(route('usuario-nuevo'), $this->datosDeUsuario([
                'username' => 'colado',
                'rol' => 'administrador',
            ]))
            ->assertForbidden();

        $this->assertNull(User::where('username', 'colado')->first());
    }

    public function test_un_director_no_asciende_a_nadie_a_administrador(): void
    {
        $this->actingAs($this->director->user)
            ->post(route('usuario-editar', $this->profesor), $this->datosDeUsuario([
                'username' => 'profe',
                'password' => '',
                'rol' => 'administrador',
                'nombre_completo' => 'Profe',
            ]))
            ->assertForbidden();

        $this->assertSame('profesor', $this->profesor->fresh()->rol);
    }

    /** El camino corto y el mas obvio: ascenderse uno mismo. */
    public function test_un_director_no_se_asciende_a_si_mismo(): void
    {
        $this->actingAs($this->director->user)
            ->post(route('usuario-editar', $this->director), $this->datosDeUsuario([
                'username' => 'dire',
                'password' => '',
                'rol' => 'administrador',
                'nombre_completo' => 'Dire',
            ]))
            ->assertForbidden();

        $this->assertSame('director', $this->director->fresh()->rol);
    }

    /**
     * El otro camino: no ascenderse, sino suplantar.
     *
     * Sin esto la restriccion anterior no vale nada — bastaba con ponerle al
     * administrador una contrasena conocida y entrar como el.
     */
    public function test_un_director_no_le_cambia_la_contrasena_al_administrador(): void
    {
        $antes = $this->admin->user->password;

        $this->actingAs($this->director->user)
            ->post(route('usuario-editar', $this->admin), $this->datosDeUsuario([
                'username' => 'admin',
                'password' => 'lamiaahora',
                'rol' => 'administrador',
                'nombre_completo' => 'Admin',
            ]))
            ->assertForbidden();

        $this->assertSame($antes, $this->admin->fresh()->user->password);
    }

    public function test_un_director_no_abre_la_edicion_de_un_administrador(): void
    {
        $this->actingAs($this->director->user)
            ->get(route('usuario-editar', $this->admin))
            ->assertForbidden();
    }

    /**
     * Desactivar tambien es tocar la cuenta.
     *
     * No es ascenso, pero deja al administrador fuera y con el a las tres
     * pantallas que solo el abre, que es el mismo daño por el otro lado.
     */
    public function test_un_director_no_desactiva_a_un_administrador(): void
    {
        $this->actingAs($this->director->user)
            ->post(route('usuario-alternar-activo', $this->admin))
            ->assertForbidden();

        $this->assertTrue($this->admin->fresh()->user->activo);
    }

    /** El desplegable no ofrece lo que va a rebotar. */
    public function test_el_formulario_no_le_ofrece_administrador_a_un_director(): void
    {
        $this->actingAs($this->director->user)
            ->get(route('usuario-nuevo'))
            ->assertOk()
            ->assertDontSee('value="administrador"', false)
            ->assertSee('value="profesor"', false);
    }

    /** Ni el listado pinta acciones sobre una cuenta que no puede tocar. */
    public function test_el_listado_no_le_ofrece_editar_al_administrador(): void
    {
        $this->actingAs($this->director->user)
            ->get(route('usuario-lista'))
            ->assertOk()
            ->assertDontSee(route('usuario-editar', $this->admin), false)
            ->assertSee(route('usuario-editar', $this->profesor), false);
    }

    // -- Y el administrador sigue pudiendo con todo -------------------------
    //
    // La contraparte, y es la mitad que importa: una restriccion que de paso
    // hubiera cerrado la puerta al administrador seria peor que el problema.

    public function test_el_administrador_si_crea_administradores(): void
    {
        $this->actingAs($this->admin->user)
            ->post(route('usuario-nuevo'), $this->datosDeUsuario([
                'username' => 'otro.admin',
                'rol' => 'administrador',
            ]))
            ->assertRedirect(route('usuario-lista'));

        $this->assertSame('administrador', User::where('username', 'otro.admin')->first()->perfil->rol);
    }

    public function test_el_administrador_si_edita_a_otro_administrador(): void
    {
        $otro = $this->crearPerfil('admin2', 'administrador');

        $this->actingAs($this->admin->user)
            ->post(route('usuario-editar', $otro), $this->datosDeUsuario([
                'username' => 'admin2',
                'password' => '',
                'rol' => 'administrador',
                'nombre_completo' => 'Admin Dos',
            ]))
            ->assertRedirect(route('usuario-lista'));

        $this->assertSame('Admin Dos', $otro->fresh()->nombre_completo);
    }

    public function test_el_alta_guarda_el_correo(): void
    {
        $this->actingAs($this->director->user)
            ->post(route('usuario-nuevo'), $this->datosDeUsuario([
                'username' => 'con.correo',
                'correo' => 'profe@ejemplo.co',
            ]))
            ->assertRedirect(route('usuario-lista'));

        $this->assertSame('profe@ejemplo.co', User::where('username', 'con.correo')->first()->email);
    }

    /**
     * El correo PUEDE repetirse, y es deliberado.
     *
     * Dos hermanos matriculados comparten el de su acudiente, y en una casa de
     * la cultura ese caso es corriente. Un indice unico lo convertiria en un
     * error que la familia no sabria como resolver.
     */
    public function test_dos_cuentas_pueden_compartir_correo(): void
    {
        foreach (['hermano.uno', 'hermano.dos'] as $username) {
            $this->actingAs($this->director->user)
                ->post(route('usuario-nuevo'), $this->datosDeUsuario([
                    'username' => $username,
                    'correo' => 'acudiente@ejemplo.co',
                ]))
                ->assertRedirect(route('usuario-lista'));
        }

        $this->assertSame(2, User::where('email', 'acudiente@ejemplo.co')->count());
    }

    /** Sin correo se guarda null, no cadena vacia. */
    public function test_el_alta_sin_correo_guarda_null(): void
    {
        $this->actingAs($this->director->user)
            ->post(route('usuario-nuevo'), $this->datosDeUsuario(['username' => 'sin.correo']))
            ->assertRedirect(route('usuario-lista'));

        $this->assertNull(User::where('username', 'sin.correo')->first()->email);
    }

    public function test_un_correo_mal_escrito_no_crea_la_cuenta(): void
    {
        $this->actingAs($this->director->user)
            ->post(route('usuario-nuevo'), $this->datosDeUsuario([
                'username' => 'correo.malo',
                'correo' => 'arroba-ninguna',
            ]))
            ->assertSessionHasErrors('correo');

        $this->assertNull(User::where('username', 'correo.malo')->first());
    }

    /** Un director sigue repartiendo los tres roles que si le tocan. */
    public function test_un_director_si_crea_profesores(): void
    {
        $this->actingAs($this->director->user)
            ->post(route('usuario-nuevo'), $this->datosDeUsuario(['username' => 'profe.nuevo']))
            ->assertRedirect(route('usuario-lista'));

        $this->assertSame('profesor', User::where('username', 'profe.nuevo')->first()->perfil->rol);
    }

    /**
     * Desactivar y no borrar: borrar el usuario se llevaria su perfil y con el
     * todo su historial de matriculas.
     */
    public function test_se_desactiva_una_cuenta(): void
    {
        $this->actingAs($this->director->user)
            ->post(route('usuario-alternar-activo', $this->estudiante))
            ->assertSessionHas('success');

        $this->assertFalse($this->estudiante->fresh()->user->activo);
    }

    public function test_nadie_se_desactiva_a_si_mismo(): void
    {
        $this->actingAs($this->director->user)
            ->post(route('usuario-alternar-activo', $this->director))
            ->assertSessionHas('error');

        $this->assertTrue($this->director->fresh()->user->activo);
    }

    /**
     * Filtrar por promotoria devuelve las dos formas de estar vinculado a ella:
     * quien la cursa y quien la dicta.
     */
    public function test_el_filtro_por_promotoria_trae_estudiantes_y_profesor(): void
    {
        $this->matricular($this->estudiante, $this->violin, Matricula::ACTIVA);

        $respuesta = $this->actingAs($this->director->user)
            ->get(route('usuario-lista', ['promotoria' => $this->violin->id]));

        $respuesta->assertOk();
        $respuesta->assertSee('Ana');
        $respuesta->assertSee('Profe');
        $respuesta->assertDontSee('>Dire<', false);
    }

    /** Quien se retiro de Violin ya no es de Violin. */
    public function test_el_filtro_no_trae_a_los_retirados(): void
    {
        $this->matricular($this->estudiante, $this->violin, Matricula::RETIRADA);

        $this->actingAs($this->director->user)
            ->get(route('usuario-lista', ['promotoria' => $this->violin->id]))
            ->assertDontSee('>Ana<', false);
    }

    // -----------------------------------------------------------------------
    // Institucion
    // -----------------------------------------------------------------------

    public function test_se_guarda_la_configuracion(): void
    {
        $this->actingAs($this->admin->user)
            ->post(route('gestion-configuracion'), [
                'nombre_institucion' => 'Casa de la Cultura El Santuario',
                'color_acento' => '#0a7a59',
                'limite_promotorias_por_periodo' => 3,
                'promotorias_visibles_para_estudiantes' => 1,
            ])
            ->assertSessionHas('success');

        $configuracion = ConfiguracionInstitucion::actual();

        $this->assertSame('Casa de la Cultura El Santuario', $configuracion->nombre_institucion);
        $this->assertSame(3, $configuracion->limite_promotorias_por_periodo);
    }

    /**
     * El contraste no bloquea —una marca clara puede ser legitima— pero el texto
     * blanco de los botones deja de leerse y hay que avisarlo.
     */
    public function test_un_acento_demasiado_claro_avisa_del_contraste(): void
    {
        $this->actingAs($this->admin->user)
            ->post(route('gestion-configuracion'), [
                'nombre_institucion' => 'Casa',
                'color_acento' => '#ffee00',
                'limite_promotorias_por_periodo' => 2,
            ])
            ->assertSessionHas('success')
            ->assertSessionHas('error');

        $this->assertSame('#ffee00', ConfiguracionInstitucion::actual()->color_acento);
    }

    /**
     * Un papel no se borra: los archivos que ya subieron los estudiantes cuelgan
     * de el, y borrarlo se llevaria la prueba de que en su momento cumplieron.
     */
    public function test_un_documento_requerido_se_desactiva_en_vez_de_borrarse(): void
    {
        $documento = DocumentoRequerido::create([
            'nombre' => 'Certificado de EPS',
            'obligatorio' => true,
            'activo' => true,
            'orden' => 1,
        ]);

        $this->actingAs($this->admin->user)
            ->post(route('documento-requerido-alternar', $documento))
            ->assertSessionHas('success');

        $this->assertFalse($documento->fresh()->activo);
        $this->assertNotNull($documento->fresh());
    }

    // -----------------------------------------------------------------------
    // Estadisticas
    // -----------------------------------------------------------------------

    public function test_las_estadisticas_cuentan_lo_que_hay(): void
    {
        $this->matricular($this->estudiante, $this->violin, Matricula::ACTIVA);

        EncuestaDemografica::create([
            'perfil_id' => $this->estudiante->id,
            'genero' => 'f',
            'barrio' => 'El Centro',
            'estrato' => 2,
            'nivel_educativo' => 'secundaria_com',
            'ocupacion' => 'estudiante',
            'autoriza_tratamiento_datos' => true,
        ]);

        $this->actingAs($this->admin->user)
            ->get(route('gestion-estadisticas'))
            ->assertOk()
            ->assertSee('Estudiantes por departamento y promotoría', false)
            ->assertSee('Musica');
    }

    /**
     * «Estudiantes activos» cuenta el periodo que se mira, no la historia.
     *
     * La distincion no la inventa esta prueba: la pantalla la promete en prosa
     * tres lineas encima de la cifra --«el periodo mueve las matriculas»-- y
     * esta es una cifra de matriculas. Sin el filtro contaba a quien estuvo
     * activo en CUALQUIER periodo, y la diferencia crece con cada periodo que
     * pasa.
     *
     * La matricula del periodo viejo se queda en `activa` y eso es correcto: la
     * aplicacion la ensena como «Finalizada» por `estado_visible`, y de que el
     * estado NO cambie al cerrar un periodo cuelgan la renovacion, los
     * certificados y la antiguedad. Lo que estaba mal era preguntar sin acotar.
     */
    public function test_los_estudiantes_activos_son_los_del_periodo_que_se_mira(): void
    {
        $viejo = Periodo::create([
            'nombre' => '2025-2',
            'fecha_inicio' => '2025-07-15',
            'fecha_fin' => '2025-12-15',
            'activo' => false,
            'matriculas_abiertas' => false,
        ]);

        // DOS en el periodo cerrado y UNA en el de ahora, y personas distintas
        // en cada uno. Los dos detalles compran una barrera cada uno:
        //
        //  - Personas distintas, porque con la misma en ambos el `distinct()`
        //    devolveria 1 con filtro y sin el.
        //  - Conteos distintos (2 y 1), porque con uno en cada periodo los dos
        //    lados de la prueba valen 1 y la segunda mitad no discrimina nada.
        //    Comprobado: atando la consulta al periodo EN CURSO fijo --que es
        //    el error que la segunda mitad existe para atrapar-- la prueba
        //    pasaba igual antes de desequilibrar los conteos.
        foreach (['pedro', 'lucia'] as $nombre) {
            $antiguo = $this->crearEstudiante($nombre);
            $matriculaVieja = new Matricula([
                'estudiante_id' => $antiguo->id,
                'promotoria_id' => $this->violin->id,
                'periodo_id' => $viejo->id,
                'estado' => Matricula::ACTIVA,
            ]);
            $matriculaVieja->save();
        }

        $this->matricular($this->estudiante, $this->violin, Matricula::ACTIVA);

        $respuesta = $this->actingAs($this->admin->user)
            ->get(route('gestion-estadisticas'))
            ->assertOk();

        // Uno, no tres: los dos del periodo cerrado siguen en `activa` y no se
        // cuentan aqui.
        $this->assertSame(1, $respuesta->viewData('totalEstudiantesActivos'));

        // Y al retroceder con la flecha, la cifra se mueve con ella.
        $this->actingAs($this->admin->user)
            ->get(route('gestion-estadisticas-periodo', $viejo))
            ->assertOk()
            ->assertViewHas('totalEstudiantesActivos', 2);
    }

    /**
     * La encuesta se agrega en memoria, no con nueve GROUP BY.
     *
     * La tabla se lee entera de todas formas —hace falta para contar las
     * incompletas, que no tiene una version buena en SQL—, asi que agregarla
     * ademas en el motor eran diez pasadas por la misma tabla para pintar una
     * pantalla.
     *
     * Aqui no sirve la prueba de «que no crezca» que usan el catalogo y los
     * cupos: estas nueve consultas eran una cifra fija, no una por fila. Lo que
     * se comprueba es que ya no se emitan.
     */
    public function test_las_estadisticas_no_agrupan_la_encuesta_en_sql(): void
    {
        EncuestaDemografica::create([
            'perfil_id' => $this->estudiante->id,
            'genero' => 'f',
            'barrio' => 'El Centro',
            'estrato' => 2,
            'nivel_educativo' => 'secundaria_com',
            'ocupacion' => 'estudiante',
            'autoriza_tratamiento_datos' => true,
        ]);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->actingAs($this->admin->user)
            ->get(route('gestion-estadisticas'))
            ->assertOk();

        $consultas = collect(DB::getQueryLog())
            ->pluck('query')
            ->map(fn ($sql) => strtolower((string) $sql));

        DB::disableQueryLog();

        foreach (array_keys(EncuestaDemografica::OPCIONES) as $campo) {
            $this->assertFalse(
                $consultas->contains(fn (string $sql) => str_contains($sql, "group by `{$campo}`")),
                "La pantalla sigue agrupando en SQL por `{$campo}`, y la tabla de "
                .'encuestas ya estaba entera en memoria.'
            );
        }
    }

    // -----------------------------------------------------------------------
    // Satisfaccion: agregada para todos, con nombre solo para administracion
    // -----------------------------------------------------------------------

    /**
     * Deja una encuesta de satisfaccion sobre un periodo ya terminado, que es
     * como llegan de verdad: se contestan al renovar.
     */
    private function encuestar(Perfil $perfil, int $general, int $profesor, string $comentario = ''): Periodo
    {
        $anterior = Periodo::firstOrCreate(
            ['nombre' => '2025-2'],
            [
                'fecha_inicio' => '2025-07-15',
                'fecha_fin' => '2025-12-15',
                'activo' => false,
                'matriculas_abiertas' => false,
            ]
        );

        EncuestaSatisfaccion::create([
            'perfil_id' => $perfil->id,
            'periodo_id' => $anterior->id,
            'satisfaccion_general' => $general,
            'calificacion_profesor' => $profesor,
            'horario_funciono' => true,
            'recomendaria' => $general >= 3,
            'comentario' => $comentario,
        ]);

        return $anterior;
    }

    public function test_la_satisfaccion_sale_agregada(): void
    {
        $this->encuestar($this->estudiante, 5, 4, 'Todo muy bien.');
        $this->encuestar($this->crearEstudiante('samu'), 3, 3);

        $respuesta = $this->actingAs($this->admin->user)->get(route('gestion-estadisticas'));

        $respuesta->assertOk();
        $respuesta->assertSee('Satisfacción', false);
        // Media de 5 y 3.
        $respuesta->assertSee('4.0');
        $respuesta->assertSee('Todo muy bien.');
    }

    /**
     * El comentario se publica sin nombre al lado: es lo que permite que la
     * gente escriba lo que piensa.
     */
    public function test_el_comentario_no_lleva_nombre(): void
    {
        $this->encuestar($this->estudiante, 5, 5, 'Me encantó el curso.');

        $html = $this->actingAs($this->admin->user)
            ->get(route('gestion-estadisticas'))
            ->getContent();

        $comentario = strpos($html, 'Me encantó el curso.');
        $bloque = substr($html, $comentario - 400, 500);

        $this->assertStringNotContainsString('Ana', $bloque);
    }

    /**
     * El seguimiento es la excepcion deliberada al anonimato: administracion ve
     * quien lo paso mal, con su telefono, porque el motivo de recoger la
     * encuesta es poder llamarlo.
     */
    public function test_el_administrador_ve_quien_puntuo_bajo(): void
    {
        $this->encuestar($this->estudiante, 2, 1, 'No me gustó el horario.');

        $this->actingAs($this->admin->user)
            ->get(route('gestion-estadisticas'))
            ->assertOk()
            ->assertSee('Para seguimiento')
            ->assertSee('Ana')
            // Sin el telefono la lista no sirve para lo unico que la justifica.
            ->assertSee('3000000000');
    }

    /**
     * A un menor no se le llama: la conversacion es con su acudiente. Dar el
     * telefono del nino seria dar por bueno un contacto que ni la ley ni el
     * sentido comun admiten.
     */
    public function test_de_un_menor_se_da_el_telefono_del_acudiente(): void
    {
        $menor = $this->crearEstudiante('nino', Carbon::today()->subYears(11)->toDateString());

        $acudiente = Acudiente::create([
            'nombre' => 'Lucía Ortiz',
            'telefono' => '3111111111',
        ]);

        $datos = $menor->datosEstudiante;
        $datos->acudiente_id = $acudiente->id;
        $datos->save();

        $this->encuestar($menor, 1, 1, 'No me gustó.');

        $this->actingAs($this->admin->user)
            ->get(route('gestion-estadisticas'))
            ->assertOk()
            ->assertSee('Lucía Ortiz', false)
            ->assertSee('3111111111')
            ->assertSee('acudiente')
            // El telefono del propio menor no aparece.
            ->assertDontSee('3000000000');
    }

    /** Una nota de 3 es "ni bien ni mal": no pide llamar a nadie. */
    public function test_una_nota_media_no_entra_en_el_seguimiento(): void
    {
        $this->encuestar($this->estudiante, 3, 3);

        $this->actingAs($this->admin->user)
            ->get(route('gestion-estadisticas'))
            ->assertOk()
            ->assertSee('No hay a quién llamar', false)
            ->assertDontSee('3000000000');
    }

    public function test_sin_respuestas_la_pantalla_lo_dice_y_no_se_cae(): void
    {
        $this->actingAs($this->admin->user)
            ->get(route('gestion-estadisticas'))
            ->assertOk()
            ->assertSee('Todavía nadie ha contestado la encuesta de satisfacción', false);
    }

    /**
     * Sin periodo EN CURSO se cae al mas reciente, no a una pantalla vacia.
     *
     * Entre dos semestres no hay ninguno activo, y en ese hueco lo que se quiere
     * mirar es justamente el que acaba de terminar. Es lo mismo que ya hace la
     * pantalla de cupos, para que las dos se comporten igual.
     */
    public function test_sin_periodo_en_curso_las_estadisticas_ensenan_el_mas_reciente(): void
    {
        $this->periodo->activo = false;
        $this->periodo->save();

        $this->actingAs($this->admin->user)
            ->get(route('gestion-estadisticas'))
            ->assertOk()
            ->assertSee($this->periodo->nombre)
            ->assertDontSee('no hay nada que medir');
    }

    /** Sin NINGUN periodo si no hay nada que medir, y la pantalla no se cae. */
    public function test_las_estadisticas_abren_sin_ningun_periodo(): void
    {
        Matricula::query()->delete();
        Periodo::query()->delete();

        $this->actingAs($this->admin->user)
            ->get(route('gestion-estadisticas'))
            ->assertOk()
            ->assertSee('no hay nada que medir');
    }

    // -----------------------------------------------------------------------
    // Filtros del listado de grupos
    // -----------------------------------------------------------------------

    /**
     * Monta un segundo departamento con su promotoria, su profesor y su grupo,
     * para que cada filtro tenga algo que dejar fuera.
     *
     * @return array{grupo: Grupo, otroGrupo: Grupo, otroProfesor: Perfil, danza: Area}
     */
    private function montarCatalogoParaFiltrar(): array
    {
        $danza = Area::create(['nombre' => 'Danza']);
        $otroProfesor = $this->crearPerfil('profe2', 'profesor');

        $ballet = Promotoria::create([
            'nombre' => 'Ballet',
            'area_id' => $danza->id,
            'profesor_id' => $otroProfesor->id,
        ]);

        return [
            'grupo' => Grupo::create([
                'promotoria_id' => $this->violin->id,
                'nombre' => 'Lunes tarde',
                'nivel' => 'basico',
                'salon' => 'Salon 1',
                'cupo_maximo' => 10,
            ]),
            'otroGrupo' => Grupo::create([
                'promotoria_id' => $ballet->id,
                'nombre' => 'Martes tarde',
                'nivel' => 'basico',
                'salon' => 'Salon 2',
                'cupo_maximo' => 10,
            ]),
            'otroProfesor' => $otroProfesor,
            'danza' => $danza,
        ];
    }

    public function test_los_grupos_se_filtran_por_departamento(): void
    {
        $c = $this->montarCatalogoParaFiltrar();

        $this->actingAs($this->director->user)
            ->get(route('grupo-lista', ['area' => $c['danza']->id]))
            ->assertOk()
            // La FILA, no el nombre suelto: «Violin» aparece igual como opcion
            // del propio desplegable de promotorias.
            ->assertSee('Ballet - Martes tarde')
            ->assertDontSee('Violin - Lunes tarde');
    }

    public function test_los_grupos_se_filtran_por_promotoria(): void
    {
        $this->montarCatalogoParaFiltrar();

        $this->actingAs($this->director->user)
            ->get(route('grupo-lista', ['promotoria' => $this->violin->id]))
            ->assertOk()
            ->assertSee('Violin - Lunes tarde')
            ->assertDontSee('Ballet - Martes tarde');
    }

    public function test_los_grupos_se_filtran_por_profesor(): void
    {
        $c = $this->montarCatalogoParaFiltrar();

        $this->actingAs($this->director->user)
            ->get(route('grupo-lista', ['profesor' => $c['otroProfesor']->id]))
            ->assertOk()
            ->assertSee('Ballet - Martes tarde')
            ->assertDontSee('Violin - Lunes tarde');
    }

    /**
     * «Sin asignar» no es un adorno del desplegable.
     *
     * Una promotoria sin nadie asignado es aquella en la que NADIE puede
     * registrar clases: poder listar sus grupos de un vistazo es lo que
     * convierte un hueco del catalogo en una tarea.
     */
    public function test_los_grupos_se_filtran_por_promotoria_sin_profesor(): void
    {
        $this->montarCatalogoParaFiltrar();

        $huerfana = Promotoria::create(['nombre' => 'Titeres', 'area_id' => $this->musica->id]);
        Grupo::create([
            'promotoria_id' => $huerfana->id,
            'nombre' => 'Viernes tarde',
            'nivel' => 'basico',
            'salon' => 'Salon 3',
            'cupo_maximo' => 10,
        ]);

        $this->actingAs($this->director->user)
            ->get(route('grupo-lista', ['profesor' => GrupoController::PROFESOR_SIN_ASIGNAR]))
            ->assertOk()
            ->assertSee('Titeres - Viernes tarde')
            ->assertDontSee('Ballet - Martes tarde');
    }

    /**
     * Desde Gestion tambien se crean varios grupos del mismo nivel.
     *
     * Es el mismo caso que en el Panel, y hace falta probarlo aparte porque son
     * dos formularios distintos con dos juegos de reglas: la regla vieja —un
     * nivel por promotoria— estaba escrita en los dos sitios, y quitarla de uno
     * solo dejaba el otro cerrado.
     */
    public function test_gestion_crea_varios_grupos_del_mismo_nivel(): void
    {
        $this->actingAs($this->director->user)->post(route('grupo-nuevo'), [
            'promotoria_id' => $this->violin->id,
            'nombre' => 'Lunes tarde',
            'nivel' => 'basico',
            'sesiones' => [1 => ['activo' => 1, 'desde' => '16:00', 'hasta' => '18:00']],
            'salon' => 'Salon 1',
            'cupo_maximo' => 10,
        ]);

        $this->actingAs($this->director->user)->post(route('grupo-nuevo'), [
            'promotoria_id' => $this->violin->id,
            'nombre' => 'Jueves tarde',
            'nivel' => 'basico',
            'sesiones' => [4 => ['activo' => 1, 'desde' => '16:00', 'hasta' => '18:00']],
            'salon' => 'Salon 1',
            'cupo_maximo' => 10,
        ]);

        $this->assertSame(2, $this->violin->grupos()->count());
        $this->assertSame(
            ['Jueves tarde', 'Lunes tarde'],
            $this->violin->grupos()->orderBy('nombre')->pluck('nombre')->all()
        );
    }

    public function test_gestion_rechaza_un_nombre_de_grupo_repetido(): void
    {
        Grupo::create([
            'promotoria_id' => $this->violin->id,
            'nombre' => 'Lunes tarde',
            'nivel' => 'basico',
            'salon' => 'Salon 1',
            'cupo_maximo' => 10,
        ]);

        $this->actingAs($this->director->user)
            ->post(route('grupo-nuevo'), [
                'promotoria_id' => $this->violin->id,
                'nombre' => 'Lunes tarde',
                'nivel' => 'avanzado',
                'sesiones' => [4 => ['activo' => 1, 'desde' => '16:00', 'hasta' => '18:00']],
                'salon' => 'Salon 2',
                'cupo_maximo' => 10,
            ])
            ->assertSessionHasErrors('nombre');

        $this->assertSame(1, $this->violin->grupos()->count());
    }

    /**
     * La garantia de verdad esta en el motor, no en el formulario.
     *
     * Se comprueban las dos caras: que el unico por NOMBRE existe —es lo que
     * impide dos grupos indistinguibles si algo se salta la validacion— y que
     * el viejo por NIVEL ya no esta, porque mientras siguiera ahi la base
     * rechazaria el segundo Basico por su cuenta, con la aplicacion diciendo
     * que si.
     */
    public function test_el_esquema_limita_el_nombre_y_ya_no_el_nivel(): void
    {
        $indices = collect(DB::select('SHOW INDEX FROM grupos'))->pluck('Key_name')->unique();

        $this->assertTrue($indices->contains('un_nombre_por_promotoria'));
        $this->assertFalse($indices->contains('un_nivel_por_promotoria'));
    }

    /** Los filtros se combinan: departamento Y profesor a la vez. */
    public function test_los_filtros_de_grupos_se_combinan(): void
    {
        $c = $this->montarCatalogoParaFiltrar();

        // Danza + el profesor de Violin: no existe esa combinacion.
        $this->actingAs($this->director->user)
            ->get(route('grupo-lista', [
                'area' => $c['danza']->id,
                'profesor' => $this->profesor->id,
            ]))
            ->assertOk()
            ->assertSee('Ninguno coincide con estos filtros', false);
    }

    /**
     * Sin nada y sin coincidencias son dos mensajes distintos.
     *
     * «No hay grupos» manda a crear uno; «ninguno coincide» manda a soltar el
     * filtro. Con el mismo texto, quien filtra de mas cree que borro el catalogo.
     */
    public function test_sin_grupos_y_sin_coincidencias_dicen_cosas_distintas(): void
    {
        $this->actingAs($this->director->user)
            ->get(route('grupo-lista'))
            ->assertOk()
            ->assertSee('Todavía no hay nada aquí', false);
    }

    /** Los otros catalogos no pintan barra de filtros. */
    public function test_los_otros_catalogos_siguen_sin_filtros(): void
    {
        $this->actingAs($this->director->user)
            ->get(route('area-lista'))
            ->assertOk()
            ->assertDontSee('class="filtros"', false);
    }

    // -----------------------------------------------------------------------
    // Los tres indicadores nuevos del tablero
    // -----------------------------------------------------------------------

    /** Registra $cuantas clases del grupo, y confirma las primeras $verificadas. */
    private function dictar(Grupo $grupo, int $cuantas, int $verificadas = 0): void
    {
        $inscritos = Matricula::where('grupo_id', $grupo->id)
            ->whereIn('estado', Matricula::ESTADOS_INSCRITO)
            ->get();

        for ($n = 1; $n <= $cuantas; $n++) {
            $clase = Clase::create([
                'grupo_id' => $grupo->id,
                'periodo_id' => $this->periodo->id,
                'fecha_hora' => Carbon::today()->subDays($n)->setTime(10, 0),
                'registrada_por_id' => $this->profesor->id,
                'confirmaciones_requeridas' => 1,
            ]);

            if ($n <= $verificadas && $inscritos->isNotEmpty()) {
                ConfirmacionClase::create([
                    'clase_id' => $clase->id,
                    'matricula_id' => $inscritos->first()->id,
                ]);
            }
        }
    }

    private function grupoDeViolin(): Grupo
    {
        return Grupo::create([
            'promotoria_id' => $this->violin->id,
            'nombre' => 'Lunes 4-6 p. m.',
            'nivel' => 'basico',
            'salon' => 'Salon 1',
            'cupo_maximo' => 20,
        ]);
    }

    public function test_el_mapa_de_actividad_cuenta_las_clases_del_periodo(): void
    {
        $this->dictar($this->grupoDeViolin(), 3);

        $this->actingAs($this->admin->user)
            ->get(route('gestion-estadisticas'))
            ->assertOk()
            ->assertSee('Actividad de la institución', false)
            ->assertSee('Clases dictadas')
            ->assertSee('Días de la semana con más clase', false);
    }

    /**
     * El ranking de profesores enseña las verificadas al lado de las dictadas.
     *
     * No es un adorno: registrar una clase es apretar un boton y quien lo aprieta
     * es parte interesada. Sin la segunda cifra, el ranking premiaria a quien mas
     * veces lo pulsa, que no es lo mismo que quien mas clases dio.
     */
    public function test_el_ranking_de_profesores_separa_dictadas_de_verificadas(): void
    {
        $grupo = $this->grupoDeViolin();
        $matricula = $this->matricular($this->estudiante, $this->violin, Matricula::ACTIVA);
        $matricula->grupo_id = $grupo->id;
        $matricula->save();

        $this->dictar($grupo, 4, verificadas: 2);

        $this->actingAs($this->admin->user)
            ->get(route('gestion-estadisticas'))
            ->assertOk()
            ->assertSee('Profesores con más clases', false)
            ->assertSee('Profe')
            ->assertSee('(50%)', false);
    }

    /**
     * El ranking de constancia exige un minimo de clases.
     *
     * Sin el, un 1 de 1 seria un 100 % y desplazaria a quien lleva veinte de
     * veintidos: la lista se llenaria de gente que apenas empezo.
     */
    public function test_el_ranking_de_constancia_deja_fuera_a_quien_tiene_pocas_clases(): void
    {
        $grupo = $this->grupoDeViolin();
        $matricula = $this->matricular($this->estudiante, $this->violin, Matricula::ACTIVA);
        $matricula->grupo_id = $grupo->id;
        $matricula->save();

        $this->dictar($grupo, 3);

        // Tres asistencias perfectas, por debajo del minimo: no entra.
        foreach (Clase::where('grupo_id', $grupo->id)->get() as $clase) {
            Asistencia::create([
                'clase_id' => $clase->id,
                'matricula_id' => $matricula->id,
                'estado' => Asistencia::ASISTIO,
            ]);
        }

        $this->actingAs($this->admin->user)
            ->get(route('gestion-estadisticas'))
            ->assertOk()
            ->assertSee('Todavía no hay suficientes clases', false);
    }

    public function test_el_ranking_de_constancia_entra_al_llegar_al_minimo(): void
    {
        $grupo = $this->grupoDeViolin();
        $matricula = $this->matricular($this->estudiante, $this->violin, Matricula::ACTIVA);
        $matricula->grupo_id = $grupo->id;
        $matricula->save();

        $this->dictar($grupo, 6);

        // Cinco presentes y una falta: 83 %.
        foreach (Clase::where('grupo_id', $grupo->id)->orderBy('id')->get() as $indice => $clase) {
            Asistencia::create([
                'clase_id' => $clase->id,
                'matricula_id' => $matricula->id,
                'estado' => $indice === 0 ? Asistencia::FALTO : Asistencia::ASISTIO,
            ]);
        }

        $this->actingAs($this->admin->user)
            ->get(route('gestion-estadisticas'))
            ->assertOk()
            ->assertSee('Estudiantes más constantes', false)
            ->assertSee('5 de 6')
            ->assertSee('83%');
    }

    /** Las flechas caminan por los periodos. */
    public function test_se_puede_pedir_el_tablero_de_otro_periodo(): void
    {
        $viejo = Periodo::create([
            'nombre' => '2025-2',
            'fecha_inicio' => '2025-07-01',
            'fecha_fin' => '2025-12-15',
            'activo' => false,
            'matriculas_abiertas' => false,
        ]);

        $this->actingAs($this->admin->user)
            ->get(route('gestion-estadisticas-periodo', $viejo))
            ->assertOk()
            ->assertSee('2025-2');
    }

    /**
     * «Sigue» y «Deja» del arbol de departamentos.
     *
     * No habia nada que mirara esos dos numeros: la pantalla se probaba con
     * `assertOk` y con la satisfaccion, pero la consulta que los calcula podia
     * cambiar de sentido sin que la suite se enterara. Lo que fija es la parte
     * menos obvia: una CANCELACION SOLICITADA cuenta como que SIGUE, porque
     * hasta que alguien la resuelva la persona esta matriculada.
     */
    public function test_el_arbol_cuenta_las_cancelaciones_pendientes_como_que_siguen(): void
    {
        $this->matricular($this->estudiante, $this->violin, Matricula::ACTIVA);
        $this->matricular($this->crearEstudiante('beto'), $this->violin, Matricula::CANCELACION_SOLICITADA);
        $this->matricular($this->crearEstudiante('caro'), $this->violin, Matricula::RETIRADA);
        // Pendiente: no entra en el arbol ni por un lado ni por el otro.
        $this->matricular($this->crearEstudiante('dani'), $this->violin, Matricula::PENDIENTE);

        $arbol = $this->actingAs($this->admin->user)
            ->get(route('gestion-estadisticas'))
            ->assertOk()
            ->viewData('arbolDepartamentos');

        $this->assertCount(1, $arbol);
        $this->assertSame('Musica', $arbol[0]['nombre']);
        $this->assertSame(2, $arbol[0]['total'], 'Sigue: la activa y la que pidio cancelacion.');
        $this->assertSame(1, $arbol[0]['retirados'], 'Deja: solo la retirada.');
    }
}
