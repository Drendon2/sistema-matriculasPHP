<?php

namespace Tests\Feature;

use App\Models\Acudiente;
use App\Models\ConfiguracionInstitucion;
use App\Models\DatosEstudiante;
use App\Models\EncuestaDemografica;
use App\Models\Perfil;
use App\Models\Periodo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * El recordatorio de la encuesta demografica.
 *
 * Pedido por el usuario el 05/09/2026 con el motivo escrito: «las personas no
 * responden de manera voluntaria la encuesta demografica». La encuesta es lo que
 * sostiene Gestion → Estadisticas —barrio, estrato, zona, grupo etnico— y es lo
 * que una entidad publica reporta; con la mitad sin contestar, esas cifras no
 * describen a nadie.
 *
 * LAS TRES COSAS QUE NO SE ROMPEN:
 *
 * 1. SOLO LE SALE A QUIEN LE FALTA ALGO. Es lo que hace que encenderlo no sea
 *    una molestia para todo el mundo: quien ya contesto no ve nada nunca.
 * 2. PIDE, NO OBLIGA. La palabra del encargo fue «amablemente». No bloquea
 *    ninguna pantalla — un muro dejaria encerrado a quien entra con prisa a
 *    mirar su horario, y el sistema no tiene forma de avisarle por otro canal.
 * 3. SE APAGA DESDE INSTITUCION, tambien pedido: un aviso en cada entrada es de
 *    las cosas que cansan.
 */
class RecordarEncuestaTest extends TestCase
{
    use RefreshDatabase;

    private Perfil $ana;

    protected function setUp(): void
    {
        parent::setUp();

        Periodo::create([
            'nombre' => '2026-1',
            'fecha_inicio' => Carbon::today()->subMonth()->toDateString(),
            'fecha_fin' => Carbon::today()->addMonths(3)->toDateString(),
            'activo' => true,
            'matriculas_abiertas' => true,
        ]);

        ConfiguracionInstitucion::actual();

        $this->ana = $this->estudiante('ana');
    }

    /** Sin encuesta empezada, el catalogo se lo pide. */
    public function test_a_quien_no_la_ha_empezado_se_le_pide(): void
    {
        $this->actingAs($this->ana->user)
            ->get(route('promotorias-disponibles'))
            ->assertOk()
            ->assertSee('Contestar la encuesta');
    }

    /** Empezada pero a medias, se dice CUANTAS faltan y cuales. */
    public function test_a_medias_dice_cuantas_faltan(): void
    {
        EncuestaDemografica::create([
            'perfil_id' => $this->ana->id,
            'genero' => 'f',
            'barrio' => 'El Carmen',
            'estrato' => '1',
            // Las dos EN BLANCO, que es el caso: la columna no admite nulo,
            // asi que «sin contestar» es la cadena vacia. Es exactamente lo que
            // dejaron las encuestas viejas del original, y por lo que
            // `preguntas_faltantes` existe.
            'nivel_educativo' => '',
            'ocupacion' => '',
        ]);

        $html = $this->actingAs($this->ana->user)
            ->get(route('promotorias-disponibles'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Terminar la encuesta', $html);
        $this->assertStringContainsString('2', $html, 'no dice cuántas faltan.');
    }

    /**
     * Y A QUIEN YA LA CONTESTO NO SE LE DICE NADA.
     *
     * Es la prueba que sostiene todo lo demas: sin ella el aviso seria una
     * molestia para todo el mundo y habria que apagarlo.
     */
    public function test_a_quien_ya_la_contesto_no_se_le_molesta(): void
    {
        $this->encuestaCompleta($this->ana);

        $html = $this->actingAs($this->ana->user)
            ->get(route('promotorias-disponibles'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('recordar-encuesta', $html, 'le sale el aviso a quien ya contestó.');
    }

    /**
     * El interruptor de Institucion lo apaga para todos.
     *
     * Con SONDA delante: se comprueba primero que ENCENDIDO si sale. Sin eso,
     * la prueba pasaria igual el dia que el aviso dejara de pintarse por
     * cualquier otro motivo, que es la forma mas comun de tener una prueba que
     * no prueba nada. `saved()` tira la copia memorizada de la peticion, asi
     * que no hay que olvidarla a mano.
     */
    public function test_apagado_desde_institucion_no_sale(): void
    {
        $configuracion = ConfiguracionInstitucion::actual();

        // SONDA: encendido SI sale.
        $configuracion->recordar_encuesta = true;
        $configuracion->save();

        $this->actingAs($this->ana->user)
            ->get(route('promotorias-disponibles'))
            ->assertSee('Contestar la encuesta');

        $configuracion->recordar_encuesta = false;
        $configuracion->save();

        $html = $this->actingAs($this->ana->user)
            ->get(route('promotorias-disponibles'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('recordar-encuesta', $html, 'el interruptor no lo apaga.');
    }

    /** Le alcanza tambien al personal, que tambien tiene encuesta. */
    public function test_al_personal_tambien_se_le_pide(): void
    {
        $profe = $this->perfil('profe', 'profesor');

        $this->actingAs($profe->user)
            ->get(route('panel'))
            ->assertOk()
            ->assertSee('Contestar la encuesta');
    }

    /**
     * NO SE PINTA EN «Mi perfil», que es donde vive la encuesta: el aviso
     * quedaria encima del propio formulario que pide rellenar.
     */
    public function test_en_mi_perfil_no_se_repite_el_aviso(): void
    {
        $html = $this->actingAs($this->ana->user)
            ->get(route('mi-perfil'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('recordar-encuesta', $html, 'el aviso se repite sobre la propia encuesta.');
    }

    /** PIDE, NO OBLIGA: la pantalla sigue siendo suya. */
    public function test_el_aviso_no_bloquea_la_pantalla(): void
    {
        $this->actingAs($this->ana->user)
            ->get(route('promotorias-disponibles'))
            ->assertOk()
            // Lo que se venia a hacer sigue ahi.
            ->assertSee('Promotorías disponibles');
    }

    private function encuestaCompleta(Perfil $perfil): void
    {
        EncuestaDemografica::create([
            'perfil_id' => $perfil->id,
            'genero' => 'f',
            'barrio' => 'El Carmen',
            'estrato' => '1',
            'nivel_educativo' => 'primaria_com',
            'ocupacion' => 'estudia',
        ]);
    }

    private function estudiante(string $username): Perfil
    {
        $perfil = $this->perfil($username, 'estudiante');

        DatosEstudiante::create([
            'perfil_id' => $perfil->id,
            'documento_identidad' => '1'.$perfil->id,
            'acudiente_id' => Acudiente::create(['nombre' => 'Tutor', 'telefono' => '300'])->id,
        ]);

        return $perfil->refresh();
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
