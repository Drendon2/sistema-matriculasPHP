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
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
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

    // --------------------------------------------------------------------
    // EL OTRO EXTREMO: la pantalla a la que manda el aviso (12/09/2026)
    //
    // Lo reportaron los usuarios: «me sigue apareciendo el aviso despues de
    // llenarla». No la habian llenado. `$faltanPreguntas` vale `[]` cuando la
    // encuesta NO EXISTE —ahi lo que falta es entera— y la seccion de Mi perfil,
    // su chip y su aviso colgaban los TRES de esa condicion: quien pulsaba
    // «Contestar la encuesta» aterrizaba en un titulo plegado con nada debajo.
    //
    // Medido en produccion ese dia: 124 encuestas y CERO incompletas, con 1.048
    // perfiles con rol. O sea que el caso roto no era el raro — era el de ~924
    // personas, y el de «a medias» no lo tenia nadie.
    // --------------------------------------------------------------------

    /** La etiqueta de apertura de la seccion, que es donde vive el `open`. */
    private function seccionDeEncuesta(string $html): string
    {
        $i = strpos($html, '<details class="perfil-seccion" id="bloque-encuesta"');
        $this->assertNotFalse($i, 'no esta la seccion de la encuesta');

        return substr($html, $i, strpos($html, '>', $i) - $i + 1);
    }

    /** SIN ENCUESTA la seccion viene ABIERTA. Es el fallo reportado. */
    public function test_sin_encuesta_la_seccion_viene_abierta(): void
    {
        $html = (string) $this->actingAs($this->ana->user)
            ->get(route('mi-perfil'))->assertOk()->getContent();

        $this->assertStringContainsString('open', $this->seccionDeEncuesta($html));
        $this->assertStringContainsString('Sin contestar', $html);
        $this->assertStringContainsString('Todavía no has contestado esta encuesta', $html);
    }

    /** A medias tambien, y entonces el chip dice otra cosa. */
    public function test_a_medias_la_seccion_viene_abierta(): void
    {
        EncuestaDemografica::create([
            'perfil_id' => $this->ana->id,
            'genero' => 'f',
            'barrio' => 'Centro',
            'estrato' => '2',
            'nivel_educativo' => '',
            'ocupacion' => '',
        ]);

        $html = (string) $this->actingAs($this->ana->user)
            ->get(route('mi-perfil'))->assertOk()->getContent();

        $this->assertStringContainsString('open', $this->seccionDeEncuesta($html));
        $this->assertStringContainsString('Incompleta', $html);
    }

    /** Y contestada entera se pliega: quien ya cumplio no tiene que ver nada. */
    public function test_contestada_la_seccion_viene_plegada(): void
    {
        EncuestaDemografica::create([
            'perfil_id' => $this->ana->id,
            'genero' => 'f',
            'barrio' => 'Centro',
            'estrato' => '2',
            'nivel_educativo' => 'ninguno',
            'ocupacion' => 'estudiante',
        ]);

        $html = (string) $this->actingAs($this->ana->user)
            ->get(route('mi-perfil'))->assertOk()->getContent();

        $this->assertStringNotContainsString('open', $this->seccionDeEncuesta($html));
        $this->assertStringNotContainsString('Sin contestar', $html);
    }

    /**
     * UN RECHAZO DE LA ENCUESTA LA ABRE, aunque siga sin haber encuesta.
     *
     * Es la regla de la casa —un `<details>` con formulario se abre si SUS
     * campos traen error— y aqui faltaba. Sin JavaScript el rechazo repinta la
     * pagina desde cero y la seccion volvia plegada CON LOS ERRORES DENTRO: el
     * aviso de arriba mandando a buscar lo rojo mas abajo y nada rojo a la
     * vista. Con JavaScript no se ve, porque `acciones.js` conserva abiertos los
     * `<details>` que tienen `id`.
     */
    public function test_un_rechazo_de_la_encuesta_abre_su_seccion(): void
    {
        // LA ENCUESTA VA COMPLETA A PROPOSITO. Sin esto la prueba no comprobaba
        // NADA: sin encuesta la seccion se abre igual por `$encuestaSinEmpezar`,
        // asi que quitar la condicion del error la dejaba en verde. Se vio
        // saboteando, que es la regla de la casa. Con la encuesta entera, lo
        // unico que puede abrirla es el error.
        EncuestaDemografica::create([
            'perfil_id' => $this->ana->id,
            'genero' => 'f',
            'barrio' => 'Centro',
            'estrato' => '2',
            'nivel_educativo' => 'ninguno',
            'ocupacion' => 'estudiante',
        ]);

        $bolsa = new MessageBag(['ocupacion' => ['Falta ocupación.']]);
        $errores = new ViewErrorBag;
        $errores->put('default', $bolsa);

        $html = (string) $this->actingAs($this->ana->user)
            ->withSession(['errors' => $errores])
            ->get(route('mi-perfil'))->assertOk()->getContent();

        $this->assertStringContainsString('open', $this->seccionDeEncuesta($html));
    }

    /**
     * Y el rechazo de OTRO formulario NO la abre.
     *
     * `hasAny` acotado a sus campos y no `$errors->any()`: con eso la encuesta
     * se abriria porque fallo el formulario de la contraseña, que no tiene nada
     * que ver, y quien baje a buscar lo rojo lo encontrara en el plegado
     * equivocado. Esta mitad es la que evita «arreglarlo» al reves.
     */
    public function test_el_rechazo_de_otro_formulario_no_la_abre(): void
    {
        EncuestaDemografica::create([
            'perfil_id' => $this->ana->id,
            'genero' => 'f',
            'barrio' => 'Centro',
            'estrato' => '2',
            'nivel_educativo' => 'ninguno',
            'ocupacion' => 'estudiante',
        ]);

        $bolsa = new MessageBag(['password' => ['La contraseña es muy corta.']]);
        $errores = new ViewErrorBag;
        $errores->put('default', $bolsa);

        $html = (string) $this->actingAs($this->ana->user)
            ->withSession(['errors' => $errores])
            ->get(route('mi-perfil'))->assertOk()->getContent();

        $this->assertStringNotContainsString('open', $this->seccionDeEncuesta($html));
    }
}
