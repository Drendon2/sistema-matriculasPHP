<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Clase;
use App\Models\ConfiguracionInstitucion;
use App\Models\DatosEstudiante;
use App\Models\Grupo;
use App\Models\Matricula;
use App\Models\Perfil;
use App\Models\Periodo;
use App\Models\Promotoria;
use App\Models\SesionGrupo;
use App\Models\User;
use App\Support\Alertas;
use App\Support\ResumenInstitucion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * La portada de Gestion como pantalla de entrada del administrador.
 *
 * Pedido por el usuario el 04/09/2026: que sea su vista principal, con las
 * cifras de como va la escuela arriba y un banner con las ultimas alertas que
 * se actualice segun se resuelven.
 *
 * LO FRAGIL, y es lo que vigila esta clase:
 *
 * 1. Las cifras salen del MISMO sitio que las de Estadisticas. Dos pantallas
 *    que ensenan la misma cifra calculandola cada una por su cuenta acaban
 *    diciendo cosas distintas, y de eso este proyecto ya tiene historia.
 * 2. El banner no guarda ninguna cola: las alertas se calculan al abrir, asi
 *    que resolver una la quita y sube la siguiente sola. La prueba de abajo lo
 *    comprueba resolviendo una de verdad.
 * 3. Con las alertas APAGADAS no se pinta un banner vacio, que se leeria como
 *    «no hay nada». Se dice que estan apagadas, que no es lo mismo.
 */
class PortadaDeGestionTest extends TestCase
{
    use RefreshDatabase;

    private Perfil $admin;

    private Periodo $periodo;

    private Promotoria $promotoria;

    protected function setUp(): void
    {
        parent::setUp();

        $this->periodo = Periodo::create([
            'nombre' => '2026-1',
            'fecha_inicio' => Carbon::today()->subMonths(2)->toDateString(),
            'fecha_fin' => Carbon::today()->addMonths(2)->toDateString(),
            'activo' => true,
            'matriculas_abiertas' => true,
        ]);

        $this->admin = $this->perfil('jefa', 'administrador');

        $this->promotoria = Promotoria::create([
            'nombre' => 'Violin',
            'area_id' => Area::create(['nombre' => 'Musica'])->id,
            'profesor_id' => $this->perfil('profe', 'profesor')->id,
        ]);
    }

    /** Las cifras de «cómo va la escuela» se ven al entrar. */
    public function test_la_portada_ensena_las_cifras_de_la_escuela(): void
    {
        $this->matriculaActiva('ana');
        $this->matriculaActiva('beto');
        // Un segundo profesor: con uno solo la etiqueta va en singular, y lo
        // que se quiere mirar aqui es el caso normal.
        $this->perfil('otroprofe', 'profesor');

        $html = $this->portada();

        $this->assertStringContainsString('Estudiantes activos', $html);
        $this->assertStringContainsString('Profesores', $html);
        $this->assertStringContainsString('Cursos y talleres', $html);

        $cifras = ResumenInstitucion::cifras($this->periodo);
        $this->assertSame(2, $cifras['estudiantesActivos'], 'la sonda no vale: no hay dos activos.');
        $this->assertStringContainsString('>2<', $html, 'la cifra de estudiantes activos no llegó a la pantalla.');
    }

    /**
     * Y son LAS MISMAS que las de Estadísticas.
     *
     * Se comprueba contra la pantalla de verdad y no contra el helper: lo que
     * importa no es que el helper sea consistente consigo mismo, sino que las
     * dos pantallas hayan dejado de calcular por su cuenta.
     */
    public function test_las_cifras_coinciden_con_las_de_estadisticas(): void
    {
        $this->matriculaActiva('ana');

        $estadisticas = $this->actingAs($this->admin->user)
            ->get(route('gestion-estadisticas'))
            ->assertOk();

        $cifras = ResumenInstitucion::cifras($this->periodo);

        $this->assertSame(
            $cifras['estudiantesActivos'],
            $estadisticas->viewData('totalEstudiantesActivos'),
            'Estadísticas volvió a calcular «estudiantes activos» por su cuenta.'
        );
        $this->assertSame($cifras['promotorias'], $estadisticas->viewData('totalPromotorias'));
        $this->assertSame($cifras['grupos'], $estadisticas->viewData('totalGrupos'));
    }

    /**
     * EL BANNER SE ACTUALIZA SOLO al resolverse una alerta.
     *
     * Es lo que pidió el usuario y es lo que hace que no haga falta ninguna
     * cola: las alertas se deducen al abrir la pantalla, así que registrar la
     * clase que faltaba la borra del banner sin tocar nada más.
     */
    public function test_al_resolverse_una_alerta_desaparece_del_banner(): void
    {
        $grupo = $this->grupoConClaseSinDictar();

        // La MAS RECIENTE, que es la que el banner pone arriba.
        $pendientes = Alertas::clasesNoDictadas($this->periodo);
        $laDeArriba = $pendientes->first();
        $cuantasAntes = $pendientes->count();

        $html = $this->portada();
        $this->assertStringContainsString('alertas-banner', $html, 'no salió el banner de alertas.');
        $this->assertStringContainsString(
            'Clase del '.$laDeArriba['fecha']->format('d/m'),
            $html,
            'la sonda no vale: esa alerta no estaba en el banner.'
        );

        // Se registra justo esa clase: la alerta deja de existir sin que nadie
        // toque el banner, porque no hay ninguna cola que mantener.
        Clase::create([
            'grupo_id' => $grupo->id,
            'periodo_id' => $this->periodo->id,
            'fecha_hora' => $laDeArriba['fecha']->copy()->setTime(8, 0),
        ]);

        $despues = $this->portada();

        $this->assertStringNotContainsString(
            'Clase del '.$laDeArriba['fecha']->format('d/m'),
            $despues,
            'la alerta resuelta sigue en el banner: se está guardando una cola en vez de deducirla.'
        );

        $this->assertStringContainsString(
            'Ver las '.($cuantasAntes - 1),
            $despues,
            'la cuenta del banner no bajó al resolverse una.'
        );
    }

    /**
     * Enseña tres como mucho, y dice cuántas hay en total.
     *
     * La cifra del enlace es la mitad útil: el banner enseña tres, y sin ella
     * nadie sabe si son tres o cuarenta.
     */
    public function test_el_banner_ensena_tres_y_dice_cuantas_hay(): void
    {
        $this->grupoConClaseSinDictar();

        $cuantas = Alertas::clasesNoDictadas($this->periodo)->count();
        $this->assertGreaterThan(3, $cuantas, 'la sonda no vale: hacen falta más de tres alertas.');

        $html = $this->portada();

        $this->assertSame(
            3,
            substr_count($html, 'alertas-banner-item'),
            'el banner no se quedó en tres.'
        );
        $this->assertStringContainsString(
            "Ver las {$cuantas}",
            $html,
            'el banner no dice cuántas hay en total.'
        );
    }

    /**
     * Con las alertas apagadas NO se pinta un banner vacío.
     *
     * Un banner que no sale se lee como «no hay nada que mirar», y con los
     * interruptores apagados eso es falso: lo que pasa es que nadie está
     * mirando. Se dice, y se dice dónde se encienden.
     */
    public function test_con_las_alertas_apagadas_se_avisa_en_vez_de_callar(): void
    {
        $this->grupoConClaseSinDictar();

        $config = ConfiguracionInstitucion::actual();
        $config->alerta_clase_no_dictada = false;
        $config->alerta_abandono = false;
        $config->save();

        $html = $this->portada();

        $this->assertStringNotContainsString('alertas-banner', $html, 'salió el banner con las alertas apagadas.');
        $this->assertStringContainsString('Las alertas están apagadas', $html, 'no se dice que están apagadas.');
    }

    private function portada(): string
    {
        return $this->actingAs($this->admin->user)
            ->get(route('gestion-inicio'))
            ->assertOk()
            ->getContent();
    }

    /** Un grupo con clases en el horario que no se registraron. */
    private function grupoConClaseSinDictar(): Grupo
    {
        $config = ConfiguracionInstitucion::actual();
        $config->alerta_clase_no_dictada = true;
        $config->alerta_abandono = false;
        $config->save();

        $grupo = Grupo::create([
            'promotoria_id' => $this->promotoria->id,
            'nombre' => 'Grupo 1',
            'nivel' => 'basico',
            'salon' => 'A1',
            'cupo_maximo' => 10,
        ]);

        // Un dia de la semana en el horario: las clases que ese dia no se
        // registraron son las que la alerta encuentra.
        SesionGrupo::create([
            'grupo_id' => $grupo->id,
            'dia' => (int) Carbon::today()->subWeek()->dayOfWeekIso,
            'hora_inicio' => '08:00',
            'hora_fin' => '10:00',
        ]);

        $this->matriculaActiva('ana', $grupo);

        return $grupo;
    }

    private function matriculaActiva(string $quien, ?Grupo $grupo = null): Matricula
    {
        $estudiante = $this->perfil($quien, 'estudiante');

        DatosEstudiante::create([
            'perfil_id' => $estudiante->id,
            'documento_identidad' => '1'.$estudiante->id,
        ]);

        $matricula = new Matricula([
            'estudiante_id' => $estudiante->id,
            'promotoria_id' => $this->promotoria->id,
            'periodo_id' => $this->periodo->id,
            'grupo_id' => $grupo?->id,
            'estado' => Matricula::ACTIVA,
        ]);
        $matricula->save();

        return $matricula;
    }

    private function perfil(string $username, string $rol): Perfil
    {
        $user = User::create(['username' => $username, 'password' => 'x', 'activo' => true]);

        return Perfil::create([
            'user_id' => $user->id,
            'rol' => $rol,
            'nombre_completo' => ucfirst($username),
            'fecha_nacimiento' => Carbon::today()->subYears(25)->toDateString(),
            'telefono' => '3000000000',
        ]);
    }
}
