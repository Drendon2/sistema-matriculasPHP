<?php

namespace Tests\Feature;

use App\Models\Acudiente;
use App\Models\Area;
use App\Models\ConfiguracionInstitucion;
use App\Models\DatosEstudiante;
use App\Models\DocumentoEstudiante;
use App\Models\DocumentoRequerido;
use App\Models\Matricula;
use App\Models\Perfil;
use App\Models\Periodo;
use App\Models\Promotoria;
use App\Models\User;
use App\Support\FichasIncompletas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * LA TERCERA BANDEJA: a quien le falta algo por completar.
 *
 * Ocho motivos, dos filtros y un boton con su cifra. Lo que estas pruebas
 * vigilan no es que la lista salga —eso se ve abriendo la pantalla— sino las
 * cuatro cosas que NO se ven mirando:
 *
 * 1. Que la cifra del boton y la lista salgan del MISMO recorrido. Si se
 *    separan, el boton dice 810 y dentro hay 640, y nadie lo mira dos veces.
 * 2. Que el interruptor de la encuesta apague ESE motivo y solo ese.
 * 3. Que el filtro de promotoria alcance al profesor que la dicta, que es para
 *    quien se hizo la lista.
 * 4. Que un requerido NO obligatorio no meta a media institucion en la bandeja.
 */
class FichasIncompletasTest extends TestCase
{
    use RefreshDatabase;

    private Perfil $jefa;

    private Periodo $periodo;

    private Promotoria $violin;

    private Promotoria $danza;

    protected function setUp(): void
    {
        parent::setUp();

        $this->jefa = $this->crearPerfil('jefa', 'administrador');

        $this->periodo = Periodo::create([
            'nombre' => '2026-1',
            'fecha_inicio' => '2026-01-15',
            'fecha_fin' => '2026-06-30',
            'activo' => true,
            'matriculas_abiertas' => true,
        ]);

        $area = Area::create(['nombre' => 'Música']);
        $this->violin = Promotoria::create(['nombre' => 'Violín', 'area_id' => $area->id]);
        $this->danza = Promotoria::create(['nombre' => 'Danza', 'area_id' => $area->id]);

        // La encuesta apagada por defecto en las pruebas: encendida mete a TODO
        // el mundo en la lista y taparia lo que cada prueba quiere ver. Las dos
        // que la miran la encienden a mano.
        $config = ConfiguracionInstitucion::actual();
        $config->recordar_encuesta = false;
        $config->save();
    }

    private function crearPerfil(string $username, string $rol, ?string $nacimiento = null): Perfil
    {
        $user = User::create(['username' => $username, 'password' => 'demo1234', 'activo' => true]);

        return Perfil::create([
            'user_id' => $user->id,
            'rol' => $rol,
            'nombre_completo' => ucfirst($username),
            // Mayor de edad por defecto: la minoria de edad la pide cada prueba
            // que la necesita, para que ninguna arrastre el acudiente sin querer.
            'fecha_nacimiento' => $nacimiento ?? Carbon::today()->subYears(30)->toDateString(),
            'telefono' => '3001112233',
        ]);
    }

    private function crearEstudiante(string $username, ?string $nacimiento = null): Perfil
    {
        $perfil = $this->crearPerfil($username, 'estudiante', $nacimiento);

        DatosEstudiante::create([
            'perfil_id' => $perfil->id,
            'documento_identidad' => str_pad((string) $perfil->id, 8, '0', STR_PAD_LEFT),
        ]);

        return $perfil;
    }

    private function matricular(Perfil $perfil, Promotoria $promotoria, string $estado): Matricula
    {
        return Matricula::create([
            'estudiante_id' => $perfil->id,
            'promotoria_id' => $promotoria->id,
            'periodo_id' => $this->periodo->id,
            'estado' => $estado,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fichaDe(Perfil $perfil): ?array
    {
        // Las filas son arrays planos y no modelos: hidratar los perfiles con
        // sus relaciones reventaba la memoria. Ver `FichasIncompletas`.
        foreach (FichasIncompletas::todas() as $ficha) {
            if ($ficha['id'] === $perfil->id) {
                return $ficha;
            }
        }

        return null;
    }

    // --------------------------------------------------------------------
    // Los motivos
    // --------------------------------------------------------------------

    public function test_una_matricula_pendiente_de_aprobar_entra_con_su_promotoria(): void
    {
        $ana = $this->crearEstudiante('ana');
        $this->matricular($ana, $this->violin, Matricula::PENDIENTE);

        $ficha = $this->fichaDe($ana);

        $this->assertNotNull($ficha);
        $this->assertContains('aprobacion', $ficha['motivos']);
        // El detalle dice EN QUE promotoria, que es lo que decide a quien
        // preguntarle. Sin eso la fila dice «algo pendiente» y no sirve.
        $this->assertContains('Matrícula por aprobar en Violín', $ficha['detalles']);
    }

    public function test_una_matricula_activa_sin_grupo_entra(): void
    {
        $beto = $this->crearEstudiante('beto');
        $this->matricular($beto, $this->violin, Matricula::ACTIVA);

        $ficha = $this->fichaDe($beto);

        $this->assertNotNull($ficha);
        $this->assertContains('grupo', $ficha['motivos']);
    }

    public function test_una_matricula_retirada_no_entra_por_ningun_motivo(): void
    {
        $colado = $this->crearEstudiante('colado');
        $this->matricular($colado, $this->violin, Matricula::RETIRADA);

        // Puede seguir apareciendo por otra cosa —un papel sin subir— pero no
        // por estos dos: quien se salió ya no espera que le aprueben nada ni
        // que le asignen grupo.
        $motivos = $this->fichaDe($colado)['motivos'] ?? [];

        $this->assertNotContains('aprobacion', $motivos);
        $this->assertNotContains('grupo', $motivos);
    }

    public function test_quien_se_registro_y_no_tiene_rol_entra(): void
    {
        $nadie = $this->crearPerfil('nadie', '');

        $ficha = $this->fichaDe($nadie);

        $this->assertNotNull($ficha);
        $this->assertContains('rol', $ficha['motivos']);
    }

    /**
     * Solo los OBLIGATORIOS cuentan, y esta es la que evita el desastre.
     *
     * Un requerido no obligatorio es una ranura ofrecida, no una deuda. Si
     * contara, la bandeja meteria a toda la institucion por no haber subido algo
     * que nadie le exigio — y con 807 estudiantes eso no se ve en una prueba
     * que mire a una sola persona.
     */
    public function test_un_requerido_opcional_no_mete_a_nadie_en_la_lista(): void
    {
        DocumentoRequerido::create([
            'nombre' => 'Foto tipo documento',
            'obligatorio' => false,
            'activo' => true,
            'orden' => 1,
        ]);

        $ana = $this->crearEstudiante('ana');

        $ficha = $this->fichaDe($ana);

        $this->assertNull($ficha, 'un papel opcional metió a alguien en la bandeja.');
    }

    public function test_un_requerido_obligatorio_sin_subir_si_entra(): void
    {
        DocumentoRequerido::create([
            'nombre' => 'Consentimiento firmado',
            'obligatorio' => true,
            'activo' => true,
            'orden' => 1,
        ]);

        $ana = $this->crearEstudiante('ana');

        $ficha = $this->fichaDe($ana);

        $this->assertNotNull($ficha);
        $this->assertContains('papel', $ficha['motivos']);
        $this->assertContains('Falta subir: Consentimiento firmado', $ficha['detalles']);
    }

    public function test_quien_ya_subio_el_obligatorio_sale_de_la_lista(): void
    {
        $requerido = DocumentoRequerido::create([
            'nombre' => 'Consentimiento firmado',
            'obligatorio' => true,
            'activo' => true,
            'orden' => 1,
        ]);

        $ana = $this->crearEstudiante('ana');

        DocumentoEstudiante::create([
            'datos_estudiante_id' => $ana->datosEstudiante->id,
            'requerido_id' => $requerido->id,
            'archivo' => 'documentos/lo-que-sea.pdf',
        ]);

        $this->assertNull($this->fichaDe($ana->fresh()));
    }

    public function test_un_menor_sin_acudiente_entra(): void
    {
        $nino = $this->crearEstudiante('nino', Carbon::today()->subYears(10)->toDateString());

        $ficha = $this->fichaDe($nino);

        $this->assertNotNull($ficha);
        $this->assertContains('acudiente', $ficha['motivos']);
    }

    /**
     * Y la otra mitad de la regla, que es la que se olvida.
     *
     * El acudiente se registra para poder LLAMARLO. Uno sin telefono no cumple
     * la funcion por la que se pide, y `DatosEstudiante::validar()` ya lo
     * rechaza: si la bandeja lo diera por bueno, diria que esa ficha está
     * completa mientras el formulario se niega a guardarla.
     */
    public function test_un_menor_con_acudiente_sin_telefono_tambien_entra(): void
    {
        $nino = $this->crearEstudiante('nino', Carbon::today()->subYears(10)->toDateString());

        $acudiente = Acudiente::create(['nombre' => 'La mamá', 'telefono' => '']);
        $nino->datosEstudiante->acudiente_id = $acudiente->id;
        $nino->datosEstudiante->save();

        $ficha = $this->fichaDe($nino->fresh());

        $this->assertNotNull($ficha);
        $this->assertContains('acudiente', $ficha['motivos']);
    }

    public function test_un_mayor_de_edad_sin_acudiente_no_entra_por_eso(): void
    {
        $ana = $this->crearEstudiante('ana');

        $this->assertNull($this->fichaDe($ana));
    }

    public function test_un_telefono_que_ya_no_pasa_la_validacion_entra(): void
    {
        $ana = $this->crearEstudiante('ana');
        // Se escribe a la base sin pasar por el formulario, que es como llegaron
        // los 43 que hay en produccion: son anteriores a la regla del 07/09.
        Perfil::where('id', $ana->id)->update(['telefono' => '300 111 2233']);

        $ficha = $this->fichaDe($ana);

        $this->assertNotNull($ficha);
        $this->assertContains('formato', $ficha['motivos']);
    }

    public function test_un_campo_en_blanco_entra_como_dato_que_falta(): void
    {
        $ana = $this->crearEstudiante('ana');
        Perfil::where('id', $ana->id)->update(['telefono' => '']);

        $ficha = $this->fichaDe($ana);

        $this->assertNotNull($ficha);
        $this->assertContains('datos', $ficha['motivos']);
        // Y NO por formato: un campo vacio y uno mal escrito son dos trabajos
        // distintos —uno se pide, el otro se corrige— y mezclarlos deja el
        // filtro sin poder separarlos.
        $this->assertNotContains('formato', $ficha['motivos']);
    }

    // --------------------------------------------------------------------
    // El interruptor de la encuesta
    // --------------------------------------------------------------------

    public function test_con_la_encuesta_apagada_nadie_entra_por_ella(): void
    {
        $ana = $this->crearEstudiante('ana');

        $this->assertNull($this->fichaDe($ana));
    }

    /**
     * Encendida, la ausencia de encuesta SI es algo que atender.
     *
     * Es el mismo interruptor que decide si se le recuerda al entrar, y eso es a
     * proposito: pedirsela por un lado y no contarla por el otro dejaria la
     * bandeja diciendo que no falta nada mientras la pantalla de inicio le
     * insiste a 775 personas.
     */
    public function test_con_la_encuesta_encendida_quien_no_la_contesto_entra(): void
    {
        $config = ConfiguracionInstitucion::actual();
        $config->recordar_encuesta = true;
        $config->save();

        $ana = $this->crearEstudiante('ana');

        $ficha = $this->fichaDe($ana);

        $this->assertNotNull($ficha);
        $this->assertSame(['encuesta'], $ficha['motivos']);
    }

    /** Y alcanza al PERSONAL, no solo a estudiantes. */
    public function test_la_encuesta_tambien_se_le_cuenta_al_personal(): void
    {
        $config = ConfiguracionInstitucion::actual();
        $config->recordar_encuesta = true;
        $config->save();

        $profe = $this->crearPerfil('profe', 'profesor');

        $ficha = $this->fichaDe($profe);

        $this->assertNotNull($ficha);
        $this->assertContains('encuesta', $ficha['motivos']);
    }

    // --------------------------------------------------------------------
    // Los filtros
    // --------------------------------------------------------------------

    public function test_el_filtro_de_promotoria_deja_solo_a_los_de_esa(): void
    {
        $ana = $this->crearEstudiante('ana');
        $this->matricular($ana, $this->violin, Matricula::PENDIENTE);

        $beto = $this->crearEstudiante('beto');
        $this->matricular($beto, $this->danza, Matricula::PENDIENTE);

        $html = $this->actingAs($this->jefa->user)
            ->get(route('gestion-fichas-incompletas', ['promotoria' => $this->violin->id]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Ana', (string) $html);
        $this->assertStringNotContainsString('Beto', (string) $html);
    }

    /**
     * El filtro alcanza al PROFESOR de la promotoria, no solo a sus alumnos.
     *
     * Es para quien se hizo la lista: se filtra por Violín para mandarsela a
     * quien dicta Violín, y si a esa persona le falta la encuesta tiene que
     * verse en su propia lista. Es ademas el mismo significado que ya tiene
     * «filtrar por promotoría» en Gestion → Usuarios; dos lecturas distintas de
     * la misma palabra en dos pantallas seria lo peor de los dos mundos.
     */
    public function test_el_filtro_de_promotoria_alcanza_a_quien_la_dicta(): void
    {
        $config = ConfiguracionInstitucion::actual();
        $config->recordar_encuesta = true;
        $config->save();

        $profe = $this->crearPerfil('profe', 'profesor');
        $this->violin->profesor_id = $profe->id;
        $this->violin->save();

        $html = (string) $this->actingAs($this->jefa->user)
            ->get(route('gestion-fichas-incompletas', ['promotoria' => $this->violin->id]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Profe', $html);
    }

    public function test_la_pantalla_dice_a_quien_mandarle_la_lista(): void
    {
        $profe = $this->crearPerfil('profe', 'profesor');
        $this->violin->profesor_id = $profe->id;
        $this->violin->save();

        $html = (string) $this->actingAs($this->jefa->user)
            ->get(route('gestion-fichas-incompletas', ['promotoria' => $this->violin->id]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('que dicta', $html);
        $this->assertStringContainsString('Profe', $html);
    }

    public function test_el_filtro_de_motivo_deja_solo_ese_motivo(): void
    {
        $ana = $this->crearEstudiante('ana');
        $this->matricular($ana, $this->violin, Matricula::PENDIENTE);

        $beto = $this->crearEstudiante('beto');
        $this->matricular($beto, $this->danza, Matricula::ACTIVA);

        $html = (string) $this->actingAs($this->jefa->user)
            ->get(route('gestion-fichas-incompletas', ['motivo' => 'aprobacion']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Ana', $html);
        $this->assertStringNotContainsString('Beto', $html);
    }

    /** Un motivo inventado no filtra nada, en vez de dejar la lista vacia. */
    public function test_un_motivo_que_no_existe_no_esconde_a_nadie(): void
    {
        $ana = $this->crearEstudiante('ana');
        $this->matricular($ana, $this->violin, Matricula::PENDIENTE);

        $html = (string) $this->actingAs($this->jefa->user)
            ->get(route('gestion-fichas-incompletas', ['motivo' => 'loquesea']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Ana', $html);
    }

    // --------------------------------------------------------------------
    // El boton de entrada
    // --------------------------------------------------------------------

    /**
     * La cifra del boton y la lista salen del MISMO recorrido.
     *
     * Es la prueba que parece tonta y no lo es: contar por separado con ocho
     * consultas sueltas seria mas barato y dejaria dos verdades. El dia que se
     * separen, el boton dira 810 y dentro habra 640 — y nadie vuelve a mirar un
     * contador que ya le mintio una vez.
     */
    public function test_el_boton_de_alertas_dice_cuantas_hay_y_cuadra_con_la_lista(): void
    {
        $ana = $this->crearEstudiante('ana');
        $this->matricular($ana, $this->violin, Matricula::PENDIENTE);

        $beto = $this->crearEstudiante('beto');
        $this->matricular($beto, $this->danza, Matricula::ACTIVA);

        $alertas = (string) $this->actingAs($this->jefa->user)
            ->get(route('gestion-cancelaciones'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Fichas por completar', $alertas);
        $this->assertStringContainsString('<span class="bandeja-cuenta">2</span>', $alertas);

        // Y la lista trae las mismas dos.
        $lista = (string) $this->actingAs($this->jefa->user)
            ->get(route('gestion-fichas-incompletas'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Ana', $lista);
        $this->assertStringContainsString('Beto', $lista);
    }

    /** Sin nada pendiente el boton sigue estando, y dice cero. */
    public function test_sin_nada_pendiente_el_boton_dice_cero(): void
    {
        $alertas = (string) $this->actingAs($this->jefa->user)
            ->get(route('gestion-cancelaciones'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('<span class="bandeja-cuenta">0</span>', $alertas);
        $this->assertStringContainsString('No falta nada por completar', $alertas);
    }

    /** El desglose entra a la lista YA filtrada, que es como se usa. */
    public function test_el_desglose_enlaza_a_la_lista_filtrada_por_ese_motivo(): void
    {
        $ana = $this->crearEstudiante('ana');
        $this->matricular($ana, $this->violin, Matricula::PENDIENTE);

        $alertas = (string) $this->actingAs($this->jefa->user)
            ->get(route('gestion-cancelaciones'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(
            route('gestion-fichas-incompletas', ['motivo' => 'aprobacion']),
            $alertas
        );
    }

    // --------------------------------------------------------------------
    // La pantalla
    // --------------------------------------------------------------------

    /** Nombre, teléfono y qué le falta: las tres columnas del encargo. */
    public function test_la_lista_trae_nombre_telefono_y_lo_que_falta(): void
    {
        $ana = $this->crearEstudiante('ana');
        $this->matricular($ana, $this->violin, Matricula::PENDIENTE);

        $html = (string) $this->actingAs($this->jefa->user)
            ->get(route('gestion-fichas-incompletas'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Ana', $html);
        $this->assertStringContainsString('3001112233', $html);
        $this->assertStringContainsString('Matrícula por aprobar en Violín', $html);
    }

    /**
     * De un menor se da tambien el telefono del ACUDIENTE.
     *
     * Es a quien hay que llamar de verdad, y es la razon por la que la
     * institucion lo registra. Una lista de menores con el telefono del niño y
     * sin el de su casa no sirve para lo que se hizo.
     */
    public function test_de_un_menor_se_da_el_telefono_del_acudiente(): void
    {
        $nino = $this->crearEstudiante('nino', Carbon::today()->subYears(10)->toDateString());

        $acudiente = Acudiente::create(['nombre' => 'La mamá', 'telefono' => '3009998877']);
        $nino->datosEstudiante->acudiente_id = $acudiente->id;
        $nino->datosEstudiante->save();

        $this->matricular($nino, $this->violin, Matricula::PENDIENTE);

        $html = (string) $this->actingAs($this->jefa->user)
            ->get(route('gestion-fichas-incompletas'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('3009998877', $html);
    }

    /**
     * La tabla es `.tabla-personas`, que bajo 640px la convierte en fichas.
     *
     * PHPUnit no tiene navegador, asi que esto no mide nada: vigila que la
     * MARCA siga puesta. Quien la quite no romperá ninguna pantalla que él vaya
     * a mirar —en escritorio no cambia nada— y dejará las tres columnas al otro
     * lado de un arrastre en el teléfono, que es desde donde se usa esto.
     */
    public function test_la_tabla_se_convierte_en_fichas_en_el_telefono(): void
    {
        $ana = $this->crearEstudiante('ana');
        $this->matricular($ana, $this->violin, Matricula::PENDIENTE);

        $html = (string) $this->actingAs($this->jefa->user)
            ->get(route('gestion-fichas-incompletas'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('tabla-personas', $html);
        $this->assertStringContainsString('data-label="Teléfono"', $html);
        $this->assertStringContainsString('data-celda="detalle"', $html);
    }

    /** Un profesor no entra a esta pantalla: es de dirección. */
    public function test_un_profesor_no_entra(): void
    {
        $profe = $this->crearPerfil('profe', 'profesor');

        $this->actingAs($profe->user)
            ->get(route('gestion-fichas-incompletas'))
            ->assertRedirect();
    }
}
