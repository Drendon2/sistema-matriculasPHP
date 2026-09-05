<?php

namespace Tests\Feature;

use App\Models\ConfiguracionInstitucion;
use App\Models\Periodo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * El ojo para ver la contrasena que se esta escribiendo.
 *
 * Pedido por el usuario el 05/09/2026 para las tres pantallas sin sesion. La
 * razon es el celular: teclear una clave a ciegas con el pulgar es donde mas
 * gente se atasca, y en dos de las tres hay ademas un campo de confirmacion que
 * sin ver no se puede cuadrar salvo enviando y que te rechacen.
 *
 * LO QUE ESTAS PRUEBAS PUEDEN SABER ES POCO, y conviene tenerlo claro: el boton
 * lo crea `ver-clave.js` en el navegador, y aqui no hay ninguno que ejecute ese
 * archivo. Asi que NO se comprueba que el ojo aparezca ni que alterne — eso se
 * vio abriendo la pagina. Lo que se vigila es lo unico que se cae sin ruido: que
 * el guion siga cargandose en las tres pantallas, y que sigan existiendo los
 * campos de los que se cuelga. Si alguien mueve el <script> o le cambia el
 * selector al CSS, esto no lo ve; por eso la comprobacion de verdad es abrirlo.
 */
class VerLaClaveTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        ConfiguracionInstitucion::actual();

        // La inscripcion solo PINTA el formulario con un periodo de matriculas
        // abierto; cerrado enseña el aviso y no hay campo de clave del que
        // colgarse. Sin esto la prueba de esa pantalla fallaba por una razon
        // que no tiene nada que ver con el ojo.
        Periodo::create([
            'nombre' => '2026-1',
            'fecha_inicio' => Carbon::today()->subMonth()->toDateString(),
            'fecha_fin' => Carbon::today()->addMonths(3)->toDateString(),
            'activo' => true,
            'matriculas_abiertas' => true,
        ]);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function pantallasConClave(): array
    {
        return [
            'entrar' => ['/entrar'],
            'inscripcion del estudiante' => ['/inscripcion'],
            'registro del profesor' => ['/registro'],
        ];
    }

    #[DataProvider('pantallasConClave')]
    public function test_la_pantalla_carga_el_guion_del_ojo(string $url): void
    {
        $html = $this->get($url)->assertOk()->getContent();

        $this->assertStringContainsString(
            'js/ver-clave.js',
            $html,
            "{$url} no carga el guion, asi que su campo de clave no tiene ojo."
        );
    }

    /**
     * Y el guion se cuelga de `.caja input[type=password]`, asi que las dos
     * mitades tienen que seguir estando.
     */
    #[DataProvider('pantallasConClave')]
    public function test_la_pantalla_trae_el_campo_del_que_se_cuelga(string $url): void
    {
        $html = $this->get($url)->assertOk()->getContent();

        $this->assertStringContainsString('class="caja"', $html, "{$url} ya no trae la .caja que busca el selector.");
        $this->assertMatchesRegularExpression(
            '/<input[^>]*type="password"/',
            $html,
            "{$url} no tiene ningun campo de contrasena."
        );
    }

    /**
     * SIN JAVASCRIPT NO HAY BOTON MUERTO.
     *
     * El boton no viene del servidor a proposito: uno de «ver la clave» sin
     * JavaScript no haria nada, y este proyecto no pinta controles que no
     * funcionan. Esta prueba es la que se pondria roja si alguien decidiera
     * meterlo en el Blade «para que se vea en el HTML».
     */
    public function test_el_boton_no_viene_del_servidor(): void
    {
        $html = $this->get('/entrar')->assertOk()->getContent();

        $this->assertStringNotContainsString('ojo-clave', $html, 'el boton llega del servidor y sin JS no haria nada.');
    }
}
