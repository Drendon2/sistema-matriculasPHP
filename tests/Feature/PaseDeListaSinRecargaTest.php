<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Asistencia;
use App\Models\Clase;
use App\Models\DatosEstudiante;
use App\Models\Grupo;
use App\Models\Matricula;
use App\Models\Perfil;
use App\Models\Periodo;
use App\Models\Promotoria;
use App\Models\User;
use App\Support\Fragmento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Guardar la lista sin recargar la pantalla.
 *
 * EL PROBLEMA ERA UN VIAJE, no unos bytes. `acciones.js` ya interceptaba este
 * formulario, pero el controlador guardaba y REDIRIGIA: la peticion eran dos
 * —el POST y el GET al que llevaba la redireccion— y la segunda volvia a montar
 * la pantalla entera. En el servidor eso cuesta 35 ms y no se nota; desde el
 * celular de un profesor en un salon cuesta un viaje de ida y vuelta, que es lo
 * unico que de verdad se siente.
 *
 * LO QUE SE CREYO QUE HABIA Y NO HABIA, que conviene dejar escrito para que no
 * se vuelva a «arreglar»: se dio por hecho que el aviso de «Asistencia
 * guardada» quedaba fuera de pantalla —se pinta arriba del todo y quien pulsa
 * Guardar esta al final de veinte nombres—, y se llego a anadir una marca para
 * traerlo a la vista. Es falso: `.messages` es `position: sticky` en
 * `app.css`, asi que ya se ve sin que nadie la mueva. Medido en Chrome a 390px
 * el 03/09, quedaba a 10px del borde con el scroll en 2220, antes y despues del
 * cambio; lo unico que conseguia la marca era subir al profesor 390px. Se
 * quito. Antes de volver a intentarlo, mirar si esa regla del CSS sigue puesta.
 *
 * LO QUE NINGUNA PRUEBA DE AQUI PUEDE VER: que el navegador coloque de verdad
 * el fragmento donde toca. PHPUnit no tiene navegador. Se comprobo a mano con
 * Chrome sin cabeza —una sola peticion, `redirected: false`, la cabecera de
 * vuelta puesta y el documento sin recargarse— y lo que queda vigilado aqui es
 * todo lo que decide el servidor: cuando responde con fragmento y cuando no.
 */
class PaseDeListaSinRecargaTest extends TestCase
{
    use RefreshDatabase;

    private Perfil $profesor;

    private Perfil $director;

    private Clase $clase;

    /** @var array<int, int> */
    private array $matriculas;

    protected function setUp(): void
    {
        parent::setUp();

        $periodo = Periodo::create([
            'nombre' => '2026-1',
            'fecha_inicio' => '2026-01-15',
            'fecha_fin' => '2026-06-30',
            'activo' => true,
            'matriculas_abiertas' => true,
        ]);

        $this->profesor = $this->crearPerfil('profe', 'profesor');
        $this->director = $this->crearPerfil('dire', 'director');

        $area = Area::create(['nombre' => 'Musica']);
        $promotoria = Promotoria::create([
            'nombre' => 'Violin',
            'area_id' => $area->id,
            'profesor_id' => $this->profesor->id,
        ]);

        $grupo = Grupo::create([
            'promotoria_id' => $promotoria->id,
            'nombre' => 'Grupo 1',
            'nivel' => 'basico',
            'salon' => 'A1',
            'cupo_maximo' => 5,
        ]);

        $this->matriculas = [];
        foreach (['ana', 'beto'] as $nombre) {
            $estudiante = $this->crearEstudiante($nombre);
            $matricula = new Matricula([
                'estudiante_id' => $estudiante->id,
                'promotoria_id' => $promotoria->id,
                'periodo_id' => $periodo->id,
                'estado' => Matricula::ACTIVA,
            ]);
            $matricula->save();
            $matricula->grupo_id = $grupo->id;
            $matricula->save();

            $this->matriculas[] = $matricula->id;
        }

        $this->clase = Clase::abrir($grupo, $periodo, $this->profesor);
    }

    /**
     * SIN JavaScript se sigue redirigiendo, que es la rama por defecto.
     *
     * La cabecera la manda `acciones.js` y nadie mas. Un formulario enviado por
     * el navegador a pelo tiene que seguir funcionando igual que siempre: si
     * esto se pusiera rojo, la pantalla habria dejado de funcionar para quien no
     * tenga JavaScript y no habria botones muertos, habria una pagina en blanco.
     */
    public function test_sin_la_cabecera_guardar_sigue_redirigiendo(): void
    {
        $respuesta = $this->actingAs($this->profesor->user)->post($this->url(), [
            'estado_'.$this->matriculas[0] => 'asistio',
        ]);

        $respuesta->assertRedirect(route('clase-asistencia', $this->clase));
        $respuesta->assertSessionHas('success');
        $respuesta->assertHeaderMissing(Fragmento::CABECERA);
    }

    /**
     * CON la cabecera, la respuesta es el contenido de <main> y nada mas.
     *
     * Las dos mitades importan. Que traiga la lista es lo que hace que sirva;
     * que NO traiga la pagina es lo que ahorra el viaje, y ademas es lo que
     * distingue esta respuesta de la de siempre.
     */
    public function test_con_la_cabecera_la_respuesta_es_el_fragmento(): void
    {
        $respuesta = $this->guardarComoFragmento(['estado_'.$this->matriculas[0] => 'asistio']);

        $respuesta->assertOk();
        $respuesta->assertHeader(Fragmento::CABECERA, '1');

        $html = $respuesta->getContent();

        $this->assertStringNotContainsString('<html', $html, 'el fragmento trae la pagina entera.');
        $this->assertStringNotContainsString('<main', $html, 'el fragmento trae el <main> que ya existe.');
        $this->assertStringNotContainsString('Cerrar sesión', $html, 'el fragmento trae la navegacion.');

        $this->assertStringContainsString('Asistencia guardada', $html, 'el fragmento no trae el aviso.');
        $this->assertStringContainsString('Ana', $html, 'el fragmento no trae la lista.');
    }

    /**
     * Lo que el profesor ve confirmado sale de la BASE, no del formulario.
     *
     * Es la razon de que las dos ramas compartan `datosDeLaHoja()`. Si la de
     * guardar armara su propia respuesta a partir de lo que llego en el POST,
     * enseñaria como guardado algo que el motor pudo rechazar —un estado que no
     * existe se descarta en silencio— y el profesor se iria convencido de haber
     * marcado a alguien que no quedo marcado.
     */
    public function test_el_fragmento_ensena_lo_que_quedo_escrito_y_no_lo_que_se_envio(): void
    {
        $html = $this->guardarComoFragmento([
            'estado_'.$this->matriculas[0] => 'asistio',
            'estado_'.$this->matriculas[1] => 'llego_tarde',
        ])->getContent();

        $this->assertSame(
            ['estado_'.$this->matriculas[0] => 'asistio'],
            $this->marcadas($html),
            'el fragmento enseña marcado un estado que no se guardo.'
        );

        $this->assertSame(
            1,
            Asistencia::where('clase_id', $this->clase->id)->count(),
            'la sonda de esta prueba no vale: se guardo lo que no debia.'
        );
    }

    /**
     * La hoja recien guardada y la hoja recien abierta son la MISMA.
     *
     * Esta es la prueba que sostiene la refactorizacion, mas que ninguna de las
     * otras. Dos maneras de pintar la misma pantalla pueden separarse sin que
     * nada falle y sin que nadie se entere; lo unico que lo impide es que sean
     * el mismo codigo, y lo unico que vigila que lo sigan siendo es esto.
     */
    public function test_el_fragmento_y_la_pantalla_completa_pintan_las_mismas_marcas(): void
    {
        $delFragmento = $this->marcadas($this->guardarComoFragmento([
            'estado_'.$this->matriculas[0] => 'excusa',
            'estado_'.$this->matriculas[1] => 'falto',
        ])->getContent());

        $deLaPantalla = $this->marcadas(
            $this->actingAs($this->profesor->user)->get($this->url())->getContent()
        );

        $this->assertSame(
            $deLaPantalla,
            $delFragmento,
            'la hoja se pinta distinta segun por donde se llegue.'
        );
        $this->assertCount(2, $delFragmento, 'la sonda de esta prueba no vale: no habia marcas que comparar.');
    }

    /**
     * El aviso se gasta en ESTA respuesta y no se encola para la siguiente.
     *
     * Es la trampa de cambiar una redireccion por un renderizado. `with()` deja
     * el mensaje en la sesion para la peticion siguiente, que en la rama del
     * fragmento ya no existe: se quedaria esperando y reapareceria pegado a la
     * proxima pantalla que el profesor abriera, diciendo «Asistencia guardada»
     * encima de otra cosa. Por eso es `now()`.
     */
    public function test_el_aviso_no_se_queda_esperando_a_la_siguiente_pantalla(): void
    {
        $this->guardarComoFragmento(['estado_'.$this->matriculas[0] => 'asistio'])
            ->assertSessionMissing('success');

        $siguiente = $this->actingAs($this->profesor->user)->get(route('panel'));

        $this->assertStringNotContainsString(
            'Asistencia guardada',
            $siguiente->getContent(),
            'el aviso reaparecio pegado a la pantalla siguiente.'
        );
    }

    /**
     * Un rechazo REDIRIGE aunque se pida el fragmento, y eso es correcto.
     *
     * El director puede mirar la hoja y no puede escribirla. Su respuesta llega
     * sin la cabecera de vuelta, asi que `acciones.js` se encuentra una pagina
     * normal y hace lo de siempre: el camino corto se salta solo, sin que el
     * controlador tenga que tratar el caso.
     */
    public function test_a_quien_no_dicta_se_le_sigue_redirigiendo(): void
    {
        $respuesta = $this->actingAs($this->director->user)
            ->withHeader(Fragmento::CABECERA, '1')
            ->post($this->url(), ['estado_'.$this->matriculas[0] => 'asistio']);

        $respuesta->assertRedirect(route('clase-asistencia', $this->clase));
        $respuesta->assertHeaderMissing(Fragmento::CABECERA);

        $this->assertSame(
            0,
            Asistencia::where('clase_id', $this->clase->id)->count(),
            'el director escribio en la hoja.'
        );
    }

    private function url(): string
    {
        return route('clase-asistencia', $this->clase);
    }

    /**
     * @param  array<string, string>  $marcas
     */
    private function guardarComoFragmento(array $marcas): TestResponse
    {
        return $this->actingAs($this->profesor->user)
            ->withHeader(Fragmento::CABECERA, '1')
            ->post($this->url(), $marcas);
    }

    /**
     * Los radios que salen MARCADOS en ese HTML, por nombre de campo.
     *
     * Se leen las marcas y no se comparan los dos HTML enteros a proposito: lo
     * que esta prueba juzga es el estado que se enseña, y una comparacion literal
     * se pondria roja por un espacio en blanco sin que nadie hubiera roto nada.
     *
     * @return array<string, string>
     */
    private function marcadas(string $html): array
    {
        preg_match_all(
            '/<input[^>]*name="(estado_\d+)"[^>]*value="([a-z_]+)"[^>]*\bchecked\b/i',
            $html,
            $encontradas,
            PREG_SET_ORDER
        );

        $marcadas = [];
        foreach ($encontradas as $una) {
            $marcadas[$una[1]] = $una[2];
        }

        return $marcadas;
    }

    private function crearEstudiante(string $username): Perfil
    {
        $perfil = $this->crearPerfil($username, 'estudiante');

        DatosEstudiante::create([
            'perfil_id' => $perfil->id,
            'documento_identidad' => '1'.$perfil->id,
        ]);

        return $perfil;
    }

    private function crearPerfil(string $username, string $rol): Perfil
    {
        $user = User::create(['username' => $username, 'password' => 'x', 'activo' => true]);

        return Perfil::create([
            'user_id' => $user->id,
            'rol' => $rol,
            'nombre_completo' => ucfirst($username),
            'fecha_nacimiento' => Carbon::today()->subYears(20)->toDateString(),
            'telefono' => '3000000000',
        ]);
    }
}
