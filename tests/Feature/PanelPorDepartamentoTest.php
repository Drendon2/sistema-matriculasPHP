<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\DatosEstudiante;
use App\Models\Matricula;
use App\Models\Perfil;
use App\Models\Periodo;
use App\Models\Promotoria;
use App\Models\User;
use App\Support\Fragmento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * La portada del Panel, plegada por departamento.
 *
 * POR QUE, pedido por el usuario el 04/09/2026: un director ve veintiuna
 * promotorias seguidas y eso es demasiada pantalla para encontrar una, sobre
 * todo desde el telefono, que es desde donde se usa esto.
 *
 * LO QUE HAY QUE NO ROMPER, y es lo que vigila esta clase:
 *
 * 1. Plegado no puede significar ESCONDIDO. Si el departamento cerrado no dice
 *    cuantas solicitudes esperan dentro, la portada deja de servir para lo que
 *    sirve —saber donde hay algo que hacer— y hay que abrir los seis a mano.
 * 2. Con un solo departamento no se pliega nada: plegar ahi no esconde nada y
 *    solo anade un clic. Le pasaria a cualquier profesor que dicte en uno.
 * 3. El <details> lleva `id`, que es lo que permite que siga abierto despues de
 *    confirmar una matricula. Sin el, resolver quince seguidas devolveria al
 *    principio quince veces.
 */
class PanelPorDepartamentoTest extends TestCase
{
    use RefreshDatabase;

    private Perfil $director;

    private Periodo $periodo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->periodo = Periodo::create([
            'nombre' => '2026-1',
            'fecha_inicio' => Carbon::today()->subMonth()->toDateString(),
            'fecha_fin' => Carbon::today()->addMonths(4)->toDateString(),
            'activo' => true,
            'matriculas_abiertas' => true,
        ]);

        $this->director = $this->perfil('jefa', 'director');
    }

    /** Cada departamento es un pliegue, con sus promotorías dentro. */
    public function test_las_promotorias_van_dentro_de_su_departamento(): void
    {
        $this->promotoria('Violin', 'Musica');
        $this->promotoria('Piano', 'Musica');
        $this->promotoria('Ballet', 'Danza');

        $html = $this->portada();

        $this->assertStringContainsString('panel-departamento', $html, 'no se pliega por departamento.');
        $this->assertSame(2, substr_count($html, 'class="panel-departamento"'), 'no salieron dos departamentos.');
        $this->assertStringContainsString('id="departamento-musica"', $html);
        $this->assertStringContainsString('id="departamento-danza"', $html);
    }

    /**
     * PLEGADO NO ES ESCONDIDO: el departamento cerrado dice cuántas esperan.
     *
     * Es la mitad que hace usable el pliegue. Sin esta cifra hay que abrir los
     * departamentos uno a uno para saber en cuál hay trabajo, que es más
     * trabajo del que el pliegue ahorra.
     */
    public function test_el_departamento_cerrado_dice_cuantas_solicitudes_esperan(): void
    {
        $musica = $this->promotoria('Violin', 'Musica');
        $this->promotoria('Ballet', 'Danza');

        $this->pendiente($musica, 'ana');
        $this->pendiente($musica, 'beto');

        $html = $this->portada();

        $resumen = $this->trozoEntre($html, 'id="departamento-musica"', '</summary>');

        $this->assertStringContainsString('2 pendientes', $resumen, 'el departamento no dice cuántas esperan dentro.');
        $this->assertStringContainsString('1 promotoría', $resumen, 'el departamento no dice cuántas contiene.');

        // Y el de Danza, que no tiene ninguna, no se inventa una insignia.
        $danza = $this->trozoEntre($html, 'id="departamento-danza"', '</summary>');
        $this->assertStringNotContainsString('pendiente', $danza);
    }

    /**
     * Con UN SOLO departamento se abre solo.
     *
     * Es el caso del profesor que dicta en uno: plegar ahí no esconde nada y
     * solo le cobra un clic por entrar a lo único que tiene.
     */
    public function test_con_un_solo_departamento_no_hay_nada_que_plegar(): void
    {
        $this->promotoria('Violin', 'Musica');
        $this->promotoria('Piano', 'Musica');

        $resumen = $this->trozoEntre($this->portada(), '<details class="panel-departamento"', '<summary');

        $this->assertStringContainsString('open', $resumen, 'con un solo departamento la portada llegó cerrada.');
    }

    /** Con dos o más, va cerrado: es lo que se pidió. */
    public function test_con_varios_departamentos_llegan_plegados(): void
    {
        $this->promotoria('Violin', 'Musica');
        $this->promotoria('Ballet', 'Danza');

        // Se mira la ETIQUETA DE APERTURA entera, no una cadena exacta: los
        // atributos van repartidos en dos lineas en el Blade, asi que buscar
        // «... id="..." open» no coincide nunca y la prueba pasaria con el
        // fallo puesto. Comprobado quitando el arreglo: no enrojecia.
        $apertura = $this->trozoEntre($this->portada(), '<details class="panel-departamento"', '>');

        $this->assertStringNotContainsString(
            'open',
            $apertura,
            'con varios departamentos la portada sigue llegando abierta.'
        );
    }

    /**
     * El departamento sigue ABIERTO después de confirmar una matrícula.
     *
     * `acciones.js` solo sabe reabrir los <details> que llevan `id`, y esta
     * pantalla se repinta entera en cada acción. Sin el id, confirmar una
     * solicitud cerraría el departamento y devolvería al principio a quien está
     * resolviendo veinte seguidas — que es exactamente como se usa el Panel al
     * abrir matrículas.
     */
    public function test_el_departamento_lleva_id_para_seguir_abierto_tras_una_accion(): void
    {
        $promotoria = $this->promotoria('Violin', 'Musica');
        $this->promotoria('Ballet', 'Danza');
        $matricula = $this->pendiente($promotoria, 'ana');

        $html = $this->actingAs($this->director->user)
            ->withHeader(Fragmento::CABECERA, '1')
            ->post(route('panel-confirmar-matricula', $matricula))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(
            'id="departamento-musica"',
            $html,
            'el fragmento repintado perdió el id del departamento: se cerrará en cada acción.'
        );
    }

    private function portada(): string
    {
        return $this->actingAs($this->director->user)
            ->get(route('panel'))
            ->assertOk()
            ->getContent();
    }

    private function trozoEntre(string $html, string $desde, string $hasta): string
    {
        $i = strpos($html, $desde);
        $this->assertNotFalse($i, "la sonda no vale: no aparece «{$desde}».");

        $j = strpos($html, $hasta, $i);
        $this->assertNotFalse($j, "la sonda no vale: no aparece «{$hasta}».");

        return substr($html, $i, $j - $i);
    }

    private function promotoria(string $nombre, string $departamento): Promotoria
    {
        $area = Area::firstOrCreate(['nombre' => $departamento]);

        return Promotoria::create([
            'nombre' => $nombre,
            'area_id' => $area->id,
            'profesor_id' => $this->perfil('p'.strtolower($nombre), 'profesor')->id,
        ]);
    }

    private function pendiente(Promotoria $promotoria, string $quien): Matricula
    {
        $estudiante = $this->perfil($quien, 'estudiante');

        DatosEstudiante::create([
            'perfil_id' => $estudiante->id,
            'documento_identidad' => '1'.$estudiante->id,
        ]);

        $matricula = new Matricula([
            'estudiante_id' => $estudiante->id,
            'promotoria_id' => $promotoria->id,
            'periodo_id' => $this->periodo->id,
            'estado' => Matricula::PENDIENTE,
        ]);
        $matricula->save();

        return $matricula;
    }

    private function perfil(string $username, string $rol): Perfil
    {
        $user = User::create(['username' => $username, 'password' => 'x', 'activo' => true]);

        return Perfil::create([
            'user_id' => $user->id,
            'rol' => $rol,
            'nombre_completo' => ucfirst($username),
            'fecha_nacimiento' => Carbon::today()->subYears(25)->toDateString(),
            'telefono' => '3000000000',
        ]);
    }
}
