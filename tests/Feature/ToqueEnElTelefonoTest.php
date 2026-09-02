<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\CupoPromotoria;
use App\Models\Matricula;
use App\Models\Perfil;
use App\Models\Periodo;
use App\Models\Promotoria;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Los MARCADORES de los que depende que una pantalla se pueda usar con el dedo.
 *
 * Este proyecto no puede probar el tamaño de nada: PHPUnit no tiene navegador,
 * no hay CSS aplicado y `getBoundingClientRect` no existe aquí. Lo que SÍ se
 * puede vigilar es lo que la hoja de estilos necesita encontrar en el HTML para
 * hacer su trabajo, que es exactamente lo que se cae sin ruido.
 *
 * Y se cayó: medido a 390px antes de esta tanda, «Editar» y «Eliminar» de los
 * seis catálogos eran dos enlaces de texto de 20px de alto pegados con un punto
 * en medio, uno de ellos el borrado; y en Cupos la casilla que se viene a editar
 * quedaba fuera de la pantalla, al otro lado de un arrastre de 90px. Las dos
 * cosas las arregla la misma pareja de marcas —`.tabla-personas` en la tabla y
 * `data-celda="accion"` en la celda—, que no cambian NADA en escritorio: quien
 * las quite creyendo que sobran no rompe ninguna pantalla que él vaya a mirar.
 *
 * Por eso estas pruebas. No dicen que se vea bien; dicen que sigue puesto lo
 * único de lo que depende que se vea bien en un teléfono, que es donde este
 * sistema se usa.
 */
class ToqueEnElTelefonoTest extends TestCase
{
    use RefreshDatabase;

    private Perfil $admin;

    private Area $musica;

    private Promotoria $violin;

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

        $this->musica = Area::create(['nombre' => 'Musica']);
        $this->admin = $this->crearPerfil('admin', 'administrador');
        $this->violin = Promotoria::create([
            'nombre' => 'Violin',
            'area_id' => $this->musica->id,
            'profesor_id' => $this->crearPerfil('profe', 'profesor')->id,
        ]);
    }

    private function crearPerfil(string $username, string $rol): Perfil
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

    /**
     * El fragmento de HTML de la celda que contiene ese texto.
     *
     * Se busca hacia atras desde el texto hasta el `<td` que lo abre: asi la
     * prueba habla de «la celda donde vive Eliminar» y no de la posicion que
     * ocupe esa celda en la fila, que puede cambiar sin que nada se rompa.
     */
    private function celdaQueContiene(string $html, string $texto): string
    {
        $donde = strpos($html, $texto);
        $this->assertNotFalse($donde, "No aparece «{$texto}» en la pantalla.");

        $abre = strrpos(substr($html, 0, $donde), '<td');
        $this->assertNotFalse($abre, "«{$texto}» no esta dentro de ninguna celda.");

        return substr($html, $abre, $donde - $abre);
    }

    // -----------------------------------------------------------------------
    // Los catalogos

    public function test_la_lista_de_catalogo_es_una_tabla_que_deja_de_serlo(): void
    {
        $html = $this->actingAs($this->admin->user)
            ->get(route('area-lista'))->assertOk()->getContent();

        // `.tabla-personas` es lo que bajo 640px convierte cada fila en ficha, y
        // `.tabla-catalogo` lo que deja su primera celda fluyendo como texto.
        // `.tabla-menu-esquina` es lo que pega el menú de la fila arriba a la
        // derecha de la ficha en vez de dejarlo en un renglón al final.
        $this->assertStringContainsString(
            'class="tabla-personas tabla-catalogo tabla-menu-esquina"',
            $html
        );
    }

    public function test_las_acciones_del_catalogo_van_en_una_celda_de_accion(): void
    {
        $html = $this->actingAs($this->admin->user)
            ->get(route('area-lista'))->assertOk()->getContent();

        // Sin esta marca los dos enlaces siguen siendo texto de 20px de alto.
        $this->assertStringContainsString(
            'data-celda="accion"',
            $this->celdaQueContiene($html, 'Editar')
        );
        $this->assertStringContainsString(
            'data-celda="accion"',
            $this->celdaQueContiene($html, 'Eliminar')
        );
    }

    /**
     * Un departamento con promotorias no se borra, y su «Eliminar» se queda a la
     * vista APAGADO en vez de desaparecer: si desapareciera, enterarse de que
     * esta protegido exigiria pulsarlo y que te lo nieguen.
     *
     * `.menu-fila-inerte` es lo que lo pinta apagado dentro del menú, que
     * es el vocabulario de forma del sistema. Antes era un `.campo-info` con el
     * margen anulado a mano en el atributo `style`, y ahi estaba el problema:
     * un `style=` no se puede anular desde la hoja, asi que en la ficha del
     * telefono ese «Eliminar» no habia forma de darle la forma de sus vecinos.
     */
    public function test_el_eliminar_bloqueado_se_queda_apagado_y_no_es_un_enlace(): void
    {
        $html = $this->actingAs($this->admin->user)
            ->get(route('area-lista'))->assertOk()->getContent();

        $celda = $this->celdaQueContiene($html, 'Eliminar');

        // Desde el 01/09 vive dentro del menú de la fila, apagada.
        $this->assertStringContainsString('menu-fila-inerte', $celda);
        $this->assertStringNotContainsString(route('area-eliminar', $this->musica), $html);
    }

    // -----------------------------------------------------------------------
    // Cupos

    public function test_la_casilla_de_cupo_va_en_una_celda_de_accion(): void
    {
        CupoPromotoria::create([
            'promotoria_id' => $this->violin->id,
            'periodo_id' => $this->periodo->id,
            'cupo_maximo' => 10,
        ]);

        $html = $this->actingAs($this->admin->user)
            ->get(route('gestion-cupos'))->assertOk()->getContent();

        $this->assertStringContainsString('class="tabla-personas tabla-catalogo"', $html);
        // Cupos NO lleva menú de fila: su acción es la casilla, que se edita
        // ahí mismo. Sacar esa celda del flujo la mandaría a la esquina.
        $this->assertStringNotContainsString('tabla-menu-esquina', $html);

        // La casilla es lo que se viene a tocar a esta pantalla. `data-celda`
        // es lo que en el telefono la baja al final de la ficha a ancho
        // completo; sin el se queda en la cuarta columna, fuera de pantalla.
        $celda = $this->celdaQueContiene($html, 'name="cupo_'.$this->violin->id.'"');
        $this->assertStringContainsString('data-celda="accion"', $celda);
    }

    // -----------------------------------------------------------------------
    // La barra de filtros plegada

    public function test_la_barra_de_filtros_esta_plegada_y_dice_cuantos_hay_puestos(): void
    {
        $sinFiltrar = $this->actingAs($this->admin->user)
            ->get(route('usuario-lista'))->assertOk()->getContent();

        $this->assertStringContainsString('class="filtros-plegables"', $sinFiltrar);
        // Sin nada filtrado no hay cuenta que enseñar.
        $this->assertStringNotContainsString('filtros-cuenta', $sinFiltrar);

        $filtrado = $this->actingAs($this->admin->user)
            ->get(route('usuario-lista', ['rol' => 'profesor', 'area' => $this->musica->id]))
            ->assertOk()->getContent();

        // Con la barra cerrada, esto es lo unico que dice que la lista de abajo
        // esta acotada. Plegado no puede acabar significando escondido.
        $this->assertStringContainsString('filtros-cuenta', $filtrado);
        $this->assertStringContainsString('2 puestos', $filtrado);
    }

    /**
     * «Limpiar» es la salida de un filtro puesto. Si viviera DENTRO del pliegue,
     * quitar un filtro exigiria abrir el mismo panel que lo esconde: la salida
     * quedaria dentro de la trampa.
     */
    public function test_limpiar_no_esta_dentro_del_pliegue(): void
    {
        $html = $this->actingAs($this->admin->user)
            ->get(route('usuario-lista', ['rol' => 'profesor']))
            ->assertOk()->getContent();

        $cierre = strpos($html, '</details>', strpos($html, 'filtros-plegables'));
        $this->assertNotFalse($cierre, 'El pliegue de filtros no cierra.');

        $limpiar = strpos($html, 'Limpiar');
        $this->assertNotFalse($limpiar, 'No hay boton de limpiar con un filtro puesto.');
        $this->assertGreaterThan($cierre, $limpiar, '«Limpiar» quedo dentro del pliegue.');
    }

    // -----------------------------------------------------------------------
    // La ayuda que se montaba encima del campo de arriba

    /**
     * `.campo-info` trae `margin-top: -0.6rem` porque esta hecha para pegarse
     * bajo un titulo. Debajo de un input se sube ENCIMA y se solapa con el: en
     * el formulario de usuario, «Opcional. Puede repetirse…» partia el borde
     * inferior del campo de correo por la mitad. Se vio abriendo la pagina, y
     * no la vio ninguna prueba en las cuatro veces que mordio.
     *
     * `.campo-ayuda` es la misma regla con el margen positivo. Esto vigila que
     * no vuelva la otra a ese sitio.
     */
    public function test_la_ayuda_de_un_campo_no_usa_la_clase_del_margen_negativo(): void
    {
        $plantilla = file_get_contents(resource_path('views/gestion/usuario-form.blade.php'));

        $this->assertStringNotContainsString('campo-info', $plantilla);
        $this->assertStringContainsString('campo-ayuda', $plantilla);
    }

    // -----------------------------------------------------------------------
    // El modal que solo tiene salida

    /**
     * La rama de «no se puede eliminar» no pregunta nada: solo explica y ofrece
     * la salida. Esa salida iba suelta en un `<p>`, fuera de `.modal-botones`,
     * que es lo que en el telefono pone los botones a ancho completo — o sea que
     * era el unico boton del sistema que se quedaba a media pantalla.
     */
    public function test_la_salida_del_modal_que_no_borra_va_en_la_fila_de_botones(): void
    {
        Matricula::create([
            'estudiante_id' => $this->admin->id,
            'promotoria_id' => $this->violin->id,
            'periodo_id' => $this->periodo->id,
            'estado' => 'activa',
        ]);

        $html = $this->actingAs($this->admin->user)
            ->get(route('area-eliminar', $this->musica))->assertOk()->getContent();

        $this->assertStringContainsString('No se puede eliminar', $html);

        $donde = strpos($html, 'Volver');
        $abre = strrpos(substr($html, 0, $donde), '<div');
        $this->assertStringContainsString('modal-botones', substr($html, $abre, $donde - $abre));
    }
}
