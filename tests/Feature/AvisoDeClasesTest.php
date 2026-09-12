<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Clase;
use App\Models\ConfiguracionInstitucion;
use App\Models\Grupo;
use App\Models\Matricula;
use App\Models\Perfil;
use App\Models\Periodo;
use App\Models\Promotoria;
use App\Models\User;
use App\Support\ClasesPendientes;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * EL AVISO DE CLASES SIN CONFIRMAR — 12/09/2026.
 *
 * ─── EL AGUJERO QUE CIERRA ─────────────────────────────────────────────────
 *
 * Confirmar una clase es la accion mas importante que tiene un estudiante aqui
 * y CADUCA a las 48 horas. Hasta este dia el unico aviso vivia en «Promotorias
 * disponibles», que es donde aterriza al entrar — y esa pantalla LA PUEDE
 * APAGAR la institucion (`promotorias_visibles_para_estudiantes`, para entidades
 * que matriculan en ventanilla). Con el interruptor apagado, el catalogo lo
 * redirige a «Mis matriculas», que no dice nada de clases: tenia 48 horas para
 * hacer algo de lo que nadie le avisaba, y este sistema no manda notificaciones
 * por ningun otro canal.
 *
 * Ahora el aviso va en el MENU, que no se puede apagar, y ademas salta una vez
 * por sesion como dialogo.
 *
 * ─── LAS DOS MITADES, Y POR QUE SON DOS ────────────────────────────────────
 *
 * EL PUNTO ROJO es la garantia: es CSS y no necesita JavaScript.
 * EL DIALOGO lo amplifica, y viene CERRADO en el HTML — lo abre un guion. Sin
 * JavaScript no se ve, y eso es correcto; lo que NO puede pasar es que sin
 * JavaScript quede un panel tapando la pantalla, porque este aviso pide y no
 * obliga. Hay una prueba que lo fija.
 */
class AvisoDeClasesTest extends TestCase
{
    use RefreshDatabase;

    private Periodo $periodo;

    private Promotoria $piano;

    private Grupo $grupo;

    private Perfil $ana;

    private Perfil $profesor;

    protected function setUp(): void
    {
        parent::setUp();

        // La memoria es estatica y vive mas que una prueba: sin esto, la
        // segunda veria la cifra de la primera.
        ClasesPendientes::olvidar();

        $this->periodo = Periodo::create([
            'nombre' => '2026-2',
            'fecha_inicio' => Carbon::today()->subMonth()->toDateString(),
            'fecha_fin' => Carbon::today()->addMonths(3)->toDateString(),
            'activo' => true,
            'matriculas_abiertas' => true,
        ]);

        $area = Area::create(['nombre' => 'Música']);
        $this->piano = Promotoria::create(['nombre' => 'Piano', 'area_id' => $area->id]);
        $this->grupo = Grupo::create([
            'promotoria_id' => $this->piano->id,
            'nombre' => 'Mañana',
            'nivel' => 'basico',
            'salon' => 'A1',
            'cupo_maximo' => 20,
        ]);

        $this->profesor = $this->perfil('profe', 'profesor');
        $this->ana = $this->perfil('ana', 'estudiante');
    }

    private function perfil(string $username, string $rol): Perfil
    {
        $user = User::create(['username' => $username, 'password' => 'demo1234', 'activo' => true]);

        return Perfil::create([
            'user_id' => $user->id,
            'nombre_completo' => ucfirst($username).' Pérez',
            'rol' => $rol,
            'telefono' => '3001112233',
            'fecha_nacimiento' => Carbon::today()->subYears(20),
        ]);
    }

    /** Una clase suya, dada hace seis horas, o sea dentro del plazo de 48. */
    private function claseReciente(): Clase
    {
        $matricula = new Matricula([
            'estudiante_id' => $this->ana->id,
            'promotoria_id' => $this->piano->id,
            'periodo_id' => $this->periodo->id,
            'estado' => Matricula::ACTIVA,
        ]);
        $matricula->save();
        $matricula->repartirEn([$this->grupo->id]);

        // LA FECHA HACIA ATRAS NO SOBRA. `Clase::porConfirmar()` descarta las
        // clases anteriores a la matricula —«no das fe de lo de antes de
        // entrar»— y una matricula creada AHORA es posterior a una clase de
        // hace seis horas, asi que sin esto la lista sale vacia y las pruebas
        // fallan por el motivo equivocado. Costo cuatro rojas al escribirlas.
        $matricula->fecha = Carbon::now()->subMonth();
        $matricula->save();

        return Clase::create([
            'grupo_id' => $this->grupo->id,
            'periodo_id' => $this->periodo->id,
            'fecha_hora' => Carbon::now()->subHours(6),
            'registrada_por_id' => $this->profesor->id,
            'confirmaciones_requeridas' => 1,
        ]);
    }

    /** El punto, con su numero para quien no ve el color. */
    public function test_con_una_clase_pendiente_el_menu_lleva_punto(): void
    {
        $this->claseReciente();

        $html = (string) $this->actingAs($this->ana->user)
            ->get(route('mis-matriculas'))->assertOk()->getContent();

        $this->assertStringContainsString('nav-con-aviso', $html);
        $this->assertStringContainsString('1 clase sin confirmar', $html);
    }

    /** Y sin nada pendiente no hay punto: un aviso que sale siempre no es aviso. */
    public function test_sin_clases_pendientes_no_hay_punto(): void
    {
        $html = (string) $this->actingAs($this->ana->user)
            ->get(route('mis-matriculas'))->assertOk()->getContent();

        $this->assertStringNotContainsString('nav-con-aviso', $html);
        $this->assertStringNotContainsString('data-aviso-clases', $html);
    }

    /**
     * LA PRUEBA QUE DA SENTIDO A TODO ESTO: el punto sale tambien con el
     * catalogo APAGADO.
     *
     * Ese era el agujero. Con `promotorias_visibles_para_estudiantes` en false,
     * el unico aviso que existia —el de «Promotorias disponibles»— no se pintaba
     * nunca, porque esa pantalla redirige. El estudiante tenia 48 horas para
     * confirmar y nadie se lo decia.
     */
    public function test_el_punto_sale_aunque_el_catalogo_este_apagado(): void
    {
        $config = ConfiguracionInstitucion::actual();
        $config->promotorias_visibles_para_estudiantes = false;
        $config->save();

        $this->claseReciente();

        $html = (string) $this->actingAs($this->ana->user)
            ->get(route('mis-matriculas'))->assertOk()->getContent();

        $this->assertStringContainsString('nav-con-aviso', $html);
        $this->assertStringContainsString('data-aviso-clases', $html);
    }

    /**
     * El dialogo salta la primera vez y deja la marca en la SESION.
     *
     * Se afirma en dos mitades y no encadenando dos peticiones, y la razon es
     * del andamiaje: `phpunit.xml` pone `SESSION_DRIVER=array`, que NO persiste
     * entre peticiones. Encadenadas, esta prueba fallaba con el codigo bueno —
     * y quien la arreglara «relajando» la asercion se llevaria por delante la
     * garantia de que solo se enseña una vez.
     */
    public function test_la_primera_vez_salta_y_deja_la_marca(): void
    {
        $this->claseReciente();

        $respuesta = $this->actingAs($this->ana->user)->get(route('mis-matriculas'));

        $respuesta->assertOk();
        $this->assertStringContainsString('data-aviso-clases', (string) $respuesta->getContent());
        $respuesta->assertSessionHas('aviso_clases_visto', true);
    }

    /** Y con la marca puesta ya no vuelve, aunque siga habiendo clases. */
    public function test_con_la_marca_puesta_ya_no_vuelve(): void
    {
        $this->claseReciente();

        $html = (string) $this->actingAs($this->ana->user)
            ->withSession(['aviso_clases_visto' => true])
            ->get(route('mis-clases'))->assertOk()->getContent();

        $this->assertStringNotContainsString(
            'data-aviso-clases',
            $html,
            'reaparece en cada pantalla: asi se enseña a cerrar avisos sin leerlos'
        );

        // El punto SI sigue: es la garantia, y esa no se gasta.
        $this->assertStringContainsString('nav-con-aviso', $html);
    }

    /**
     * EL DIALOGO VIENE CERRADO, y sin JavaScript no tapa nada.
     *
     * Es lo que separa «pedir» de «obligar». Un `<dialog open>` se pinta en el
     * flujo, y un panel de aviso que no se puede cerrar sin guion deja encerrado
     * a quien entra con prisa a mirar su horario — que es la razon escrita por la
     * que el aviso de la encuesta tampoco bloquea.
     */
    public function test_el_dialogo_viene_cerrado(): void
    {
        $this->claseReciente();

        $html = (string) $this->actingAs($this->ana->user)
            ->get(route('mis-matriculas'))->assertOk()->getContent();

        $this->assertStringContainsString('<dialog class="aviso-clases" data-aviso-clases>', $html);
        $this->assertStringNotContainsString('data-aviso-clases open', $html);
    }

    /** Al profesor no le sale nada: el no confirma sus propias clases. */
    public function test_al_personal_no_le_sale(): void
    {
        $this->claseReciente();

        $html = (string) $this->actingAs($this->profesor->user)
            ->get(route('panel'))->assertOk()->getContent();

        $this->assertStringNotContainsString('nav-con-aviso', $html);
        $this->assertStringNotContainsString('data-aviso-clases', $html);
    }
}
