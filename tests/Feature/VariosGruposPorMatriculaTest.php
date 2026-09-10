<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Clase;
use App\Models\Grupo;
use App\Models\Matricula;
use App\Models\Perfil;
use App\Models\Periodo;
use App\Models\Promotoria;
use App\Models\User;
use App\Support\Dependencias;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Que una matricula pueda estar en VARIOS grupos.
 *
 * El caso que se viene a resolver es corriente: alguien que va al Grupo A el
 * lunes Y al Grupo B el miercoles, de la misma promotoria. Son dos clases
 * distintas y se le pasa lista en las dos. Con `matriculas.grupo_id` —una sola
 * columna— no se puede ni escribir.
 *
 * ─── VA EN CUATRO PASOS, Y ESTE ARCHIVO CRECE CON ELLOS ─────────────────────
 *
 * 1. La tabla `asignaciones_grupo` y el volcado de lo que ya habia.
 * 2. La ESCRITURA DOBLE: lo que se guarda en la columna se copia a la tabla.
 *    Sin ella, el primer lector que se mueva se queda leyendo una copia que ya
 *    nadie actualiza — y no lo ve nadie, porque la pantalla sigue pintando
 *    algo, solo que lo de antes.
 * 3. Los LECTORES, uno a uno y con la suite verde en medio.
 * 4. Borrar la columna y con ella la escritura doble.
 *
 * Mientras 4 no llegue, LA COLUMNA MANDA y la tabla la sigue. Las pruebas de
 * cada paso van agrupadas y rotuladas mas abajo.
 */
class VariosGruposPorMatriculaTest extends TestCase
{
    use RefreshDatabase;

    private Periodo $periodo;

    private Promotoria $violin;

    private Grupo $lunes;

    private Grupo $miercoles;

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
        $this->violin = Promotoria::create(['nombre' => 'Violin', 'area_id' => $area->id]);

        $this->lunes = $this->grupo('Lunes tarde');
        $this->miercoles = $this->grupo('Miercoles tarde');
    }

    /**
     * LO QUE ESTE PASO VIENE A HACER POSIBLE: dos grupos, una matricula.
     *
     * Con la columna sola esto no se puede ni escribir. Es la prueba que da
     * sentido a la tabla; el comportamiento de las pantallas viene despues.
     */
    public function test_una_matricula_puede_estar_en_dos_grupos_de_la_misma_promotoria(): void
    {
        $matricula = $this->matricula('ana');

        $matricula->grupos()->attach([$this->lunes->id, $this->miercoles->id]);

        $suyos = $matricula->fresh()->grupos->pluck('id')->sort()->values()->all();

        $this->assertSame(
            collect([$this->lunes->id, $this->miercoles->id])->sort()->values()->all(),
            $suyos
        );
    }

    /**
     * La misma persona no entra dos veces en el mismo grupo.
     *
     * Sin el indice unico, pulsar «Asignar» dos veces la contaria dos veces
     * contra el cupo del grupo — y el cupo es lo unico que vigila el aforo del
     * salon.
     */
    public function test_no_se_puede_entrar_dos_veces_en_el_mismo_grupo(): void
    {
        $matricula = $this->matricula('ana');
        $matricula->grupos()->attach($this->lunes->id);

        $this->expectException(QueryException::class);

        DB::table('asignaciones_grupo')->insert([
            'matricula_id' => $matricula->id,
            'grupo_id' => $this->lunes->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Borrar la matricula se lleva sus asignaciones (CASCADE).
     *
     * No tienen vida propia. Sin el CASCADE quedarian filas apuntando a una
     * matricula que ya no existe, y esas cuentan contra el cupo de un grupo
     * sin que nadie pueda verlas en ninguna pantalla.
     */
    public function test_borrar_la_matricula_se_lleva_sus_asignaciones(): void
    {
        $matricula = $this->matricula('ana');
        $matricula->grupos()->attach([$this->lunes->id, $this->miercoles->id]);

        $matricula->delete();

        $this->assertSame(0, DB::table('asignaciones_grupo')->count());
    }

    /**
     * UN GRUPO CON GENTE DENTRO NO SE BORRA (RESTRICT).
     *
     * Es lo mismo que ya hacia la FK de la columna. Si esto cayera a CASCADE,
     * borrar un grupo dejaria a sus estudiantes repartidos en la nada sin que
     * nada fallara — que es exactamente el peligro que `Dependencias::MAPA`
     * documenta para los perfiles.
     */
    public function test_un_grupo_con_gente_dentro_no_se_borra(): void
    {
        $matricula = $this->matricula('ana');
        $matricula->grupos()->attach($this->lunes->id);

        $this->expectException(QueryException::class);

        $this->lunes->delete();
    }

    // --------------------------------------------------------------------
    // La escritura doble (paso 2)
    // --------------------------------------------------------------------

    /*
     * Mientras la columna y la tabla convivan, LA COLUMNA MANDA y la tabla la
     * sigue. Sin esto, el primer lector que se mueva a la tabla se queda
     * leyendo una copia que ya nadie actualiza — y no lo ve nadie, porque la
     * pantalla sigue pintando algo, solo que lo de antes.
     */

    /** Nacer con grupo deja ya su fila en la puente. */
    public function test_crear_con_grupo_escribe_en_la_puente(): void
    {
        $matricula = $this->matricula('ana');
        $matricula->grupo_id = $this->lunes->id;
        $matricula->save();

        $nueva = Matricula::create([
            'estudiante_id' => $matricula->estudiante_id,
            'promotoria_id' => $this->otraPromotoria()->id,
            'periodo_id' => $this->periodo->id,
            'grupo_id' => $this->grupoDeLaOtra()->id,
            'fecha' => Carbon::now(),
            'estado' => Matricula::ACTIVA,
        ]);

        $this->assertSame([$this->grupoDeLaOtra()->id], $nueva->grupos()->pluck('grupos.id')->all());
    }

    /** Asignar grupo por la columna escribe en la puente. */
    public function test_asignar_grupo_escribe_en_la_puente(): void
    {
        $matricula = $this->matricula('ana');

        $matricula->grupo_id = $this->lunes->id;
        $matricula->save();

        $this->assertSame([$this->lunes->id], $matricula->grupos()->pluck('grupos.id')->all());
    }

    /** Cambiar de grupo MUEVE la fila: no deja la vieja detrás. */
    public function test_cambiar_de_grupo_suelta_el_anterior(): void
    {
        $matricula = $this->matricula('ana');
        $matricula->grupo_id = $this->lunes->id;
        $matricula->save();

        $matricula->grupo_id = $this->miercoles->id;
        $matricula->save();

        $this->assertSame([$this->miercoles->id], $matricula->grupos()->pluck('grupos.id')->all());
    }

    /** Y quitarle el grupo la borra: es lo que hacen retirar y cancelar. */
    public function test_quitar_el_grupo_borra_la_fila(): void
    {
        $matricula = $this->matricula('ana');
        $matricula->grupo_id = $this->lunes->id;
        $matricula->save();

        $matricula->grupo_id = null;
        $matricula->save();

        $this->assertSame(0, $matricula->grupos()->count());
    }

    /**
     * CAMBIAR EL GRUPO DE LA COLUMNA NO SE LLEVA LOS DEMAS.
     *
     * Es la prueba que sostiene todo el paso 2. Lo obvio en el gancho seria un
     * `sync([$this->grupo_id])`, y eso borraria exactamente aquello para lo que
     * se esta haciendo todo esto: a quien esta en dos grupos, moverle el de la
     * columna le dejaria SOLO ese. Sin fallar y sin avisar, porque la columna
     * solo sabe de uno.
     *
     * OJO CON COMO SE ESCRIBE, que la primera version no probaba nada: hay que
     * CAMBIAR `grupo_id`, no guardar cualquier otra cosa. Un guardado que no
     * toca el grupo sale por el `wasChanged` de arriba y no llega nunca al
     * `sync`, asi que la prueba pasaba en verde con el fallo puesto —
     * comprobado. El caso que importa es el reparto: mover a alguien de
     * horario.
     */
    public function test_cambiar_el_grupo_de_la_columna_no_borra_los_demas(): void
    {
        $viernes = $this->grupo('Viernes tarde');

        $matricula = $this->matricula('ana');
        $matricula->grupo_id = $this->lunes->id;
        $matricula->save();

        // El segundo grupo solo existe en la puente: la columna no puede con el.
        $matricula->grupos()->syncWithoutDetaching($this->miercoles->id);

        // La mueven del lunes al viernes. El miercoles no se toca.
        $matricula->grupo_id = $viernes->id;
        $matricula->save();

        $this->assertSame(
            [$this->miercoles->id, $viernes->id],
            $matricula->grupos()->pluck('grupos.id')->sort()->values()->all(),
            'mover el grupo de la columna se llevo por delante el otro.'
        );
    }

    /** Y guardar sin tocar el grupo tampoco, que es el otro camino. */
    public function test_guardar_sin_tocar_el_grupo_no_borra_los_demas(): void
    {
        $matricula = $this->matricula('ana');
        $matricula->grupo_id = $this->lunes->id;
        $matricula->save();

        $matricula->grupos()->syncWithoutDetaching($this->miercoles->id);

        $matricula->estado = Matricula::CANCELACION_SOLICITADA;
        $matricula->save();

        $this->assertSame(2, $matricula->grupos()->count());
    }

    /** Un guardado que no toca el grupo no reescribe nada. */
    public function test_guardar_sin_tocar_el_grupo_deja_la_puente_igual(): void
    {
        $matricula = $this->matricula('ana');
        $matricula->grupo_id = $this->lunes->id;
        $matricula->save();

        $antes = DB::table('asignaciones_grupo')->where('matricula_id', $matricula->id)->first();

        $matricula->estado = Matricula::CANCELACION_SOLICITADA;
        $matricula->save();

        $despues = DB::table('asignaciones_grupo')->where('matricula_id', $matricula->id)->first();

        $this->assertSame($antes->id, $despues->id, 'reescribio la fila sin necesidad.');
    }

    // --------------------------------------------------------------------
    // Los lectores (paso 3)
    // --------------------------------------------------------------------

    /**
     * EL CUPO DEL GRUPO YA CUENTA POR LA PUENTE, no por la columna.
     *
     * Es la prueba que demuestra que `Grupo::matriculas()` cambio de fuente: con
     * la columna sola esto no se puede ni montar, porque una matricula solo
     * cabe en un grupo. Quien va al lunes Y al miercoles ocupa una silla en cada
     * uno, que es lo que pasa fisicamente.
     */
    public function test_quien_esta_en_dos_grupos_ocupa_una_silla_en_cada_uno(): void
    {
        $matricula = $this->matricula('ana');
        $matricula->grupos()->attach([$this->lunes->id, $this->miercoles->id]);

        $this->assertSame(1, $this->lunes->ocupadosEn($this->periodo));
        $this->assertSame(1, $this->miercoles->ocupadosEn($this->periodo));
    }

    /**
     * Y su segunda silla LLENA el segundo grupo de verdad.
     *
     * Sin esto, lo de arriba podria estar contando bien y el cupo seguir
     * decidiendo por la columna — que es justo el fallo que se viene a evitar.
     */
    public function test_la_segunda_silla_llena_el_grupo(): void
    {
        $lleno = $this->grupo('Sabado');
        $lleno->cupo_maximo = 1;
        $lleno->save();

        $ana = $this->matricula('ana');
        $ana->grupos()->attach([$this->lunes->id, $lleno->id]);

        $this->assertSame(0, $lleno->cuposDisponibles($this->periodo), 'la segunda silla no lo llena.');
    }

    /**
     * A QUIEN VA A DOS HORARIOS SE LE PASA LISTA EN LOS DOS.
     *
     * Es el caso que se pidio, visto desde la pantalla de quien dicta. Con la
     * columna sola, la persona aparecia en la lista de UN grupo y en el otro no
     * — y el profesor del miercoles no tenia forma de marcarle asistencia.
     */
    public function test_a_quien_va_a_dos_horarios_se_le_pasa_lista_en_los_dos(): void
    {
        $matricula = $this->matricula('ana');
        $matricula->grupos()->attach([$this->lunes->id, $this->miercoles->id]);

        $delLunes = Clase::abrir($this->lunes, $this->periodo, null);
        $delMiercoles = Clase::abrir($this->miercoles, $this->periodo, null);

        $this->assertSame(
            [$matricula->id],
            $delLunes->matriculasAPasar()->pluck('id')->all(),
            'no sale en la lista del lunes.'
        );
        $this->assertSame(
            [$matricula->id],
            $delMiercoles->matriculasAPasar()->pluck('id')->all(),
            'no sale en la lista del miercoles.'
        );
    }

    /**
     * Y las clases de LOS DOS grupos le salen a ella para confirmar.
     *
     * La otra mitad de lo mismo. Sin esto, el profesor le marcaria asistencia
     * en el miercoles y a ella no le aparecería la clase — que es exactamente
     * el fallo que ya costo una vez, el 09/09, por otra causa.
     */
    public function test_las_clases_de_los_dos_grupos_le_salen_al_estudiante(): void
    {
        $matricula = $this->matricula('ana');
        $matricula->grupos()->attach([$this->lunes->id, $this->miercoles->id]);

        Clase::abrir($this->lunes, $this->periodo, null);
        Clase::abrir($this->miercoles, $this->periodo, null);

        $suyas = Clase::porConfirmar($matricula->estudiante, $this->periodo);

        $this->assertSame(
            [$this->lunes->id, $this->miercoles->id],
            collect($suyas)->pluck('clase.grupo_id')->sort()->values()->all(),
            'le falta la clase de uno de sus dos horarios.'
        );
    }

    /**
     * El numero de confirmaciones que pide una clase cuenta las dos sillas.
     *
     * `Clase::abrir()` congela cuanta gente habia en el grupo ese dia. Si
     * contara por la columna, el segundo horario abriria sus clases creyendo
     * que no hay nadie dentro.
     */
    public function test_la_clase_del_segundo_grupo_sabe_cuanta_gente_hay(): void
    {
        $matricula = $this->matricula('ana');
        $matricula->grupos()->attach([$this->lunes->id, $this->miercoles->id]);

        $clase = Clase::abrir($this->miercoles, $this->periodo, null);

        $this->assertSame(
            Clase::confirmacionesPara(1),
            $clase->confirmaciones_requeridas,
            'abrio la clase del segundo grupo como si estuviera vacio.'
        );
    }

    /**
     * UN GRUPO CON GENTE SOLO EN LA PUENTE TAMPOCO SE BORRA.
     *
     * `Dependencias::MAPA` cuenta por la relacion `matriculas` del grupo, asi
     * que al cambiarle la fuente cambia tambien lo que ese mapa ve. Sin esta
     * prueba, un grupo con su segundo horario lleno se pintaria como borrable.
     */
    public function test_dependencias_ve_a_quien_solo_esta_en_la_puente(): void
    {
        $matricula = $this->matricula('ana');
        $matricula->grupos()->attach($this->miercoles->id);

        $this->assertTrue(
            Dependencias::estaBloqueado($this->miercoles),
            'el grupo se pinta como borrable con gente dentro.'
        );
    }

    // --------------------------------------------------------------------
    // El volcado
    // --------------------------------------------------------------------

    /**
     * LA MIGRACION COPIA LO QUE YA HABIA, Y SE PUEDE REPETIR.
     *
     * Las dos mitades importan. La primera porque el dia que corra en
     * produccion hay ~1.148 matriculas cuyo grupo no se puede perder. La
     * segunda porque la columna SIGUE siendo la que manda hasta que se borre:
     * entre esta migracion y aquella se van a seguir asignando grupos, asi que
     * el volcado tendra que correr otra vez, y repetirlo no puede duplicar
     * nada.
     *
     * Se prueba llamando a la sentencia real de la migracion y no a una copia
     * escrita aqui: una copia probaria que la copia funciona.
     */
    public function test_el_volcado_copia_lo_que_hay_y_se_puede_repetir(): void
    {
        $this->assertTrue(
            Schema::hasColumn('matriculas', 'grupo_id'),
            'la prueba no vale: la columna ya no existe y no hay nada que volcar.'
        );

        $conGrupo = $this->matricula('ana');
        $conGrupo->grupo_id = $this->lunes->id;
        $conGrupo->save();

        $sinGrupo = $this->matricula('beto');

        // La tabla arranca vacia: estas filas se escribieron DESPUES de migrar.
        DB::table('asignaciones_grupo')->delete();

        $this->volcar();

        $this->assertSame(1, DB::table('asignaciones_grupo')->count(), 'no copio la que tenia grupo.');
        $this->assertDatabaseHas('asignaciones_grupo', [
            'matricula_id' => $conGrupo->id,
            'grupo_id' => $this->lunes->id,
        ]);
        $this->assertDatabaseMissing('asignaciones_grupo', ['matricula_id' => $sinGrupo->id]);

        // Y otra vez, que es lo que hara la migracion que borre la columna.
        $this->volcar();

        $this->assertSame(1, DB::table('asignaciones_grupo')->count(), 'repetirlo duplico la fila.');
    }

    /** El volcado respeta lo que ya se hubiera asignado a mano por la puente. */
    public function test_el_volcado_no_pisa_las_asignaciones_que_ya_estaban(): void
    {
        $matricula = $this->matricula('ana');
        $matricula->grupo_id = $this->lunes->id;
        $matricula->save();

        // Alguien la mando ademas al miercoles: eso solo vive en la puente.
        $matricula->grupos()->syncWithoutDetaching($this->miercoles->id);

        $this->volcar();

        $this->assertSame(
            2,
            $matricula->fresh()->grupos()->count(),
            'el volcado se llevo por delante el grupo que no estaba en la columna.'
        );
    }

    /**
     * La sentencia REAL de la migracion, sacada del archivo.
     *
     * Se lee del disco a proposito: escrita aqui a mano, esta prueba seguiria
     * verde el dia que alguien cambie la de la migracion y la rompa.
     */
    private function volcar(): void
    {
        $archivo = database_path(
            'migrations/2026_09_10_100000_una_matricula_puede_ir_a_varios_grupos.php'
        );

        $migracion = require $archivo;

        $metodo = new \ReflectionMethod($migracion, 'volcarDesdeLaColumna');
        $metodo->invoke($migracion);
    }

    // --------------------------------------------------------------------

    private function otraPromotoria(): Promotoria
    {
        return Promotoria::firstOrCreate(
            ['nombre' => 'Piano'],
            ['area_id' => $this->violin->area_id]
        );
    }

    private function grupoDeLaOtra(): Grupo
    {
        return Grupo::firstOrCreate(
            ['promotoria_id' => $this->otraPromotoria()->id, 'nombre' => 'Jueves'],
            ['nivel' => 'basico', 'salon' => 'B2', 'cupo_maximo' => 10]
        );
    }

    private function grupo(string $nombre): Grupo
    {
        return Grupo::create([
            'promotoria_id' => $this->violin->id,
            'nombre' => $nombre,
            'nivel' => 'basico',
            'salon' => 'A1',
            'cupo_maximo' => 10,
        ]);
    }

    private function matricula(string $nombre): Matricula
    {
        $user = User::create(['username' => $nombre, 'password' => 'demo1234', 'activo' => true]);

        $perfil = Perfil::create([
            'user_id' => $user->id,
            'rol' => 'estudiante',
            'nombre_completo' => ucfirst($nombre),
            'fecha_nacimiento' => Carbon::today()->subYears(20)->toDateString(),
            'telefono' => '3000000000',
        ]);

        return Matricula::create([
            'estudiante_id' => $perfil->id,
            'promotoria_id' => $this->violin->id,
            'periodo_id' => $this->periodo->id,
            'fecha' => Carbon::now(),
            'estado' => Matricula::ACTIVA,
        ]);
    }
}
