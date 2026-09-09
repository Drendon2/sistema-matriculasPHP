<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Asistencia;
use App\Models\Clase;
use App\Models\Grupo;
use App\Models\Matricula;
use App\Models\Perfil;
use App\Models\Periodo;
use App\Models\Promotoria;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Si consta que fue a la clase, la clase le sale para confirmar.
 *
 * ─── EL CASO DE PRODUCCION QUE LO DESTAPO ──────────────────────────────────
 *
 * Lo reporto el usuario el 09/09/2026: «una estudiante asistio a clase, el
 * profesor le confirmo la asistencia, pero al estudiante no le aparece para
 * confirmar y esta dentro del rango de las 48 horas».
 *
 * La causa: «Mis clases» se deducia SOLO de la matricula tal como esta HOY —su
 * grupo actual y la fecha en que se creo—, y la asistencia es un hecho ya
 * registrado del pasado. Cuando las dos cosas no coinciden, la lista se fiaba
 * de la matricula. Dos situaciones corrientes lo provocan:
 *
 * 1. LA MOVIERON DE GRUPO despues de la clase. Su matricula apunta al grupo
 *    nuevo, y las clases del anterior desaparecen de su lista.
 * 2. LA MATRICULARON EL MISMO DIA, despues de la hora a la que empezo la clase.
 *    `matriculas.fecha` guarda la hora exacta, asi que una clase de las 9 queda
 *    «antes» de una matricula de las 13. La regla queria decir «no puedes dar fe
 *    de las clases de antes de entrar» y acababa diciendo algo mas estrecho.
 *
 * LO QUE HACE QUE ESTO NO SE NOTE es que las dos mitades NO comparten filtro:
 * `Clase::matriculasAPasar()` —la lista del profesor— no mira ni el grupo
 * historico ni la fecha, asi que el profesor SI la ve y le marca asistencia. El
 * sistema tenia escrito que estuvo y aun asi le escondia la clase.
 *
 * ─── EL ALCANCE, MEDIDO POR SSH ESE DIA ────────────────────────────────────
 *
 * De 578 asistencias de un mes, 34 estaban ocultas a su propio estudiante y 13
 * seguian dentro del plazo de 48 h. Los motivos, 23 y 11 respectivamente. O
 * sea: un 6% de las asistencias, y no un caso raro.
 *
 * ─── LO QUE VIGILA ESTE ARCHIVO ────────────────────────────────────────────
 *
 * Los dos casos de arriba, y —igual de importante— que la puerta NO se haya
 * abierto de mas: sin asistencia registrada, las reglas de siempre siguen
 * valiendo. Si no, esto pasaria de esconder clases a ofrecer clases en las que
 * nadie estuvo, que es peor: la confirmacion dejaria de probar nada.
 */
class ClaseQueSiVioTest extends TestCase
{
    use RefreshDatabase;

    private Periodo $periodo;

    private Promotoria $piano;

    private Grupo $manana;

    private Grupo $tarde;

    private Perfil $profesor;

    private Perfil $ana;

    protected function setUp(): void
    {
        parent::setUp();

        $this->periodo = Periodo::create([
            'nombre' => '2026-2',
            'fecha_inicio' => Carbon::today()->subMonth()->toDateString(),
            'fecha_fin' => Carbon::today()->addMonths(3)->toDateString(),
            'activo' => true,
            'matriculas_abiertas' => true,
        ]);

        $area = Area::create(['nombre' => 'Música']);
        $this->piano = Promotoria::create(['nombre' => 'Piano', 'area_id' => $area->id]);
        $this->manana = $this->grupo('Mañana');
        $this->tarde = $this->grupo('Tarde');

        $this->profesor = $this->perfil('profe', 'profesor');
        $this->ana = $this->perfil('ana', 'estudiante');
    }

    // ------------------------------------------------------------------
    // Los dos casos de producción
    // ------------------------------------------------------------------

    /**
     * La movieron de grupo DESPUÉS de la clase.
     *
     * Su matrícula es la misma fila —cambia de `grupo_id`— así que la asistencia
     * la sigue señalando. Lo que se perdía era la clase.
     */
    public function test_si_la_movieron_de_grupo_sigue_viendo_la_clase_a_la_que_fue(): void
    {
        $matricula = $this->inscribir($this->manana);
        $clase = $this->claseDe($this->manana, hace: 6);

        $this->marcarAsistencia($clase, $matricula);

        // Y al día siguiente dirección la pasa al grupo de la tarde.
        $matricula->update(['grupo_id' => $this->tarde->id]);

        $this->assertVeLaClase($clase);
        $this->assertPuedeConfirmar($clase);
    }

    /**
     * La matricularon el mismo día, después de la hora de la clase.
     *
     * Es lo que pasa cuando alguien llega, entra al salón y lo matriculan
     * después: `matriculas.fecha` guarda la hora exacta.
     */
    public function test_si_la_matricularon_despues_de_la_clase_sigue_viendola(): void
    {
        $clase = $this->claseDe($this->manana, hace: 6);

        $matricula = $this->inscribir($this->manana);
        $matricula->update(['fecha' => Carbon::now()->subHours(2)]);

        $this->marcarAsistencia($clase, $matricula);

        $this->assertVeLaClase($clase);
        $this->assertPuedeConfirmar($clase);
    }

    /**
     * Y el profesor la veía en su lista todo el tiempo, que es lo que hace que
     * esto no se note: las dos mitades no comparten filtro.
     *
     * Sin esta prueba, alguien podría «arreglar» el desajuste por el otro lado
     * —quitándola de la lista del profesor— y eso sería peor: se perdería la
     * asistencia de quien sí fue.
     */
    public function test_la_lista_del_profesor_si_la_incluye(): void
    {
        $matricula = $this->inscribir($this->manana);
        $matricula->update(['fecha' => Carbon::now()->addHour()]);

        $clase = $this->claseDe($this->manana, hace: 6);

        $this->assertContains(
            $matricula->id,
            $clase->matriculasAPasar()->pluck('id')->all(),
            'Si el profesor deja de verla, se pierde la asistencia de quien sí fue.'
        );
    }

    // ------------------------------------------------------------------
    // Y la puerta no se abrió de más
    // ------------------------------------------------------------------

    /**
     * SIN asistencia registrada, una clase anterior a su matrícula sigue fuera.
     *
     * Es la regla de siempre y tiene que seguir en pie: quien acaba de entrar al
     * grupo no estuvo en las clases de antes y no puede dar fe de ellas.
     */
    public function test_sin_asistencia_una_clase_anterior_a_su_matricula_no_sale(): void
    {
        $clase = $this->claseDe($this->manana, hace: 6);

        $matricula = $this->inscribir($this->manana);
        $matricula->update(['fecha' => Carbon::now()->subHours(2)]);

        $this->assertNoVeLaClase($clase);
    }

    /** Y una clase de un grupo que nunca fue el suyo, tampoco. */
    public function test_una_clase_de_un_grupo_ajeno_no_sale(): void
    {
        $this->inscribir($this->manana);
        $ajena = $this->claseDe($this->tarde, hace: 6);

        $this->assertNoVeLaClase($ajena);

        $this->actingAs($this->ana->user)
            ->post(route('confirmar-clase', $ajena))
            ->assertSessionHas('error');

        $this->assertSame(0, $ajena->confirmaciones()->count());
    }

    /**
     * Ni siquiera con asistencia registrada, si la matrícula ya no cuenta.
     *
     * A quien retiraron no se le pide que dé fe de nada: su matrícula salió de
     * `ESTADOS_INSCRITO` y esta lista es de estudiantes inscritos. La asistencia
     * levanta el filtro del grupo y el de la fecha, que son deducciones; no
     * levanta el del estado, que es una decisión tomada sobre esa persona.
     */
    public function test_a_quien_retiraron_no_le_sale_aunque_hubiera_asistido(): void
    {
        $matricula = $this->inscribir($this->manana);
        $clase = $this->claseDe($this->manana, hace: 6);

        $this->marcarAsistencia($clase, $matricula);

        $matricula->update(['estado' => Matricula::RETIRADA]);

        $this->assertNoVeLaClase($clase);
    }

    // ------------------------------------------------------------------
    // Andamiaje
    // ------------------------------------------------------------------

    private function assertVeLaClase(Clase $clase): void
    {
        $this->assertContains(
            $clase->id,
            $this->clasesQueVe(),
            'La clase no le sale, y el sistema tiene registrado que estuvo en ella.'
        );
    }

    private function assertNoVeLaClase(Clase $clase): void
    {
        $this->assertNotContains($clase->id, $this->clasesQueVe());
    }

    /** @return array<int, int> */
    private function clasesQueVe(): array
    {
        return array_map(
            fn (array $f) => $f['clase']->id,
            Clase::porConfirmar($this->ana->fresh(), $this->periodo)
        );
    }

    /**
     * Y que la pueda confirmar de verdad, no solo verla.
     *
     * Las dos mitades hacen falta: el controlador vuelve a resolver la fila por
     * su cuenta antes de escribir, así que ver la clase y poder confirmarla son
     * dos afirmaciones distintas.
     */
    private function assertPuedeConfirmar(Clase $clase): void
    {
        $this->actingAs($this->ana->user)
            ->post(route('confirmar-clase', $clase))
            ->assertSessionHas('success');

        $this->assertSame(1, $clase->confirmaciones()->count());
    }

    private function marcarAsistencia(Clase $clase, Matricula $matricula): void
    {
        Asistencia::create([
            'clase_id' => $clase->id,
            'matricula_id' => $matricula->id,
            'estado' => Asistencia::ASISTIO,
        ]);
    }

    private function claseDe(Grupo $grupo, int $hace): Clase
    {
        return Clase::create([
            'grupo_id' => $grupo->id,
            'periodo_id' => $this->periodo->id,
            'fecha_hora' => Carbon::now()->subHours($hace),
            'registrada_por_id' => $this->profesor->id,
            'confirmaciones_requeridas' => 1,
        ]);
    }

    private function inscribir(Grupo $grupo): Matricula
    {
        $matricula = new Matricula([
            'estudiante_id' => $this->ana->id,
            'promotoria_id' => $this->piano->id,
            'periodo_id' => $this->periodo->id,
            'estado' => Matricula::ACTIVA,
        ]);
        $matricula->save();

        $matricula->grupo_id = $grupo->id;
        $matricula->save();

        return $matricula;
    }

    private function grupo(string $nombre): Grupo
    {
        return Grupo::create([
            'promotoria_id' => $this->piano->id,
            'nombre' => $nombre,
            'nivel' => 'basico',
            'salon' => 'A1',
            'cupo_maximo' => 20,
        ]);
    }

    private function perfil(string $username, string $rol): Perfil
    {
        $user = User::create(['username' => $username, 'password' => 'x', 'activo' => true]);

        return Perfil::create([
            'user_id' => $user->id,
            'nombre_completo' => ucfirst($username).' Pérez',
            'rol' => $rol,
            'telefono' => '3001112233',
            'fecha_nacimiento' => Carbon::today()->subYears(20),
        ]);
    }
}
