<?php

namespace Tests\Feature;

use App\Models\Perfil;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Lo que una pantalla le dice a quien no la mira.
 *
 * EL FALLO GRANDE, del 04/09/2026: esta aplicacion responde a una accion
 * cambiando el contenido de `<main>` sin navegar (ver `acciones.js` y
 * `App\Support\Fragmento`). Para quien ve la pantalla eso ya estaba resuelto y
 * con historia: el aviso se trae a la vista tras un rechazo, `.messages` es
 * `position: sticky`, y el de rechazo no se desvanece. Para quien usa un lector
 * de pantalla no habia NADA: cambiar el contenido de un elemento no lo anuncia
 * ningun lector, asi que ni «se guardo» ni «no se guardo» llegaban.
 *
 * Es el mismo fallo que ya costo un profesor en produccion —creyo que habia un
 * tope de grupos porque no vio el aviso— pero total en vez de parcial.
 *
 * LO QUE ESTA CLASE NO PUEDE VER: si un lector de pantalla lo lee de verdad.
 * PHPUnit no tiene navegador y no tiene lector. Vigila que las piezas de las
 * que depende sigan puestas, que es la parte que alguien puede quitar sin
 * romper nada que vaya a mirar. El comportamiento se comprobo en Chrome
 * sustituyendo `fetch`, para que corriera el camino de verdad: un rechazo, un
 * exito, y el mismo rechazo dos veces seguidas.
 */
class AccesibilidadTest extends TestCase
{
    use RefreshDatabase;

    private function css(): string
    {
        return (string) File::get(public_path('css/app.css'));
    }

    private function js(): string
    {
        return (string) File::get(public_path('js/acciones.js'));
    }

    /** Una pagina cualquiera de las que llevan el layout con `<main>`. */
    private function pagina(): string
    {
        $user = User::create(['username' => 'jefa', 'password' => 'x', 'activo' => true]);

        Perfil::create([
            'user_id' => $user->id,
            'rol' => 'administrador',
            'nombre_completo' => 'Jefa',
            'fecha_nacimiento' => Carbon::today()->subYears(30)->toDateString(),
            'telefono' => '3000000000',
        ]);

        return $this->actingAs($user)->get(route('panel'))->assertOk()->getContent();
    }

    /**
     * LAS DOS CAJAS DE VOZ EXISTEN, Y EXISTEN VACIAS.
     *
     * Vacias no es un detalle: una region viva que se inserta ya con texto
     * dentro no se anuncia de forma fiable. Tienen que estar desde la primera
     * carga para que despues solo se les cambie el texto.
     */
    public function test_la_pagina_trae_las_dos_regiones_vivas_y_vacias(): void
    {
        $html = $this->pagina();

        $this->assertMatchesRegularExpression(
            '/<div class="sr-solo" role="status" aria-live="polite" data-voz="bien"><\/div>/',
            $html,
            'se fue la caja que anuncia lo que salio bien, o dejo de estar vacia.'
        );

        $this->assertMatchesRegularExpression(
            '/<div class="sr-solo" role="alert" aria-live="assertive" data-voz="mal"><\/div>/',
            $html,
            'se fue la caja que anuncia un rechazo, o dejo de estar vacia.'
        );
    }

    /**
     * Y viven FUERA de `<main>`.
     *
     * Es la otra mitad del arreglo: `acciones.js` reemplaza todo lo que hay
     * dentro de `<main>`, asi que unas cajas puestas ahi dentro se destruirian
     * en el mismo repintado que tendrian que anunciar. No fallaria nada visible
     * — simplemente dejarian de hablar.
     */
    public function test_las_regiones_vivas_estan_fuera_del_main_que_se_repinta(): void
    {
        $html = $this->pagina();

        $cierre = strpos($html, '</main>');
        $this->assertNotFalse($cierre, 'la sonda no vale: esta pagina no trae <main>.');

        $dentro = substr($html, 0, $cierre);

        $this->assertStringNotContainsString(
            'data-voz=',
            $dentro,
            'una caja de voz quedo dentro de <main>: el repintado se la lleva y deja de anunciar.'
        );
    }

    /**
     * `pintar()` llama a `anunciar()`.
     *
     * Sin esta linea las cajas se quedan vacias para siempre y todo lo demas es
     * decoracion. No rompe ninguna pantalla, no da error en consola.
     */
    public function test_el_repintado_anuncia(): void
    {
        $this->assertMatchesRegularExpression(
            '/function pintar\([^)]*\)\s*\{.*?\banunciar\(\);/s',
            $this->js(),
            '`pintar()` dejo de anunciar: las cajas de voz se quedan mudas.'
        );
    }

    /**
     * SE VACIA ANTES DE ESCRIBIR.
     *
     * Un lector anuncia el CAMBIO, no el contenido. Dos rechazos seguidos por
     * el mismo motivo —que es justo lo que pasa cuando alguien no entiende que
     * le piden— escribirian el mismo texto, no habria cambio, y el segundo no
     * se diria. Comprobado en Chrome: la secuencia real es texto, vacio, texto.
     */
    public function test_la_caja_se_vacia_antes_de_volver_a_hablar(): void
    {
        $js = $this->js();

        $this->assertMatchesRegularExpression(
            '/querySelectorAll\("\[data-voz\]"\)\.forEach\(function \(c\) \{ c\.textContent = ""; \}\);/',
            $js,
            'ya no se vacian las cajas antes de escribir: dos rechazos iguales seguidos '
            .'solo se anunciarian una vez.'
        );

        $this->assertMatchesRegularExpression(
            '/setTimeout\(function \(\) \{ caja\.textContent = texto; \}, 0\);/',
            $js,
            'el texto se escribe en la misma vuelta que el vaciado: para el lector no hay cambio.'
        );
    }

    /**
     * EL FOCO DE TECLADO SE VE en los dos controles donde no se veia.
     *
     * Los dos anulaban el anillo del navegador —uno explicitamente, el otro
     * porque quien recibe el foco es un input de 1px escondido— y lo
     * sustituian por un halo de `--accent-soft`, que da 1.18 contra la tarjeta
     * y 1.02 sobre la fila en hover. Invisible. En `input:focus` ese mismo halo
     * SI vale, porque alli el indicador de verdad es el borde que pasa a
     * `--accent` (5.33 contra la tarjeta); estos dos no tienen ese borde.
     */
    public function test_el_menu_de_fila_y_la_baldosa_del_logo_ensenan_el_foco(): void
    {
        $css = $this->css();

        foreach ([
            '.menu-fila-boton:focus-visible' => 'el boton que abre «Editar» y «Eliminar»',
            '.config-logo-file:focus-visible + .config-logo-boton' => 'la baldosa de subir el logo',
        ] as $selector => $que) {
            preg_match('/'.preg_quote($selector, '/').' \{([^}]*)\}/', $css, $regla);

            $this->assertNotEmpty($regla, "desaparecio la regla de foco de {$que}.");

            $this->assertMatchesRegularExpression(
                '/outline:\s*2px solid var\(--accent\)/',
                $regla[1],
                "{$que} se quedo sin anillo de foco visible: el halo de `--accent-soft` "
                .'solo, sin borde que lo acompañe, da 1.18 contra la tarjeta.'
            );
        }
    }

    /**
     * Los iconos decorativos no se leen; el GRAFICO si.
     *
     * La torta lleva `role="img"` y su propio `aria-label` porque informa: es lo
     * unico que dice el reparto a quien no ve los sectores. Un barrido que
     * marque «todo svg es decorativo» se la lleva por delante sin que falle
     * nada — paso el 04/09 mientras se escribia esto.
     */
    public function test_la_torta_sigue_siendo_un_grafico_con_nombre(): void
    {
        $torta = (string) File::get(resource_path('views/gestion/torta.blade.php'));

        $this->assertStringContainsString('role="img"', $torta, 'la torta perdio su rol de grafico.');
        $this->assertStringContainsString('aria-label', $torta, 'la torta perdio su nombre.');
        $this->assertStringNotContainsString(
            'aria-hidden',
            $torta,
            'la torta se marco como decorativa: es lo unico que le cuenta el reparto '
            .'a quien no ve los sectores.'
        );
    }
}
