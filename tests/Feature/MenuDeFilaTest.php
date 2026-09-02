<?php

namespace Tests\Feature;

use App\Models\Actividad;
use App\Models\Area;
use App\Models\Perfil;
use App\Models\Periodo;
use App\Models\Promotoria;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * «Editar» y «Eliminar», detras de un menu (01/09/2026).
 *
 * No son lo que se viene a hacer a una lista de catalogo —se entra a mirar, o a
 * bajar un nivel— y sin embargo eran lo unico pulsable de cada fila: dos botones
 * a ancho completo que ocupaban media ficha en el telefono. Parecian la funcion
 * principal de la pantalla, que es justo lo que no son.
 *
 * Lo que estas pruebas vigilan es lo que se romperia SIN RUIDO:
 *
 * 1. Que las acciones sigan DENTRO del menu. Sacarlas no rompe nada: la pagina
 *    responde igual, los enlaces funcionan, y lo unico que cambia es que vuelven
 *    a gritar.
 * 2. Que el menu NO tenga `id`. Es la linea mas fragil de todo esto:
 *    `acciones.js` conserva abiertos los `<details>` que tienen uno, asi que un
 *    id aqui haria que el menu reapareciese abierto tras cada accion — sobre una
 *    fila que puede que ya no exista. Ponerselo es de las cosas que uno hace
 *    «por consistencia» sin saber lo que desata.
 * 3. Que la accion FRECUENTE de cada lista se quede fuera del menu: el
 *    interruptor del enlace de una actividad y «Poner en curso» de un periodo.
 *    Esa es la linea que separa las dos, y no es de estilo.
 */
class MenuDeFilaTest extends TestCase
{
    use RefreshDatabase;

    private Perfil $admin;

    private Area $musica;

    protected function setUp(): void
    {
        parent::setUp();

        // Hace falta que haya uno en curso para que Matrículas pinte su ficha;
        // no se vuelve a leer, así que no se guarda.
        Periodo::create([
            'nombre' => '2026-1',
            'fecha_inicio' => '2026-01-15',
            'fecha_fin' => '2026-06-30',
            'activo' => true,
            'matriculas_abiertas' => true,
        ]);

        $this->musica = Area::create(['nombre' => 'Musica']);
        $this->admin = $this->crearPerfil('admin', 'administrador');
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

    /** El HTML del panel del primer menu de la pagina. */
    private function panel(string $html): string
    {
        $ini = strpos($html, 'menu-fila-panel');
        $this->assertNotFalse($ini, 'No hay ningún menú de fila en esta pantalla.');

        $fin = strpos($html, '</div>', $ini);

        return substr($html, $ini, $fin - $ini);
    }

    // -----------------------------------------------------------------------

    public function test_editar_y_eliminar_viven_dentro_del_menu(): void
    {
        $html = $this->actingAs($this->admin->user)
            ->get(route('gestion-programas'))->assertOk()->getContent();

        $panel = $this->panel($html);

        $this->assertStringContainsString('Editar', $panel);
        $this->assertStringContainsString('Eliminar', $panel);
        $this->assertStringContainsString(route('area-editar', $this->musica), $panel);
    }

    /**
     * La linea mas fragil. Ver la cabecera de esta clase.
     */
    public function test_el_menu_no_lleva_id(): void
    {
        $html = $this->actingAs($this->admin->user)
            ->get(route('gestion-programas'))->assertOk()->getContent();

        preg_match_all('/<details[^>]*class="[^"]*menu-fila[^"]*"[^>]*>/', $html, $menus);

        $this->assertNotEmpty($menus[0], 'No hay ningún menú de fila.');

        foreach ($menus[0] as $etiqueta) {
            $this->assertStringNotContainsString(
                'id=',
                $etiqueta,
                'Un menú de fila con id reaparece abierto tras cada acción: '
                    .'`acciones.js` conserva el estado de los <details> que lo tienen.'
            );
        }
    }

    /**
     * Un departamento con promotorias no se borra. La opcion se queda en el
     * menu, apagada y diciendo por que, en vez de desaparecer: si desapareciera,
     * enterarse exigiria pulsarla y que te lo nieguen.
     */
    public function test_la_opcion_de_borrar_lo_protegido_sale_apagada_y_sin_enlace(): void
    {
        Promotoria::create([
            'nombre' => 'Violin',
            'area_id' => $this->musica->id,
            'profesor_id' => $this->admin->id,
        ]);

        $html = $this->actingAs($this->admin->user)
            ->get(route('gestion-programas'))->assertOk()->getContent();

        $panel = $this->panel($html);

        $this->assertStringContainsString('menu-fila-inerte', $panel);
        $this->assertStringContainsString('en su historial', $panel);
        $this->assertStringNotContainsString(route('area-eliminar', $this->musica), $html);
    }

    // -----------------------------------------------------------------------
    // Lo que NO entra al menu

    /**
     * Abrir o cerrar el enlace es lo que se hace con una actividad en marcha
     * —«ya empezamos, no reciban más»—, y es lo unico que puede parar una
     * actividad sin cupo. Esconderlo a un toque de distancia seria esconder la
     * accion frecuente para dejar a la vista las raras.
     */
    public function test_el_interruptor_del_enlace_se_queda_fuera_del_menu(): void
    {
        $actividad = Actividad::create([
            'tipo' => Actividad::PROYECCION,
            'nombre' => 'Orquesta',
            'responsable_id' => $this->admin->id,
            'abierta' => true,
        ]);

        $html = $this->actingAs($this->admin->user)
            ->get(route('gestion-programas'))->assertOk()->getContent();

        $accion = route('actividad-proyeccion-enlace', $actividad);
        $this->assertStringContainsString($accion, $html);

        // Fuera de TODOS los paneles: el formulario va antes en el HTML.
        foreach (explode('menu-fila-panel', $html) as $i => $trozo) {
            if ($i === 0) {
                continue;
            }
            $panel = substr($trozo, 0, strpos($trozo, '</div>') ?: 0);
            $this->assertStringNotContainsString($accion, $panel);
        }
    }

    public function test_poner_en_curso_se_queda_fuera_del_menu(): void
    {
        Periodo::create([
            'nombre' => '2026-2',
            'fecha_inicio' => '2026-07-01',
            'fecha_fin' => '2026-12-15',
            'activo' => false,
            'matriculas_abiertas' => false,
        ]);

        $html = $this->actingAs($this->admin->user)
            ->get(route('gestion-matriculas'))->assertOk()->getContent();

        $this->assertStringContainsString('Poner en curso', $html);
        $this->assertStringNotContainsString('Poner en curso', $this->panel($html));
    }

    // -----------------------------------------------------------------------

    /**
     * Sin JavaScript el menu se abre igual y sus opciones llevan a una URL de
     * verdad: son enlaces, no botones que llame un guion. Es la misma regla que
     * el modal de confirmacion, que tambien sigue siendo una pagina.
     */
    public function test_las_opciones_son_enlaces_de_verdad(): void
    {
        $html = $this->actingAs($this->admin->user)
            ->get(route('gestion-programas'))->assertOk()->getContent();

        $panel = $this->panel($html);

        $this->assertStringContainsString('<a href=', $panel);
        $this->assertStringNotContainsString('<button', $panel);
    }
}
