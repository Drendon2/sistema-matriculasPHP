<?php

namespace Tests\Feature;

use App\Models\ConfiguracionInstitucion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

/**
 * El encabezado titula con el nombre de la institucion y nada mas.
 *
 * La aplicacion dejo de ser «de matriculas»: gestiona personal y estudiantes de
 * instituciones de formacion, asi que la palabra no puede ir delante del nombre
 * de la entidad. Cada institucion titula como quiera desde `nombre_institucion`,
 * que ya edita en Gestion.
 *
 * Se prueba el layout AISLADO, con vistas hijas de mentira, porque el caso que
 * mas duele —una pantalla que no declara titulo— no lo produce hoy ninguna de
 * las 26 vistas reales: todas lo declaran. Quitar el valor por defecto sin mas
 * dejaba a esa pantalla con un guion suelto, «— Casa de la Cultura», y ninguna
 * prueba funcional lo habria visto.
 */
class EncabezadoTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Vista hija minima. El salto de linea antes de `@endsection` NO es estetico:
     * pegada a una letra, la directiva se lee como un correo y no se compila, y
     * la seccion se queda con el buffer de salida abierto.
     */
    private const HIJA = "@extends('layouts.app')\n%s@section('content')\nx\n@endsection\n";

    private string $carpeta;

    protected function setUp(): void
    {
        parent::setUp();

        ConfiguracionInstitucion::actual()->update(['nombre_institucion' => 'Escuela de Artes']);

        $this->carpeta = storage_path('framework/testing/vistas-encabezado');
        File::ensureDirectoryExists($this->carpeta);
        File::put($this->carpeta.'/con-titulo.blade.php', sprintf(self::HIJA, "@section('title', 'Panel')\n"));
        File::put($this->carpeta.'/sin-titulo.blade.php', sprintf(self::HIJA, ''));

        View::addLocation($this->carpeta);

        // Con el grupo `web`, como cualquier pantalla de verdad. Sin el, la
        // vista se pinta sin lo que ese grupo comparte —entre otras cosas
        // `$errors`, que reparte `ShareErrorsFromSession`— y el layout se
        // prueba en unas condiciones que no existen en la aplicacion.
        Route::middleware('web')->group(function () {
            Route::get('/prueba-con-titulo', fn () => view('con-titulo'));
            Route::get('/prueba-sin-titulo', fn () => view('sin-titulo'));
        });
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->carpeta);

        parent::tearDown();
    }

    public function test_el_encabezado_visible_es_solo_el_nombre_de_la_institucion(): void
    {
        $html = $this->get('/prueba-con-titulo')->getContent();

        $this->assertStringContainsString('<h1>Escuela de Artes</h1>', $html);
        $this->assertStringNotContainsString('Matrículas', $html);
    }

    /** Guarda el caso que YA funcionaba: es la unica de las cuatro que no se vio en rojo. */
    public function test_el_titulo_de_la_pestana_encadena_la_seccion_con_la_institucion(): void
    {
        $html = $this->get('/prueba-con-titulo')->getContent();

        $this->assertStringContainsString('<title>Panel — Escuela de Artes</title>', $html);
    }

    public function test_una_pantalla_sin_titulo_no_deja_un_guion_suelto(): void
    {
        $html = $this->get('/prueba-sin-titulo')->getContent();

        $this->assertStringContainsString('<title>Escuela de Artes</title>', $html);
    }

    public function test_el_pie_del_certificado_no_nombra_un_sistema_de_matriculas(): void
    {
        $plantilla = File::get(resource_path('views/certificados/matricula.blade.php'));

        $this->assertStringNotContainsString('sistema de matrículas', $plantilla);
    }
}
