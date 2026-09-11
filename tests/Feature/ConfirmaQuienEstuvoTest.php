<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Asistencia;
use App\Models\Clase;
use App\Models\ConfirmacionClase;
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
 * Quien consta que NO estuvo en la clase no puede dar fe de que se dio.
 *
 * ─── DE DONDE SALE ─────────────────────────────────────────────────────────
 *
 * Del recorrido completo del 10/09/2026 en el navegador: una estudiante marcada
 * «Falto» tenia el boton de confirmar activo, dentro del plazo. Quedo anotado
 * ese dia y lo decidio el usuario el 11/09. La regla escrita era «entran las
 * clases de tu grupo», no «las clases a las que fuiste».
 *
 * ─── EL CORTE ES POR ESTADO, NO POR EXISTENCIA DE LA FILA ──────────────────
 *
 * Y esa es LA decision de este archivo, medida en produccion antes de tomarla:
 *
 * - Corte por estado (el que hay): no confirma quien consta «Falto» ni «Falto
 *   con excusa». De 253 confirmaciones, 9 venian de ahi. Solo 2 clases de 61
 *   verificadas se habrian quedado cortas.
 * - Corte por existencia (el que NO se hizo): confirmar solo si consta
 *   «Asistio». Habria dejado 23 clases de 123 sin poder verificarse NUNCA, y 16
 *   de ellas no tienen ni un presente marcado porque nadie paso lista. O sea
 *   que castiga al que no paso lista, no al que falto.
 *
 * Que no haya fila significa que a esa persona no la paso nadie —lo dice el
 * propio modelo `Asistencia`— y eso no afirma que faltara. Por eso
 * `test_quien_no_fue_pasado_si_puede_confirmar` no es un camino bueno de
 * relleno: es el testigo de esa decision, y se pone rojo el dia que alguien
 * estreche el corte «para que sea coherente».
 *
 * ─── Y LO QUE LA BARRERA CUESTA ────────────────────────────────────────────
 *
 * `ConfirmacionClase` tenia escrito lo contrario a proposito: confirma
 * cualquier inscrito del grupo porque atar la verificacion a la asistencia se
 * la pone en manos de quien pasa lista, que es justo a quien vigila. No le
 * sirve para fabricar una clase —cada confirmacion la pulsa su dueno desde su
 * propia sesion— pero si le da un motivo para marcar presente a quien no fue.
 * Se asumio con el dato delante: el dia de la medida, 22 clases de 123 tenian
 * tantos presentes como confirmaciones pedian, o sea sin margen para una falta.
 */
class ConfirmaQuienEstuvoTest extends TestCase
{
    use RefreshDatabase;

    private Periodo $periodo;

    private Promotoria $piano;

    private Grupo $grupo;

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
        $this->grupo = Grupo::create([
            'promotoria_id' => $this->piano->id,
            'nombre' => 'Mañana',
            'nivel' => 'basico',
            'salon' => 'A1',
            'cupo_maximo' => 20,
        ]);

        $this->profesor = $this->perfil('profe', 'profesor');
        $this->ana = $this->perfil('ana', 'estudiante');
    }

    // ------------------------------------------------------------------
    // La puerta que se cierra
    // ------------------------------------------------------------------

    public function test_quien_consta_que_falto_no_puede_confirmar(): void
    {
        [$clase] = $this->claseConAsistencia(Asistencia::FALTO);

        $this->actingAs($this->ana->user)
            ->post(route('confirmar-clase', $clase))
            ->assertSessionHas('error');

        $this->assertSame(0, $clase->confirmaciones()->count());
    }

    /**
     * La excusa tampoco confirma, y no es un descuido.
     *
     * Avisar es lo contrario de desaparecer —por eso una excusa corta la racha
     * de la alerta de abandono— pero tampoco estuvo en el salon, que es lo
     * unico que esta pantalla pregunta.
     */
    public function test_quien_falto_con_excusa_tampoco_puede_confirmar(): void
    {
        [$clase] = $this->claseConAsistencia(Asistencia::EXCUSA);

        $this->actingAs($this->ana->user)
            ->post(route('confirmar-clase', $clase))
            ->assertSessionHas('error');

        $this->assertSame(0, $clase->confirmaciones()->count());
    }

    /**
     * El mensaje dice el motivo de VERDAD.
     *
     * Reusar el del plazo habria sido mas corto y habria mandado a reclamar por
     * donde no es; por eso el corte va antes que la comprobacion del plazo.
     */
    public function test_el_rechazo_explica_que_fue_por_la_falta(): void
    {
        [$clase] = $this->claseConAsistencia(Asistencia::FALTO);

        $respuesta = $this->actingAs($this->ana->user)
            ->post(route('confirmar-clase', $clase));

        $this->assertStringContainsString('no asististe', (string) session('error'));
        $respuesta->assertRedirect(route('mis-clases'));
    }

    // ------------------------------------------------------------------
    // Las puertas que siguen abiertas
    // ------------------------------------------------------------------

    public function test_quien_consta_que_asistio_si_puede_confirmar(): void
    {
        [$clase] = $this->claseConAsistencia(Asistencia::ASISTIO);

        $this->actingAs($this->ana->user)
            ->post(route('confirmar-clase', $clase))
            ->assertSessionHas('success');

        $this->assertSame(1, $clase->confirmaciones()->count());
    }

    /**
     * A QUIEN NO PASO NADIE SI LE SALE, y este es el testigo de la decision.
     *
     * Sin fila de asistencia no hay nadie afirmando que falto: hay una clase a
     * la que no le pasaron lista. Estrechar el corte hasta aqui deja sin poder
     * verificarse las clases donde no se paso lista, que en produccion eran 14.
     */
    public function test_quien_no_fue_pasado_si_puede_confirmar(): void
    {
        // Ya estaba inscrita antes de la clase: sin fila de asistencia manda la
        // regla de siempre, que exige que la clase sea posterior a su matricula.
        $this->inscribir()->update(['fecha' => Carbon::now()->subDay()]);
        $clase = $this->clase();

        $this->actingAs($this->ana->user)
            ->post(route('confirmar-clase', $clase))
            ->assertSessionHas('success');

        $this->assertSame(1, $clase->confirmaciones()->count());
    }

    /**
     * Quien ya habia confirmado puede QUITAR su confirmacion aunque despues le
     * marcaran la falta.
     *
     * Pasa solo: el profesor corrige la lista al dia siguiente. Con el corte
     * puesto tambien en retirar, esa confirmacion quedaria clavada y sin nadie
     * que pudiera deshacerla — justo la que ya no deberia contar.
     */
    public function test_quien_confirmo_antes_de_la_falta_puede_retirar_su_confirmacion(): void
    {
        $matricula = $this->inscribir();
        $matricula->update(['fecha' => Carbon::now()->subDay()]);
        $clase = $this->clase();

        ConfirmacionClase::create(['clase_id' => $clase->id, 'matricula_id' => $matricula->id]);

        Asistencia::create([
            'clase_id' => $clase->id,
            'matricula_id' => $matricula->id,
            'estado' => Asistencia::FALTO,
        ]);

        $this->actingAs($this->ana->user)
            ->post(route('retirar-confirmacion-clase', $clase))
            ->assertSessionHas('success');

        $this->assertSame(0, $clase->confirmaciones()->count());
    }

    // ------------------------------------------------------------------
    // Lo que se ve en la pantalla
    // ------------------------------------------------------------------

    /**
     * La clase NO desaparece: se queda con la accion apagada y el motivo escrito.
     *
     * Esconderla era la otra salida y es el fallo que ya costo un profesor —
     * quien esperaba confirmarla creeria que el sistema se rompio.
     */
    public function test_la_clase_le_sigue_saliendo_y_dice_por_que_no_puede(): void
    {
        $this->claseConAsistencia(Asistencia::FALTO);

        $respuesta = $this->actingAs($this->ana->user)->get(route('mis-clases'));

        $respuesta->assertOk();
        $respuesta->assertSee('Faltaste a esta clase');
        $respuesta->assertDontSee('Sí, esta clase se dio');
    }

    public function test_la_excusa_se_lee_distinto_de_la_falta_a_secas(): void
    {
        $this->claseConAsistencia(Asistencia::EXCUSA);

        $this->actingAs($this->ana->user)
            ->get(route('mis-clases'))
            ->assertSee('Faltaste con excusa');
    }

    /**
     * Y el contador no la cuenta.
     *
     * Es lo que muerde al olvidar una de las dos copias de ese criterio: «Te
     * falta 1 por confirmar» en una pantalla donde no hay nada que pulsar.
     */
    public function test_el_contador_no_cuenta_la_clase_a_la_que_falto(): void
    {
        $this->claseConAsistencia(Asistencia::FALTO);

        $this->assertSame(
            0,
            Clase::esperanConfirmacion(Clase::porConfirmar($this->ana->fresh(), $this->periodo)),
            'La cifra promete algo que hacer, y aquí no hay nada que pulsar.'
        );

        $this->actingAs($this->ana->user)
            ->get(route('mis-clases'))
            ->assertDontSee('por confirmar.');
    }

    /**
     * La misma cifra en la pantalla de entrada, que es la otra copia.
     *
     * VA EN DOS MITADES a proposito. La primera version solo tenia la negativa y
     * buscaba «por confirmar», que es el texto de la OTRA pantalla —aqui dice
     * «sin confirmar»—: pasaba en verde con el contador saboteado, o sea que no
     * comprobaba nada. La mitad positiva es la que impide que vuelva a quedarse
     * vacia sin que nadie se entere.
     */
    public function test_el_catalogo_avisa_de_la_clase_que_si_puede_confirmar(): void
    {
        $this->claseConAsistencia(Asistencia::ASISTIO);

        $this->actingAs($this->ana->user)
            ->get(route('promotorias-disponibles'))
            ->assertSee('sin confirmar');
    }

    public function test_el_catalogo_no_avisa_de_la_clase_a_la_que_falto(): void
    {
        $this->claseConAsistencia(Asistencia::FALTO);

        $this->actingAs($this->ana->user)
            ->get(route('promotorias-disponibles'))
            ->assertDontSee('sin confirmar');
    }

    // ------------------------------------------------------------------
    // Andamiaje
    // ------------------------------------------------------------------

    /** @return array{0: Clase, 1: Matricula} */
    private function claseConAsistencia(string $estado): array
    {
        $matricula = $this->inscribir();
        $clase = $this->clase();

        Asistencia::create([
            'clase_id' => $clase->id,
            'matricula_id' => $matricula->id,
            'estado' => $estado,
        ]);

        return [$clase, $matricula];
    }

    private function clase(): Clase
    {
        return Clase::create([
            'grupo_id' => $this->grupo->id,
            'periodo_id' => $this->periodo->id,
            'fecha_hora' => Carbon::now()->subHours(6),
            'registrada_por_id' => $this->profesor->id,
            'confirmaciones_requeridas' => 1,
        ]);
    }

    private function inscribir(): Matricula
    {
        $matricula = new Matricula([
            'estudiante_id' => $this->ana->id,
            'promotoria_id' => $this->piano->id,
            'periodo_id' => $this->periodo->id,
            'estado' => Matricula::ACTIVA,
        ]);
        $matricula->save();
        $matricula->repartirEn([$this->grupo->id]);

        return $matricula;
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
