<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Grupo;
use App\Models\Matricula;
use App\Models\Perfil;
use App\Models\Periodo;
use App\Models\Promotoria;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * PASO 1 de que una matricula pueda estar en VARIOS grupos.
 *
 * El caso que se viene a resolver es corriente: alguien que va al Grupo A el
 * lunes Y al Grupo B el miercoles, de la misma promotoria. Son dos clases
 * distintas y se le pasa lista en las dos. Con `matriculas.grupo_id` —una sola
 * columna— no se puede representar.
 *
 * ─── QUE PRUEBA ESTE ARCHIVO HOY, Y QUE NO ──────────────────────────────────
 *
 * Solo la ESTRUCTURA y el VOLCADO. Nada de la aplicacion lee todavia la tabla
 * nueva: la columna sigue siendo la verdad, y por eso ninguna pantalla cambia
 * de comportamiento en este paso. Eso es deliberado — borrar la columna aqui
 * tumbaria de golpe los quince archivos que la leen, y con la suite entera en
 * rojo se pierde la unica red que tiene un cambio de este tamano.
 *
 * Lo que viene despues: mover los lectores uno a uno con la suite verde en
 * medio, y borrar la columna al final. Cuando eso pase, este archivo crece con
 * las pruebas de comportamiento — que a alguien en dos grupos se le pase lista
 * en los dos, que ocupe una silla en cada uno y UN solo cupo de promotoria.
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
