<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\DatosEstudiante;
use App\Models\Matricula;
use App\Models\Perfil;
use App\Models\Periodo;
use App\Models\Promotoria;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Que a quien pidio entrar se le pueda distinguir un «no» de un «me fui».
 *
 * EL PROBLEMA, del 04/09/2026: nada le avisa a nadie de nada. No hay correo ni
 * notificacion —no existen `app/Mail` ni `app/Notifications`, no hay una sola
 * llamada a `Mail::` en toda la aplicacion—, asi que la unica forma de saber
 * como acabo una solicitud es entrar a mirarla.
 *
 * Y al entrar a mirarla no se sabia. 'retirada' es el desenlace de cuatro cosas
 * distintas —te dicen que no, te echas atras, te tramitan la cancelacion, te
 * retiran por no aparecer— y las cuatro se leian «Retirada». El propio codigo
 * lo tenia anotado en `PanelController::rechazarLote()`.
 *
 * Peor: el catalogo excluia las retiradas, asi que la promotoria le volvia a
 * salir con el boton «Matricularme» en la PRIMERA pantalla que ve al entrar.
 * Reintentar a ciegas no era un descuido del usuario, era el camino que le
 * ofrecia la aplicacion.
 *
 * LO QUE ESTO NO ARREGLA, y conviene no confundirlo: sigue sin haber aviso
 * saliente. Quien no vuelva a entrar sigue sin enterarse de nada.
 */
class RechazoQueSeDistingueTest extends TestCase
{
    use RefreshDatabase;

    private Perfil $profesor;

    private Perfil $estudiante;

    private Promotoria $promotoria;

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

        $this->profesor = $this->perfil('profe', 'profesor');
        $this->estudiante = $this->perfil('ana', 'estudiante');

        DatosEstudiante::create([
            'perfil_id' => $this->estudiante->id,
            'documento_identidad' => '1'.$this->estudiante->id,
        ]);

        $this->promotoria = Promotoria::create([
            'nombre' => 'Violin',
            'area_id' => Area::create(['nombre' => 'Musica'])->id,
            'profesor_id' => $this->profesor->id,
        ]);
    }

    /** Rechazar deja el motivo puesto, y el estudiante lee «Rechazada». */
    public function test_una_solicitud_rechazada_se_lee_distinto_de_una_retirada(): void
    {
        $matricula = $this->solicitud();

        $this->actingAs($this->profesor->user)
            ->post(route('panel-rechazar-matricula', $matricula))
            ->assertRedirect();

        $matricula->refresh();

        $this->assertSame(Matricula::RETIRADA, $matricula->estado, 'el estado guardado cambio.');
        $this->assertSame(Matricula::RETIRO_RECHAZO, $matricula->motivo_retiro);
        $this->assertSame(Matricula::RECHAZADA, $matricula->estado_visible);
        $this->assertSame('Rechazada', $matricula->estado_visible_display);
    }

    /** Y el rechazo en bloque hace lo mismo: es el camino que mas se usa. */
    public function test_el_rechazo_en_bloque_tambien_deja_el_motivo(): void
    {
        $una = $this->solicitud();
        $otra = $this->solicitud('beto');

        $this->actingAs($this->profesor->user)
            ->post(route('panel-pendientes-lote', $this->promotoria), [
                'decision' => 'rechazar',
                'matricula_ids' => [$una->id, $otra->id],
            ])->assertRedirect();

        foreach ([$una, $otra] as $m) {
            $this->assertSame(Matricula::RETIRO_RECHAZO, $m->fresh()->motivo_retiro);
        }
    }

    /**
     * Echarse atras uno mismo NO se lee como un rechazo.
     *
     * Es la mitad que hace util la distincion. Si las dos salieran iguales,
     * separar los motivos no habria servido de nada.
     */
    public function test_retirar_la_propia_solicitud_sigue_leyendose_retirada(): void
    {
        $matricula = $this->solicitud();

        $this->actingAs($this->estudiante->user)
            ->post(route('mis-matriculas.retirar', $matricula))
            ->assertRedirect();

        $matricula->refresh();

        $this->assertSame(Matricula::RETIRO_PROPIO, $matricula->motivo_retiro);
        $this->assertSame(Matricula::RETIRADA, $matricula->estado_visible);
        $this->assertSame('Retirada', $matricula->estado_visible_display);
    }

    /**
     * Una retirada SIN motivo tambien sigue leyendose «Retirada».
     *
     * Son las filas anteriores al cambio: de una retirada vieja no hay forma de
     * saber por cual de los cuatro caminos llego, y ponerle un motivo inventado
     * seria peor que dejarla muda.
     */
    public function test_una_retirada_vieja_sin_motivo_no_se_convierte_en_rechazo(): void
    {
        $matricula = $this->solicitud();
        $matricula->estado = Matricula::RETIRADA;
        $matricula->motivo_retiro = null;
        $matricula->save();

        $this->assertSame(Matricula::RETIRADA, $matricula->fresh()->estado_visible);
        $this->assertSame('Retirada', $matricula->fresh()->estado_visible_display);
    }

    /**
     * EL CATALOGO NO LE VUELVE A OFRECER EL BOTON.
     *
     * Es la pantalla a la que cae al iniciar sesion, asi que es donde el
     * reintento a ciegas empezaba.
     */
    public function test_el_catalogo_ensena_el_rechazo_en_vez_del_boton(): void
    {
        $matricula = $this->solicitud();
        $this->rechazar($matricula);

        $html = $this->actingAs($this->estudiante->user)
            ->get(route('promotorias-disponibles'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('estado-rechazada', $html, 'no se ve que se lo rechazaron.');
        $this->assertStringNotContainsString(
            route('matricular', $this->promotoria),
            $html,
            'el catalogo le vuelve a ofrecer pedir la misma promotoria que acaban de negarle.'
        );
    }

    /**
     * Y la URL tambien esta cerrada, no solo el boton.
     *
     * Es la norma de esta pantalla, escrita en `CatalogoController`: esconder el
     * control no cierra la URL, y una pagina vieja en el telefono seguiria
     * mandando el POST.
     */
    public function test_no_se_puede_volver_a_pedir_por_la_url(): void
    {
        $matricula = $this->solicitud();
        $this->rechazar($matricula);

        $this->actingAs($this->estudiante->user)
            ->post(route('matricular', $this->promotoria))
            ->assertRedirect();

        $this->assertSame(
            Matricula::RETIRADA,
            $matricula->fresh()->estado,
            'la solicitud rechazada revivio por la URL.'
        );
        $this->assertSame(Matricula::RETIRO_RECHAZO, $matricula->fresh()->motivo_retiro);
    }

    /**
     * UNA RECHAZADA NO LE GASTA UN CUPO DE SUS PROMOTORIAS.
     *
     * El limite de promotorias por periodo cuenta las que tiene en pie. Al dejar
     * de excluir las retiradas de la consulta habria sido facil colar la
     * rechazada en esa cuenta y dejarlo sin poder entrar a ninguna otra.
     */
    public function test_una_rechazada_no_ocupa_una_de_sus_promotorias(): void
    {
        $matricula = $this->solicitud();
        $this->rechazar($matricula);

        $respuesta = $this->actingAs($this->estudiante->user)
            ->get(route('promotorias-disponibles'))
            ->assertOk();

        $this->assertSame(0, $respuesta->viewData('cuposUsados'), 'la rechazada le gasto un cupo.');
    }

    /**
     * Retirarse uno mismo SI deja volver a entrar.
     *
     * La guarda de arriba tiene que morder solo al rechazo. Si cerrara toda
     * retirada, alguien que se echo atras por error se quedaria fuera del
     * periodo, y eso ya funcionaba antes.
     */
    public function test_quien_se_retiro_solo_si_puede_volver_a_entrar(): void
    {
        $matricula = $this->solicitud();

        $this->actingAs($this->estudiante->user)->post(route('mis-matriculas.retirar', $matricula));
        $this->assertSame(Matricula::RETIRADA, $matricula->fresh()->estado, 'la sonda no vale.');

        $this->actingAs($this->estudiante->user)->post(route('matricular', $this->promotoria));

        $matricula->refresh();
        $this->assertSame(Matricula::PENDIENTE, $matricula->estado, 'no pudo volver a entrar.');
        $this->assertNull($matricula->motivo_retiro, 'la solicitud nueva arrastro el motivo de la vieja.');
    }

    /** La solicitud pendiente de siempre. */
    private function solicitud(string $quien = 'ana'): Matricula
    {
        $estudiante = $quien === 'ana' ? $this->estudiante : $this->crearEstudiante($quien);

        $matricula = new Matricula([
            'estudiante_id' => $estudiante->id,
            'promotoria_id' => $this->promotoria->id,
            'periodo_id' => $this->periodo->id,
            'estado' => Matricula::PENDIENTE,
        ]);
        $matricula->save();

        return $matricula;
    }

    private function rechazar(Matricula $matricula): void
    {
        $this->actingAs($this->profesor->user)
            ->post(route('panel-rechazar-matricula', $matricula))
            ->assertRedirect();

        $this->assertSame(
            Matricula::RETIRO_RECHAZO,
            $matricula->fresh()->motivo_retiro,
            'la sonda no vale: el rechazo no dejo el motivo.'
        );
    }

    private function crearEstudiante(string $username): Perfil
    {
        $perfil = $this->perfil($username, 'estudiante');

        DatosEstudiante::create([
            'perfil_id' => $perfil->id,
            'documento_identidad' => '1'.$perfil->id,
        ]);

        return $perfil;
    }

    private function perfil(string $username, string $rol): Perfil
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

    /**
     * DESHACER UN RECHAZO devuelve la solicitud a la cola de quien dicta.
     *
     * Sin esto la pantalla era un callejon: al rechazado no se le vuelve a
     * ofrecer «Matricularme» —ni el boton ni la URL— y «Corregir promotoria» se
     * niega a mover una matricula a donde ya esta, asi que una rechazada en
     * Piano no podia volver a Piano ese periodo por ningun camino.
     *
     * Vuelve a PENDIENTE y no a activa: se deshace la decision, no se toma la
     * contraria.
     */
    public function test_deshacer_el_rechazo_devuelve_la_solicitud_a_pendiente(): void
    {
        $matricula = $this->solicitud();
        $this->rechazar($matricula);

        $this->actingAs($this->profesor->user)
            ->post(route('deshacer-rechazo', $matricula))
            ->assertRedirect();

        $matricula->refresh();

        $this->assertSame(Matricula::PENDIENTE, $matricula->estado);
        $this->assertNull($matricula->motivo_retiro, 'se deshizo pero quedo marcada como rechazo.');
        $this->assertSame('Pendiente de confirmación', $matricula->estado_visible_display);
    }

    /**
     * Lo hace el PROFESOR de esa promotoria, no solo direccion.
     *
     * Es la puerta de `puedeGestionarPromotoria` —la misma que rechaza en el
     * Panel— y no la de «Corregir promotoria» que tiene al lado en la pantalla.
     * Quien pudo rechazar puede deshacerlo: es quien se equivoca al pulsar y
     * quien lo nota primero.
     */
    public function test_el_boton_de_deshacer_lo_ve_el_profesor_de_la_promotoria(): void
    {
        $matricula = $this->solicitud();
        $this->rechazar($matricula);

        $html = $this->actingAs($this->profesor->user)
            ->get(route('historial-estudiante', $this->estudiante))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(
            route('deshacer-rechazo', $matricula),
            $html,
            'el profesor que rechazo no encuentra como deshacerlo.'
        );
    }

    /** Y NO lo puede hacer un profesor de otra promotoria. */
    public function test_un_profesor_ajeno_no_puede_deshacer_el_rechazo(): void
    {
        $matricula = $this->solicitud();
        $this->rechazar($matricula);

        $ajeno = $this->perfil('otro', 'profesor');

        $this->actingAs($ajeno->user)
            ->post(route('deshacer-rechazo', $matricula))
            ->assertRedirect();

        $this->assertSame(
            Matricula::RETIRO_RECHAZO,
            $matricula->fresh()->motivo_retiro,
            'un profesor ajeno deshizo un rechazo que no era suyo.'
        );

        $html = $this->actingAs($ajeno->user)
            ->get(route('historial-estudiante', $this->estudiante))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString(route('deshacer-rechazo', $matricula), $html);
    }

    /**
     * No se deshace lo que no es un rechazo.
     *
     * Una retirada por voluntad propia no es una decision de nadie que haya que
     * poder revertir por aqui; el estudiante vuelve a entrar solo, con el boton
     * de siempre.
     */
    public function test_no_se_puede_deshacer_una_retirada_que_no_fue_un_rechazo(): void
    {
        $matricula = $this->solicitud();

        $this->actingAs($this->estudiante->user)->post(route('mis-matriculas.retirar', $matricula));
        $this->assertSame(Matricula::RETIRO_PROPIO, $matricula->fresh()->motivo_retiro, 'la sonda no vale.');

        $this->actingAs($this->profesor->user)
            ->post(route('deshacer-rechazo', $matricula))
            ->assertRedirect();

        $this->assertSame(Matricula::RETIRADA, $matricula->fresh()->estado, 'revivio una retirada propia.');
    }

    /**
     * El viaje entero: rechazo, arrepentimiento, y todo vuelve a su sitio.
     *
     * Lo que se mira es la pantalla del ESTUDIANTE, que es donde el callejon se
     * notaba: la promotoria deja de estar bloqueada y vuelve a leerse pendiente.
     */
    public function test_tras_deshacerlo_el_estudiante_vuelve_a_verla_pendiente(): void
    {
        $matricula = $this->solicitud();
        $this->rechazar($matricula);

        $this->actingAs($this->profesor->user)->post(route('deshacer-rechazo', $matricula));

        $html = $this->actingAs($this->estudiante->user)
            ->get(route('promotorias-disponibles'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('estado-rechazada', $html, 'sigue marcada como rechazada.');
        $this->assertStringContainsString('estado-pendiente', $html, 'no volvio a leerse pendiente.');
    }
}
