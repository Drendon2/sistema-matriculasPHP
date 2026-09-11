<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Grupo;
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
 * El cupo de un GRUPO: cuantas sillas hay en el salon.
 *
 * ─── POR QUE EXISTE ESTE ARCHIVO ────────────────────────────────────────────
 *
 * Hasta el 10/09/2026 ese cupo se contaba de DOS MANERAS a la vez. El Panel y
 * `Grupo::cuposDisponibles()` contaban `ESTADOS_INSCRITO` —activas y
 * cancelaciones en tramite—; `Matricula::validar()`, que es la puerta de
 * verdad, llevaba su propia consulta y contaba solo las ACTIVAS.
 *
 * Se probo con una sonda: un grupo de cupo 1, con su unico sitio ocupado por una
 * matricula en cancelacion en tramite, se pintaba «1/1, lleno» y el desplegable
 * de «Asignar a» lo ofrecia como lleno — y asignar a un segundo FUNCIONABA. Dos
 * filas en un grupo de uno, y `cuposDisponibles()` devolviendo -1.
 *
 * Nadie lo habia visto en un mes porque el metodo que seguia la regla buena
 * —`cuposDisponibles()`, con la regla escrita en su propio comentario— NO LO
 * LLAMABA NADIE. La divergencia vivia entre una funcion muerta y una pantalla.
 *
 * ─── LO QUE ESTE ARCHIVO VIGILA ─────────────────────────────────────────────
 *
 * 1. Que la cancelacion en tramite OCUPE. Es la mitad del arreglo, y la que se
 *    pone roja si alguien devuelve el conteo a `ACTIVA`.
 * 2. Que la PENDIENTE con grupo NO ocupe. Es la otra mitad y es un camino
 *    bueno: `ESTADOS_INSCRITO` es la medida exacta, ni mas ni menos. Existe
 *    para que nadie "unifique" esta regla con la del cupo de PROMOTORIA, que
 *    cuenta todo lo no retirado, pendientes incluidas, y a proposito.
 * 3. Que la retirada libere.
 * 4. Que una matricula no se cuente A SI MISMA al re-guardarla.
 * 5. Que la pantalla y la puerta digan lo MISMO, que es de lo que iba todo.
 */
class CupoDeGrupoTest extends TestCase
{
    use RefreshDatabase;

    private Periodo $periodo;

    private Promotoria $violin;

    private Grupo $grupo;

    private Perfil $profesor;

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
        $this->profesor = $this->perfil('profe', 'profesor');

        $this->violin = Promotoria::create([
            'nombre' => 'Violin',
            'area_id' => $area->id,
            'profesor_id' => $this->profesor->id,
        ]);

        // SIN fila en `cupos_promotoria` a proposito: sin tope de promotoria no
        // hay trigger que salte, asi que lo unico que puede rechazar aqui es el
        // cupo del GRUPO, que es lo que se esta probando. Con un tope puesto,
        // una prueba podria pasar en verde por el motivo equivocado.
        $this->grupo = Grupo::create([
            'promotoria_id' => $this->violin->id,
            'nombre' => 'Lunes tarde',
            'nivel' => 'basico',
            'salon' => 'A1',
            'cupo_maximo' => 1,
        ]);
    }

    // --------------------------------------------------------------------
    // La mitad que se arreglo
    // --------------------------------------------------------------------

    /**
     * UNA CANCELACION EN TRAMITE OCUPA SU SILLA.
     *
     * Quien pidio cancelar sigue yendo a clase hasta que direccion lo resuelva
     * —por eso conserva su grupo— asi que el sitio no esta libre. Es el caso
     * exacto que la sonda encontro abierto.
     */
    public function test_una_cancelacion_en_tramite_ocupa_sitio(): void
    {
        $suya = $this->matricula('ana', Matricula::ACTIVA, conGrupo: true);
        $suya->estado = Matricula::CANCELACION_SOLICITADA;
        $suya->save();

        $this->assertSame(
            [$this->grupo->id],
            $suya->grupos()->pluck('grupos.id')->all(),
            'la prueba no vale: la cancelacion perdio el grupo y ya no ocupa nada.'
        );

        $this->esperarRechazo('beto');
    }

    /** Y por el camino de verdad, que es el boton del Panel. */
    public function test_el_panel_no_mete_a_nadie_en_un_grupo_lleno_por_una_cancelacion(): void
    {
        $suya = $this->matricula('ana', Matricula::ACTIVA, conGrupo: true);
        $suya->estado = Matricula::CANCELACION_SOLICITADA;
        $suya->save();

        $otra = $this->matricula('beto', Matricula::ACTIVA, conGrupo: false);

        $this->actingAs($this->profesor->user)
            ->post(route('panel-asignar-grupo', $otra), ['grupo_id' => $this->grupo->id]);

        $this->assertSame(0, $otra->grupos()->count(), 'el Panel lo metio en un grupo lleno.');
        $this->assertSame(1, $this->ocupados(), 'hay dos personas en un grupo de una.');
    }

    /**
     * LA PANTALLA Y LA PUERTA DICEN LO MISMO, que es de lo que iba todo esto.
     *
     * `cuposDisponibles()` en negativo era el sintoma visible: significa que hay
     * mas gente dentro de la que cabe.
     */
    public function test_los_cupos_disponibles_no_se_van_a_negativo(): void
    {
        $suya = $this->matricula('ana', Matricula::ACTIVA, conGrupo: true);
        $suya->estado = Matricula::CANCELACION_SOLICITADA;
        $suya->save();

        $this->assertSame(0, $this->grupo->cuposDisponibles($this->periodo));

        $this->esperarRechazo('beto');

        $this->assertSame(
            0,
            $this->grupo->fresh()->cuposDisponibles($this->periodo),
            'entro alguien de mas: la pantalla dice que caben menos de cero.'
        );
    }

    // --------------------------------------------------------------------
    // La otra mitad: caminos buenos que tienen que seguir verdes
    // --------------------------------------------------------------------

    /**
     * UNA PENDIENTE CON GRUPO NO OCUPA SILLA, y esto NO es un descuido.
     *
     * Mientras nadie confirme la solicitud esa persona no esta en la clase; es
     * la misma razon por la que el Panel las lista aparte de los grupos. El cupo
     * de PROMOTORIA si las cuenta, porque alli la pregunta es otra —un sitio en
     * la lista, no una silla en el salon—.
     *
     * Esta prueba existe para que nadie "unifique" las dos reglas creyendo que
     * la diferencia es un olvido.
     */
    public function test_una_pendiente_con_grupo_no_ocupa_sitio(): void
    {
        $pendiente = $this->matricula('ana', Matricula::PENDIENTE, conGrupo: true);

        $this->assertSame([$this->grupo->id], $pendiente->grupos()->pluck('grupos.id')->all());
        $this->assertSame(1, $this->grupo->cuposDisponibles($this->periodo), 'la pendiente ocupo silla.');

        // Y entra alguien de verdad, porque la silla esta libre.
        $this->matricula('beto', Matricula::ACTIVA, conGrupo: true);
        $this->assertSame(
            [$this->grupo->id],
            Matricula::where('estudiante_id', $this->buscar('beto')->id)->first()->grupos()->pluck('grupos.id')->all()
        );
    }

    /** Una retirada libera la silla: se fue de verdad. */
    public function test_una_retirada_libera_el_sitio(): void
    {
        $suya = $this->matricula('ana', Matricula::ACTIVA, conGrupo: true);
        $suya->estado = Matricula::RETIRADA;
        $suya->save();

        $this->assertSame(1, $this->grupo->cuposDisponibles($this->periodo));

        $otra = $this->matricula('beto', Matricula::ACTIVA, conGrupo: true);
        $this->assertSame(
            [$this->grupo->id],
            $otra->grupos()->pluck('grupos.id')->all(),
            'la retirada no solto el sitio.'
        );
    }

    /**
     * Una matricula no se cuenta A SI MISMA al volver a guardarla.
     *
     * Sin el `excluir`, re-guardar la unica matricula de un grupo de cupo 1
     * fallaria diciendo que no hay sitio — el sitio que ella misma ocupa. Pasa
     * cada vez que el Panel toca una fila que ya estaba en su grupo.
     */
    public function test_una_matricula_no_se_cuenta_a_si_misma(): void
    {
        $suya = $this->matricula('ana', Matricula::ACTIVA, conGrupo: true);

        $suya->validar();
        $suya->save();

        $this->assertSame([$this->grupo->id], $suya->grupos()->pluck('grupos.id')->all());
    }

    // --------------------------------------------------------------------

    private function esperarRechazo(string $nombre): void
    {
        $otra = $this->matricula($nombre, Matricula::ACTIVA, conGrupo: false);

        try {
            $otra->repartirEn([$this->grupo->id]);
            $this->fail('entro en un grupo que ya estaba lleno.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('grupo', $e->errors(), 'lo rechazo, pero por otra cosa.');
        }
    }

    private function ocupados(): int
    {
        return $this->grupo->matriculas()
            ->whereIn('matriculas.estado', Matricula::ESTADOS_INSCRITO)
            ->count();
    }

    private function matricula(string $nombre, string $estado, bool $conGrupo): Matricula
    {
        $matricula = Matricula::create([
            'estudiante_id' => $this->perfil($nombre, 'estudiante')->id,
            'promotoria_id' => $this->violin->id,
            'periodo_id' => $this->periodo->id,
            'fecha' => Carbon::now(),
            'estado' => $estado,
        ]);

        if ($conGrupo) {
            $matricula->repartirEn([$this->grupo->id]);
        }

        return $matricula;
    }

    private function buscar(string $nombre): Perfil
    {
        return Perfil::whereHas('user', fn ($q) => $q->where('username', $nombre))->firstOrFail();
    }

    private function perfil(string $nombre, string $rol): Perfil
    {
        $existente = Perfil::whereHas('user', fn ($q) => $q->where('username', $nombre))->first();

        if ($existente !== null) {
            return $existente;
        }

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
