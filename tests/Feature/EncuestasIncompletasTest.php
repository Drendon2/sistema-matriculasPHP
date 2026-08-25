<?php

namespace Tests\Feature;

use App\Models\EncuestaDemografica;
use App\Models\Perfil;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Cuantas encuestas estan a medias, ahora contado en SQL (C-02).
 *
 * Antes la pantalla de Estadisticas hacia `EncuestaDemografica::all()` y
 * recorria la coleccion con el accesor `esta_completa`. La tabla crece con cada
 * estudiante que contesta, y el techo lo pone la memoria de un hosting
 * compartido.
 *
 * LO QUE ESTAS PRUEBAS VIGILAN NO ES EL NUMERO, ES QUE LAS DOS DEFINICIONES NO
 * SE SEPAREN. Hay dos sitios que deciden que es «estar a medias»: el accesor del
 * modelo, que manda, y el filtro en SQL del controlador, que tiene que dar
 * exactamente lo mismo. La primera prueba no escribe la cifra esperada a mano:
 * la calcula con el accesor y la compara contra lo que pinta la pantalla, asi
 * que si alguien anade un campo obligatorio al modelo y se olvida del filtro,
 * se pone roja sola.
 */
class EncuestasIncompletasTest extends TestCase
{
    use RefreshDatabase;

    private Perfil $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->crearPerfil('jefa', 'administrador');
    }

    /**
     * El filtro en SQL y el accesor del modelo dicen lo mismo.
     *
     * Se siembran encuestas con huecos distintos --uno por cada campo
     * obligatorio de texto, mas una completa y una con varios huecos-- y el
     * numero esperado sale de preguntarle al MODELO, no de contarlas a mano.
     */
    public function test_la_cuenta_en_sql_coincide_con_la_del_modelo(): void
    {
        $this->sembrarUnaCompleta();

        foreach (array_keys(EncuestaDemografica::CAMPOS_OBLIGATORIOS) as $campo) {
            if ($campo === 'estrato') {
                continue;
            }

            $this->sembrarUnaCompleta([$campo => '']);
        }

        $this->sembrarUnaCompleta(['genero' => '', 'ocupacion' => '']);

        $segunElModelo = EncuestaDemografica::all()
            ->reject(fn (EncuestaDemografica $e) => $e->esta_completa)
            ->count();

        $this->assertGreaterThan(0, $segunElModelo, 'El sembrado no dejo ninguna a medias.');
        $this->assertSame($segunElModelo, $this->incompletasQuePintaLaPantalla());
    }

    /** Ninguna a medias: la pantalla no puede inventarse un hueco. */
    public function test_con_todas_completas_no_cuenta_ninguna(): void
    {
        $this->sembrarUnaCompleta();
        $this->sembrarUnaCompleta();

        $this->assertSame(0, $this->incompletasQuePintaLaPantalla());
    }

    /**
     * La pantalla no se trae la tabla entera.
     *
     * Se mira la CONSULTA y no la memoria: `SELECT *` sobre esta tabla es lo que
     * el hallazgo pedia quitar, y una asercion sobre bytes seria fragil. Que la
     * consulta nombre sus columnas es lo que garantiza que no vuelve el `all()`.
     */
    public function test_la_pantalla_no_hace_un_select_de_todo_sobre_las_encuestas(): void
    {
        $this->sembrarUnaCompleta();

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->actingAs($this->admin->user)->get(route('gestion-estadisticas'))->assertOk();

        $sobreEncuestas = collect(DB::getQueryLog())
            ->pluck('query')
            ->filter(fn (string $q) => str_contains($q, 'encuestas_demograficas'));

        DB::disableQueryLog();

        $this->assertTrue($sobreEncuestas->isNotEmpty(), 'La pantalla no consulto las encuestas.');
        $this->assertTrue(
            $sobreEncuestas->every(fn (string $q) => ! str_contains($q, 'select * from `encuestas_demograficas`')),
            "Volvio el SELECT * sobre las encuestas:\n".$sobreEncuestas->implode("\n")
        );
    }

    // -----------------------------------------------------------------------

    private function incompletasQuePintaLaPantalla(): int
    {
        $vista = $this->actingAs($this->admin->user)
            ->get(route('gestion-estadisticas'))
            ->assertOk()
            ->original;

        return $vista->getData()['encuestasIncompletas'];
    }

    private function sembrarUnaCompleta(array $huecos = []): EncuestaDemografica
    {
        $perfil = $this->crearPerfil('est-'.uniqid(), 'estudiante');

        return EncuestaDemografica::create([
            'perfil_id' => $perfil->id,
            'genero' => 'F',
            'barrio' => 'Centro',
            'estrato' => 3,
            'nivel_educativo' => array_key_first(EncuestaDemografica::NIVELES_EDUCATIVOS),
            'ocupacion' => array_key_first(EncuestaDemografica::OCUPACIONES),
            'zona' => array_key_first(EncuestaDemografica::ZONAS),
            'afiliacion_salud' => array_key_first(EncuestaDemografica::AFILIACIONES_SALUD),
            'grupo_etnico' => array_key_first(EncuestaDemografica::GRUPOS_ETNICOS),
            'discapacidad' => array_key_first(EncuestaDemografica::DISCAPACIDADES),
            'victima_conflicto_armado' => array_key_first(EncuestaDemografica::VICTIMAS_CONFLICTO),
            'autoriza_tratamiento_datos' => true,
            ...$huecos,
        ]);
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
