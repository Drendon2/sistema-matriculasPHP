<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Clase;
use App\Models\ConfiguracionInstitucion;
use App\Models\Matricula;
use App\Models\Perfil;
use App\Models\Periodo;
use App\Models\Promotoria;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Las reglas de dominio que el esquema NO puede imponer por si solo.
 *
 * Lo que si impone el esquema (indices, triggers, CHECK) se prueba aparte, en
 * database/verificacion_esquema.php, contra SQL crudo.
 */
class ModelosTest extends TestCase
{
    use RefreshDatabase;

    private Periodo $periodo;

    private Promotoria $violin;

    private Promotoria $danza;

    private Promotoria $teatro;

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
        $this->danza = Promotoria::create(['nombre' => 'Danza', 'area_id' => $area->id]);
        $this->teatro = Promotoria::create(['nombre' => 'Teatro', 'area_id' => $area->id]);
    }

    private function estudiante(string $username = 'ana', string $nacimiento = '2000-05-01'): Perfil
    {
        $user = User::create(['username' => $username, 'password' => 'x', 'activo' => true]);

        return Perfil::create([
            'user_id' => $user->id,
            'rol' => 'estudiante',
            'nombre_completo' => 'Ana Ruiz',
            'fecha_nacimiento' => $nacimiento,
            'telefono' => '3000000000',
        ]);
    }

    private function matricular(Perfil $perfil, Promotoria $promotoria): Matricula
    {
        $matricula = new Matricula([
            'estudiante_id' => $perfil->id,
            'promotoria_id' => $promotoria->id,
            'periodo_id' => $this->periodo->id,
        ]);

        $matricula->validar();
        $matricula->save();

        return $matricula;
    }

    // -----------------------------------------------------------------------
    // Periodo
    // -----------------------------------------------------------------------

    public function test_solo_un_periodo_queda_en_curso(): void
    {
        $siguiente = Periodo::create([
            'nombre' => '2026-2',
            'fecha_inicio' => '2026-07-01',
            'fecha_fin' => '2026-12-15',
            'activo' => false,
            'matriculas_abiertas' => false,
        ]);

        Periodo::ponerEnCurso($siguiente);

        $this->assertSame($siguiente->id, Periodo::enCurso()->id);
        $this->assertSame(1, Periodo::where('activo', true)->count());
    }

    /**
     * Al periodo que sale se le cierran tambien las matriculas: dejarlas
     * abiertas en un periodo que ya no esta en curso solo confundiria a quien
     * lo reactive meses despues.
     */
    public function test_el_periodo_que_sale_cierra_sus_matriculas(): void
    {
        $siguiente = Periodo::create([
            'nombre' => '2026-2',
            'fecha_inicio' => '2026-07-01',
            'fecha_fin' => '2026-12-15',
        ]);

        Periodo::ponerEnCurso($siguiente);

        $this->assertFalse($this->periodo->fresh()->matriculas_abiertas);
    }

    /**
     * "Termino" exige las dos condiciones. Un periodo FUTURO no ha terminado
     * aunque no este activo, y el periodo en curso no termina solo porque el
     * personal se retrase un dia en cerrarlo.
     */
    public function test_un_periodo_futuro_no_cuenta_como_terminado(): void
    {
        $futuro = Periodo::create([
            'nombre' => '2027-1',
            'fecha_inicio' => Carbon::today()->addMonths(3),
            'fecha_fin' => Carbon::today()->addMonths(9),
            'activo' => false,
        ]);

        $this->assertFalse($futuro->termino);
    }

    public function test_un_periodo_pasado_e_inactivo_termino(): void
    {
        $pasado = Periodo::create([
            'nombre' => '2025-2',
            'fecha_inicio' => Carbon::today()->subMonths(9),
            'fecha_fin' => Carbon::today()->subMonths(3),
            'activo' => false,
        ]);

        $this->assertTrue($pasado->termino);
    }

    // -----------------------------------------------------------------------
    // Limite configurable de promotorias
    // -----------------------------------------------------------------------

    public function test_el_limite_por_defecto_son_dos_promotorias(): void
    {
        $ana = $this->estudiante();

        $this->matricular($ana, $this->violin);
        $this->matricular($ana, $this->danza);

        $this->expectException(ValidationException::class);
        $this->matricular($ana, $this->teatro);
    }

    /** El limite sale de la configuracion, no de una constante. */
    public function test_subir_el_limite_permite_una_tercera(): void
    {
        ConfiguracionInstitucion::actual()->update(['limite_promotorias_por_periodo' => 3]);

        $ana = $this->estudiante();
        $this->matricular($ana, $this->violin);
        $this->matricular($ana, $this->danza);
        $tercera = $this->matricular($ana, $this->teatro);

        $this->assertTrue($tercera->exists);
        $this->assertSame(3, Matricula::promotoriasOcupadas($ana->id, $this->periodo->id));
    }

    /** Las ranuras se asignan solas, sin repetirse. */
    public function test_cada_matricula_toma_una_ranura_distinta(): void
    {
        $ana = $this->estudiante();

        $primera = $this->matricular($ana, $this->violin);
        $segunda = $this->matricular($ana, $this->danza);

        $this->assertSame(1, $primera->ranura);
        $this->assertSame(2, $segunda->ranura);
    }

    /** Retirarse libera la ranura y deja sitio para otra promotoria. */
    public function test_retirarse_libera_el_cupo(): void
    {
        $ana = $this->estudiante();
        $primera = $this->matricular($ana, $this->violin);
        $this->matricular($ana, $this->danza);

        $primera->update(['estado' => Matricula::RETIRADA]);

        $tercera = $this->matricular($ana, $this->teatro);
        $this->assertTrue($tercera->exists);
    }

    /**
     * Una cancelacion pedida y sin resolver NO libera nada: mientras nadie la
     * apruebe, el estudiante sigue inscrito.
     */
    public function test_una_cancelacion_en_tramite_sigue_ocupando(): void
    {
        $ana = $this->estudiante();
        $primera = $this->matricular($ana, $this->violin);
        $this->matricular($ana, $this->danza);

        $primera->update(['estado' => Matricula::CANCELACION_SOLICITADA]);

        $this->expectException(ValidationException::class);
        $this->matricular($ana, $this->teatro);
    }

    /**
     * Bajar el limite no puede dejar al personal sin poder tocar las matriculas
     * que YA existen: confirmar no suma ocupacion, asi que no se le aplica el
     * tope.
     */
    public function test_bajar_el_limite_no_bloquea_confirmar_lo_existente(): void
    {
        $ana = $this->estudiante();
        $primera = $this->matricular($ana, $this->violin);
        $this->matricular($ana, $this->danza);

        ConfiguracionInstitucion::actual()->update(['limite_promotorias_por_periodo' => 1]);

        $primera->estado = Matricula::ACTIVA;
        $primera->validar();
        $primera->save();

        $this->assertSame(Matricula::ACTIVA, $primera->fresh()->estado);
    }

    // -----------------------------------------------------------------------
    // Estado visible
    // -----------------------------------------------------------------------

    /**
     * "Finalizada" no se guarda: es lo que se LEE de una matricula activa cuyo
     * periodo quedo atras.
     */
    public function test_la_activa_de_un_periodo_pasado_se_lee_finalizada(): void
    {
        $pasado = Periodo::create([
            'nombre' => '2025-2',
            'fecha_inicio' => Carbon::today()->subMonths(9),
            'fecha_fin' => Carbon::today()->subMonths(3),
            'activo' => false,
        ]);

        $ana = $this->estudiante();
        $matricula = Matricula::create([
            'estudiante_id' => $ana->id,
            'promotoria_id' => $this->violin->id,
            'periodo_id' => $pasado->id,
            'estado' => Matricula::ACTIVA,
        ]);

        $this->assertSame(Matricula::FINALIZADA, $matricula->estado_visible);
        $this->assertSame('Finalizada', $matricula->estado_visible_display);
        // Lo GUARDADO no cambia: la renovacion busca matriculas 'activa'.
        $this->assertSame(Matricula::ACTIVA, $matricula->fresh()->estado);
    }

    /** Una pendiente que nadie confirmo sigue pendiente, no "finalizada". */
    public function test_la_pendiente_de_un_periodo_pasado_sigue_pendiente(): void
    {
        $pasado = Periodo::create([
            'nombre' => '2025-2',
            'fecha_inicio' => Carbon::today()->subMonths(9),
            'fecha_fin' => Carbon::today()->subMonths(3),
            'activo' => false,
        ]);

        $ana = $this->estudiante();
        $matricula = Matricula::create([
            'estudiante_id' => $ana->id,
            'promotoria_id' => $this->violin->id,
            'periodo_id' => $pasado->id,
            'estado' => Matricula::PENDIENTE,
        ]);

        $this->assertSame(Matricula::PENDIENTE, $matricula->estado_visible);
    }

    // -----------------------------------------------------------------------
    // Cancelacion segun la edad
    // -----------------------------------------------------------------------

    public function test_la_cancelacion_de_un_menor_se_puede_rechazar(): void
    {
        $menor = $this->estudiante('nino', Carbon::today()->subYears(12)->toDateString());
        $matricula = $this->matricular($menor, $this->violin);

        $this->assertTrue($matricula->cancelacion_es_rechazable);
    }

    /** Irse es decision del adulto y el sistema no se la discute. */
    public function test_la_cancelacion_de_un_adulto_no_se_puede_rechazar(): void
    {
        $adulto = $this->estudiante('adulta', Carbon::today()->subYears(30)->toDateString());
        $matricula = $this->matricular($adulto, $this->violin);

        $this->assertFalse($matricula->cancelacion_es_rechazable);
    }

    // -----------------------------------------------------------------------
    // Edad
    // -----------------------------------------------------------------------

    public function test_la_edad_cuenta_anos_cumplidos(): void
    {
        $justoHoy = $this->estudiante('cumple', Carbon::today()->subYears(18)->toDateString());
        $manana = $this->estudiante('manana', Carbon::today()->subYears(18)->addDay()->toDateString());

        $this->assertSame(18, $justoHoy->edad);
        $this->assertFalse($justoHoy->es_menor);

        $this->assertSame(17, $manana->edad);
        $this->assertTrue($manana->es_menor);
    }

    // -----------------------------------------------------------------------
    // Clases
    // -----------------------------------------------------------------------

    /**
     * El requisito tiene que ser alcanzable o deja de verificar nada: un grupo
     * de uno o dos no puede reunir tres confirmaciones nunca.
     */
    public function test_las_confirmaciones_requeridas_se_ajustan_al_grupo(): void
    {
        $this->assertSame(0, Clase::confirmacionesPara(0));
        $this->assertSame(1, Clase::confirmacionesPara(1));
        $this->assertSame(1, Clase::confirmacionesPara(2));
        $this->assertSame(3, Clase::confirmacionesPara(3));
        $this->assertSame(3, Clase::confirmacionesPara(25));
    }

    /** Un grupo vacio no significa "ya confirmada": no hay quien la confirme. */
    public function test_un_grupo_vacio_no_da_la_clase_por_confirmada(): void
    {
        $clase = new Clase(['confirmaciones_requeridas' => 0]);

        $this->assertFalse($clase->estaConfirmada(0));
    }

    // -----------------------------------------------------------------------
    // Configuracion
    // -----------------------------------------------------------------------

    /** Fila unica: guardar dos veces escribe sobre la misma. */
    public function test_la_configuracion_es_una_sola_fila(): void
    {
        ConfiguracionInstitucion::actual()->update(['nombre_institucion' => 'Casa A']);
        ConfiguracionInstitucion::actual()->update(['nombre_institucion' => 'Casa B']);

        $this->assertSame(1, ConfiguracionInstitucion::count());
        $this->assertSame('Casa B', ConfiguracionInstitucion::actual()->nombre_institucion);
    }

    public function test_la_configuracion_no_se_puede_borrar(): void
    {
        $this->expectException(\RuntimeException::class);
        ConfiguracionInstitucion::actual()->delete();
    }

    /** Los tonos derivados se calculan, no se guardan. */
    public function test_los_tonos_derivados_salen_del_acento(): void
    {
        $configuracion = ConfiguracionInstitucion::actual();
        $configuracion->update(['color_acento' => '#0a7a59']);

        $this->assertSame('#075941', $configuracion->color_acento_oscuro);
        $this->assertSame('#dbf2eb', $configuracion->color_acento_suave);
        $this->assertGreaterThan(4.5, $configuracion->contraste_texto_boton);
    }
}
