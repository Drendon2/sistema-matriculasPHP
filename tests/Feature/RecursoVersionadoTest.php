<?php

namespace Tests\Feature;

use App\Models\Periodo;
use App\Support\Recurso;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

/**
 * El CSS y el JS se sirven con la fecha del archivo pegada detras (D-02).
 *
 * Por que importa mas de lo que parece: `acciones.js` es donde vive el aviso de
 * formulario rechazado. Un navegador que conserve en cache la version anterior
 * reproduce el mismo sintoma que ese archivo arreglo —quien pulsa no ve nada—,
 * y encima con la aplicacion ya corregida en el servidor.
 *
 * La ultima prueba es la que mas valor tiene a un ano: recorre TODAS las
 * plantillas. El hallazgo original solo miro los dos layouts y dio por buenos
 * tres activos cuando eran siete; sin una prueba que barra el arbol, la
 * plantilla que se escriba manana volvera a usar `asset()` por costumbre.
 */
class RecursoVersionadoTest extends TestCase
{
    use RefreshDatabase;

    /** Un activo de mentira en public/, para poder cambiarle la fecha sin tocar los de verdad. */
    private const ACTIVO = 'css/prueba-recurso.css';

    private string $carpeta;

    protected function setUp(): void
    {
        parent::setUp();

        File::put(public_path(self::ACTIVO), 'body{}');

        $this->carpeta = storage_path('framework/testing/vistas-recurso');
        File::ensureDirectoryExists($this->carpeta);
        File::put($this->carpeta.'/con-recurso.blade.php', "@recurso('".self::ACTIVO."')\n");
        File::put($this->carpeta.'/hija.blade.php', "@extends('layouts.app')\n@section('content')\nx\n@endsection\n");

        View::addLocation($this->carpeta);

        Route::middleware('web')->group(function () {
            Route::get('/prueba-recurso', fn () => view('con-recurso'));
            Route::get('/prueba-layout', fn () => view('hija'));
        });
    }

    protected function tearDown(): void
    {
        File::delete(public_path(self::ACTIVO));
        File::deleteDirectory($this->carpeta);

        parent::tearDown();
    }

    public function test_la_url_de_un_activo_lleva_la_fecha_del_archivo(): void
    {
        $fecha = filemtime(public_path(self::ACTIVO));

        $this->assertSame(asset(self::ACTIVO).'?v='.$fecha, Recurso::versionado(self::ACTIVO));
    }

    public function test_tocar_el_archivo_cambia_la_marca(): void
    {
        $antes = Recurso::versionado(self::ACTIVO);

        touch(public_path(self::ACTIVO), time() + 60);
        clearstatcache(true, public_path(self::ACTIVO));

        $this->assertNotSame($antes, Recurso::versionado(self::ACTIVO));
    }

    public function test_un_activo_que_no_existe_no_rompe_ni_inventa_marca(): void
    {
        $url = Recurso::versionado('css/no-existe.css');

        $this->assertSame(asset('css/no-existe.css'), $url);
        $this->assertStringNotContainsString('?v=', $url);
    }

    /**
     * La regresion que traeria `view:cache`, que el despliegue SI corre.
     *
     * Si la directiva resolviera la fecha al COMPILAR en vez de al pintar, se
     * congelaria la del momento del despliegue y no volveria a moverse nunca.
     *
     * Se mira el PHP compilado y no el HTML de dos peticiones seguidas. Esa fue
     * la primera version y era mentira: Blade solo recompila si la plantilla es
     * mas nueva que su compilado, y esa comparacion la responde la cache de
     * `stat` de PHP, que dentro de un mismo proceso conserva lo que leyo antes.
     * La prueba pasaba o fallaba segun que se hubiera ejecutado ANTES en la
     * misma clase —verde con la clase entera, roja en aislado— con el mismo
     * codigo debajo. Sobre el compilado no hay carrera: o hay una llamada, o hay
     * un numero.
     */
    public function test_la_directiva_compila_a_una_llamada_y_no_a_la_fecha(): void
    {
        $compilado = Blade::compileString("@recurso('".self::ACTIVO."')");

        $this->assertStringContainsString('Recurso::versionado', $compilado);
        $this->assertDoesNotMatchRegularExpression('#\?v=\d+#', $compilado);
    }

    public function test_el_layout_con_sesion_sirve_el_css_y_el_js_versionados(): void
    {
        $html = $this->get('/prueba-layout')->getContent();

        $this->assertMatchesRegularExpression('#css/app\.css\?v=\d+#', $html);
        $this->assertMatchesRegularExpression('#js/acciones\.js\?v=\d+#', $html);
    }

    public function test_la_pantalla_publica_sirve_el_css_y_el_js_versionados(): void
    {
        // Sin periodo abierto la pantalla se queda en el aviso y no pinta su
        // script: sin esto la prueba comprobaria el CSS y nada mas.
        Periodo::create([
            'nombre' => '2026-1',
            'fecha_inicio' => '2026-01-15',
            'fecha_fin' => '2026-06-30',
            'activo' => true,
            'matriculas_abiertas' => true,
        ]);

        $html = $this->get('/inscripcion')->getContent();

        $this->assertMatchesRegularExpression('#css/publico\.css\?v=\d+#', $html);
        $this->assertMatchesRegularExpression('#js/inscripcion\.js\?v=\d+#', $html);
    }

    /** Ninguna plantilla enlaza un CSS o un JS por `asset()`, que no versiona. */
    public function test_ninguna_plantilla_enlaza_un_activo_sin_version(): void
    {
        $culpables = [];

        foreach (File::allFiles(resource_path('views')) as $vista) {
            if (preg_match_all("#asset\(\s*'(css|js)/[^']+'#", $vista->getContents(), $c)) {
                $culpables[] = $vista->getRelativePathname().': '.implode(', ', $c[0]);
            }
        }

        $this->assertSame([], $culpables, "Estas plantillas se sirven sin version:\n".implode("\n", $culpables));
    }
}
