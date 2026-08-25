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
use App\Models\SesionActividad;
use App\Models\User;
use App\Support\PaseDeLista;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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

    private Perfil $director;

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
        $this->director = $this->crearPerfil('dire', 'director');

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

    /**
     * Corregir una marca REFRESCA `fecha_registro`. Es la trampa de C-05.
     *
     * Esa columna la ponia el `saving` del modelo --«se refresca en cada
     * guardado: es la marca de la ultima correccion»-- y el guardado masivo
     * (`upsert`) NO dispara eventos de Eloquent. Sin escribirla a mano, una
     * hoja nueva choca contra el NOT NULL y se ve enseguida; pero la
     * CORRECCION no falla: guarda el estado nuevo conservando la fecha de la
     * primera vez. El dato se queda ahi, mintiendo, y nada avisa.
     *
     * Ninguna prueba cubria esta columna antes: el `saving` del modelo llevaba
     * desde el principio sin una sola.
     */
    public function test_la_fecha_de_registro_se_refresca_al_corregir_en_las_dos(): void
    {
        foreach ($this->hojas as $hoja) {
            $campo = 'estado_'.$hoja['ids'][0];

            Carbon::setTestNow('2026-09-03 10:00:00');
            $this->actingAs($this->profesor->user)->post($hoja['url'], [$campo => 'asistio']);

            Carbon::setTestNow('2026-09-03 18:30:00');
            $this->actingAs($this->profesor->user)->post($hoja['url'], [$campo => 'excusa']);

            Carbon::setTestNow();

            $this->assertSame(
                '2026-09-03 18:30:00',
                ($hoja['fechaRegistro'])()->format('Y-m-d H:i:s'),
                "{$hoja['nombre']}: la correccion no refresco la fecha de registro."
            );
        }
    }

    /**
     * Marcar a dos cuesta las mismas consultas que marcar a uno (C-05).
     *
     * Se miden CONSULTAS y no segundos: lo que se arregla es el coste en
     * hosting compartido y que deje de crecer con el tamano del grupo. Antes
     * eran dos por persona --el `updateOrCreate` pregunta y luego escribe--,
     * asi que una hoja de treinta eran sesenta viajes; ahora es una sentencia,
     * marque a uno o a la clase entera.
     *
     * Comparar «uno» contra «dos» basta para distinguir las dos
     * implementaciones y no depende de cuantas consultas cueste el resto de la
     * peticion, que no es lo que esta prueba juzga.
     */
    public function test_marcar_a_dos_cuesta_lo_mismo_que_marcar_a_uno_en_las_dos(): void
    {
        foreach ($this->hojas as $hoja) {
            $conUno = $this->consultasDeMarcar($hoja, [$hoja['ids'][0] => 'asistio']);

            ($hoja['borrarFilas'])();

            $conDos = $this->consultasDeMarcar($hoja, [
                $hoja['ids'][0] => 'asistio',
                $hoja['ids'][1] => 'falto',
            ]);

            $this->assertSame($conUno, $conDos, "{$hoja['nombre']}: el coste crece con la hoja.");
        }
    }

    /**
     * Cuantas consultas contra la tabla de asistencia cuesta marcar.
     *
     * Se filtra por la tabla y no se cuentan todas: el resto de la peticion
     * --sesion, perfil, permisos-- es ruido que no cambia entre los dos casos y
     * solo haria la cifra fragil.
     *
     * @param  array<int, string>  $marcas
     */
    private function consultasDeMarcar(array $hoja, array $marcas): int
    {
        $campos = [];

        foreach ($marcas as $id => $estado) {
            $campos['estado_'.$id] = $estado;
        }

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->actingAs($this->profesor->user)->post($hoja['url'], $campos);

        $consultas = collect(DB::getQueryLog())
            ->filter(fn (array $q) => str_contains($q['query'], $hoja['tabla']))
            ->count();

        DB::disableQueryLog();

        return $consultas;
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

    /**
     * El chip de solo lectura va coloreado IGUAL en las dos.
     *
     * Quien no puede marcar ve lo ya marcado como un chip, y ese chip toma su
     * color del estado (`ResumenAsistencia::MARCA`: verde, rojo y ambar, los
     * mismos que los estados de matricula). Antes lo calculaba el controlador
     * de promotorias y se lo pasaba a la plantilla, y el de actividades no se
     * lo pasaba: el mismo estado salia coloreado en una pantalla y gris en la
     * otra. Ahora lo deriva el parcial, asi que no hay nada que acordarse de
     * pasar.
     */
    public function test_el_chip_de_solo_lectura_va_coloreado_en_las_dos(): void
    {
        foreach ($this->hojas as $hoja) {
            $this->actingAs($this->profesor->user)
                ->post($hoja['url'], ['estado_'.$hoja['ids'][0] => 'asistio']);

            $this->actingAs($this->director->user)
                ->get($hoja['url'])
                ->assertOk()
                ->assertSee('class="estado estado-activa"', false);
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
            'tabla' => 'asistencias',
            'fechaRegistro' => fn () => Asistencia::where('clase_id', $clase->id)->first()->fecha_registro,
            'borrarFilas' => fn () => Asistencia::where('clase_id', $clase->id)->delete(),
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
            /** @var InscritoActividad $inscrito */
            $inscrito = $taller->inscritos()->create([
                'nombre_completo' => "Inscrito {$n}",
                'documento' => "100{$n}",
                'origen' => InscritoActividad::ENLACE,
            ]);

            $inscritos[] = $inscrito->id;
        }

        // Los @var son para PHPStan: `sesiones()` e `inscritos()` no declaran
        // que devuelven, asi que la relacion sale como Model a secas y cada
        // `->id` era una entrada en la linea base. Anotarlos aqui la deja a
        // cero para este archivo, que es como el propio phpstan.neon dice que
        // se vacia.
        /** @var SesionActividad $sesion */
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
            'tabla' => 'asistencias_actividad',
            'fechaRegistro' => fn () => AsistenciaActividad::where('sesion_id', $sesion->id)->first()->fecha_registro,
            'borrarFilas' => fn () => AsistenciaActividad::where('sesion_id', $sesion->id)->delete(),
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
