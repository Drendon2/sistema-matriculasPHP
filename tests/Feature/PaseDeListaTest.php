<?php

namespace Tests\Feature;

use App\Models\Actividad;
use App\Models\Area;
use App\Models\Asistencia;
use App\Models\AsistenciaActividad;
use App\Models\Clase;
use App\Models\DatosEstudiante;
use App\Models\Grupo;
use App\Models\InscritoActividad;
use App\Models\Matricula;
use App\Models\Perfil;
use App\Models\Periodo;
use App\Models\Promotoria;
use App\Models\User;
use App\Support\PaseDeLista;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * La regla de pasar lista es UNA, y vale igual en las dos verticales.
 *
 * Paso 1 de B-01. El sistema tiene dos maneras completas y paralelas de
 * responder a «quien vino»: `clases`/`asistencias` para promotorias y
 * `sesiones_actividad`/`asistencias_actividad` para actividades. El esquema
 * sigue duplicado a proposito —unificarlo es cirugia sobre produccion y no esta
 * decidido—, pero el bucle que guarda ya no: es `App\Support\PaseDeLista`.
 *
 * ESTA CLASE ES EL PUNTO DE LA REFACTORIZACION, mas que el codigo extraido.
 * Las dos mitades ya tenian pruebas, pero cada una las suyas, que es
 * exactamente la asimetria que el informe denuncia: `sin_marcar_no_guarda_fila`
 * existia solo del lado de actividades y `la_asistencia_se_puede_corregir` solo
 * del de promotorias. Aqui cada regla se escribe UNA vez y el bucle la aplica a
 * las dos, asi que el dia que alguien vuelva a meter mano en una sola, la
 * prueba de la otra se pone roja sin que nadie tenga que acordarse.
 */
class PaseDeListaTest extends TestCase
{
    use RefreshDatabase;

    private Perfil $profesor;

    private Periodo $periodo;

    /** @var array<int, array<string, mixed>> las dos hojas, ya montadas */
    private array $hojas;

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

        $this->profesor = $this->crearPerfil('profe', 'profesor');

        $this->hojas = [$this->hojaDePromotoria(), $this->hojaDeActividad()];
    }

    /**
     * Un estado inventado se descarta EN SILENCIO, sin reventar.
     *
     * La peticion llega igual si alguien la envia a mano, asi que esconder los
     * controles no basta. Lo que entra sin ser un estado conocido se trata como
     * si no lo hubieran marcado.
     *
     * El `assertRedirect()` no es adorno y sin el la prueba era falsa: las dos
     * tablas tienen un CHECK de estado, asi que sin la guarda del codigo la
     * fila tampoco se guarda —la rechaza el motor— y la peticion muere con un
     * 500 que esta prueba daba por bueno. Comprobado con una sonda: quitando la
     * guarda seguia verde. Lo que distingue «descartado» de «reventado» es que
     * la respuesta siga siendo una redireccion normal.
     */
    public function test_un_estado_inventado_se_descarta_en_silencio_en_las_dos(): void
    {
        foreach ($this->hojas as $hoja) {
            $this->actingAs($this->profesor->user)
                ->post($hoja['url'], ['estado_'.$hoja['ids'][0] => 'llego_tarde'])
                ->assertRedirect();

            $this->assertSame(0, ($hoja['filas'])(), "{$hoja['nombre']}: se guardo un estado que no existe.");
        }
    }

    /**
     * No marcar a alguien es la AUSENCIA de fila, no un cuarto estado.
     *
     * Importa que sea asi: que no haya fila es informacion real —la sesion se
     * dio y a esa persona nadie la paso— y guardarla como un valor la volveria
     * indistinguible de haberla marcado.
     */
    public function test_a_quien_no_se_marca_no_se_le_crea_fila_en_las_dos(): void
    {
        foreach ($this->hojas as $hoja) {
            $this->actingAs($this->profesor->user)->post($hoja['url'], [
                'estado_'.$hoja['ids'][0] => 'asistio',
            ]);

            $this->assertSame(1, ($hoja['filas'])(), "{$hoja['nombre']}: se creo fila de quien nadie marco.");
        }
    }

    /** Volver a pasar lista corrige la marca, no anade una segunda. */
    public function test_pasar_lista_otra_vez_corrige_y_no_duplica_en_las_dos(): void
    {
        foreach ($this->hojas as $hoja) {
            $campo = 'estado_'.$hoja['ids'][0];

            $this->actingAs($this->profesor->user)->post($hoja['url'], [$campo => 'asistio']);
            $this->actingAs($this->profesor->user)->post($hoja['url'], [$campo => 'excusa']);

            $this->assertSame(1, ($hoja['filas'])(), "{$hoja['nombre']}: la correccion duplico la fila.");
            $this->assertSame('excusa', ($hoja['estado'])(), "{$hoja['nombre']}: no se guardo la correccion.");
        }
    }

    /** Se marca a varios de una vez, que es como se usa de verdad. */
    public function test_se_marca_a_toda_la_hoja_de_una_vez_en_las_dos(): void
    {
        foreach ($this->hojas as $hoja) {
            $this->actingAs($this->profesor->user)->post($hoja['url'], [
                'estado_'.$hoja['ids'][0] => 'asistio',
                'estado_'.$hoja['ids'][1] => 'falto',
            ]);

            $this->assertSame(2, ($hoja['filas'])(), "{$hoja['nombre']}: no se guardo la hoja entera.");
        }
    }

    /**
     * El campo que PINTA el formulario es el que LEE el guardado.
     *
     * Es la costura que abre esta refactorizacion, y ninguna prueba de arriba
     * la ve: todas envian el campo a mano. Si el parcial y `PaseDeLista` se
     * separaran, el formulario mandaria un campo que nadie lee y pasar lista
     * dejaria de marcar a nadie --sin error, sin aviso y con la pantalla
     * diciendo «Asistencia guardada»--, que es la peor forma de romperse.
     */
    public function test_el_campo_que_se_pinta_es_el_que_se_lee_en_las_dos(): void
    {
        foreach ($this->hojas as $hoja) {
            $this->actingAs($this->profesor->user)
                ->get($hoja['url'])
                ->assertOk()
                ->assertSee('name="'.PaseDeLista::PREFIJO.$hoja['ids'][0].'"', false);
        }
    }

    // -----------------------------------------------------------------------
    // Las dos hojas, montadas hasta el punto en que solo falta marcar.

    private function hojaDePromotoria(): array
    {
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

        $matriculas = [];
        foreach (['ana', 'beto'] as $nombre) {
            $estudiante = $this->crearEstudiante($nombre);
            $matricula = new Matricula([
                'estudiante_id' => $estudiante->id,
                'promotoria_id' => $promotoria->id,
                'periodo_id' => $this->periodo->id,
                'estado' => Matricula::ACTIVA,
            ]);
            $matricula->save();
            $matricula->grupo_id = $grupo->id;
            $matricula->save();

            $matriculas[] = $matricula->id;
        }

        $clase = Clase::abrir($grupo, $this->periodo, $this->profesor);

        return [
            'nombre' => 'promotorías',
            'url' => route('clase-asistencia', $clase),
            'ids' => $matriculas,
            'filas' => fn () => Asistencia::where('clase_id', $clase->id)->count(),
            'estado' => fn () => Asistencia::where('clase_id', $clase->id)->value('estado'),
        ];
    }

    private function hojaDeActividad(): array
    {
        $taller = Actividad::create([
            'tipo' => Actividad::TALLER,
            'nombre' => 'Taller de cajón',
            'responsable_id' => $this->profesor->id,
            'periodo_id' => $this->periodo->id,
        ]);

        $inscritos = [];
        foreach ([1, 2] as $n) {
            $inscritos[] = $taller->inscritos()->create([
                'nombre_completo' => "Inscrito {$n}",
                'documento' => "100{$n}",
                'origen' => InscritoActividad::ENLACE,
            ])->id;
        }

        $sesion = $taller->sesiones()->create([
            'fecha' => '2026-09-03',
            'iniciada_en' => now(),
            'iniciada_por_id' => $this->profesor->id,
        ]);

        return [
            'nombre' => 'actividades',
            'url' => route('panel-actividad-lista', $sesion),
            'ids' => $inscritos,
            'filas' => fn () => AsistenciaActividad::where('sesion_id', $sesion->id)->count(),
            'estado' => fn () => AsistenciaActividad::where('sesion_id', $sesion->id)->value('estado'),
        ];
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
