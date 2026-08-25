<?php

namespace Tests\Feature;

use App\Http\Controllers\Gestion\UsuarioController;
use App\Models\Area;
use App\Models\Matricula;
use App\Models\Perfil;
use App\Models\Periodo;
use App\Models\Promotoria;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Los listados largos se sirven por paginas (C-01 de la auditoria del 24/08).
 *
 * Lo que se prueba de verdad esta en la primera: que el coste de la pantalla NO
 * crezca con el numero de filas. Es la regla del proyecto —medir consultas y no
 * segundos—, y aqui no es una preferencia de estilo: en local, con la base al
 * lado, traer 308 perfiles o 50 tarda lo mismo. Lo que se arregla es el coste en
 * un hosting compartido con la memoria contada, y que deje de crecer solo.
 *
 * Las demas cubren lo que la paginacion ROMPE si se pone sin mirar: el contador
 * que pasa a contar la pagina en vez del total, los filtros que se pierden al
 * pasar de pagina, y la vuelta a la pagina 1 despues de cada accion en una
 * bandeja que se vacia de a tandas.
 *
 * NO hay prueba del desempate por id del ORDER BY, y la hubo: creaba sesenta
 * homonimos y exigia que las dos paginas no repitieran a nadie. Se quito porque
 * pasaba IGUAL sin el desempate, tres veces de tres. MariaDB devuelve aqui un
 * orden estable aunque no este obligada a hacerlo, asi que la prueba daba una
 * confianza que no habia comprado. Por que se deja el desempate en el codigo,
 * en el comentario del controlador.
 */
class PaginacionTest extends TestCase
{
    use RefreshDatabase;

    private Perfil $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->crearPerfil('jefa', 'administrador');
    }

    /**
     * El coste de la pantalla no crece con la gente que haya dentro.
     *
     * Se comparan dos poblaciones muy distintas (10 y 130). El numero de
     * consultas ya era constante antes de paginar —eran unas pocas, con `with()`
     * bien puesto—, asi que la consulta sola no distingue el antes del despues:
     * lo que cambia es cuantas FILAS trae cada una, que es lo que llena la
     * memoria. Por eso la prueba mira las dos cosas.
     */
    public function test_el_coste_de_la_pantalla_no_crece_con_los_usuarios(): void
    {
        $this->poblar(9);
        [$consultasPocos, $filasPocos] = $this->medir('/gestion/usuarios');

        $this->poblar(120);
        [$consultasMuchos, $filasMuchos] = $this->medir('/gestion/usuarios');

        $this->assertSame($consultasPocos, $consultasMuchos);
        $this->assertSame(10, $filasPocos);
        $this->assertSame(UsuarioController::POR_PAGINA, $filasMuchos);
    }

    public function test_la_segunda_pagina_trae_gente_distinta_y_entre_todas_estan_todos(): void
    {
        $this->poblar(129);

        $primera = $this->idsDe('/gestion/usuarios');
        $segunda = $this->idsDe('/gestion/usuarios?page=2');
        $tercera = $this->idsDe('/gestion/usuarios?page=3');

        $this->assertCount(50, $primera);
        $this->assertCount(50, $segunda);
        $this->assertCount(30, $tercera);

        $todos = array_merge($primera, $segunda, $tercera);
        $this->assertCount(130, array_unique($todos), 'Hay filas repetidas o perdidas entre paginas.');
        $this->assertSame(Perfil::count(), count(array_unique($todos)));
    }

    /** El contador dice cuanta gente hay, no cuanta cabe en la pagina. */
    public function test_el_contador_dice_el_total_y_no_los_de_la_pagina(): void
    {
        $this->poblar(129, 'estudiante');

        $html = $this->actingAs($this->admin->user)
            ->get('/gestion/usuarios?rol=estudiante')
            ->getContent();

        $this->assertStringContainsString('129 usuarios', $html);
        $this->assertStringNotContainsString('50 usuarios', $html);
    }

    /** Pasar de pagina no puede limpiar los filtros: la 2 seria la de todos. */
    public function test_los_enlaces_de_pagina_conservan_los_filtros(): void
    {
        $this->poblar(129, 'estudiante');

        $html = $this->actingAs($this->admin->user)
            ->get('/gestion/usuarios?rol=estudiante')
            ->getContent();

        $this->assertMatchesRegularExpression('#href="[^"]*rol=estudiante[^"]*page=2#', $html);
    }

    /**
     * Desactivar a alguien de la pagina 6 no puede devolver a la 1 sin filtro.
     *
     * Esta no salio de leer el codigo: salio de pulsar «Desactivar» en la
     * pantalla, con la suite entera en verde. Al paginar Cancelaciones si se
     * penso en volver donde estabas; en Usuarios no, porque el razonamiento fue
     * sobre las filas que SALEN de la lista y esta no sale. Perder el sitio es
     * otro problema y lo tenian las dos.
     */
    public function test_desactivar_una_cuenta_devuelve_a_la_misma_pagina_y_con_el_filtro(): void
    {
        $this->poblar(129, 'estudiante');
        $victima = Perfil::where('rol', 'estudiante')->orderByDesc('id')->first();

        $this->actingAs($this->admin->user);
        $this->get('/gestion/usuarios?rol=estudiante&page=3')->assertOk();

        $destino = $this->post('/gestion/usuarios/'.$victima->id.'/alternar-activo')
            ->headers->get('Location');

        // Se miran las piezas y no la URL como texto: el orden de los
        // parametros no es parte del trato y compararlo entero hacia fallar la
        // prueba por `?page=3&rol=` frente a `?rol=&page=3`.
        parse_str((string) parse_url($destino, PHP_URL_QUERY), $query);

        $this->assertSame('/gestion/usuarios', parse_url($destino, PHP_URL_PATH));
        $this->assertSame('estudiante', $query['rol'] ?? null);
        $this->assertSame('3', $query['page'] ?? null);
    }

    /**
     * La bandeja de cancelaciones se vacia de a tandas: resolver una en la
     * pagina 2 no puede devolver a la 1.
     */
    public function test_resolver_una_cancelacion_devuelve_a_la_misma_pagina(): void
    {
        $matriculas = $this->sembrarCancelaciones(60);

        // Un solo actingAs para las dos peticiones: el de este proyecto vacia
        // la sesion en cada llamada —a proposito, ver Tests\TestCase— y con
        // ella se iria la URL previa de la que `back()` saca la pagina.
        $this->actingAs($this->admin->user);

        $this->get('/gestion/cancelaciones?page=2')->assertOk();

        $this->post('/gestion/cancelaciones/'.$matriculas[55]->id.'/aprobar')
            ->assertRedirect(route('gestion-cancelaciones', ['page' => 2]));
    }

    /** Vaciada la ultima pagina, la peticion apunta mas alla del final. */
    public function test_una_pagina_pasada_del_final_aterriza_en_la_ultima(): void
    {
        $this->sembrarCancelaciones(60);

        $this->actingAs($this->admin->user)
            ->get('/gestion/cancelaciones?page=9')
            ->assertRedirect(route('gestion-cancelaciones', ['page' => 2]));
    }

    // -----------------------------------------------------------------------

    /**
     * Consultas lanzadas y filas de la tabla que llegan al HTML.
     *
     * Se pide la pagina DOS veces y solo se mide la segunda. La primera de todo
     * el proceso arrastra tres consultas de arranque que no se repiten —resolver
     * la sesion y crear la fila de configuracion de la institucion— y contarlas
     * daba una diferencia de 3 que no tenia nada que ver con cuanta gente hay.
     */
    private function medir(string $url): array
    {
        $this->actingAs($this->admin->user)->get($url);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $html = $this->actingAs($this->admin->user)->get($url)->getContent();

        $consultas = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Una <tr> es la del encabezado.
        return [$consultas, substr_count($html, '<tr>') - 1];
    }

    private function idsDe(string $url): array
    {
        $respuesta = $this->actingAs($this->admin->user)->get($url);
        $respuesta->assertOk();

        preg_match_all('#/gestion/usuarios/(\d+)/editar#', $respuesta->getContent(), $c);

        return array_values(array_unique($c[1]));
    }

    private function poblar(int $cuantos, string $rol = 'profesor'): void
    {
        for ($i = 0; $i < $cuantos; $i++) {
            $this->crearPerfil($rol.'-'.$i.'-'.uniqid(), $rol);
        }
    }

    private function crearPerfil(string $username, string $rol, ?string $nombre = null): Perfil
    {
        $user = User::create(['username' => $username, 'password' => 'x', 'activo' => true]);

        return Perfil::create([
            'user_id' => $user->id,
            'rol' => $rol,
            'nombre_completo' => $nombre ?: ucfirst($username),
            'fecha_nacimiento' => Carbon::today()->subYears(35)->toDateString(),
            'telefono' => '3000000000',
        ]);
    }

    /** @return array<int, Matricula> */
    private function sembrarCancelaciones(int $cuantas): array
    {
        $periodo = Periodo::create([
            'nombre' => '2026-1',
            'fecha_inicio' => '2026-01-15',
            'fecha_fin' => '2026-06-30',
            'activo' => true,
            'matriculas_abiertas' => true,
        ]);

        $area = Area::create(['nombre' => 'Musica']);
        $promotoria = Promotoria::create(['nombre' => 'Violin', 'area_id' => $area->id]);

        $matriculas = [];
        for ($i = 0; $i < $cuantas; $i++) {
            $matriculas[] = Matricula::create([
                'estudiante_id' => $this->crearPerfil('est-'.$i.'-'.uniqid(), 'estudiante')->id,
                'promotoria_id' => $promotoria->id,
                'periodo_id' => $periodo->id,
                'estado' => Matricula::CANCELACION_SOLICITADA,
            ]);
        }

        return $matriculas;
    }
}
