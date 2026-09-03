<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\DatosEstudiante;
use App\Models\Grupo;
use App\Models\Matricula;
use App\Models\Perfil;
use App\Models\Periodo;
use App\Models\Promotoria;
use App\Models\User;
use App\Support\Fragmento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Las acciones del Panel responden con la portada ya pintada.
 *
 * ERAN TRES VIAJES para cambiar una fila: el POST, el GET al que redirigia, y
 * el que `panel.js` lanzaba despues para recuperar el cuerpo que el repintado
 * habia dejado sin cargar. En una tanda de quince confirmaciones seguidas —que
 * es como se usa esta pantalla, y lo dice la cabecera de `acciones.js`— son
 * cuarenta y cinco viajes.
 *
 * SE REPINTA LA PORTADA ENTERA, no solo el cuerpo de la promotoria, y la razon
 * esta probada abajo: la insignia de «N pendientes» vive en el <summary>, fuera
 * del cuerpo. Confirmar la cambia, y un fragmento mas fino la dejaria mintiendo
 * sobre el numero que sirve justamente para decidir donde entrar. La portada de
 * un profesor son 2 KB; no hay nada que ganar afinando mas.
 *
 * LO QUE NO SE ESTRECHA AL ESTRECHAR LA RESPUESTA es el permiso. La consulta
 * que arma la portada es `visiblesPara()`, la misma del indice y la misma que
 * cierra la URL de un cuerpo suelto, asi que un profesor no puede sacar por
 * aqui una promotoria que no dicta. Hay una prueba solo para eso.
 */
class PanelSinRecargaTest extends TestCase
{
    use RefreshDatabase;

    private Perfil $profesor;

    private Perfil $otroProfesor;

    private Promotoria $promotoria;

    private Promotoria $ajena;

    private Periodo $periodo;

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

        $this->profesor = $this->crearPerfil('profe', 'profesor');
        $this->otroProfesor = $this->crearPerfil('otro', 'profesor');

        $area = Area::create(['nombre' => 'Musica']);

        $this->promotoria = Promotoria::create([
            'nombre' => 'Violín',
            'area_id' => $area->id,
            'profesor_id' => $this->profesor->id,
        ]);

        $this->ajena = Promotoria::create([
            'nombre' => 'Trompeta ajena',
            'area_id' => $area->id,
            'profesor_id' => $this->otroProfesor->id,
        ]);

        Grupo::create([
            'promotoria_id' => $this->promotoria->id,
            'nombre' => 'Grupo 1',
            'nivel' => 'basico',
            'salon' => 'A1',
            'cupo_maximo' => 10,
        ]);
    }

    /** SIN JavaScript se sigue redirigiendo: es la rama por defecto. */
    public function test_sin_la_cabecera_confirmar_sigue_redirigiendo(): void
    {
        $matricula = $this->pendiente('ana');

        $respuesta = $this->actingAs($this->profesor->user)
            ->post(route('panel-confirmar-matricula', $matricula));

        $respuesta->assertRedirect(route('panel'));
        $respuesta->assertSessionHas('success');
        $respuesta->assertHeaderMissing(Fragmento::CABECERA);

        $this->assertSame(Matricula::ACTIVA, $matricula->fresh()->estado);
    }

    /**
     * CON la cabecera vuelve la portada, y el cuerpo ya viene dentro.
     *
     * Lo del cuerpo es la mitad que ahorra el TERCER viaje. Sin `data-cargado`
     * el destino llega en blanco, `panel.js` lo pide otra vez y el ahorro se
     * queda a medias sin que nada falle ni avise.
     */
    public function test_con_la_cabecera_vuelve_la_portada_con_el_cuerpo_puesto(): void
    {
        $matricula = $this->pendiente('ana');

        $respuesta = $this->confirmarComoFragmento($matricula);

        $respuesta->assertOk();
        $respuesta->assertHeader(Fragmento::CABECERA, '1');

        $html = $respuesta->getContent();

        $this->assertStringNotContainsString('<html', $html, 'el fragmento trae la pagina entera.');
        $this->assertStringNotContainsString('<main', $html, 'el fragmento trae el <main> que ya existe.');

        $this->assertStringContainsString('data-cargado="si"', $html, 'el cuerpo llego sin marcar y se volvera a pedir.');
        $this->assertStringContainsString('Ana', $html, 'el cuerpo no traia a los matriculados.');
        $this->assertStringContainsString('Matrícula de Ana confirmada.', $html, 'el fragmento no trae el aviso.');
    }

    /**
     * La insignia de pendientes baja EN LA MISMA respuesta.
     *
     * Esta es la prueba que justifica repintar la portada entera en vez de solo
     * el cuerpo. La cifra vive en el <summary>; con un fragmento mas fino se
     * quedaria diciendo «2 pendientes» con uno solo por resolver, y es
     * exactamente el numero que alguien mira para decidir donde entrar.
     */
    public function test_la_insignia_de_pendientes_baja_en_la_misma_respuesta(): void
    {
        $una = $this->pendiente('ana');
        $this->pendiente('beto');

        $antes = $this->actingAs($this->profesor->user)->get(route('panel'))->getContent();
        $this->assertStringContainsString('2 pendientes', $antes, 'la sonda no vale: no habia dos pendientes.');

        $html = $this->confirmarComoFragmento($una)->getContent();

        $this->assertStringContainsString('1 pendiente', $html, 'la insignia no bajo.');
        $this->assertStringNotContainsString('2 pendientes', $html, 'la insignia se quedo con la cifra vieja.');
    }

    /**
     * El cuerpo viene puesto SOLO en la promotoria sobre la que se actuo.
     *
     * Las demas siguen plegadas y sin cargar, que es lo que hace barata la
     * portada: un director tiene veintiuna y traerlas todas eran cientos de KB
     * para ensenar una lista de titulos.
     */
    public function test_el_cuerpo_solo_viene_puesto_en_la_promotoria_que_se_toco(): void
    {
        $otraMia = Promotoria::create([
            'nombre' => 'Guitarra',
            'area_id' => $this->promotoria->area_id,
            'profesor_id' => $this->profesor->id,
        ]);

        $html = $this->confirmarComoFragmento($this->pendiente('ana'))->getContent();

        $this->assertSame(1, substr_count($html, 'data-cargado="si"'), 'vino puesto mas de un cuerpo.');
        $this->assertStringContainsString('Cargando…', $html, 'las demas promotorias no quedaron por cargar.');
        $this->assertStringContainsString($otraMia->nombre, $html, 'la portada perdio una promotoria.');
    }

    /**
     * QUE LA RESPUESTA SEA PEQUENA NO LA HACE CONFIADA.
     *
     * La portada del fragmento se arma con `visiblesPara()`, igual que el
     * indice. Un profesor que consiga disparar una accion no puede sacar por
     * ahi el nombre —ni nada— de una promotoria que no dicta.
     */
    public function test_el_fragmento_no_ensena_promotorias_que_no_son_suyas(): void
    {
        $html = $this->confirmarComoFragmento($this->pendiente('ana'))->getContent();

        $this->assertStringNotContainsString(
            $this->ajena->nombre,
            $html,
            'el fragmento filtro una promotoria de otro profesor.'
        );
    }

    /**
     * Actuar sobre lo ajeno REDIRIGE aunque se pida el fragmento.
     *
     * El rechazo por permiso no lleva promotoria a `volver()`, asi que sale por
     * la rama de siempre: la respuesta llega sin la cabecera de vuelta y
     * `acciones.js` se encuentra una pagina normal.
     */
    public function test_actuar_sobre_una_promotoria_ajena_sigue_redirigiendo(): void
    {
        $matricula = $this->pendiente('ana');

        $respuesta = $this->actingAs($this->otroProfesor->user)
            ->withHeader(Fragmento::CABECERA, '1')
            ->post(route('panel-confirmar-matricula', $matricula));

        $respuesta->assertRedirect(route('panel'));
        $respuesta->assertHeaderMissing(Fragmento::CABECERA);

        $this->assertSame(
            Matricula::PENDIENTE,
            $matricula->fresh()->estado,
            'un profesor confirmo una matricula que no era suya.'
        );
    }

    /** El aviso se gasta en ESTA respuesta y no reaparece en la siguiente. */
    public function test_el_aviso_no_se_queda_esperando_a_la_siguiente_pantalla(): void
    {
        $this->confirmarComoFragmento($this->pendiente('ana'))
            ->assertSessionMissing('success');

        $siguiente = $this->actingAs($this->profesor->user)->get(route('mi-perfil'));

        $this->assertStringNotContainsString(
            'confirmada.',
            $siguiente->getContent(),
            'el aviso reaparecio pegado a la pantalla siguiente.'
        );
    }

    private function confirmarComoFragmento(Matricula $matricula): TestResponse
    {
        return $this->actingAs($this->profesor->user)
            ->withHeader(Fragmento::CABECERA, '1')
            ->post(route('panel-confirmar-matricula', $matricula));
    }

    private function pendiente(string $nombre): Matricula
    {
        $estudiante = $this->crearPerfil($nombre, 'estudiante');

        DatosEstudiante::create([
            'perfil_id' => $estudiante->id,
            'documento_identidad' => '1'.$estudiante->id,
        ]);

        $matricula = new Matricula([
            'estudiante_id' => $estudiante->id,
            'promotoria_id' => $this->promotoria->id,
            'periodo_id' => $this->periodo->id,
            'estado' => Matricula::PENDIENTE,
        ]);
        $matricula->save();

        return $matricula;
    }

    private function crearPerfil(string $username, string $rol): Perfil
    {
        $user = User::create(['username' => $username, 'password' => 'x', 'activo' => true]);

        return Perfil::create([
            'user_id' => $user->id,
            'rol' => $rol,
            'nombre_completo' => ucfirst($username),
            'fecha_nacimiento' => Carbon::today()->subYears(20)->toDateString(),
            'telefono' => '3000000000',
        ]);
    }
}
