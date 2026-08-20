<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\DatosEstudiante;
use App\Models\EncuestaDemografica;
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
 * Los dos informes descargables.
 *
 * Lo que se comprueba aqui no es solo que salga un archivo, sino las dos cosas
 * que lo hacen util o inutil: que ABRA bien en Excel —BOM y separador— y que
 * cada quien reciba solo lo que puede ver.
 */
class InformeTest extends TestCase
{
    use RefreshDatabase;

    private Periodo $periodo;
    private Promotoria $violin;
    private Promotoria $danza;
    private Perfil $profesor;
    private Perfil $director;
    private Perfil $admin;

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

        $this->profesor = $this->crearPerfil('profe', 'profesor');
        $this->director = $this->crearPerfil('dire', 'director');
        $this->admin = $this->crearPerfil('admin', 'administrador');

        $this->violin = Promotoria::create([
            'nombre' => 'Violin',
            'area_id' => $area->id,
            'profesor_id' => $this->profesor->id,
        ]);

        // Sin profesor asignado: es de otro, para probar el recorte.
        $this->danza = Promotoria::create(['nombre' => 'Danza', 'area_id' => $area->id]);
    }

    private function crearPerfil(string $username, string $rol, ?string $nacimiento = null): Perfil
    {
        $user = User::create(['username' => $username, 'password' => 'secreta123', 'activo' => true]);

        return Perfil::create([
            'user_id' => $user->id,
            'rol' => $rol,
            'nombre_completo' => ucfirst($username).' Apellido',
            'fecha_nacimiento' => $nacimiento ?? Carbon::today()->subYears(30)->toDateString(),
            'telefono' => '3001112233',
        ]);
    }

    private function crearEstudiante(string $username): Perfil
    {
        $perfil = $this->crearPerfil($username, 'estudiante');

        DatosEstudiante::create([
            'perfil_id' => $perfil->id,
            'documento_identidad' => (string) random_int(10000000, 99999999),
        ]);

        return $perfil;
    }

    private function matricular(Perfil $perfil, Promotoria $promotoria, string $estado = Matricula::ACTIVA): Matricula
    {
        return Matricula::create([
            'estudiante_id' => $perfil->id,
            'promotoria_id' => $promotoria->id,
            'periodo_id' => $this->periodo->id,
            'estado' => $estado,
            'fecha' => now(),
        ]);
    }

    /** El cuerpo del CSV, ya descargado. */
    private function contenido($respuesta): string
    {
        ob_start();
        $respuesta->baseResponse->sendContent();

        return ob_get_clean();
    }

    // -----------------------------------------------------------------------
    // Que el archivo ABRA bien
    // -----------------------------------------------------------------------

    /**
     * BOM y punto y coma. Sin BOM, Excel lee el archivo como Latin-1 y
     * «Promotoría» sale «PromotorÃ­a»; con comas en vez de punto y coma, la hoja
     * entera cae en una sola columna. Las dos cosas convierten un informe
     * correcto en uno que parece roto.
     */
    public function test_el_csv_abre_bien_en_excel(): void
    {
        $respuesta = $this->actingAs($this->admin->user)->get(route('informe-estudiantes'));
        $respuesta->assertOk();

        $csv = $this->contenido($respuesta);

        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString('Promotoría;', $csv);
        $this->assertStringContainsString('Teléfono del acudiente', $csv);
    }

    public function test_el_archivo_se_llama_con_la_fecha(): void
    {
        $this->actingAs($this->admin->user)
            ->get(route('informe-estudiantes'))
            ->assertDownload('estudiantes-por-grupo-'.now()->format('Y-m-d').'.csv');
    }

    // -----------------------------------------------------------------------
    // Estudiantes por grupo
    // -----------------------------------------------------------------------

    public function test_el_informe_trae_al_estudiante_con_su_contacto(): void
    {
        $ana = $this->crearEstudiante('ana');
        $grupo = Grupo::create([
            'promotoria_id' => $this->violin->id,
            'nombre' => 'Lunes 4-6 p. m.',
            'nivel' => 'basico',
            'horario' => 'Lunes 4-6 p. m.',
            'salon' => 'Salon 3',
            'cupo_maximo' => 10,
        ]);

        $matricula = $this->matricular($ana, $this->violin);
        $matricula->grupo_id = $grupo->id;
        $matricula->save();

        $csv = $this->contenido(
            $this->actingAs($this->director->user)->get(route('informe-estudiantes'))
        );

        $this->assertStringContainsString('Ana Apellido', $csv);
        $this->assertStringContainsString('Lunes 4-6 p. m.', $csv);
        $this->assertStringContainsString('Salon 3', $csv);
        $this->assertStringContainsString('Básico', $csv);
    }

    /** Sin grupo asignado sigue saliendo: es justo a quien hay que repartir. */
    public function test_quien_no_tiene_grupo_sale_marcado(): void
    {
        $this->matricular($this->crearEstudiante('beto'), $this->violin);

        $csv = $this->contenido(
            $this->actingAs($this->director->user)->get(route('informe-estudiantes'))
        );

        $this->assertStringContainsString('Beto Apellido', $csv);
        $this->assertStringContainsString('Sin grupo', $csv);
    }

    /**
     * Un profesor recibe SOLO sus promotorias, aunque pida el informe entero.
     *
     * Es la misma matriz de visibilidad de la ficha —el telefono de un
     * estudiante ajeno no es suyo—, y aqui importa el doble porque un CSV se
     * guarda y se reenvia.
     */
    public function test_el_profesor_solo_recibe_sus_promotorias(): void
    {
        $this->matricular($this->crearEstudiante('mia'), $this->violin);
        $this->matricular($this->crearEstudiante('ajeno'), $this->danza);

        $csv = $this->contenido(
            $this->actingAs($this->profesor->user)->get(route('informe-estudiantes'))
        );

        $this->assertStringContainsString('Mia Apellido', $csv);
        $this->assertStringNotContainsString('Ajeno Apellido', $csv);
    }

    public function test_direccion_recibe_todas_las_promotorias(): void
    {
        $this->matricular($this->crearEstudiante('mia'), $this->violin);
        $this->matricular($this->crearEstudiante('otro'), $this->danza);

        $csv = $this->contenido(
            $this->actingAs($this->director->user)->get(route('informe-estudiantes'))
        );

        $this->assertStringContainsString('Mia Apellido', $csv);
        $this->assertStringContainsString('Otro Apellido', $csv);
    }

    /** Quien se retiro ya no esta en la lista de quien va a clase. */
    public function test_los_retirados_no_salen(): void
    {
        $this->matricular($this->crearEstudiante('fuera'), $this->violin, Matricula::RETIRADA);

        $csv = $this->contenido(
            $this->actingAs($this->director->user)->get(route('informe-estudiantes'))
        );

        $this->assertStringNotContainsString('Fuera Apellido', $csv);
    }

    public function test_un_estudiante_no_baja_el_informe(): void
    {
        $ana = $this->crearEstudiante('ana');

        $this->actingAs($ana->user)
            ->get(route('informe-estudiantes'))
            ->assertRedirect(route('post-login'));
    }

    // -----------------------------------------------------------------------
    // Informe completo de la institucion
    // -----------------------------------------------------------------------

    /**
     * Solo el administrador. Es la puerta mas estrecha del sistema junto con la
     * copia del documento, y por la misma razon: este archivo saca la encuesta
     * demografica con nombre fuera del sistema.
     */
    public function test_el_informe_completo_es_solo_del_administrador(): void
    {
        foreach ([$this->director, $this->profesor] as $perfil) {
            $this->actingAs($perfil->user)
                ->get(route('informe-institucion'))
                ->assertRedirect(route('post-login'));
        }

        $this->actingAs($this->admin->user)
            ->get(route('informe-institucion'))
            ->assertOk();
    }

    public function test_el_informe_completo_trae_correo_rol_y_encuesta(): void
    {
        $ana = $this->crearEstudiante('ana');
        $ana->user->update(['email' => 'ana@ejemplo.co']);
        $this->matricular($ana, $this->violin);

        EncuestaDemografica::create([
            'perfil_id' => $ana->id,
            'genero' => 'f',
            'barrio' => 'La Candelaria',
            'estrato' => 2,
            'nivel_educativo' => 'primaria_com',
            'ocupacion' => 'estudiante',
        ]);

        $csv = $this->contenido(
            $this->actingAs($this->admin->user)->get(route('informe-institucion'))
        );

        $this->assertStringContainsString('Ana Apellido', $csv);
        $this->assertStringContainsString('ana@ejemplo.co', $csv);
        $this->assertStringContainsString('Estudiante', $csv);
        $this->assertStringContainsString('La Candelaria', $csv);
        // Traducido a su etiqueta: en una hoja de calculo «f» no lo lee nadie.
        $this->assertStringContainsString('Femenino', $csv);
        $this->assertStringContainsString('Primaria completa', $csv);
    }

    /** Quien no cursa nada sale igual, con las columnas de promotoria vacias. */
    public function test_el_personal_sin_promotoria_tambien_sale(): void
    {
        $csv = $this->contenido(
            $this->actingAs($this->admin->user)->get(route('informe-institucion'))
        );

        $this->assertStringContainsString('Dire Apellido', $csv);
        $this->assertStringContainsString('Director de escuela', $csv);
    }

    /**
     * Una fila por persona y promotoria: quien cursa dos sale dos veces.
     *
     * Es la forma correcta para una hoja de calculo y la unica que puede llevar
     * el nivel y el tiempo, que son datos DE la promotoria y no de la persona.
     */
    public function test_quien_cursa_dos_promotorias_sale_en_dos_filas(): void
    {
        $ana = $this->crearEstudiante('ana');
        $this->matricular($ana, $this->violin);
        $this->matricular($ana, $this->danza);

        $csv = $this->contenido(
            $this->actingAs($this->admin->user)->get(route('informe-institucion'))
        );

        $this->assertSame(2, substr_count($csv, 'Ana Apellido'));
        $this->assertStringContainsString('Violin', $csv);
        $this->assertStringContainsString('Danza', $csv);
    }

    /**
     * El tiempo se mide en PERIODOS cursados, que es la unidad de la casa.
     *
     * Cuentan solo los que quedaron ACTIVOS: una solicitud que nadie confirmo no
     * es tiempo cursado.
     */
    public function test_el_tiempo_cuenta_los_periodos_cursados(): void
    {
        $anterior = Periodo::create([
            'nombre' => '2025-2',
            'fecha_inicio' => '2025-07-01',
            'fecha_fin' => '2025-12-15',
            'activo' => false,
            'matriculas_abiertas' => false,
        ]);

        $ana = $this->crearEstudiante('ana');

        Matricula::create([
            'estudiante_id' => $ana->id,
            'promotoria_id' => $this->violin->id,
            'periodo_id' => $anterior->id,
            'estado' => Matricula::ACTIVA,
            'fecha' => now(),
        ]);
        $this->matricular($ana, $this->violin);

        $csv = $this->contenido(
            $this->actingAs($this->admin->user)->get(route('informe-institucion'))
        );

        // Dos periodos, y el primero es el del semestre pasado.
        $this->assertMatchesRegularExpression('/Ana Apellido.*;2;2025-2;/s', $csv);

        // Y UNA sola fila: la del periodo en curso. Las de periodos cerrados
        // siguen guardadas como 'activa' —el estado no cambia, cambia el
        // calendario—, y sin filtrarlas quien lleva tres semestres salia tres
        // veces con las mismas columnas.
        $this->assertSame(1, substr_count($csv, 'Ana Apellido'));
    }

    // -----------------------------------------------------------------------
    // Acotar a una promotoria o a un grupo
    // -----------------------------------------------------------------------

    private function grupoDe(Promotoria $promotoria, string $nivel = 'basico'): Grupo
    {
        return Grupo::create([
            'promotoria_id' => $promotoria->id,
            'nombre' => 'Grupo '.(Grupo::count() + 1),
            'nivel' => $nivel,
            'horario' => 'Lunes 4-6 p. m.',
            'salon' => 'Salon 1',
            'cupo_maximo' => 10,
        ]);
    }

    private function matricularEnGrupo(Perfil $perfil, Grupo $grupo): Matricula
    {
        $matricula = $this->matricular($perfil, $grupo->promotoria);
        $matricula->grupo_id = $grupo->id;
        $matricula->save();

        return $matricula;
    }

    /**
     * La lista de UN grupo: la que se lleva impresa al salon.
     *
     * Con el informe entero encima, quien dicta tendria que buscar sus veinte
     * filas entre trescientas.
     */
    public function test_se_puede_pedir_la_lista_de_un_solo_grupo(): void
    {
        $basico = $this->grupoDe($this->violin, 'basico');
        $avanzado = $this->grupoDe($this->violin, 'avanzado');

        $this->matricularEnGrupo($this->crearEstudiante('delbasico'), $basico);
        $this->matricularEnGrupo($this->crearEstudiante('delavanzado'), $avanzado);

        $csv = $this->contenido(
            $this->actingAs($this->profesor->user)
                ->get(route('informe-estudiantes', ['grupo' => $basico->id]))
        );

        $this->assertStringContainsString('Delbasico Apellido', $csv);
        $this->assertStringNotContainsString('Delavanzado Apellido', $csv);
    }

    public function test_se_puede_pedir_la_lista_de_una_sola_promotoria(): void
    {
        $this->matricular($this->crearEstudiante('mia'), $this->violin);
        $this->matricular($this->crearEstudiante('otra'), $this->danza);

        $csv = $this->contenido(
            $this->actingAs($this->director->user)
                ->get(route('informe-estudiantes', ['promotoria' => $this->violin->id]))
        );

        $this->assertStringContainsString('Mia Apellido', $csv);
        $this->assertStringNotContainsString('Otra Apellido', $csv);
    }

    /**
     * Pedir el grupo de una promotoria ajena da 404, no un archivo vacio.
     *
     * Un CSV con la cabecera y ninguna fila se lee como «ese grupo esta vacio»,
     * que es una respuesta falsa a una pregunta que no correspondia hacer.
     */
    public function test_un_profesor_no_baja_la_lista_de_un_grupo_ajeno(): void
    {
        $ajeno = $this->grupoDe($this->danza);

        $this->actingAs($this->profesor->user)
            ->get(route('informe-estudiantes', ['grupo' => $ajeno->id]))
            ->assertNotFound();
    }

    public function test_un_profesor_no_baja_la_lista_de_una_promotoria_ajena(): void
    {
        $this->actingAs($this->profesor->user)
            ->get(route('informe-estudiantes', ['promotoria' => $this->danza->id]))
            ->assertNotFound();
    }

    /** Direccion si baja la de cualquiera. */
    public function test_el_director_baja_la_lista_de_cualquier_grupo(): void
    {
        $grupo = $this->grupoDe($this->danza);
        $this->matricularEnGrupo($this->crearEstudiante('suyo'), $grupo);

        $csv = $this->contenido(
            $this->actingAs($this->director->user)
                ->get(route('informe-estudiantes', ['grupo' => $grupo->id]))
        );

        $this->assertStringContainsString('Suyo Apellido', $csv);
    }

    /**
     * El archivo lleva el nombre de la promotoria y del grupo.
     *
     * Quien dicta se baja tres listas seguidas, una por horario; con el mismo
     * nombre las tres, el navegador las guarda como «(1)» y «(2)» y para saber
     * cual es cual hay que abrirlas.
     */
    public function test_el_archivo_de_un_grupo_lleva_su_nombre(): void
    {
        $grupo = $this->grupoDe($this->violin);

        $this->actingAs($this->profesor->user)
            ->get(route('informe-estudiantes', ['grupo' => $grupo->id]))
            ->assertDownload('estudiantes-violin-grupo-1-'.now()->format('Y-m-d').'.csv');
    }

    /**
     * Ni una fila de mas ni una de menos cuando el informe pasa de UNA tanda.
     *
     * Esta prueba existe por un fallo concreto: el informe se traia por tandas
     * con `lazyById`, que pagina preguntando por el id mayor que el ultimo
     * devuelto, mientras el orden era por departamento, promotoria y nombre. Con
     * dos ordenes distintos las tandas se solapan y las filas se repiten — 4961
     * filas para 302 matriculas—, y no se veia con cuatro filas de prueba porque
     * todo cabia en la primera tanda.
     *
     * Por eso siembra por encima del tamano de tanda: por debajo, la prueba
     * pasaria igual con el codigo roto.
     */
    public function test_no_se_repiten_filas_cuando_hay_mas_de_una_tanda(): void
    {
        $cuantos = 130;

        for ($n = 1; $n <= $cuantos; $n++) {
            $this->matricular($this->crearEstudiante("est{$n}"), $this->violin);
        }

        $csv = $this->contenido(
            $this->actingAs($this->director->user)->get(route('informe-estudiantes'))
        );

        $lineas = array_filter(explode("\n", trim($csv)));

        // Cabecera + una fila por matricula, sin repetidas.
        $this->assertCount($cuantos + 1, $lineas);
        $this->assertCount($cuantos + 1, array_unique($lineas));
    }

    /**
     * Una celda que empieza por `=` la ejecuta Excel al abrir el archivo.
     *
     * Los nombres y los barrios los escribe el publico, asi que es una via real
     * de inyeccion de formulas. Se les antepone un apostrofo, que Excel entiende
     * como «esto es texto».
     */
    public function test_una_celda_con_formula_se_neutraliza(): void
    {
        $ana = $this->crearEstudiante('ana');
        $ana->update(['nombre_completo' => '=HYPERLINK("http://malo","clic")']);
        $this->matricular($ana, $this->violin);

        $csv = $this->contenido(
            $this->actingAs($this->admin->user)->get(route('informe-institucion'))
        );

        $this->assertStringContainsString("'=HYPERLINK", $csv);
    }
}
