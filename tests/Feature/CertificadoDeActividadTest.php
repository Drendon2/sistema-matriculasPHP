<?php

namespace Tests\Feature;

use App\Models\Actividad;
use App\Models\AsistenciaActividad;
use App\Models\ConfiguracionInstitucion;
use App\Models\InscritoActividad;
use App\Models\Perfil;
use App\Models\Periodo;
use App\Models\SesionActividad;
use App\Models\User;
use App\Support\AsistenciaDeActividad;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\View;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * El certificado de asistencia a un curso o un taller.
 *
 * ─── QUE CERTIFICA, Y POR QUE NO ES EL DE MATRICULA ────────────────────────
 *
 * A una actividad se entra por un enlace, sin cuenta: `inscritos_actividad`
 * guarda el nombre y el documento como TEXTO y `perfil_id` es nulable. Asi que
 * por debajo no hay matricula que alguien haya confirmado, y lo unico que puede
 * sostener el papel es la lista que paso quien la dicta. De ahi que este
 * certifique ASISTENCIA —con un minimo— y el de matricula no exija ninguna.
 *
 * ─── LAS REGLAS, DECIDIDAS POR EL USUARIO EL 11/09/2026 ────────────────────
 *
 * Ninguna se deduce del esquema, y por eso cada una tiene aqui su prueba:
 *
 * - El 80% de las sesiones.
 * - El denominador son las sesiones CON LISTA TOMADA. «Si el profesor no toma
 *   lista no se le cuenta la falta a los estudiantes.»
 * - La excusa NO cuenta como asistencia.
 * - Lo saca el responsable, un administrador o el director. El propio inscrito
 *   no puede: no tiene cuenta con la que entrar.
 * - Solo cursos y talleres. Un grupo de proyeccion no, aunque tenga ensayos con
 *   lista — se decidio con la alternativa delante.
 *
 * ─── Y LA MEDIDA DE LA HOJA ────────────────────────────────────────────────
 *
 * El papel va HORIZONTAL, asi que el alto disponible no son 792 pt sino 612.
 * `test_el_certificado_cabe_en_una_hoja_apaisada` busca por biseccion la hoja
 * minima donde todavia cabe, como hacen `ConsentimientoTest` y
 * `CertificadoTest`: contar hojas dice si o no y no dice por cuanto, y el
 * certificado de matricula ya se paso de hoja por SIETE puntos sin que ninguna
 * de sus ocho pruebas lo viera.
 */
class CertificadoDeActividadTest extends TestCase
{
    use RefreshDatabase;

    /** El alto util de una carta APAISADA. La vertical son 792. */
    private const CARTA_APAISADA = 612.0;

    private Perfil $profesor;

    private Perfil $otroProfesor;

    private Perfil $director;

    private Perfil $admin;

    private Perfil $estudiante;

    private Periodo $periodo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->periodo = Periodo::create([
            'nombre' => '2026-2',
            'fecha_inicio' => Carbon::today()->subMonths(2)->toDateString(),
            'fecha_fin' => Carbon::today()->addMonths(2)->toDateString(),
            'activo' => true,
            'matriculas_abiertas' => true,
        ]);

        $this->profesor = $this->perfil('ines', 'profesor');
        $this->otroProfesor = $this->perfil('mario', 'profesor');
        $this->director = $this->perfil('dora', 'director');
        $this->admin = $this->perfil('ada', 'administrador');
        $this->estudiante = $this->perfil('luz', 'estudiante');
    }

    // ------------------------------------------------------------------
    // El 80%
    // ------------------------------------------------------------------

    /** Ocho de diez es exactamente el minimo, y el minimo entra. */
    public function test_con_el_ochenta_por_ciento_justo_se_certifica(): void
    {
        [$curso, $inscrito] = $this->cursoCon(asistidas: 8, faltadas: 2);

        $this->assertEsPdf($this->bajar($curso, $inscrito));
    }

    public function test_por_debajo_del_ochenta_no_hay_papel(): void
    {
        [$curso, $inscrito] = $this->cursoCon(asistidas: 7, faltadas: 3);

        $respuesta = $this->bajar($curso, $inscrito);

        $respuesta->assertRedirect();
        $this->assertStringContainsString('7 de 10', (string) session('error'));
        $this->assertStringContainsString('70%', (string) session('error'));
    }

    /**
     * LA EXCUSA NO SUMA.
     *
     * Siete asistencias y una excusa sobre diez sesiones son el 70%, no el 80%.
     * Una excusa justifica la falta —por eso corta la racha de la alerta de
     * abandono— pero no pone a nadie en el salon, y esto certifica haber estado.
     */
    public function test_la_excusa_no_cuenta_como_asistencia(): void
    {
        [$curso, $inscrito] = $this->cursoCon(asistidas: 7, faltadas: 2, excusadas: 1);

        $this->bajar($curso, $inscrito)->assertRedirect();
        $this->assertSame(70, AsistenciaDeActividad::deInscrito($inscrito)['porcentaje']);
    }

    /**
     * LA SESION SIN LISTA NO CUENTA, que es la regla que mas cambia el
     * resultado.
     *
     * Diez clases programadas, el profesor solo paso lista en cinco, y esta
     * persona fue a cuatro de esas cinco: es el 80% y se certifica. Contando las
     * diez seria el 40% y no habria papel — o sea que el descuido de quien dicta
     * le quitaria el certificado a quien si fue.
     */
    public function test_las_sesiones_sin_lista_no_bajan_el_porcentaje(): void
    {
        $curso = $this->curso();
        $inscrito = $this->inscribir($curso, 'Ana Ruiz');

        // Cinco con lista: fue a cuatro.
        foreach (range(1, 5) as $n) {
            $sesion = $this->sesion($curso, $n);
            $this->marcar($sesion, $inscrito, $n === 5 ? 'falto' : AsistenciaActividad::ASISTIO);
        }

        // Y otras cinco que existen y hasta se iniciaron, pero a las que nadie
        // paso lista. No son evidencia de que nadie fuera: son la falta de
        // evidencia.
        foreach (range(6, 10) as $n) {
            $this->sesion($curso, $n);
        }

        $resumen = AsistenciaDeActividad::deInscrito($inscrito);

        $this->assertSame(5, $resumen['sesiones'], 'el denominador son las sesiones con lista, no las programadas');
        $this->assertSame(80, $resumen['porcentaje']);
        $this->assertEsPdf($this->bajar($curso, $inscrito));
    }

    /** Sin ninguna lista tomada no hay nada que certificar, y lo dice asi. */
    public function test_sin_ninguna_lista_tomada_no_se_certifica(): void
    {
        $curso = $this->curso();
        $inscrito = $this->inscribir($curso, 'Ana Ruiz');
        $this->sesion($curso, 1);

        $this->bajar($curso, $inscrito)->assertRedirect();

        $this->assertStringContainsString('no se ha pasado lista', (string) session('error'));
    }

    /**
     * EL PORCENTAJE SE TRUNCA Y NO SE REDONDEA.
     *
     * 43 de 54 es 79,62%. Con `round` se pintaria «80%» y el boton no saldria:
     * quien lo lee ve el numero que pide el papel y ningun sitio donde pulsar,
     * que es el «el botón no hizo nada» que este proyecto ya pago una vez.
     */
    public function test_el_porcentaje_que_se_ensena_no_contradice_al_boton(): void
    {
        [, $inscrito] = $this->cursoCon(asistidas: 43, faltadas: 11);

        $resumen = AsistenciaDeActividad::deInscrito($inscrito);

        $this->assertSame(79, $resumen['porcentaje'], '79,62% truncado es 79, no 80');
        $this->assertFalse($resumen['certificable']);
    }

    // ------------------------------------------------------------------
    // Quien lo saca
    // ------------------------------------------------------------------

    public function test_lo_saca_el_responsable_el_director_y_el_administrador(): void
    {
        [$curso, $inscrito] = $this->cursoCon(asistidas: 10, faltadas: 0);

        foreach ([$this->profesor, $this->director, $this->admin] as $quien) {
            $this->assertEsPdf($this->bajar($curso, $inscrito, $quien));
        }
    }

    /**
     * Otro profesor no, y un estudiante tampoco.
     *
     * 404 y no 403, como los otros dos certificados: que exista o no esta
     * actividad tampoco es asunto de quien pregunta.
     */
    public function test_quien_no_tiene_que_ver_con_la_actividad_no_lo_saca(): void
    {
        [$curso, $inscrito] = $this->cursoCon(asistidas: 10, faltadas: 0);

        $this->bajar($curso, $inscrito, $this->otroProfesor)->assertNotFound();
        $this->bajar($curso, $inscrito, $this->estudiante)->assertNotFound();
    }

    /**
     * Y el inscrito de OTRA actividad no sale con este papel.
     *
     * Lo sostiene `scopeBindings()` en la ruta. Sin el, el id de un inscrito
     * ajeno se resolveria igual y el permiso no lo veria —mira la ACTIVIDAD, y
     * esa si es suya—, asi que saldria un certificado con el nombre de alguien
     * que no estuvo en el curso que se esta certificando.
     */
    public function test_un_inscrito_de_otra_actividad_no_se_certifica_con_este_curso(): void
    {
        [$curso] = $this->cursoCon(asistidas: 10, faltadas: 0);

        $otro = $this->curso('Taller de vitral');
        $ajeno = $this->inscribir($otro, 'Persona Ajena');

        $this->bajar($curso, $ajeno)->assertNotFound();
    }

    /** Un grupo de proyeccion no da certificado, aunque cumpla de sobra. */
    public function test_el_grupo_de_proyeccion_no_da_certificado(): void
    {
        $banda = Actividad::create([
            'tipo' => Actividad::PROYECCION,
            'nombre' => 'Banda sinfónica',
            'responsable_id' => $this->profesor->id,
            'periodo_id' => $this->periodo->id,
        ]);

        $inscrito = $this->inscribir($banda, 'Ana Ruiz');

        foreach (range(1, 10) as $n) {
            $this->marcar($this->sesion($banda, $n), $inscrito, AsistenciaActividad::ASISTIO);
        }

        $this->bajar($banda, $inscrito)->assertNotFound();
    }

    // ------------------------------------------------------------------
    // El papel
    // ------------------------------------------------------------------

    public function test_lo_que_se_descarga_es_un_pdf_de_una_hoja(): void
    {
        [$curso, $inscrito] = $this->cursoCon(asistidas: 10, faltadas: 0);

        $respuesta = $this->bajar($curso, $inscrito);

        $this->assertEsPdf($respuesta);
        $this->assertSame(1, $this->hojas((string) $respuesta->getContent()));
    }

    /**
     * Y es APAISADO de verdad.
     *
     * Se lee del `/MediaBox`, que es donde dompdf escribe el tamano real del
     * papel: en apaisado el ancho es el lado largo. Sin esta prueba, quitar el
     * segundo argumento de `setPaper()` no rompe nada — sale un PDF perfecto y
     * vertical, que no es lo que se pidio.
     */
    public function test_el_papel_es_carta_horizontal(): void
    {
        [$curso, $inscrito] = $this->cursoCon(asistidas: 10, faltadas: 0);

        $pdf = (string) $this->bajar($curso, $inscrito)->getContent();

        $this->assertSame(
            1,
            preg_match('#/MediaBox\s*\[\s*[\d.]+\s+[\d.]+\s+([\d.]+)\s+([\d.]+)#', $pdf, $lados),
            'no se pudo leer el tamaño del papel'
        );

        [$ancho, $alto] = [round((float) $lados[1]), round((float) $lados[2])];

        $this->assertSame(792.0, $ancho, "el ancho es {$ancho} pt: el papel salió vertical");
        $this->assertSame(612.0, $alto);
    }

    /**
     * CABE EN UNA HOJA, con holgura medida y no supuesta.
     *
     * El caso peor es el que mas texto mete: nombre largo, documento, responsable
     * con nombre largo y las dos fechas, que es lo unico que crece con el tipo de
     * actividad.
     */
    public function test_el_certificado_cabe_en_una_hoja_apaisada(): void
    {
        $pide = $this->altoQuePide();

        $this->assertLessThanOrEqual(
            self::CARTA_APAISADA,
            $pide,
            "el certificado pide {$pide} pt y la carta apaisada son ".self::CARTA_APAISADA
            .' pt: sale en dos hojas, y la segunda se lleva la firma sola.'
        );

        // Y con margen, por lo mismo que el consentimiento: uno que cabe por dos
        // puntos se parte en cuanto llegue un nombre mas largo.
        $this->assertLessThanOrEqual(
            self::CARTA_APAISADA - 15,
            $pide,
            'el certificado cabe por menos de 15 pt: cualquier nombre largo lo parte.'
        );
    }

    /**
     * Y no pesa.
     *
     * El recorte de fuente del 09/09 lo hereda gratis —esta en
     * `config/dompdf.php`— pero una prueba que lo afirme aqui es lo que avisa el
     * dia que alguien meta una imagen sin acotar en esta plantilla. El de
     * matricula bajo de 911 KB a 60 con ese recorte.
     */
    public function test_el_certificado_no_pesa(): void
    {
        [$curso, $inscrito] = $this->cursoCon(asistidas: 10, faltadas: 0);

        $bytes = strlen((string) $this->bajar($curso, $inscrito)->getContent());

        $this->assertLessThan(
            120 * 1024,
            $bytes,
            'el certificado pesa '.round($bytes / 1024).' KB: se le está incrustando algo sin acotar.'
        );
    }

    // ------------------------------------------------------------------
    // Lo que dice
    // ------------------------------------------------------------------

    public function test_el_papel_dice_las_sesiones_y_la_asistencia(): void
    {
        $html = $this->pintar(sesiones: 10, asistidas: 9);

        $this->assertStringContainsString('Ana Ruiz', $html);
        $this->assertStringContainsString('Curso de tiple', $html);
        // Diez dictadas, nueve asistidas, 90%.
        $this->assertMatchesRegularExpression('/Clases dictadas.*?10/s', $html);
        $this->assertMatchesRegularExpression('/Asistencias.*?9/s', $html);
        $this->assertStringContainsString('90%', $html);
    }

    /**
     * Las fechas salen cuando hay MAS DE UNA sesion, que es lo que se pidio.
     *
     * En un taller de un dia, «del 3 de marzo al 3 de marzo» es ruido y ademas
     * repite lo que ya dice el renglon de al lado.
     *
     * ESTAS DOS PRUEBAS PASAN POR LA PETICION, no por `pintar()`. La primera
     * version las escribio renderizando la plantilla con datos que fabricaba
     * este mismo archivo —y la fabricacion repetia la condicion `> 1`—, o sea
     * que median el andamiaje y no el controlador: con las fechas saliendo
     * SIEMPRE seguian verdes. Se vio al sabotearlo, que es para lo que se
     * sabotea.
     */
    public function test_con_varias_sesiones_el_papel_dice_entre_que_fechas(): void
    {
        [$curso, $inscrito] = $this->cursoCon(asistidas: 10, faltadas: 0);

        $datos = $this->datosQueRecibeLaPlantilla($curso, $inscrito);

        $this->assertNotNull($datos['fechas']);
        $this->assertCount(2, $datos['fechas']);
        // Y son las de la primera y la ultima sesion CON LISTA, que es lo que
        // paso — no las que se programaron al crear el curso.
        $this->assertSame(
            Carbon::today()->subMonths(2)->addDay()->toDateString(),
            $datos['fechas'][0]->toDateString()
        );
        $this->assertSame(
            Carbon::today()->subMonths(2)->addDays(10)->toDateString(),
            $datos['fechas'][1]->toDateString()
        );
    }

    public function test_con_una_sola_sesion_no_dice_fechas(): void
    {
        [$curso, $inscrito] = $this->cursoCon(asistidas: 1, faltadas: 0);

        $this->assertNull($this->datosQueRecibeLaPlantilla($curso, $inscrito)['fechas']);
    }

    /** Y con fechas puestas, la plantilla las imprime. */
    public function test_la_plantilla_imprime_las_fechas_que_recibe(): void
    {
        $this->assertStringContainsString('entre el', $this->pintar(sesiones: 10, asistidas: 10));
        $this->assertStringNotContainsString('entre el', $this->pintar(sesiones: 1, asistidas: 1));
    }

    // ------------------------------------------------------------------
    // La pantalla
    // ------------------------------------------------------------------

    public function test_la_pantalla_pinta_el_enlace_a_quien_llega_y_el_motivo_a_quien_no(): void
    {
        $curso = $this->curso();
        $llega = $this->inscribir($curso, 'Ana Ruiz');
        $noLlega = $this->inscribir($curso, 'Beto Mesa', '77776666');

        foreach (range(1, 10) as $n) {
            $sesion = $this->sesion($curso, $n);
            $this->marcar($sesion, $llega, AsistenciaActividad::ASISTIO);
            $this->marcar($sesion, $noLlega, $n <= 5 ? AsistenciaActividad::ASISTIO : 'falto');
        }

        $respuesta = $this->actingAs($this->profesor->user)
            ->get(route('panel-actividad', $curso))
            ->assertOk();

        $respuesta->assertSee(route('certificado-actividad', [$curso, $llega]), false);
        $respuesta->assertDontSee(route('certificado-actividad', [$curso, $noLlega]), false);
        $respuesta->assertSee('Menos del 80%');
        // Y la cifra de cada uno, que es lo que explica la diferencia.
        $respuesta->assertSee('10 de 10');
        $respuesta->assertSee('5 de 10');
    }

    /**
     * La tabla de inscritos se vuelve ficha en el telefono.
     *
     * PHPUnit no tiene navegador y no puede medir un ancho, asi que lo que se
     * vigila es que las marcas sigan puestas: `.tabla-personas` y
     * `data-celda="accion"`. Quien las quite «porque no cambian nada» tendra
     * razon en escritorio y habra dejado el boton al otro lado de un arrastre
     * horizontal en el unico sitio donde de verdad se usa.
     */
    public function test_la_tabla_de_inscritos_se_toca_en_el_telefono(): void
    {
        [$curso] = $this->cursoCon(asistidas: 10, faltadas: 0);

        $html = (string) $this->actingAs($this->profesor->user)
            ->get(route('panel-actividad', $curso))
            ->getContent();

        $this->assertStringContainsString('tabla-personas', $html);
        $this->assertStringContainsString('data-celda="accion"', $html);
    }

    // ------------------------------------------------------------------
    // Andamiaje
    // ------------------------------------------------------------------

    /**
     * Un curso con una sola persona, que asistio a unas y falto a otras.
     *
     * @return array{0: Actividad, 1: InscritoActividad}
     */
    private function cursoCon(int $asistidas, int $faltadas, int $excusadas = 0): array
    {
        $curso = $this->curso();
        $inscrito = $this->inscribir($curso, 'Ana Ruiz');

        $n = 0;

        // Un `for` y no `range(1, $cuantas)`: en PHP `range(1, 0)` devuelve
        // [1, 0], o sea DOS vueltas, asi que «cero faltas» sembraba dos
        // sesiones fantasma y descuadraba todos los porcentajes de este archivo.
        foreach ([
            [AsistenciaActividad::ASISTIO, $asistidas],
            ['falto', $faltadas],
            ['excusa', $excusadas],
        ] as [$estado, $cuantas]) {
            for ($i = 0; $i < $cuantas; $i++) {
                $this->marcar($this->sesion($curso, ++$n), $inscrito, $estado);
            }
        }

        return [$curso, $inscrito];
    }

    private function curso(string $nombre = 'Curso de tiple'): Actividad
    {
        return Actividad::create([
            'tipo' => Actividad::CURSO,
            'nombre' => $nombre,
            'responsable_id' => $this->profesor->id,
            'periodo_id' => $this->periodo->id,
        ]);
    }

    private function inscribir(Actividad $actividad, string $nombre, string $documento = '10203040'): InscritoActividad
    {
        return InscritoActividad::create([
            'actividad_id' => $actividad->id,
            'nombre_completo' => $nombre,
            'documento' => $documento,
            'origen' => InscritoActividad::ENLACE,
        ]);
    }

    private function sesion(Actividad $actividad, int $n): SesionActividad
    {
        return SesionActividad::create([
            'actividad_id' => $actividad->id,
            'fecha' => Carbon::today()->subMonths(2)->addDays($n)->toDateString(),
            'iniciada_en' => now(),
            'iniciada_por_id' => $this->profesor->id,
        ]);
    }

    private function marcar(SesionActividad $sesion, InscritoActividad $inscrito, string $estado): void
    {
        AsistenciaActividad::create([
            'sesion_id' => $sesion->id,
            'inscrito_id' => $inscrito->id,
            'estado' => $estado,
        ]);
    }

    private function bajar(Actividad $actividad, InscritoActividad $inscrito, ?Perfil $quien = null): TestResponse
    {
        return $this->actingAs(($quien ?? $this->profesor)->user)
            ->get(route('certificado-actividad', [$actividad, $inscrito]));
    }

    /**
     * Lo que el CONTROLADOR le pasa a la plantilla, capturado al vuelo.
     *
     * Es la unica forma de afirmar algo sobre una decision del controlador que
     * acaba dentro de un PDF: el texto de un PDF con la fuente recortada no se
     * lee con un `assertSee`.
     *
     * @return array<string, mixed>
     */
    private function datosQueRecibeLaPlantilla(Actividad $actividad, InscritoActividad $inscrito): array
    {
        $capturado = [];

        View::composer('certificados.actividad', function ($vista) use (&$capturado) {
            $capturado = $vista->getData();
        });

        $this->bajar($actividad, $inscrito)->assertOk();

        $this->assertNotSame([], $capturado, 'la plantilla del certificado no llegó a renderizarse');

        return $capturado;
    }

    /** La vista tal cual, sin pasar por dompdf, para poder leer el texto. */
    private function pintar(int $sesiones, int $asistidas): string
    {
        return view('certificados.actividad', $this->datos($sesiones, $asistidas))->render();
    }

    /**
     * La hoja MINIMA donde el certificado sigue cabiendo en una pagina.
     *
     * El ancho se deja fijo en el de la carta apaisada y se busca el alto: es el
     * alto lo que decide si el bloque de la firma —que lleva
     * `page-break-inside: avoid`— se MUEVE entero a una segunda hoja.
     */
    private function altoQuePide(): float
    {
        $bajo = 200.0;
        $alto = 900.0;

        while ($alto - $bajo > 1) {
            $medio = ($alto + $bajo) / 2;

            $pdf = Pdf::loadView('certificados.actividad', $this->datos(10, 9, largo: true))
                ->setPaper([0, 0, 792, $medio])
                ->output();

            if ($this->hojas($pdf) === 1) {
                $alto = $medio;
            } else {
                $bajo = $medio;
            }
        }

        return round($alto);
    }

    /**
     * Los datos de la plantilla.
     *
     * Con `$largo` puesto es el CASO PEOR: nombre de persona largo, de curso
     * largo, de responsable largo y de institucion largo. Es lo que hay que
     * medir — un certificado que cabe con «Ana Ruiz» y no con un nombre real no
     * sirve de nada.
     *
     * @return array<string, mixed>
     */
    private function datos(int $sesiones, int $asistidas, bool $largo = false): array
    {
        $institucion = ConfiguracionInstitucion::actual();

        if ($largo) {
            $institucion->nombre_institucion = 'Casa de la Cultura Luis Norberto Gómez de El Santuario, Antioquia';
            $institucion->firmante_nombre = 'María Fernanda Restrepo Gutiérrez de Piñeres';
            $institucion->firmante_cargo = 'Directora de la Casa de la Cultura Municipal';
        }

        $curso = new Actividad([
            'tipo' => Actividad::CURSO,
            'nombre' => $largo
                ? 'Curso de iniciación musical en instrumentos de cuerda pulsada andina'
                : 'Curso de tiple',
        ]);
        $curso->setRelation('responsable', new Perfil([
            'nombre_completo' => $largo ? 'Juan Sebastián Villegas Montoya de la Cuesta' : 'Inés Pérez',
        ]));

        $inscrito = new InscritoActividad([
            'nombre_completo' => $largo ? 'María de los Ángeles Restrepo Villegas de Gómez' : 'Ana Ruiz',
            'documento' => '1036261209',
        ]);

        $proporcion = $sesiones > 0 ? $asistidas / $sesiones : 0;

        return [
            'institucion' => $institucion,
            'actividad' => $curso,
            'inscrito' => $inscrito,
            'asistencia' => [
                'sesiones' => $sesiones,
                'asistidas' => $asistidas,
                'porcentaje' => (int) floor($proporcion * 100),
                'certificable' => $proporcion >= AsistenciaDeActividad::MINIMO,
            ],
            'fechas' => $sesiones > 1
                ? [Carbon::today()->subMonths(2), Carbon::today()]
                : null,
            'expedido' => Carbon::now(),
            'logo' => null,
            'firma' => null,
        ];
    }

    /** Cuantas hojas tiene un PDF: los objetos `/Type /Page`, sin los `/Pages`. */
    private function hojas(string $pdf): int
    {
        return preg_match_all('#/Type\s*/Page[^s]#', $pdf);
    }

    private function assertEsPdf(TestResponse $respuesta): void
    {
        $respuesta->assertOk();
        $this->assertStringContainsString(
            'application/pdf',
            (string) $respuesta->headers->get('content-type')
        );
    }

    private function perfil(string $username, string $rol): Perfil
    {
        $user = User::create(['username' => $username, 'password' => 'x', 'activo' => true]);

        return Perfil::create([
            'user_id' => $user->id,
            'nombre_completo' => ucfirst($username).' Pérez',
            'rol' => $rol,
            'telefono' => '3001112233',
            'fecha_nacimiento' => Carbon::today()->subYears(30),
        ]);
    }
}
