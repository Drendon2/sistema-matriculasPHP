<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Perfil;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Que llega cuando el servidor RECHAZA un formulario enviado por `acciones.js`.
 *
 * Existe por un fallo que ninguna prueba veia y que llevaba puesto desde que
 * existe ese archivo: `fetch` manda un Accept comodin si nadie dice otra cosa, y
 * con el `Request::expectsJson()` de Laravel devuelve true. Un formulario mal
 * rellenado no contestaba entonces con el 302 y su HTML, sino con un 422 y un
 * JSON — y `pintar()`, que busca un <main> ahi dentro, no lo encuentra, se rinde
 * y navega. El aviso de por que se rechazo no se veia: el mismo fallo que ya
 * costo un profesor en produccion y que se creia arreglado.
 *
 * OJO CON EL ALCANCE DE ESTE ARCHIVO. Prueba la mitad del servidor: «con estas
 * cabeceras, HTML». La otra mitad —que `acciones.js` mande esas cabeceras— no la
 * puede vigilar ninguna prueba de PHP, porque el cliente de PHPUnit no manda el
 * comodin y por ahi el servidor SIEMPRE contesto HTML. Ahi esta el porque de que
 * las pruebas de rechazo que ya existian pasaran en verde con el fallo puesto.
 */
class RechazoDeFormularioTest extends TestCase
{
    use RefreshDatabase;

    /** Las mismas que la constante CABECERAS de `acciones.js`. */
    private const COMO_ACCIONES_JS = [
        'X-Requested-With' => 'XMLHttpRequest',
        'Accept' => 'text/html',
    ];

    /** El comodin que manda `fetch` por su cuenta: el que rompia esto. */
    private const COMO_UN_FETCH_PELADO = [
        'X-Requested-With' => 'XMLHttpRequest',
        'Accept' => '*/*',
    ];

    private function director(): User
    {
        $user = User::create(['username' => 'dire', 'password' => 'x', 'activo' => true]);
        Perfil::create([
            'user_id' => $user->id,
            'rol' => 'director',
            'nombre_completo' => 'Dire',
            'fecha_nacimiento' => '1980-01-01',
            'telefono' => '3000000000',
        ]);

        return $user;
    }

    public function test_un_formulario_rechazado_vuelve_como_html_y_no_como_json(): void
    {
        $respuesta = $this->actingAs($this->director())
            ->withHeaders(self::COMO_ACCIONES_JS)
            ->post(route('area-nueva'), ['nombre' => '']);

        // 302 y no 422: es lo que `acciones.js` sigue para repintar con el aviso.
        $respuesta->assertRedirect();
        $respuesta->assertSessionHasErrors('nombre');

        $this->assertStringNotContainsString(
            'application/json',
            (string) $respuesta->headers->get('content-type')
        );
    }

    /**
     * La otra cara, y esta es la que explica el fallo.
     *
     * No se prueba para pedir que siga asi —es como se comporta Laravel y no lo
     * decidimos nosotros—, sino para dejar constancia de POR QUE hace falta
     * mandar el Accept: quitarlo devuelve exactamente esto.
     */
    public function test_con_el_accept_comodin_laravel_contesta_json(): void
    {
        $respuesta = $this->actingAs($this->director())
            ->withHeaders(self::COMO_UN_FETCH_PELADO)
            ->post(route('area-nueva'), ['nombre' => '']);

        $respuesta->assertStatus(422);
        $this->assertStringContainsString(
            'application/json',
            (string) $respuesta->headers->get('content-type')
        );
    }

    /** Y cuando el formulario SI vale, el camino de siempre no cambia. */
    public function test_un_formulario_correcto_sigue_redirigiendo_a_su_lista(): void
    {
        $this->actingAs($this->director())
            ->withHeaders(self::COMO_ACCIONES_JS)
            ->post(route('area-nueva'), ['nombre' => 'Artes plásticas'])
            ->assertRedirect(route('gestion-programas'));

        $this->assertNotNull(Area::where('nombre', 'Artes plásticas')->first());
    }
}
