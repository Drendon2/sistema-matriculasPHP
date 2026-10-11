<?php

namespace Tests\Feature;

use App\Models\Actividad;
use App\Models\Area;
use App\Models\Asistencia;
use App\Models\Clase;
use App\Models\CupoPromotoria;
use App\Models\DatosEstudiante;
use App\Models\Grupo;
use App\Models\Matricula;
use App\Models\Perfil;
use App\Models\Periodo;
use App\Models\Promotoria;
use App\Models\SesionGrupo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * La API de solo lectura del agente de voz (10/10/2026).
 *
 * Lo que se vigila es lo que no puede dejarse al modelo: que la edad se filtre
 * en el SERVIDOR, que el nivel no viaje, que «sin cupo» e «inscripciones
 * cerradas» se distingan, que una cancelacion en tramite cuente para el limite,
 * y que lo personal no exista fuera de la demo ni hable sin verificar.
 */
class ApiDelAgenteTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'llave-de-prueba-del-agente';

    // Escrita y no deducida de hoy: la fecha es lo que se compara.
    private const NACIMIENTO = '2006-03-15';

    private Periodo $periodo;

    private Area $musica;

    private Promotoria $iniciacion;

    private Promotoria $violin;

    private Grupo $grupoViolin;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'agente.token_sha256' => hash('sha256', self::TOKEN),
            'agente.datos_personales' => true,
        ]);

        $this->periodo = Periodo::create([
            'nombre' => '2026-2',
            'fecha_inicio' => Carbon::today()->subMonth()->toDateString(),
            'fecha_fin' => Carbon::today()->addMonths(4)->toDateString(),
            'activo' => true,
            'matriculas_abiertas' => true,
        ]);

        $this->musica = Area::create(['nombre' => 'Musica']);

        $this->iniciacion = Promotoria::create(['nombre' => 'Iniciacion musical', 'area_id' => $this->musica->id]);
        $this->iniciacion->forceFill(['edad_minima' => 5])->save();

        $this->violin = Promotoria::create(['nombre' => 'Violin', 'area_id' => $this->musica->id]);
        $this->violin->forceFill([
            'edad_minima' => 8,
            'descripcion' => 'Violin de orquesta',
            'palabras_clave' => 'cuerdas, arco, orquesta',
        ])->save();

        $this->grupoViolin = Grupo::create([
            'promotoria_id' => $this->violin->id, 'nombre' => 'Grupo 1', 'nivel' => 'avanzado', 'salon' => 'Espejos', 'cupo_maximo' => 10,
        ]);
        SesionGrupo::create(['grupo_id' => $this->grupoViolin->id, 'dia' => 2, 'hora_inicio' => '16:00', 'hora_fin' => '17:00']);
    }

    // ------------------------------------------------------------------
    // La llave
    // ------------------------------------------------------------------

    public function test_sin_huella_configurada_la_api_no_existe(): void
    {
        config(['agente.token_sha256' => null]);

        $this->api('/api/v1/catalogo')->assertNotFound();
    }

    public function test_un_token_equivocado_no_entra(): void
    {
        $this->getJson('/api/v1/catalogo', ['Authorization' => 'Bearer otra-cosa'])->assertUnauthorized();
        $this->getJson('/api/v1/catalogo')->assertUnauthorized();
        $this->api('/api/v1/catalogo')->assertOk();
    }

    // ------------------------------------------------------------------
    // El catalogo
    // ------------------------------------------------------------------

    public function test_la_edad_se_filtra_en_el_servidor(): void
    {
        $sinEdad = Promotoria::create(['nombre' => 'Coro', 'area_id' => $this->musica->id]);

        $nombres = collect($this->api('/api/v1/catalogo?edad=6')->assertOk()->json('departamentos.0.promotorias'))
            ->pluck('nombre');

        $this->assertContains('Iniciacion musical', $nombres);
        $this->assertNotContains('Violin', $nombres);
        // Sin edad minima declarada no se filtra: vacio es «no se ha dicho».
        $this->assertContains($sinEdad->nombre, $nombres);

        $this->assertContains('Violin', collect($this->api('/api/v1/catalogo?edad=8')->json('departamentos.0.promotorias'))->pluck('nombre'));
    }

    public function test_el_grupo_viaja_con_su_horario_y_sin_nivel(): void
    {
        $violin = collect($this->api('/api/v1/catalogo')->json('departamentos.0.promotorias'))->firstWhere('nombre', 'Violin');

        $this->assertSame(['cuerdas', 'arco', 'orquesta'], $violin['palabras_clave']);
        $this->assertSame([['dia' => 'Martes', 'inicio' => '16:00', 'fin' => '17:00']], $violin['grupos'][0]['horario']);
        $this->assertArrayNotHasKey('nivel', $violin['grupos'][0]);
    }

    public function test_los_cursos_tambien_se_filtran_por_edad(): void
    {
        $curso = Actividad::create([
            'tipo' => Actividad::CURSO, 'nombre' => 'Macrame', 'periodo_id' => $this->periodo->id,
            'responsable_id' => $this->perfilDeRol('profe', 'profesor')->id,
        ]);
        $curso->forceFill(['edad_minima' => 18])->save();

        $this->assertSame([], $this->api('/api/v1/catalogo?edad=10')->json('cursos_y_talleres'));
        $this->assertSame('Macrame', $this->api('/api/v1/catalogo?edad=30')->json('cursos_y_talleres.0.nombre'));
    }

    public function test_el_limite_sale_de_la_configuracion(): void
    {
        $this->api('/api/v1/catalogo')->assertJsonPath('limite_programas_por_persona', Matricula::limitePromotorias());
    }

    // ------------------------------------------------------------------
    // La disponibilidad
    // ------------------------------------------------------------------

    public function test_sin_fila_de_cupo_no_hay_tope(): void
    {
        $this->disponibilidad($this->violin)
            ->assertJsonPath('cupo', null)
            ->assertJsonPath('puede_inscribirse_ahora', true);
    }

    public function test_llena_dice_sin_cupo(): void
    {
        CupoPromotoria::create(['promotoria_id' => $this->violin->id, 'periodo_id' => $this->periodo->id, 'cupo_maximo' => 1]);
        $this->matricula($this->estudiante('ana', '1001'), $this->violin, Matricula::ACTIVA);

        $this->disponibilidad($this->violin)
            ->assertJsonPath('disponibles', 0)
            ->assertJsonPath('motivo', 'sin_cupo');
    }

    public function test_con_la_ventana_cerrada_dice_inscripciones_cerradas_y_no_sin_cupo(): void
    {
        $this->periodo->update(['matriculas_abiertas' => false]);

        $this->disponibilidad($this->violin)
            ->assertJsonPath('puede_inscribirse_ahora', false)
            ->assertJsonPath('motivo', 'inscripciones_cerradas');

        // El enlace propio de la promotoria matricula con la ventana cerrada.
        $this->violin->abrirEnlace(true);

        $this->disponibilidad($this->violin)->assertJsonPath('puede_inscribirse_ahora', true);
    }

    public function test_por_debajo_de_la_edad_minima_no_puede(): void
    {
        $this->disponibilidad($this->violin, 6)
            ->assertJsonPath('apta_para_edad', false)
            ->assertJsonPath('motivo', 'edad');
    }

    // ------------------------------------------------------------------
    // Lo personal
    // ------------------------------------------------------------------

    public function test_lo_personal_no_existe_fuera_de_la_demo(): void
    {
        config(['agente.datos_personales' => false]);

        $this->apiPost('/api/v1/verificacion', ['documento' => '1001', 'fecha_nacimiento' => '2000-01-01'])->assertNotFound();
        $this->api('/api/v1/me/matriculas')->assertNotFound();
    }

    public function test_verificar_devuelve_una_sesion_y_ningun_dato(): void
    {
        $ana = $this->estudiante('ana', '1001');

        $respuesta = $this->apiPost('/api/v1/verificacion', [
            'documento' => '1.001', 'fecha_nacimiento' => self::NACIMIENTO,
        ])->assertOk()->assertJsonPath('verificado', true);

        $this->assertSame(['verificado', 'sesion', 'expira_en_minutos'], array_keys($respuesta->json()));
    }

    public function test_documento_inexistente_y_fecha_equivocada_contestan_igual(): void
    {
        $this->estudiante('ana', '1001');

        $noExiste = $this->apiPost('/api/v1/verificacion', ['documento' => '9999', 'fecha_nacimiento' => '1990-01-01']);
        $fechaMala = $this->apiPost('/api/v1/verificacion', ['documento' => '1001', 'fecha_nacimiento' => '1990-01-01']);

        $this->assertSame($noExiste->status(), $fechaMala->status());
        $this->assertSame($noExiste->json(), $fechaMala->json());
    }

    public function test_tres_fallos_bloquean_el_documento_aunque_luego_acierte(): void
    {
        $ana = $this->estudiante('ana', '1001');

        foreach (range(1, 3) as $intento) {
            $this->apiPost('/api/v1/verificacion', ['documento' => '1001', 'fecha_nacimiento' => '1990-01-01']);
        }

        $this->apiPost('/api/v1/verificacion', [
            'documento' => '1001', 'fecha_nacimiento' => self::NACIMIENTO,
        ])->assertStatus(429)->assertJsonPath('verificado', false);
    }

    public function test_sin_sesion_no_dice_nada(): void
    {
        $this->api('/api/v1/me/matriculas', ['X-Sesion-Agente' => 'inventada'])->assertUnauthorized();
        $this->api('/api/v1/me/asistencia')->assertUnauthorized();
    }

    public function test_una_cancelacion_en_tramite_cuenta_para_el_limite(): void
    {
        $ana = $this->estudiante('ana', '1001');
        $this->matricula($ana, $this->violin, Matricula::ACTIVA);
        $this->matricula($ana, $this->iniciacion, Matricula::CANCELACION_SOLICITADA);

        $limite = Matricula::limitePromotorias();
        $this->assertSame(2, $limite, 'La prueba supone el limite de fabrica.');

        $this->api('/api/v1/me/matriculas', ['X-Sesion-Agente' => $this->sesion($ana)])
            ->assertOk()
            ->assertJsonPath('programas_ocupados', 2)
            ->assertJsonPath('puede_inscribir_otra', false)
            ->assertJsonCount(2, 'matriculas');
    }

    public function test_la_asistencia_cuenta_y_la_racha_corta_en_la_excusa(): void
    {
        $ana = $this->estudiante('ana', '1001');
        $matricula = $this->matricula($ana, $this->violin, Matricula::ACTIVA);
        $matricula->repartirEn([$this->grupoViolin->id]);

        // De la mas vieja a la mas reciente: asistio, excusa, asistio, asistio.
        foreach ([Asistencia::ASISTIO, Asistencia::EXCUSA, Asistencia::ASISTIO, Asistencia::ASISTIO] as $i => $estado) {
            $clase = Clase::create([
                'grupo_id' => $this->grupoViolin->id,
                'periodo_id' => $this->periodo->id,
                'fecha_hora' => Carbon::now()->subDays(10 - $i),
                'registrada_por_id' => $ana->id,
            ]);
            Asistencia::create(['clase_id' => $clase->id, 'matricula_id' => $matricula->id, 'estado' => $estado]);
        }

        $this->api('/api/v1/me/asistencia', ['X-Sesion-Agente' => $this->sesion($ana)])
            ->assertOk()
            ->assertJsonPath('asistencia.0.asistio', 3)
            ->assertJsonPath('asistencia.0.falto_con_excusa', 1)
            ->assertJsonPath('asistencia.0.racha_asistencias', 2);
    }

    // ------------------------------------------------------------------
    // Donde se escriben: al crear la promotoria y el curso
    // ------------------------------------------------------------------

    public function test_la_promotoria_guarda_edad_descripcion_y_palabras(): void
    {
        $admin = $this->perfilDeRol('jefa', 'administrador');

        $this->actingAs($admin->user)->post(route('promotoria-nueva'), [
            'nombre' => 'Percusion',
            'area_id' => $this->musica->id,
            'profesor_id' => '',
            'edad_minima' => '8',
            'descripcion' => 'Ritmos con tambores',
            'palabras_clave' => 'tambores, bateria',
        ])->assertSessionHasNoErrors();

        $percusion = Promotoria::firstWhere('nombre', 'Percusion');
        $this->assertSame(8, $percusion->edad_minima);
        $this->assertSame(['tambores', 'bateria'], $percusion->palabras_clave_lista);
    }

    public function test_la_edad_de_la_promotoria_puede_quedar_vacia(): void
    {
        $admin = $this->perfilDeRol('jefa', 'administrador');

        $this->actingAs($admin->user)->post(route('promotoria-nueva'), [
            'nombre' => 'Percusion', 'area_id' => $this->musica->id, 'profesor_id' => '',
            'edad_minima' => '', 'descripcion' => '', 'palabras_clave' => '',
        ])->assertSessionHasNoErrors();

        $this->assertNull(Promotoria::firstWhere('nombre', 'Percusion')->edad_minima);
    }

    public function test_el_curso_guarda_su_edad_minima(): void
    {
        $admin = $this->perfilDeRol('jefa', 'administrador');

        $this->actingAs($admin->user)->post(route('actividad-curso-nueva'), [
            'nombre' => 'Macrame', 'clases' => '3', 'responsable_id' => $admin->id,
            'cupo_maximo' => '', 'edad_minima' => '15',
        ])->assertSessionHasNoErrors();

        $this->assertSame(15, Actividad::firstWhere('nombre', 'Macrame')->edad_minima);
    }

    // ------------------------------------------------------------------

    private function perfilDeRol(string $username, string $rol): Perfil
    {
        $perfil = $this->estudiante($username, '7'.strlen($username).'0099');
        $perfil->update(['rol' => $rol]);

        return $perfil;
    }

    private function api(string $url, array $cabeceras = []): TestResponse
    {
        return $this->getJson($url, ['Authorization' => 'Bearer '.self::TOKEN] + $cabeceras);
    }

    private function apiPost(string $url, array $datos): TestResponse
    {
        return $this->postJson($url, $datos, ['Authorization' => 'Bearer '.self::TOKEN]);
    }

    private function disponibilidad(Promotoria $promotoria, ?int $edad = null): TestResponse
    {
        return $this->api("/api/v1/promotorias/{$promotoria->id}/disponibilidad".($edad === null ? '' : "?edad={$edad}"))->assertOk();
    }

    private function sesion(Perfil $perfil): string
    {
        return $this->apiPost('/api/v1/verificacion', [
            'documento' => $perfil->datosEstudiante->documento_identidad,
            'fecha_nacimiento' => self::NACIMIENTO,
        ])->assertOk()->json('sesion');
    }

    private function estudiante(string $username, string $documento): Perfil
    {
        $user = User::create(['username' => $username, 'password' => 'x', 'activo' => true]);

        /** @var Perfil $perfil */
        $perfil = Perfil::create([
            'user_id' => $user->id,
            'rol' => 'estudiante',
            'nombre_completo' => ucfirst($username),
            'fecha_nacimiento' => self::NACIMIENTO,
            'telefono' => '3000000000',
        ]);
        DatosEstudiante::create(['perfil_id' => $perfil->id, 'documento_identidad' => $documento]);

        return $perfil;
    }

    private function matricula(Perfil $perfil, Promotoria $promotoria, string $estado): Matricula
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
}
