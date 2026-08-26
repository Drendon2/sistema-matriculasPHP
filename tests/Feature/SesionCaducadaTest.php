<?php

namespace Tests\Feature;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Una sesion caducada devuelve al formulario, no a la pagina 419 de Laravel.
 *
 * Se prueba contra el MANEJADOR y no por HTTP a proposito, y no es por comodidad:
 * `VerifyCsrfToken` se salta entero cuando corren las pruebas
 * (`runningUnitTests()`, linea 83 del middleware), asi que una peticion de
 * prueba con un testigo falso pasaria como buena y NUNCA daria 419. Una prueba
 * por HTTP aqui seria de las que pasan sin comprobar nada.
 *
 * Lo que se ejercita es el camino real completo: el manejador recibe la
 * `TokenMismatchException`, la convierte en un `HttpException` de 419 en
 * `prepareException()` y solo despues consulta los renderizadores. Esa
 * conversion es justo la trampa que costo el primer intento --un gancho
 * registrado sobre `TokenMismatchException` se acepta sin protestar y no se
 * ejecuta jamas--, y esta prueba enrojece si alguien vuelve a atarlo ahi.
 */
class SesionCaducadaTest extends TestCase
{
    public function test_el_testigo_caducado_vuelve_al_formulario_con_aviso(): void
    {
        $respuesta = $this->renderizar($this->peticionDeLogin());

        $this->assertSame(302, $respuesta->getStatusCode());
        $this->assertSame(route('login'), $respuesta->headers->get('Location'));
        $this->assertSame(
            'Tu sesión caducó por inactividad. Vuelve a intentarlo.',
            session('error')
        );
    }

    /**
     * El usuario vuelve escrito y la contrasena no.
     *
     * Teclear un usuario en un telefono es lo tedioso; la contrasena se
     * reescribe. Es la misma regla que ya sigue el 429.
     */
    public function test_devuelve_el_usuario_pero_nunca_la_contrasena(): void
    {
        $this->renderizar($this->peticionDeLogin());

        $viejo = session('_old_input');

        $this->assertSame('ana', $viejo['username']);
        $this->assertArrayNotHasKey('password', $viejo);
    }

    /**
     * Un 419 que NO venga del CSRF se queda como estaba.
     *
     * El gancho cuelga de `HttpException`, que es una clase muy concurrida: sin
     * mirar la excepcion anterior se estaria secuestrando cualquier 419 que
     * alguien lance por otro motivo y mandandolo al login con un aviso que
     * mentiria.
     */
    public function test_un_419_ajeno_al_csrf_no_se_secuestra(): void
    {
        $respuesta = $this->renderizar(
            $this->peticionDeLogin(),
            new HttpException(419, 'otra cosa distinta')
        );

        $this->assertSame(419, $respuesta->getStatusCode());
    }

    /**
     * Quien pide JSON sigue recibiendo su 419.
     *
     * Un redirect a una pagina de login no le sirve de nada a algo que no es un
     * navegador. Mismo criterio que el 429.
     */
    public function test_una_peticion_de_json_sigue_recibiendo_el_419(): void
    {
        $peticion = $this->peticionDeLogin();
        $peticion->headers->set('Accept', 'application/json');

        $respuesta = $this->renderizar($peticion);

        $this->assertSame(419, $respuesta->getStatusCode());
    }

    // -----------------------------------------------------------------------

    private function peticionDeLogin(): Request
    {
        $peticion = Request::create(
            route('login'),
            'POST',
            ['username' => 'ana', 'password' => 'la-de-verdad', '_token' => 'caducado']
        );

        // Sin `Referer` el `back()` cae al `fallback`, que tambien acaba en el
        // login: se pone para ejercitar el camino normal, que es el que usa
        // cualquier navegador.
        $peticion->headers->set('referer', route('login'));
        $peticion->setLaravelSession($this->app['session']->driver());

        return $peticion;
    }

    private function renderizar(Request $peticion, ?\Throwable $error = null): mixed
    {
        return $this->app->make(ExceptionHandler::class)->render(
            $peticion,
            $error ?? new TokenMismatchException('CSRF token mismatch.')
        );
    }
}
