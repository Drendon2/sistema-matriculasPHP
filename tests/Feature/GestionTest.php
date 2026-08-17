<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\ConfiguracionInstitucion;
use App\Models\CupoPromotoria;
use App\Models\DatosEstudiante;
use App\Models\DocumentoRequerido;
use App\Models\EncuestaDemografica;
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
            'nivel' => 'basico',
            'horario' => 'Lunes',
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

        \App\Models\EncuestaSatisfaccion::create([
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

        $acudiente = \App\Models\Acudiente::create([
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

    /** Sin periodo en curso no hay nada que medir, y la pantalla no se cae. */
    public function test_las_estadisticas_abren_sin_periodo_en_curso(): void
    {
        $this->periodo->activo = false;
        $this->periodo->save();

        $this->actingAs($this->admin->user)
            ->get(route('gestion-estadisticas'))
            ->assertOk()
            ->assertSee('no hay nada que medir');
    }
}
