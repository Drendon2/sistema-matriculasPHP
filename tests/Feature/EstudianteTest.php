<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Asistencia;
use App\Models\Clase;
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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Las pantallas propias del estudiante que no son el catalogo ni sus matriculas:
 * confirmar clases, ver companeros, renovar y completar su perfil.
 *
 * La pieza de fondo es la confirmacion de clases: quien registra la clase es
 * parte interesada, asi que lo que la verifica es que varios estudiantes den fe.
 */
class EstudianteTest extends TestCase
{
    use RefreshDatabase;

    private Periodo $periodo;
    private Periodo $anterior;
    private Promotoria $violin;
    private Promotoria $danza;
    private Perfil $profesor;
    private Perfil $ana;
    private Grupo $grupo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->anterior = Periodo::create([
            'nombre' => '2025-2',
            'fecha_inicio' => '2025-07-15',
            'fecha_fin' => '2025-12-15',
            'activo' => false,
            'matriculas_abiertas' => false,
        ]);

        $this->periodo = Periodo::create([
            'nombre' => '2026-1',
            'fecha_inicio' => '2026-01-15',
            'fecha_fin' => '2026-06-30',
            'activo' => true,
            'matriculas_abiertas' => true,
        ]);

        $area = Area::create(['nombre' => 'Musica']);

        $this->profesor = $this->crearPerfil('profe', 'profesor');
        $this->ana = $this->crearEstudiante('ana');

        $this->violin = Promotoria::create([
            'nombre' => 'Violin',
            'area_id' => $area->id,
            'profesor_id' => $this->profesor->id,
        ]);
        $this->danza = Promotoria::create(['nombre' => 'Danza', 'area_id' => $area->id]);

        $this->grupo = Grupo::create([
            'promotoria_id' => $this->violin->id,
            'nivel' => 'basico',
            'horario' => 'Lunes 4 p. m.',
            'salon' => 'A1',
            'cupo_maximo' => 10,
        ]);
    }

    private function crearPerfil(string $username, string $rol): Perfil
    {
        $user = User::create(['username' => $username, 'password' => 'x', 'activo' => true]);

        return Perfil::create([
            'user_id' => $user->id,
            'rol' => $rol,
            'nombre_completo' => ucfirst($username),
            'fecha_nacimiento' => Carbon::today()->subYears(25)->toDateString(),
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

    private function inscribir(Perfil $perfil, Promotoria $promotoria, ?Grupo $grupo = null, ?Periodo $periodo = null): Matricula
    {
        $matricula = new Matricula([
            'estudiante_id' => $perfil->id,
            'promotoria_id' => $promotoria->id,
            'periodo_id' => ($periodo ?? $this->periodo)->id,
            'estado' => Matricula::ACTIVA,
        ]);
        $matricula->save();

        if ($grupo !== null) {
            $matricula->grupo_id = $grupo->id;
            $matricula->save();
        }

        return $matricula;
    }

    // -----------------------------------------------------------------------
    // Mis clases
    // -----------------------------------------------------------------------

    public function test_confirmar_una_clase_la_cuenta(): void
    {
        $this->inscribir($this->ana, $this->violin, $this->grupo);
        $clase = Clase::abrir($this->grupo, $this->periodo, $this->profesor);

        $this->actingAs($this->ana->user)
            ->post(route('confirmar-clase', $clase))
            ->assertSessionHas('success');

        $this->assertSame(1, $clase->confirmaciones()->count());
    }

    /** Dos pulsaciones del mismo boton no pueden chocar contra el indice unico. */
    public function test_confirmar_dos_veces_no_duplica(): void
    {
        $this->inscribir($this->ana, $this->violin, $this->grupo);
        $clase = Clase::abrir($this->grupo, $this->periodo, $this->profesor);

        $this->actingAs($this->ana->user)->post(route('confirmar-clase', $clase));
        $this->actingAs($this->ana->user)->post(route('confirmar-clase', $clase));

        $this->assertSame(1, $clase->confirmaciones()->count());
    }

    public function test_se_puede_quitar_la_confirmacion_dentro_del_plazo(): void
    {
        $this->inscribir($this->ana, $this->violin, $this->grupo);
        $clase = Clase::abrir($this->grupo, $this->periodo, $this->profesor);

        $this->actingAs($this->ana->user)->post(route('confirmar-clase', $clase));
        $this->actingAs($this->ana->user)
            ->post(route('retirar-confirmacion-clase', $clase))
            ->assertSessionHas('success');

        $this->assertSame(0, $clase->confirmaciones()->count());
    }

    /**
     * Pasado el plazo lo registrado es definitivo. Si retirar siguiera abierto,
     * una clase ya verificada podria dejar de estarlo semanas mas tarde.
     */
    public function test_vencido_el_plazo_no_se_confirma(): void
    {
        $this->inscribir($this->ana, $this->violin, $this->grupo);

        $clase = Clase::abrir($this->grupo, $this->periodo, $this->profesor);
        $clase->fecha_hora = now()->subHours(Clase::VENTANA_CONFIRMACION_HORAS + 1);
        $clase->save();

        $this->actingAs($this->ana->user)
            ->post(route('confirmar-clase', $clase))
            ->assertSessionHas('error');

        $this->assertSame(0, $clase->confirmaciones()->count());
    }

    /**
     * Se confirma lo que uno vio: quien entra al grupo despues no estuvo en las
     * clases anteriores y no puede dar fe de ellas.
     */
    public function test_no_se_confirma_una_clase_anterior_a_la_matricula(): void
    {
        $clase = Clase::abrir($this->grupo, $this->periodo, $this->profesor);
        $clase->fecha_hora = now()->subHours(2);
        $clase->save();

        $matricula = $this->inscribir($this->ana, $this->violin, $this->grupo);
        $matricula->fecha = now()->subHour();
        $matricula->save();

        $this->actingAs($this->ana->user)
            ->post(route('confirmar-clase', $clase))
            ->assertSessionHas('error');

        $this->assertSame(0, $clase->confirmaciones()->count());
    }

    public function test_no_se_confirma_una_clase_de_otro_grupo(): void
    {
        $otroGrupo = Grupo::create([
            'promotoria_id' => $this->danza->id,
            'nivel' => 'basico',
            'horario' => 'Viernes',
            'salon' => 'B2',
            'cupo_maximo' => 5,
        ]);

        $this->inscribir($this->ana, $this->violin, $this->grupo);
        $ajena = Clase::abrir($otroGrupo, $this->periodo, $this->profesor);

        $this->actingAs($this->ana->user)
            ->post(route('confirmar-clase', $ajena))
            ->assertSessionHas('error');

        $this->assertSame(0, $ajena->confirmaciones()->count());
    }

    /** En un grupo de uno o dos basta una confirmacion: el requisito tiene que ser alcanzable. */
    public function test_un_grupo_pequeno_se_verifica_con_una_confirmacion(): void
    {
        $this->inscribir($this->ana, $this->violin, $this->grupo);
        $clase = Clase::abrir($this->grupo, $this->periodo, $this->profesor);

        $this->actingAs($this->ana->user)->post(route('confirmar-clase', $clase));

        $this->assertTrue($clase->fresh()->estaConfirmada());
    }

    public function test_la_pantalla_de_mis_clases_abre(): void
    {
        $this->inscribir($this->ana, $this->violin, $this->grupo);
        Clase::abrir($this->grupo, $this->periodo, $this->profesor);

        $this->actingAs($this->ana->user)
            ->get(route('mis-clases'))
            ->assertOk()
            ->assertSee('Violin');
    }

    // -----------------------------------------------------------------------
    // Mis companeros
    // -----------------------------------------------------------------------

    public function test_los_companeros_son_los_de_la_misma_promotoria_y_periodo(): void
    {
        $samu = $this->crearEstudiante('samu');
        $beto = $this->crearEstudiante('beto');

        $this->inscribir($this->ana, $this->violin);
        $this->inscribir($samu, $this->violin);
        $this->inscribir($beto, $this->danza);

        $respuesta = $this->actingAs($this->ana->user)->get(route('mis-companeros'));

        $respuesta->assertOk();
        $respuesta->assertSee('Samu');
        $respuesta->assertDontSee('Beto');
    }

    /** Haber coincidido el semestre pasado no lo hace companero de este. */
    public function test_no_son_companeros_los_de_otro_periodo(): void
    {
        $samu = $this->crearEstudiante('samu');

        $this->inscribir($this->ana, $this->violin);
        $this->inscribir($samu, $this->violin, periodo: $this->anterior);

        $this->actingAs($this->ana->user)
            ->get(route('mis-companeros'))
            ->assertDontSee('Samu');
    }

    // -----------------------------------------------------------------------
    // Renovacion
    // -----------------------------------------------------------------------

    public function test_un_estudiante_nuevo_no_puede_renovar(): void
    {
        $this->actingAs($this->ana->user)
            ->get(route('renovar-matricula'))
            ->assertRedirect(route('promotorias-disponibles'))
            ->assertSessionHas('error');
    }

    /**
     * La pantalla no se habia renderizado NUNCA en las pruebas: las tres que
     * habia acababan en redireccion. Se colo asi un error de compilacion de
     * Blade que solo se veia abriendola en el navegador.
     */
    public function test_la_pantalla_de_renovacion_abre_con_sus_dos_bloques(): void
    {
        $this->inscribir($this->ana, $this->violin, periodo: $this->anterior);

        $this->actingAs($this->ana->user)
            ->get(route('renovar-matricula'))
            ->assertOk()
            // Lo que ya cursó, para renovar.
            ->assertSee('Violin')
            // Y el desplegable de promotorías nuevas, que es donde estaba el fallo.
            ->assertSee('Danza');
    }

    /**
     * Los campos de la encuesta van sufijados con el id de la matricula: en la
     * misma pagina puede haber una tanda por cada promotoria cursada.
     *
     * @return array<string, mixed>
     */
    private function respuestas(Matricula $matricula, int $general = 5, int $profesor = 4): array
    {
        return [
            "satisfaccion_general_{$matricula->id}" => $general,
            "calificacion_profesor_{$matricula->id}" => $profesor,
            "horario_funciono_{$matricula->id}" => 1,
            "recomendaria_{$matricula->id}" => 1,
            "comentario_{$matricula->id}" => '',
        ];
    }

    public function test_renovar_crea_la_matricula_pendiente_y_guarda_la_encuesta(): void
    {
        $cursada = $this->inscribir($this->ana, $this->violin, periodo: $this->anterior);

        $this->actingAs($this->ana->user)
            ->post(route('renovar-matricula.guardar'), [
                'promotoria' => [$this->violin->id],
                ...$this->respuestas($cursada),
            ])
            ->assertRedirect(route('mis-matriculas'))
            ->assertSessionHas('success');

        $nueva = Matricula::where('estudiante_id', $this->ana->id)
            ->where('periodo_id', $this->periodo->id)
            ->first();

        $this->assertNotNull($nueva);
        $this->assertSame(Matricula::PENDIENTE, $nueva->estado);

        // La encuesta evalua el periodo que TERMINO, no aquel al que se renueva,
        // y ahora dice de que PROMOTORIA habla.
        $encuesta = EncuestaSatisfaccion::first();
        $this->assertNotNull($encuesta);
        $this->assertSame($this->anterior->id, $encuesta->periodo_id);
        $this->assertSame($this->violin->id, $encuesta->promotoria_id);
    }

    /**
     * Quien curso dos promotorias contesta dos veces. Es el motivo entero del
     * cambio: una sola respuesta no podia decir de que profesor hablaba.
     */
    public function test_se_contesta_una_encuesta_por_promotoria_cursada(): void
    {
        $violin = $this->inscribir($this->ana, $this->violin, periodo: $this->anterior);
        $danza = $this->inscribir($this->ana, $this->danza, periodo: $this->anterior);

        $this->actingAs($this->ana->user)
            ->post(route('renovar-matricula.guardar'), [
                'promotoria' => [$this->violin->id],
                ...$this->respuestas($violin, general: 5, profesor: 5),
                ...$this->respuestas($danza, general: 2, profesor: 1),
            ])
            ->assertSessionHas('success');

        $this->assertSame(2, EncuestaSatisfaccion::count());

        $deDanza = EncuestaSatisfaccion::where('promotoria_id', $this->danza->id)->first();
        $this->assertNotNull($deDanza);
        $this->assertSame(1, $deDanza->calificacion_profesor);
    }

    /** Si falta media encuesta no se guarda ninguna ni se renueva nada. */
    public function test_una_encuesta_incompleta_no_renueva(): void
    {
        $violin = $this->inscribir($this->ana, $this->violin, periodo: $this->anterior);
        $this->inscribir($this->ana, $this->danza, periodo: $this->anterior);

        $this->actingAs($this->ana->user)
            ->post(route('renovar-matricula.guardar'), [
                'promotoria' => [$this->violin->id],
                ...$this->respuestas($violin),
            ])
            ->assertSessionHas('error');

        $this->assertSame(0, EncuestaSatisfaccion::count());
        $this->assertSame(0, Matricula::where('periodo_id', $this->periodo->id)->count());
    }

    public function test_renovar_sin_elegir_nada_no_matricula(): void
    {
        $this->inscribir($this->ana, $this->violin, periodo: $this->anterior);

        $this->actingAs($this->ana->user)
            ->post(route('renovar-matricula.guardar'), [])
            ->assertSessionHas('error');

        $this->assertSame(
            0,
            Matricula::where('periodo_id', $this->periodo->id)->count()
        );
    }

    /** Un antiguo puede dejar lo suyo y entrar a otra: ahi es un estudiante nuevo. */
    public function test_se_puede_renovar_entrando_a_una_promotoria_nueva(): void
    {
        $this->inscribir($this->ana, $this->violin, periodo: $this->anterior);

        $cursada = Matricula::where('estudiante_id', $this->ana->id)
            ->where('periodo_id', $this->anterior->id)
            ->first();

        $this->actingAs($this->ana->user)
            ->post(route('renovar-matricula.guardar'), [
                'promotoria_nueva' => $this->danza->id,
                ...$this->respuestas($cursada, general: 3, profesor: 3),
            ])
            ->assertSessionHas('success');

        $this->assertSame(
            $this->danza->id,
            Matricula::where('periodo_id', $this->periodo->id)->first()->promotoria_id
        );
    }

    public function test_no_se_renueva_con_las_matriculas_cerradas(): void
    {
        $this->inscribir($this->ana, $this->violin, periodo: $this->anterior);

        $this->periodo->matriculas_abiertas = false;
        $this->periodo->save();

        $this->actingAs($this->ana->user)
            ->get(route('renovar-matricula'))
            ->assertRedirect(route('promotorias-disponibles'));
    }

    // -----------------------------------------------------------------------
    // Encuesta de salida
    // -----------------------------------------------------------------------

    /**
     * Salirse es el ultimo momento en que esa persona sigue estando: quien se va
     * no vuelve a entrar a contestar nada.
     */
    public function test_al_salir_de_una_activa_se_pide_la_encuesta(): void
    {
        $matricula = $this->inscribir($this->ana, $this->violin);

        $this->actingAs($this->ana->user)
            ->get(route('mis-matriculas.confirmar-retiro', $matricula))
            ->assertOk()
            ->assertSee('¿Nos cuentas cómo te fue?', false)
            ->assertSee('Salir sin contestar');
    }

    public function test_la_encuesta_de_salida_se_guarda_con_su_promotoria(): void
    {
        $matricula = $this->inscribir($this->ana, $this->violin);

        $this->actingAs($this->ana->user)
            ->post(route('mis-matriculas.retirar', $matricula), [
                'satisfaccion_general' => 2,
                'calificacion_profesor' => 3,
                'horario_funciono' => 0,
                'recomendaria' => 0,
                'comentario' => 'Me cambiaron el horario.',
            ])
            ->assertSessionHas('success');

        $encuesta = EncuestaSatisfaccion::first();

        $this->assertNotNull($encuesta);
        $this->assertSame($this->violin->id, $encuesta->promotoria_id);
        $this->assertSame($this->periodo->id, $encuesta->periodo_id);
        // Y la salida se tramita igual: la encuesta no la sustituye.
        $this->assertSame(Matricula::CANCELACION_SOLICITADA, $matricula->fresh()->estado);
    }

    /**
     * No es obligatoria: poner cinco preguntas entre alguien y la puerta recoge
     * respuestas puestas al azar, no opiniones.
     */
    public function test_se_puede_salir_sin_contestar(): void
    {
        $matricula = $this->inscribir($this->ana, $this->violin);

        $this->actingAs($this->ana->user)
            ->post(route('mis-matriculas.retirar', $matricula), ['sin_contestar' => 1])
            ->assertSessionHas('success');

        $this->assertSame(0, EncuestaSatisfaccion::count());
        $this->assertSame(Matricula::CANCELACION_SOLICITADA, $matricula->fresh()->estado);
    }

    /**
     * A quien nunca tuvo clase no se le pregunta como le fue: no recogeria una
     * opinion, recogeria ruido.
     */
    public function test_a_una_pendiente_no_se_le_pide_encuesta(): void
    {
        $matricula = new Matricula([
            'estudiante_id' => $this->ana->id,
            'promotoria_id' => $this->violin->id,
            'periodo_id' => $this->periodo->id,
            'estado' => Matricula::PENDIENTE,
        ]);
        $matricula->save();

        $this->actingAs($this->ana->user)
            ->get(route('mis-matriculas.confirmar-retiro', $matricula))
            ->assertRedirect(route('mis-matriculas'));

        $this->actingAs($this->ana->user)
            ->post(route('mis-matriculas.retirar', $matricula))
            ->assertSessionHas('success');

        $this->assertSame(Matricula::RETIRADA, $matricula->fresh()->estado);
        $this->assertSame(0, EncuestaSatisfaccion::count());
    }

    /** Si ya la valoro al renovar, no se le vuelve a preguntar lo mismo. */
    public function test_no_se_pregunta_dos_veces_por_la_misma_promotoria(): void
    {
        $matricula = $this->inscribir($this->ana, $this->violin);

        EncuestaSatisfaccion::create([
            'perfil_id' => $this->ana->id,
            'promotoria_id' => $this->violin->id,
            'periodo_id' => $this->periodo->id,
            'satisfaccion_general' => 4,
            'calificacion_profesor' => 4,
            'horario_funciono' => true,
            'recomendaria' => true,
        ]);

        $this->actingAs($this->ana->user)
            ->get(route('mis-matriculas.confirmar-retiro', $matricula))
            ->assertOk()
            ->assertSee('Ya nos contaste cómo te fue', false)
            ->assertDontSee('¿Nos cuentas cómo te fue?', false);
    }

    // -----------------------------------------------------------------------
    // Mi perfil
    // -----------------------------------------------------------------------

    public function test_mi_perfil_abre_para_una_cuenta_sin_rol(): void
    {
        $sinRol = $this->crearPerfil('nueva', '');

        $this->actingAs($sinRol->user)
            ->get(route('mi-perfil'))
            ->assertOk();
    }

    /**
     * Cada quien ve su propia asistencia sin tener que pedirsela a nadie. Antes
     * el mapa solo existia en la ficha que abre el personal.
     */
    public function test_el_estudiante_ve_su_asistencia_en_su_perfil(): void
    {
        $matricula = $this->inscribir($this->ana, $this->violin, $this->grupo);
        $clase = Clase::abrir($this->grupo, $this->periodo, $this->profesor);

        \App\Models\Asistencia::create([
            'clase_id' => $clase->id,
            'matricula_id' => $matricula->id,
            'estado' => 'asistio',
        ]);

        $this->actingAs($this->ana->user)
            ->get(route('mi-perfil'))
            ->assertOk()
            ->assertSee('Asistencia')
            ->assertSee('Racha')
            // La cabecera de dias, que es lo que hace legible la rejilla.
            ->assertSee('asis-dias', false);
    }

    /** Y quien dicta ve lo que dio y cuanto le verificaron. */
    public function test_quien_dicta_ve_sus_clases_en_su_perfil(): void
    {
        $this->inscribir($this->ana, $this->violin, $this->grupo);
        Clase::abrir($this->grupo, $this->periodo, $this->profesor);

        $this->actingAs($this->profesor->user)
            ->get(route('mi-perfil'))
            ->assertOk()
            ->assertSee('Clases dictadas')
            ->assertSee('confirmadas por sus estudiantes');
    }

    /**
     * Sin clases no se pinta nada: un panel de ceros no informa y ademas miente
     * por omision — no se distingue «no he faltado nunca» de «no ha empezado el
     * periodo».
     */
    public function test_sin_clases_el_perfil_no_ensena_panel_de_asistencia(): void
    {
        $this->actingAs($this->ana->user)
            ->get(route('mi-perfil'))
            ->assertOk()
            ->assertDontSee('asis-dias', false);
    }

    // -----------------------------------------------------------------------
    // El paso entre periodos del panel de asistencia
    // -----------------------------------------------------------------------

    /**
     * Le da a Ana matricula y clase en el periodo anterior, el que ya trae el
     * `setUp`, para que tenga dos por los que caminar.
     */
    private function darleElPeriodoAnterior(): Periodo
    {
        $matricula = $this->inscribir($this->ana, $this->violin, $this->grupo, $this->anterior);

        $clase = Clase::create([
            'grupo_id' => $this->grupo->id,
            'periodo_id' => $this->anterior->id,
            'fecha_hora' => Carbon::parse('2025-08-05 10:00'),
            'registrada_por_id' => $this->profesor->id,
            'confirmaciones_requeridas' => 1,
        ]);

        Asistencia::create([
            'clase_id' => $clase->id,
            'matricula_id' => $matricula->id,
            'estado' => Asistencia::ASISTIO,
        ]);

        return $this->anterior;
    }

    /**
     * Con un solo periodo no hay a donde ir, asi que no se pinta la barra.
     *
     * Dos flechas apagadas y un nombre no son una navegacion, son un adorno que
     * invita a pulsar algo que no hace nada.
     */
    public function test_con_un_solo_periodo_no_aparece_la_barra(): void
    {
        $this->inscribir($this->ana, $this->violin, $this->grupo);
        Clase::abrir($this->grupo, $this->periodo, $this->profesor);

        $this->actingAs($this->ana->user)
            ->get(route('mi-perfil'))
            ->assertOk()
            ->assertSee('asis-dias', false)
            ->assertDontSee('periodo-nav', false);
    }

    public function test_con_dos_periodos_las_flechas_caminan(): void
    {
        $this->inscribir($this->ana, $this->violin, $this->grupo);
        Clase::abrir($this->grupo, $this->periodo, $this->profesor);
        $anterior = $this->darleElPeriodoAnterior();

        $this->actingAs($this->ana->user)
            ->get(route('mi-perfil'))
            ->assertOk()
            ->assertSee('periodo-nav', false)
            // Arranca en el EN CURSO, y la flecha de atras lleva al anterior.
            ->assertSee($this->periodo->nombre)
            ->assertSee(route('mi-perfil', ['periodo' => $anterior->id]), false);

        $this->actingAs($this->ana->user)
            ->get(route('mi-perfil', ['periodo' => $anterior->id]))
            ->assertOk()
            ->assertSee('2025-2');
    }

    /**
     * Solo se ofrecen los periodos donde esa persona TIENE algo.
     *
     * Un periodo suelto en el que no estuvo matriculada no puede aparecer: esa
     * flecha llevaria a un panel vacio y quien navega no sabria si es que no fue
     * a clase o que no estaba matriculado.
     */
    /**
     * Un periodo donde estuvo matriculada pero NADIE registro clases no se
     * ofrece.
     *
     * La flecha llevaria a un panel de ceros, y este proyecto ya tiene decidido
     * que eso no se pinta: no informa y ademas miente por omision, porque no se
     * distingue «no falte nunca» de «no hubo clases».
     */
    public function test_no_se_ofrece_un_periodo_sin_clases_registradas(): void
    {
        $this->inscribir($this->ana, $this->violin, $this->grupo);
        Clase::abrir($this->grupo, $this->periodo, $this->profesor);

        // Matriculada en el anterior, pero alli nadie registro una sola clase.
        $this->inscribir($this->ana, $this->violin, $this->grupo, $this->anterior);

        $this->actingAs($this->ana->user)
            ->get(route('mi-perfil'))
            ->assertOk()
            ->assertDontSee('periodo-nav', false);
    }

    public function test_no_se_ofrece_un_periodo_en_el_que_no_estuvo(): void
    {
        $this->inscribir($this->ana, $this->violin, $this->grupo);
        Clase::abrir($this->grupo, $this->periodo, $this->profesor);

        $ajeno = Periodo::create([
            'nombre' => '2024-1',
            'fecha_inicio' => '2024-01-15',
            'fecha_fin' => '2024-06-30',
            'activo' => false,
            'matriculas_abiertas' => false,
        ]);

        $this->actingAs($this->ana->user)
            ->get(route('mi-perfil'))
            ->assertOk()
            ->assertDontSee(route('mi-perfil', ['periodo' => $ajeno->id]), false);
    }

    /** Pedir un periodo ajeno no rompe: se cae al que corresponde. */
    public function test_pedir_un_periodo_ajeno_cae_en_el_que_corresponde(): void
    {
        $this->inscribir($this->ana, $this->violin, $this->grupo);
        Clase::abrir($this->grupo, $this->periodo, $this->profesor);

        $this->actingAs($this->ana->user)
            ->get(route('mi-perfil', ['periodo' => 9999]))
            ->assertOk()
            ->assertSee($this->periodo->nombre);
    }

    public function test_se_actualiza_el_telefono(): void
    {
        $this->actingAs($this->ana->user)
            ->post(route('mi-perfil.guardar'), ['accion' => 'contacto', 'telefono' => '3111111111'])
            ->assertSessionHas('success');

        $this->assertSame('3111111111', $this->ana->fresh()->telefono);
    }

    public function test_se_guarda_el_correo(): void
    {
        $this->actingAs($this->ana->user)
            ->post(route('mi-perfil.guardar'), ['accion' => 'correo', 'correo' => 'ana@ejemplo.co'])
            ->assertSessionHas('success');

        $this->assertSame('ana@ejemplo.co', $this->ana->fresh()->user->email);
    }

    /** Dejarlo en blanco lo BORRA, y queda null y no cadena vacia. */
    public function test_el_correo_en_blanco_lo_deja_vacio(): void
    {
        $this->ana->user->update(['email' => 'ana@ejemplo.co']);

        $this->actingAs($this->ana->user)
            ->post(route('mi-perfil.guardar'), ['accion' => 'correo', 'correo' => ''])
            ->assertSessionHas('success');

        $this->assertNull($this->ana->fresh()->user->email);
    }

    public function test_un_correo_mal_escrito_se_rechaza(): void
    {
        $this->actingAs($this->ana->user)
            ->post(route('mi-perfil.guardar'), ['accion' => 'correo', 'correo' => 'esto-no-es-un-correo'])
            ->assertSessionHasErrors('correo');

        $this->assertNull($this->ana->fresh()->user->email);
    }

    /**
     * La foto se convierte a WebP antes de tocar el disco: es una diferencia
     * deliberada con el original, que guardaba el archivo tal como llegaba del
     * celular (ver `Imagen`).
     */
    public function test_la_foto_se_guarda_convertida_a_webp(): void
    {
        Storage::fake('local');

        $this->actingAs($this->ana->user)
            ->post(route('mi-perfil.guardar'), [
                'accion' => 'foto',
                'foto_perfil' => UploadedFile::fake()->image('selfie.jpg', 1600, 1200),
            ])
            ->assertSessionHas('success');

        $ruta = $this->ana->fresh()->foto_perfil;

        $this->assertStringEndsWith('.webp', $ruta);
        Storage::disk('local')->assertExists($ruta);
    }

    public function test_se_guarda_la_encuesta_demografica(): void
    {
        $this->actingAs($this->ana->user)
            ->post(route('mi-perfil.guardar'), [
                'accion' => 'encuesta',
                'genero' => 'f',
                'barrio' => 'El Centro',
                'estrato' => 2,
                'nivel_educativo' => 'secundaria_com',
                'ocupacion' => 'estudiante',
                'autoriza_tratamiento_datos' => 1,
            ])
            ->assertSessionHas('success');

        $encuesta = EncuestaDemografica::where('perfil_id', $this->ana->id)->first();

        $this->assertNotNull($encuesta);
        $this->assertTrue($encuesta->esta_completa);
        $this->assertNotNull($encuesta->fecha_autorizacion);
    }

    /**
     * A un menor no se le pide el consentimiento: lo da su acudiente. Admitirlo
     * aqui seria recoger una autorizacion que la ley no reconoce.
     */
    public function test_a_un_menor_no_se_le_recoge_la_autorizacion(): void
    {
        $menor = $this->crearEstudiante('nino');
        $menor->fecha_nacimiento = Carbon::today()->subYears(12);
        $menor->save();

        $this->actingAs($menor->user)
            ->post(route('mi-perfil.guardar'), [
                'accion' => 'encuesta',
                'genero' => 'm',
                'barrio' => 'El Centro',
                'estrato' => 1,
                'nivel_educativo' => 'primaria_com',
                'ocupacion' => 'estudiante',
                'autoriza_tratamiento_datos' => 1,
            ]);

        $encuesta = EncuestaDemografica::where('perfil_id', $menor->id)->first();

        $this->assertFalse($encuesta->autoriza_tratamiento_datos);
        $this->assertNull($encuesta->fecha_autorizacion);
    }

    public function test_se_sube_un_papel_requerido(): void
    {
        Storage::fake('local');

        $requerido = DocumentoRequerido::create([
            'nombre' => 'Certificado de EPS',
            'obligatorio' => true,
            'activo' => true,
            'orden' => 1,
        ]);

        $this->actingAs($this->ana->user)
            ->post(route('mi-perfil.guardar'), [
                'accion' => 'papel',
                'documento_id' => $requerido->id,
                'archivo' => UploadedFile::fake()->create('eps.pdf', 20, 'application/pdf'),
            ])
            ->assertSessionHas('success');

        $this->assertSame(
            [],
            $this->ana->datosEstudiante->fresh()->load('documentos')->documentosFaltantes()
        );
    }

    // -----------------------------------------------------------------------
    // Archivos
    // -----------------------------------------------------------------------

    /** La foto de otro no se sirve a quien no comparte promotoria con esa persona. */
    public function test_la_foto_de_un_ajeno_no_se_entrega(): void
    {
        Storage::fake('local');

        $otro = $this->crearEstudiante('samu');
        $otro->foto_perfil = 'fotos_perfil/x.webp';
        $otro->save();
        Storage::disk('local')->put('fotos_perfil/x.webp', 'x');

        $this->actingAs($this->ana->user)
            ->get(route('ver-foto', $otro))
            ->assertNotFound();
    }

    public function test_la_foto_de_un_companero_si_se_entrega(): void
    {
        Storage::fake('local');

        $samu = $this->crearEstudiante('samu');
        $samu->foto_perfil = 'fotos_perfil/x.webp';
        $samu->save();
        Storage::disk('local')->put('fotos_perfil/x.webp', 'x');

        $this->inscribir($this->ana, $this->violin);
        $this->inscribir($samu, $this->violin);

        $this->actingAs($this->ana->user)
            ->get(route('ver-foto', $samu))
            ->assertOk();
    }

    public function test_el_documento_es_solo_del_administrador(): void
    {
        $datos = $this->ana->datosEstudiante;
        $datos->copia_documento = 'documentos/x.pdf';
        $datos->save();

        $this->actingAs($this->ana->user)
            ->get(route('descargar-documento', $datos))
            ->assertRedirect(route('post-login'));
    }
}
