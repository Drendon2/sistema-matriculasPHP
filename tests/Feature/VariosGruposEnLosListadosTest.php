<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\DatosEstudiante;
use App\Models\Grupo;
use App\Models\Matricula;
use App\Models\Perfil;
use App\Models\Periodo;
use App\Models\Promotoria;
use App\Models\User;
use App\Support\FichasIncompletas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Los tres listados del personal que filtran o imprimen por GRUPO, ahora que una
 * matricula puede estar en varios.
 *
 * Van aparte de `VariosGruposPorMatriculaTest`, que ya es largo, y porque estos
 * tres comparten una cosa: ninguno es una pantalla del estudiante. Son el
 * informe que se lleva quien dicta, el filtro de Gestion → Usuarios y la bandeja
 * de fichas por completar.
 *
 * LO QUE CADA UNO DECIDIO, que no es lo mismo en los tres:
 *
 * - El informe OPERATIVO saca una fila por GRUPO. Lo decide para que existe, y
 *   esta escrito en su docblock: «la lista que se lleva quien dicta para pasar
 *   asistencia en papel». Quien va a dos horarios tiene que salir en las dos
 *   listas o el profesor del miercoles va a clase con un papel al que le falta
 *   gente.
 * - El informe de PERSONAS junta los grupos en la celda, porque alli la fila es
 *   una persona y no una clase.
 * - «Sin grupo» pasa a significar «no esta en ninguno», en las dos pantallas que
 *   lo dicen.
 */
class VariosGruposEnLosListadosTest extends TestCase
{
    use RefreshDatabase;

    private Periodo $periodo;

    private Promotoria $violin;

    private Grupo $lunes;

    private Grupo $miercoles;

    private Perfil $director;

    protected function setUp(): void
    {
        parent::setUp();

        $this->periodo = Periodo::create([
            'nombre' => '2026-1',
            'fecha_inicio' => '2026-01-15',
            'fecha_fin' => '2026-06-30',
            'activo' => true,
            'matriculas_abiertas' => true,
        ]);

        $area = Area::create(['nombre' => 'Musica']);
        $this->director = $this->perfil('dire', 'director');
        $this->violin = Promotoria::create(['nombre' => 'Violin', 'area_id' => $area->id]);

        $this->lunes = $this->grupo('Lunes tarde', 'basico');
        $this->miercoles = $this->grupo('Miercoles tarde', 'intermedio');
    }

    /**
     * EL INFORME QUE SE LLEVA QUIEN DICTA TRAE UNA FILA POR GRUPO.
     *
     * Con una fila por matricula, la persona salia en la lista de un horario y
     * en el otro no — y ese papel es con el que se pasa asistencia.
     */
    public function test_el_informe_saca_una_fila_por_grupo(): void
    {
        $ana = $this->matricula('ana');
        $ana->grupos()->attach([$this->lunes->id, $this->miercoles->id]);

        $filas = $this->csv(route('informe-estudiantes', ['promotoria' => $this->violin->id]));

        $suyas = array_values(array_filter($filas, fn ($f) => in_array('Ana', $f, true)));

        $this->assertCount(2, $suyas, 'no sale en las dos listas.');
        $this->assertSame(
            ['Lunes tarde', 'Miercoles tarde'],
            collect($suyas)->pluck(2)->sort()->values()->all(),
            'las dos filas no son sus dos grupos.'
        );
    }

    /** Y quien no tiene grupo sigue saliendo, con «Sin grupo». */
    public function test_el_informe_no_pierde_a_quien_no_tiene_grupo(): void
    {
        $this->matricula('beto');

        $filas = $this->csv(route('informe-estudiantes', ['promotoria' => $this->violin->id]));
        $suya = collect($filas)->first(fn ($f) => in_array('Beto', $f, true));

        $this->assertNotNull($suya, 'desaparecio del informe.');
        $this->assertSame('Sin grupo', $suya[2]);
    }

    /**
     * EL FILTRO POR GRUPO DE GESTION → USUARIOS encuentra por la puente.
     *
     * Se filtra por el SEGUNDO grupo, que es el que la columna no puede
     * expresar: si el filtro siguiera mirandola, esa persona no aparece.
     */
    public function test_el_filtro_por_grupo_encuentra_el_segundo_horario(): void
    {
        $ana = $this->matricula('ana');
        $ana->grupos()->attach([$this->lunes->id, $this->miercoles->id]);

        $this->matricula('beto')->grupos()->attach($this->lunes->id);

        $html = $this->actingAs($this->director->user)
            ->get(route('usuario-lista', ['grupo' => $this->miercoles->id]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Ana', $html, 'no la encuentra por su segundo grupo.');
        $this->assertStringNotContainsString('Beto', $html, 'trae a quien no esta en ese grupo.');
    }

    /**
     * «SIN GRUPO» ES NO ESTAR EN NINGUNO.
     *
     * Antes era «la columna esta vacia», y eso ya no distingue nada: quien va a
     * dos horarios tiene la columna puesta igual que quien va a uno. Si esta
     * bandeja siguiera mirando la columna, no fallaria — diria que no falta
     * nadie por repartir, que es peor.
     */
    public function test_sin_grupo_no_cuenta_a_quien_tiene_dos(): void
    {
        $ana = $this->matricula('ana');
        $ana->grupos()->attach([$this->lunes->id, $this->miercoles->id]);

        $sinRepartir = $this->matricula('beto');

        $conMotivo = collect(FichasIncompletas::todas())
            ->filter(fn (array $ficha) => in_array('grupo', $ficha['motivos'], true))
            ->pluck('id')
            ->all();

        $this->assertContains($sinRepartir->estudiante_id, $conMotivo, 'no ve a quien falta por repartir.');
        $this->assertNotContains($ana->estudiante_id, $conMotivo, 'mete a quien ya tiene dos grupos.');
    }

    // --------------------------------------------------------------------

    /**
     * El CSV que devuelve una ruta de informe, ya partido en filas y columnas.
     *
     * @return list<list<string>>
     */
    private function csv(string $url): array
    {
        $respuesta = $this->actingAs($this->director->user)->get($url)->assertOk();

        ob_start();
        $respuesta->baseResponse->sendContent();
        $texto = (string) ob_get_clean();

        $filas = [];

        foreach (explode("\n", trim($texto)) as $linea) {
            $linea = trim($linea, "\r");

            if ($linea !== '') {
                $filas[] = array_map(fn ($c) => trim($c, '"'), explode(';', $linea));
            }
        }

        return $filas;
    }

    private function grupo(string $nombre, string $nivel): Grupo
    {
        return Grupo::create([
            'promotoria_id' => $this->violin->id,
            'nombre' => $nombre,
            'nivel' => $nivel,
            'salon' => 'A1',
            'cupo_maximo' => 10,
        ]);
    }

    private function matricula(string $nombre): Matricula
    {
        $perfil = $this->perfil($nombre, 'estudiante');

        DatosEstudiante::create([
            'perfil_id' => $perfil->id,
            'documento_identidad' => '10'.$perfil->id,
        ]);

        return Matricula::create([
            'estudiante_id' => $perfil->id,
            'promotoria_id' => $this->violin->id,
            'periodo_id' => $this->periodo->id,
            'fecha' => Carbon::now(),
            'estado' => Matricula::ACTIVA,
        ]);
    }

    private function perfil(string $nombre, string $rol): Perfil
    {
        $user = User::create(['username' => $nombre, 'password' => 'demo1234', 'activo' => true]);

        return Perfil::create([
            'user_id' => $user->id,
            'rol' => $rol,
            'nombre_completo' => ucfirst($nombre),
            'fecha_nacimiento' => Carbon::today()->subYears(20)->toDateString(),
            'telefono' => '3000000000',
        ]);
    }
}
