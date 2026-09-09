<?php

namespace Tests\Feature;

use App\Models\ConfiguracionInstitucion;
use App\Models\Perfil;
use App\Models\Periodo;
use App\Models\User;
use App\Support\RestablecerClave;
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
     * Y LAS DOS DE DENTRO, que son las que obligaron al observador.
     *
     * Las dos viven en un MODAL: `acciones.js` pide la tarjeta por `fetch` y la
     * mete en el <dialog> con la pagina ya cargada. Un barrido de arranque no la
     * ve nunca, asi que el ojo no aparecia — sin fallar y sin avisar. Lo unico
     * que una prueba de PHP puede mirar es que la pantalla que envuelve al modal
     * cargue el guion y que el campo siga ahi; que el ojo APAREZCA dentro del
     * dialogo se vio abriendo la pagina.
     */
    public function test_las_pantallas_con_sesion_tambien_llevan_ojo(): void
    {
        $admin = $this->perfilSuelto('jefa', 'administrador');
        $otro = $this->perfilSuelto('otra');

        foreach ([
            route('usuario-nuevo'),
            route('usuario-editar', $otro),
            route('usuario-eliminar', $otro),
        ] as $url) {
            $html = $this->actingAs($admin->user)->get($url)->assertOk()->getContent();

            $this->assertStringContainsString(
                'js/ver-clave.js',
                $html,
                "{$url} no carga el guion, asi que su campo de clave no tiene ojo."
            );
            $this->assertMatchesRegularExpression(
                '/<input[^>]*type="password"/',
                $html,
                "{$url} ya no tiene campo de contrasena."
            );
        }
    }

    /**
     * Y LA SEXTA, que nacio el 09/09/2026: la de escribir la clave nueva tras
     * pedir el enlace por correo.
     *
     * Va aparte del proveedor de datos porque su URL no es fija —lleva dentro un
     * token de un solo uso— y con una direccion escrita a mano esta prueba
     * comprobaria el aviso de «ese enlace ya no sirve», que no tiene ningun
     * campo de clave. O sea que pasaria por la barrera equivocada.
     *
     * Es ademas la pantalla donde el ojo mas falta hace: se teclea una
     * contrasena nueva DOS veces, a ciegas, y quien llega aqui es justamente
     * quien acaba de demostrar que no se acordaba de la anterior.
     */
    public function test_la_pantalla_de_la_clave_nueva_lleva_ojo(): void
    {
        $quien = $this->perfilSuelto('ana', 'estudiante');
        $quien->user->update(['email' => 'ana@example.com']);

        $token = RestablecerClave::crear($quien->user);

        $html = $this->get(route('clave-nueva', ['token' => $token]))->assertOk()->getContent();

        $this->assertStringContainsString('js/ver-clave.js', $html);
        $this->assertStringContainsString('class="caja"', $html);
        $this->assertMatchesRegularExpression('/<input[^>]*type="password"/', $html);
    }

    private function perfilSuelto(string $username, string $rol = 'profesor'): Perfil
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
