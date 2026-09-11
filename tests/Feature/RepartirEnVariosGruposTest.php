<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Grupo;
use App\Models\Matricula;
use App\Models\Perfil;
use App\Models\Periodo;
use App\Models\Promotoria;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * La PANTALLA para repartir a alguien en varios grupos de su promotoria.
 *
 * Es una pagina de verdad con su URL, y el Panel la abre en un modal: sin
 * JavaScript se abre y funciona igual, que es el criterio de las confirmaciones
 * de borrado. Por eso todo esto se prueba por HTTP contra la ruta.
 *
 * ─── POR QUE UN MODAL Y NO UN CONTROL EN LA FILA ────────────────────────────
 *
 * Medido el 10/09/2026 con el CSS real. Un `<select multiple>` en la celda sube
 * la fila de 68 a 136 px en escritorio y de 112 a 179 en el telefono. Y pesa
 * mas que el alto: en escritorio se maneja con ctrl+clic, y **un clic normal
 * borra la seleccion anterior** — probado en el navegador, con «Grupo A»
 * marcado un clic en «Grupo B» dejaba solo el B. En esta pantalla eso es sacar
 * a alguien de su grupo sin decirlo.
 *
 * ─── LO QUE MAS IMPORTA DE ESTE ARCHIVO ─────────────────────────────────────
 *
 * La primera prueba. «Quitar del grupo» mandaba un `grupo_id` vacio, que desde
 * que existe `repartirEn()` significa «sacalo de TODOS»: quitar a alguien del
 * lunes le quitaba tambien el miercoles, sin fallar y sin avisar. Se encontro
 * escribiendo esta pantalla, no probando.
 */
class RepartirEnVariosGruposTest extends TestCase
{
    use RefreshDatabase;

    private Periodo $periodo;

    private Promotoria $violin;

    private Grupo $lunes;

    private Grupo $miercoles;

    private Perfil $profesor;

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
        $this->profesor = $this->perfil('profe', 'profesor');

        $this->violin = Promotoria::create([
            'nombre' => 'Violin',
            'area_id' => $area->id,
            'profesor_id' => $this->profesor->id,
        ]);

        $this->lunes = $this->grupo('Lunes tarde', 'basico');
        $this->miercoles = $this->grupo('Miercoles tarde', 'intermedio');
    }

    // --------------------------------------------------------------------
    // Lo que se encontro al escribir la pantalla
    // --------------------------------------------------------------------

    /**
     * QUITAR DE UN GRUPO NO SACA DE LOS DEMAS.
     *
     * El boton de la fila mandaba un `grupo_id` vacio, y desde que la columna se
     * fue eso significa «sacalo de todos». A quien va a dos horarios, quitarlo
     * del lunes le quitaba tambien el miercoles. Sin fallar y sin avisar.
     */
    public function test_quitar_de_un_grupo_no_saca_de_los_demas(): void
    {
        $ana = $this->matricula('ana');
        $ana->repartirEn([$this->lunes->id, $this->miercoles->id]);

        // Lo que manda el boton: la lista de los OTROS grupos.
        $this->actingAs($this->profesor->user)
            ->post(route('panel-guardar-grupos', $ana), ['grupo_id' => ['', $this->miercoles->id]])
            ->assertRedirect();

        $this->assertSame(
            [$this->miercoles->id],
            $ana->grupos()->pluck('grupos.id')->all(),
            'quitarlo de un horario se llevo el otro.'
        );
    }

    /** Y con la lista vacia sale de todos, que es lo que significa. */
    public function test_la_lista_vacia_lo_saca_de_todos(): void
    {
        $ana = $this->matricula('ana');
        $ana->repartirEn([$this->lunes->id, $this->miercoles->id]);

        $this->actingAs($this->profesor->user)
            ->post(route('panel-guardar-grupos', $ana), ['grupo_id' => ['']]);

        $this->assertSame(0, $ana->grupos()->count());
    }

    // --------------------------------------------------------------------
    // La pantalla
    // --------------------------------------------------------------------

    /** Marca las casillas de los grupos en los que ya esta. */
    public function test_la_pantalla_marca_los_grupos_que_ya_tiene(): void
    {
        $ana = $this->matricula('ana');
        $ana->repartirEn([$this->miercoles->id]);

        $datos = collect(
            $this->actingAs($this->profesor->user)
                ->get(route('panel-grupos', $ana))->assertOk()->viewData('grupos')
        )->mapWithKeys(fn ($g) => [$g['grupo']->id => $g['dentro']]);

        $this->assertFalse($datos[$this->lunes->id]);
        $this->assertTrue($datos[$this->miercoles->id]);
    }

    /**
     * Y la trae con `data-modal-cuerpo`, que es lo que el modal se lleva.
     *
     * Sin esa marca el enlace navega a la pagina entera en vez de abrir el
     * dialogo — no falla, pero deja de ser un modal y nadie lo nota al leer el
     * Blade.
     */
    public function test_la_pantalla_se_puede_abrir_en_un_modal(): void
    {
        $ana = $this->matricula('ana');

        $html = $this->actingAs($this->profesor->user)
            ->get(route('panel-grupos', $ana))->assertOk()->getContent();

        $this->assertStringContainsString('data-modal-cuerpo', $html);
        $this->assertStringContainsString('name="grupo_id[]"', $html);
    }

    /** Reparte en los dos de una sola vez. */
    public function test_reparte_en_dos_grupos_de_una_vez(): void
    {
        $ana = $this->matricula('ana');

        $this->actingAs($this->profesor->user)
            ->post(route('panel-guardar-grupos', $ana), [
                'grupo_id' => ['', $this->lunes->id, $this->miercoles->id],
            ])
            ->assertRedirect();

        $this->assertSame(
            [$this->lunes->id, $this->miercoles->id],
            $ana->grupos()->pluck('grupos.id')->sort()->values()->all()
        );
    }

    // --------------------------------------------------------------------
    // Cuando sale el enlace, y como se llama
    // --------------------------------------------------------------------

    /**
     * CON UN SOLO GRUPO EL ENLACE NO SALE.
     *
     * No hay nada que elegir: el modal enseñaria una casilla que hace lo mismo
     * que el boton de al lado. Medido el 10/09/2026: de las catorce promotorias
     * con grupos, SIETE tienen uno solo — sin esto el enlace seria ruido en la
     * mitad de las pantallas.
     */
    public function test_con_un_solo_grupo_no_sale_el_enlace(): void
    {
        $this->miercoles->delete();

        $ana = $this->matricula('ana');
        $ana->repartirEn([$this->lunes->id]);

        $html = $this->cuerpoDelPanel();

        // Se afirma sobre el RÓTULO y no sobre la URL: «Quitar de este grupo»
        // postea a esa misma ruta —GET y POST comparten camino— asi que buscar
        // la URL la encuentra siempre y la prueba no probaria nada.
        $this->assertStringNotContainsString(
            'Agregar a más grupos',
            $html,
            'ofrece repartir en varios grupos donde solo hay uno.'
        );
    }

    /** Con dos o mas si, y con un rotulo que dice que hace. */
    public function test_con_dos_grupos_sale_el_enlace_y_dice_que_hace(): void
    {
        $ana = $this->matricula('ana');
        $ana->repartirEn([$this->lunes->id]);

        $html = $this->cuerpoDelPanel();

        $this->assertStringContainsString('Agregar a más grupos', $html);
        $this->assertStringContainsString(route('panel-grupos', $ana), $html);
    }

    /**
     * Y el modal dice que desmarcar SACA del grupo.
     *
     * Se llega a el desde un enlace que dice «Agregar», asi que quitar no se
     * espera — y desmarcar es justo lo que lo hace. Sin esa frase, quien quiere
     * sacar a alguien de un horario no sabe que esta es la pantalla.
     */
    public function test_el_modal_dice_que_desmarcar_saca_del_grupo(): void
    {
        $ana = $this->matricula('ana');

        $html = $this->actingAs($this->profesor->user)
            ->get(route('panel-grupos', $ana))->assertOk()->getContent();

        $this->assertStringContainsString('lo sacas de ese grupo', $html);
    }

    /** El cuerpo del Panel para Violin, tal como lo ve su profesor. */
    private function cuerpoDelPanel(): string
    {
        return $this->actingAs($this->profesor->user)
            ->get(route('panel-promotoria-cuerpo', $this->violin))
            ->assertOk()
            ->getContent();
    }

    // --------------------------------------------------------------------
    // Las puertas
    // --------------------------------------------------------------------

    /** Un grupo de OTRA promotoria no entra, aunque llegue en el formulario. */
    public function test_no_entra_un_grupo_de_otra_promotoria(): void
    {
        $otra = Promotoria::create(['nombre' => 'Piano', 'area_id' => $this->violin->area_id]);
        $ajeno = Grupo::create([
            'promotoria_id' => $otra->id, 'nombre' => 'Jueves',
            'nivel' => 'basico', 'salon' => 'B2', 'cupo_maximo' => 10,
        ]);

        $ana = $this->matricula('ana');

        $this->actingAs($this->profesor->user)
            ->post(route('panel-guardar-grupos', $ana), ['grupo_id' => ['', $ajeno->id]]);

        $this->assertSame(0, $ana->grupos()->count(), 'lo metio en un grupo de otra promotoria.');
    }

    /**
     * Y el MODELO tambien lo para, no solo el controlador.
     *
     * Son dos cerrojos: el controlador acota la consulta a los grupos de esta
     * promotoria, y `repartirEn()` lo comprueba otra vez. Con la prueba de
     * arriba sola no se sabe cual de los dos sostiene — se comprobo quitando
     * cada uno por separado y seguia verde. Esta le da testigo al de abajo, que
     * es el que alcanza a cualquier camino futuro.
     */
    public function test_el_modelo_rechaza_un_grupo_de_otra_promotoria(): void
    {
        $otra = Promotoria::create(['nombre' => 'Piano', 'area_id' => $this->violin->area_id]);
        $ajeno = Grupo::create([
            'promotoria_id' => $otra->id, 'nombre' => 'Jueves',
            'nivel' => 'basico', 'salon' => 'B2', 'cupo_maximo' => 10,
        ]);

        $ana = $this->matricula('ana');

        try {
            $ana->repartirEn([$ajeno->id]);
            $this->fail('Se esperaba una ValidationException.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('grupo', $e->errors());
        }

        $this->assertSame(0, $ana->grupos()->count());
    }

    /** Un grupo lleno se rechaza, y se dice por que. */
    public function test_un_grupo_lleno_se_rechaza(): void
    {
        $this->lunes->cupo_maximo = 1;
        $this->lunes->save();

        $this->matricula('beto')->repartirEn([$this->lunes->id]);

        $ana = $this->matricula('ana');

        $this->actingAs($this->profesor->user)
            ->post(route('panel-guardar-grupos', $ana), ['grupo_id' => ['', $this->lunes->id]])
            ->assertSessionHas('error');

        $this->assertSame(0, $ana->grupos()->count());
    }

    /**
     * Y un profesor ajeno no reparte en una promotoria que no dicta.
     *
     * La puerta va en el CONTROLADOR y no solo en esconder el enlace: la URL
     * existe y se puede teclear.
     */
    public function test_un_profesor_ajeno_no_reparte(): void
    {
        $ajeno = $this->perfil('otro', 'profesor');
        $ana = $this->matricula('ana');

        $this->actingAs($ajeno->user)
            ->get(route('panel-grupos', $ana))
            ->assertForbidden();

        $this->actingAs($ajeno->user)
            ->post(route('panel-guardar-grupos', $ana), ['grupo_id' => ['', $this->lunes->id]]);

        $this->assertSame(0, $ana->grupos()->count(), 'un profesor ajeno repartio en su promotoria.');
    }

    // --------------------------------------------------------------------

    private function grupo(string $nombre, string $nivel): Grupo
    {
        return Grupo::create([
            'promotoria_id' => $this->violin->id,
            'nombre' => $nombre,
            'nivel' => $nivel,
            'salon' => 'A1',
            'cupo_maximo' => 10,
        ]);
    }

    private function matricula(string $nombre): Matricula
    {
        return Matricula::create([
            'estudiante_id' => $this->perfil($nombre, 'estudiante')->id,
            'promotoria_id' => $this->violin->id,
            'periodo_id' => $this->periodo->id,
            'fecha' => Carbon::now(),
            'estado' => Matricula::ACTIVA,
        ]);
    }

    private function perfil(string $nombre, string $rol): Perfil
    {
        $user = User::create(['username' => $nombre, 'password' => 'demo1234', 'activo' => true]);

        return Perfil::create([
            'user_id' => $user->id,
            'rol' => $rol,
            'nombre_completo' => ucfirst($nombre),
            'fecha_nacimiento' => Carbon::today()->subYears(20)->toDateString(),
            'telefono' => '3000000000',
        ]);
    }
}
