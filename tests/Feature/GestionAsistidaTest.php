<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Clase;
use App\Models\ConfirmacionClase;
use App\Models\DatosEstudiante;
use App\Models\Grupo;
use App\Models\Matricula;
use App\Models\Perfil;
use App\Models\Periodo;
use App\Models\Promotoria;
use App\Models\User;
use App\Support\GestionAsistida;
use App\Support\Permisos;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Gestion asistida: el administrador trabaja desde la cuenta de otra persona.
 *
 * Pedido por el usuario el 04/09/2026, con el nombre elegido a proposito — no
 * «suplantacion», porque lo que se hace es ayudar con el trabajo de alguien y
 * no hacerse pasar por el. La auditoria guarda siempre quien fue de verdad.
 *
 * LOS TRES LIMITES, que son lo unico que hay que no romper nunca:
 *
 * 1. NO SE ESCRIBE ASISTENCIA. Es la decision del usuario y va contra la
 *    comodidad a proposito: un registro que puede escribir alguien que no dio
 *    la clase deja de ser evidencia de lo que paso, y la evidencia es lo que la
 *    confirmacion de los estudiantes sostiene. VER y CORREGIR lo que ya hay
 *    sigue disponible; lo que se cierra es escribir.
 * 2. NO SE ASISTE A UN ADMINISTRADOR. No aporta nada y seria una via para que
 *    un administrador actuara como otro dejando el rastro en su nombre.
 * 3. SE PUEDE SALIR SIEMPRE. En cuanto la asistencia empieza, para el
 *    middleware quien navega es el profesor: si la ruta de salida pidiera rol
 *    de administrador, quien entra se quedaria encerrado hasta cerrar sesion.
 *    Esa es la prueba que parece tonta y es la que evita el desastre.
 */
class GestionAsistidaTest extends TestCase
{
    use RefreshDatabase;

    private Perfil $admin;

    private Perfil $profesor;

    private Promotoria $promotoria;

    protected function setUp(): void
    {
        parent::setUp();

        Periodo::create([
            'nombre' => '2026-1',
            'fecha_inicio' => Carbon::today()->subMonth()->toDateString(),
            'fecha_fin' => Carbon::today()->addMonths(3)->toDateString(),
            'activo' => true,
            'matriculas_abiertas' => true,
        ]);

        $this->admin = $this->perfil('jefa', 'administrador');
        $this->profesor = $this->perfil('profe', 'profesor');

        $this->promotoria = Promotoria::create([
            'nombre' => 'Violin',
            'area_id' => Area::create(['nombre' => 'Musica'])->id,
            'profesor_id' => $this->profesor->id,
        ]);
    }

    /** El administrador entra y pasa a navegar como el profesor. */
    public function test_el_administrador_entra_en_la_cuenta_del_profesor(): void
    {
        $this->actingAs($this->admin->user)
            ->post(route('gestion-asistida-iniciar', $this->profesor))
            ->assertRedirect(route('panel'));

        $this->assertSame($this->profesor->user_id, auth()->id(), 'no se cambió de cuenta.');
        $this->assertTrue(GestionAsistida::activa());
        $this->assertSame($this->admin->id, GestionAsistida::administrador()?->id);
    }

    /** Y la barra lo dice en todas las pantallas. */
    public function test_la_barra_avisa_de_que_se_esta_asistiendo(): void
    {
        $this->actingAs($this->admin->user)->post(route('gestion-asistida-iniciar', $this->profesor));

        $html = $this->get(route('panel'))->assertOk()->getContent();

        $this->assertStringContainsString('barra-asistida', $html, 'no se avisa de que se está asistiendo.');
        $this->assertStringContainsString('Gestión asistida', $html);
        $this->assertStringContainsString($this->admin->nombre_completo, $html, 'no dice a nombre de quién queda.');
    }

    /**
     * SE PUEDE SALIR, y esta es la prueba que evita el encierro.
     *
     * Estando asistido el middleware ya no ve a un administrador: ve al
     * profesor. Si la ruta de salida estuviera en el grupo de administrador,
     * quien entra no podría volver salvo cerrando sesión.
     */
    public function test_se_puede_volver_a_la_propia_cuenta(): void
    {
        $this->actingAs($this->admin->user)->post(route('gestion-asistida-iniciar', $this->profesor));
        $this->assertSame($this->profesor->user_id, auth()->id(), 'la sonda no vale: no entró.');

        $this->post(route('gestion-asistida-salir'))->assertRedirect();

        $this->assertSame($this->admin->user_id, auth()->id(), 'no pudo volver a su cuenta.');
        $this->assertFalse(GestionAsistida::activa());
    }

    /**
     * NO SE ESCRIBE ASISTENCIA mientras se asiste.
     *
     * Se comprueba por la puerta que de verdad lo decide y no por una pantalla:
     * `dictaLaPromotoria` es la única que gobierna la escritura de la lista, así
     * que si cede aquí ceden las cuatro pantallas a la vez.
     */
    public function test_asistiendo_no_se_puede_escribir_la_lista(): void
    {
        // Sin asistencia, el profesor sí puede: es la sonda.
        $this->assertTrue(
            Permisos::dictaLaPromotoria($this->profesor, $this->promotoria),
            'la sonda no vale: este profesor no dicta esta promotoría.'
        );

        $this->actingAs($this->admin->user)->post(route('gestion-asistida-iniciar', $this->profesor));

        $this->assertFalse(
            Permisos::dictaLaPromotoria($this->profesor, $this->promotoria),
            'en gestión asistida se puede escribir la lista: el registro deja de ser evidencia.'
        );
    }

    /** Y la pantalla de pasar lista lo respeta, que es lo que se ve. */
    public function test_asistiendo_la_pantalla_no_deja_iniciar_una_clase(): void
    {
        $grupo = Grupo::create([
            'promotoria_id' => $this->promotoria->id,
            'nombre' => 'Grupo 1',
            'nivel' => 'basico',
            'salon' => 'A1',
            'cupo_maximo' => 10,
        ]);

        $this->actingAs($this->admin->user)->post(route('gestion-asistida-iniciar', $this->profesor));

        $this->post(route('panel-clase-nueva', $grupo));

        $this->assertSame(
            0,
            $grupo->clases()->count(),
            'se registró una clase desde una gestión asistida.'
        );
    }

    /** NO se asiste a otro administrador. */
    public function test_no_se_asiste_a_otro_administrador(): void
    {
        $otro = $this->perfil('jefe2', 'administrador');

        $this->actingAs($this->admin->user)
            ->post(route('gestion-asistida-iniciar', $otro))
            ->assertRedirect(route('usuario-lista'));

        $this->assertSame($this->admin->user_id, auth()->id(), 'un administrador entró en otro administrador.');
        $this->assertFalse(GestionAsistida::activa());
    }

    /**
     * A un ESTUDIANTE si se le asiste, desde el 04/09/2026.
     *
     * Pedido por el usuario: la mitad de las llamadas de soporte son de alguien
     * que no logra matricularse, y el camino corto es hacerlo por el.
     */
    public function test_tambien_se_asiste_a_un_estudiante(): void
    {
        $estudiante = $this->estudiante('ana');

        $this->actingAs($this->admin->user)
            ->post(route('gestion-asistida-iniciar', $estudiante))
            ->assertRedirect();

        $this->assertTrue(GestionAsistida::activa());
        $this->assertSame($estudiante->user_id, auth()->id());
    }

    /**
     * PERO NO CONFIRMA UNA CLASE POR EL.
     *
     * Es la otra cara de la garantía de la asistencia: quien registra la clase
     * es parte interesada, y por eso la verifican los estudiantes desde su
     * propia sesión. Confirmando desde una asistida, la clase queda avalada por
     * la misma casa que la registró y la confirmación deja de probar nada.
     */
    public function test_asistiendo_a_un_estudiante_no_se_confirma_su_clase(): void
    {
        $estudiante = $this->estudiante('ana');
        $clase = $this->claseConfirmable($estudiante);

        // SONDA: sin asistencia, el propio estudiante SI la confirma. Sin esto
        // la prueba pasaba por la barrera equivocada —la clase era anterior a
        // su matricula y el controlador la rechazaba por otro motivo—, asi que
        // seguia verde con la guarda quitada. Comprobado.
        $this->actingAs($estudiante->user)->post(route('confirmar-clase', $clase));
        $this->assertSame(
            1,
            ConfirmacionClase::where('clase_id', $clase->id)->count(),
            'la sonda no vale: ni el propio estudiante podia confirmar esta clase.'
        );

        ConfirmacionClase::where('clase_id', $clase->id)->delete();

        $this->actingAs($this->admin->user)->post(route('gestion-asistida-iniciar', $estudiante));
        $this->post(route('confirmar-clase', $clase))->assertRedirect();

        $this->assertSame(
            0,
            ConfirmacionClase::where('clase_id', $clase->id)->count(),
            'se dio fe de una clase desde una gestión asistida: la confirmación deja de probar nada.'
        );
    }

    /**
     * Y la pantalla lo DICE en vez de esconder el botón.
     *
     * Es la regla de este proyecto: una acción que ahora no se puede hacer se
     * queda a la vista y apagada, con su `title` diciendo por qué. Si
     * desapareciera, quien está asistiendo creería que esa pantalla no tiene
     * nada pendiente.
     */
    public function test_la_pantalla_de_clases_deja_la_accion_a_la_vista_y_apagada(): void
    {
        $estudiante = $this->estudiante('ana');
        $this->claseConfirmable($estudiante);

        // Sin asistencia el botón está vivo: es la sonda.
        $suyo = $this->actingAs($estudiante->user)->get(route('mis-clases'))->assertOk()->getContent();
        $this->assertStringContainsString(
            'Sí, esta clase se dio',
            $suyo,
            'la sonda no vale: el estudiante no tenía ninguna clase por confirmar.'
        );
        $this->assertStringNotContainsString('accion-inerte', $suyo);

        $this->actingAs($this->admin->user)->post(route('gestion-asistida-iniciar', $estudiante));

        $asistido = $this->get(route('mis-clases'))->assertOk()->getContent();

        $this->assertStringContainsString(
            'accion-inerte',
            $asistido,
            'la acción no quedó apagada: o se puede pulsar, o desapareció sin decir por qué.'
        );
        $this->assertStringContainsString('Solo el propio estudiante puede confirmar', $asistido);
    }

    /** Un grupo, una matrícula y una clase de hace una hora: confirmable. */
    private function claseConfirmable(Perfil $estudiante): Clase
    {
        $grupo = Grupo::create([
            'promotoria_id' => $this->promotoria->id,
            'nombre' => 'Grupo 1',
            'nivel' => 'basico',
            'salon' => 'A1',
            'cupo_maximo' => 10,
        ]);

        $matricula = new Matricula([
            'estudiante_id' => $estudiante->id,
            'promotoria_id' => $this->promotoria->id,
            'periodo_id' => Periodo::enCurso()->id,
            'grupo_id' => $grupo->id,
            'estado' => Matricula::ACTIVA,
        ]);
        // La matricula tiene que ser ANTERIOR a la clase: `Clase::porConfirmar`
        // excluye las clases previas a la matricula, y sin retrasarla la sonda
        // no encontraba ninguna que confirmar.
        $matricula->fecha = Carbon::now()->subWeek();
        $matricula->save();

        return Clase::create([
            'grupo_id' => $grupo->id,
            'periodo_id' => Periodo::enCurso()->id,
            'fecha_hora' => Carbon::now()->subHour(),
        ]);
    }

    private function estudiante(string $username): Perfil
    {
        $perfil = $this->perfil($username, 'estudiante');

        DatosEstudiante::create([
            'perfil_id' => $perfil->id,
            'documento_identidad' => '1'.$perfil->id,
        ]);

        return $perfil;
    }

    /**
     * NO SE ANIDA.
     *
     * Con asistencias encadenadas la sesión tendría que recordar una pila y
     * «volver a mi cuenta» dejaría de tener una respuesta clara.
     */
    public function test_no_se_puede_asistir_estando_ya_asistiendo(): void
    {
        $otroProfe = $this->perfil('profe2', 'profesor');

        $this->actingAs($this->admin->user)->post(route('gestion-asistida-iniciar', $this->profesor));
        $this->post(route('gestion-asistida-iniciar', $otroProfe));

        // Sigue siendo el primero, y el administrador de vuelta sigue siendo el
        // mismo: no se apiló nada.
        $this->assertSame($this->profesor->user_id, auth()->id());
        $this->assertSame($this->admin->id, GestionAsistida::administrador()?->id);
    }

    /** Un profesor NO puede iniciar una gestión asistida. */
    public function test_un_profesor_no_puede_asistir_a_nadie(): void
    {
        $otroProfe = $this->perfil('profe2', 'profesor');

        $this->actingAs($this->profesor->user)
            ->post(route('gestion-asistida-iniciar', $otroProfe))
            ->assertRedirect();

        $this->assertSame($this->profesor->user_id, auth()->id());
        $this->assertFalse(GestionAsistida::activa());
    }

    /**
     * Salir sin haber entrado no echa a nadie de su cuenta.
     *
     * No es un caso raro: el botón vive en una barra que se pinta en todas las
     * pantallas, y un doble toque o un reenvío mandan la petición dos veces.
     */
    public function test_salir_sin_estar_asistiendo_no_hace_nada(): void
    {
        $this->actingAs($this->profesor->user)
            ->post(route('gestion-asistida-salir'))
            ->assertRedirect();

        $this->assertSame($this->profesor->user_id, auth()->id(), 'echó de su cuenta a quien no estaba asistiendo.');
    }

    /**
     * ASISTIR A UN ESTUDIANTE ATERRIZA EN SU CATALOGO, Y CON EL AVISO PUESTO.
     *
     * Reportado por el usuario el 05/09/2026. `iniciar()` mandaba a todo el
     * mundo al Panel, donde un estudiante no entra: rebotaba en `RequiereRol`,
     * de ahi a `post-login` y de ahi al catalogo. El destino final era el bueno
     * por accidente, y el aviso se perdia por el camino — un `with()` dura UNA
     * peticion, y el rebote se gasta dos antes de pintar nada.
     *
     * Se sigue la redireccion a proposito: sin seguirla el mensaje todavia esta
     * en la sesion y la prueba pasa con el fallo puesto, que es como paso las
     * doce que ya habia.
     */
    public function test_asistir_a_un_estudiante_aterriza_en_el_catalogo_con_el_aviso(): void
    {
        $estudiante = $this->estudiante('ana');

        $html = $this->actingAs($this->admin->user)
            ->followingRedirects()
            ->post(route('gestion-asistida-iniciar', $estudiante))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Estás gestionando como', $html, 'se aterriza sin que nada lo diga.');
        $this->assertStringNotContainsString('No tienes acceso', $html, 'se llega rebotando en un error de permisos.');
    }

    /** Y a un profesor se le sigue asistiendo desde su Panel. */
    public function test_asistir_a_un_profesor_aterriza_en_el_panel_con_el_aviso(): void
    {
        $html = $this->actingAs($this->admin->user)
            ->followingRedirects()
            ->post(route('gestion-asistida-iniciar', $this->profesor))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Estás gestionando como', $html, 'se aterriza sin que nada lo diga.');
    }

    /**
     * LOS FORMULARIOS QUE CAMBIAN DE IDENTIDAD RECARGAN LA PAGINA ENTERA.
     *
     * La otra mitad de lo reportado el 05/09/2026: la sesion cambiaba de verdad
     * pero la pantalla no se enteraba. `acciones.js` intercepta todo formulario
     * que viva dentro de <main> y repinta SOLO <main>; la barra de gestion
     * asistida y el menu viven FUERA a proposito, asi que seguian mostrando al
     * administrador hasta que el navegador navegara de verdad. Pulsar «Mi
     * perfil» era lo primero que lo provocaba, y por eso «se activaba» ahi.
     *
     * Esto no lo puede ver una prueba de PHP mirando la pantalla —no hay
     * navegador que ejecute `acciones.js`— asi que se vigila el MARCADOR del
     * que depende, igual que `ToqueEnElTelefonoTest`. Quien lo quite creyendo
     * que sobra no rompera nada que una prueba mire.
     */
    public function test_entrar_en_una_asistida_recarga_la_pagina_entera(): void
    {
        $estudiante = $this->estudiante('ana');

        foreach ([
            route('detalle-usuario', $this->profesor),
            route('detalle-estudiante', $estudiante),
            route('usuario-lista'),
        ] as $url) {
            $html = $this->actingAs($this->admin->user)->get($url)->assertOk()->getContent();

            $this->assertMatchesRegularExpression(
                '/<form[^>]*gestion\/asistida[^>]*data-recarga-completa/',
                $html,
                "el formulario de entrar de {$url} se lo queda `acciones.js` y la barra no aparece."
            );
        }
    }

    /**
     * Y SALIR TAMBIEN, que es el caso peligroso: creer que saliste sin salir.
     *
     * Hoy se salva por donde esta puesto —la barra vive fuera de <main>, asi
     * que `acciones.js` ni lo mira— y eso es una casualidad de colocacion, no
     * una decision escrita. Mover la barra dentro de <main> por cualquier razon
     * de diseno lo romperia sin que nada fallara. El marcador lo dice en voz
     * alta y no cuesta nada.
     */
    public function test_salir_de_una_asistida_recarga_la_pagina_entera(): void
    {
        $this->actingAs($this->admin->user)->post(route('gestion-asistida-iniciar', $this->profesor));

        $html = $this->get(route('panel'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '/<form[^>]*gestion\/asistida\/salir[^>]*data-recarga-completa/',
            $html,
            'el formulario de salir no pide recarga completa.'
        );
    }

    private function perfil(string $username, string $rol): Perfil
    {
        $user = User::create(['username' => $username, 'password' => 'x', 'activo' => true]);

        return Perfil::create([
            'user_id' => $user->id,
            'rol' => $rol,
            'nombre_completo' => ucfirst($username),
            'fecha_nacimiento' => Carbon::today()->subYears(30)->toDateString(),
            'telefono' => '3000000000',
        ]);
    }
}
