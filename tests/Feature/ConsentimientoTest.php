<?php

namespace Tests\Feature;

use App\Models\Acudiente;
use App\Models\ConfiguracionInstitucion;
use App\Models\DatosEstudiante;
use App\Models\DocumentoRequerido;
use App\Models\Perfil;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * El formato de autorizacion de tratamiento de datos y uso de imagen.
 *
 * Es el unico papel de los que pide la institucion que el sistema IMPRIME: los
 * demas requeridos son ranuras donde el estudiante sube algo que ya tiene.
 *
 * LO QUE VIGILA ESTE ARCHIVO:
 *
 * 1. QUE HAYA DOS VERSIONES Y QUE SE ELIJA SOLA. Un menor de edad no otorga
 *    esta autorizacion por si mismo (Ley 1581 de 2012, art. 7): la da su
 *    acudiente. Si el formato del menor no pide quien firma en su
 *    representacion, lo que se recoge no es un consentimiento valido, y eso no
 *    se nota mirando el PDF por encima — se nota el dia que alguien lo revisa.
 *
 * 2. QUE CABE EN UNA HOJA. No es estetica: un formato de dos hojas se firma en
 *    la primera y la segunda se pierde. Y no basta con contar hojas, porque
 *    contar solo dice si o no: se mide el ALTO que pide, que es lo que dice
 *    cuanta holgura queda. El certificado ya se paso una vez por SIETE puntos
 *    con ocho pruebas en verde, todas comprobando que era un PDF.
 *
 * 3. QUE LA DESCARGA CUELGA DE LA COLUMNA `plantilla` Y NO DEL NOMBRE. El
 *    nombre de un requerido lo edita la entidad; el dia que lo renombren, un
 *    boton que dependiera del nombre desapareceria sin que nada fallara.
 *
 * 4. QUIEN PUEDE BAJAR CADA COSA.
 */
class ConsentimientoTest extends TestCase
{
    use RefreshDatabase;

    /** El alto de una carta en puntos. Lo que no cabe aqui sale en dos hojas. */
    private const CARTA = 792.0;

    private Perfil $admin;

    private Perfil $mayor;

    private Perfil $menor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->perfil('jefa', 'administrador');
        $this->mayor = $this->estudiante('ana', 30);
        $this->menor = $this->estudiante('tomas', 12, 'Marta Restrepo de Gómez');
    }

    // ------------------------------------------------------------------
    // Las dos versiones
    // ------------------------------------------------------------------

    /**
     * Un mayor de edad baja el formato que firma el mismo.
     *
     * Se comprueba sobre la VISTA y no sobre el PDF, y es deliberado: dentro de
     * un PDF el texto va comprimido y no se puede leer sin meter una libreria
     * de lectura en `composer.json` para una sola prueba. La vista es lo que
     * dompdf convierte, asi que lo que se afirma aqui es lo que sale impreso.
     */
    public function test_el_formato_de_mayor_de_edad_lo_firma_la_propia_persona(): void
    {
        $html = $this->pintar(esMenor: false);

        $this->assertStringContainsString('Quién autoriza', $html);
        $this->assertStringNotContainsString(
            'Nombre completo del acudiente',
            $html,
            'al mayor de edad se le pide un acudiente.'
        );
        $this->assertStringNotContainsString('en representación del menor', $html);
    }

    /** Y el de un menor lo firma su acudiente, en representacion suya. */
    public function test_el_formato_de_menor_lo_firma_el_acudiente(): void
    {
        $html = $this->pintar(esMenor: true);

        $this->assertStringContainsString('menor de edad', $html);
        $this->assertStringContainsString('padre, madre o acudiente', $html);
        $this->assertStringContainsString('en representación del menor', $html);
        // El acudiente se identifica: un nombre suelto no dice en que calidad
        // actua quien firma por otra persona.
        $this->assertStringContainsString('Parentesco o calidad en que actúa', $html);
    }

    /**
     * LAS DOS AUTORIZACIONES VAN SEPARADAS, cada una con su par de casillas.
     *
     * No es maquetacion: son finalidades distintas y la del uso de la imagen no
     * puede condicionar la matricula, asi que tiene que poder negarse sin negar
     * la otra. Un solo «acepto todo» al pie dejaria la negativa sin sitio donde
     * escribirse, y con eso la autorizacion entera deja de valer.
     */
    public function test_las_dos_autorizaciones_se_marcan_por_separado(): void
    {
        $html = $this->pintar(esMenor: false);

        $this->assertStringContainsString('análisis estadístico y la planeación', $html);
        $this->assertStringContainsString('comunicar y promocionar los procesos formativos', $html);

        // El formato que se FIRMA tampoco da por hecho nada sobre la entidad,
        // por lo mismo que la politica: se vende a instituciones que no son
        // culturales ni publicas, y este es el papel que sale impreso de la
        // casa y se firma a mano — donde peor se veria el error.
        $this->assertStringNotContainsString('sector cultura', $html);
        $this->assertStringNotContainsString('culturales', $html);
        $this->assertStringNotContainsString('políticas públicas', $html);
        $this->assertStringNotContainsString('entidades públicas', $html);

        // Cuatro casillas: sí/no para cada una de las dos.
        $this->assertSame(4, substr_count($html, 'class="casilla"'), 'no hay un sí y un no por autorización.');

        // Trozos cortos y de UNA linea: la plantilla parte las frases al
        // maquetar, asi que una cita larga no encaja aunque el texto este.
        $this->assertStringContainsString('Las dos autorizaciones son independientes', $html);
        $this->assertStringContainsString('no afecta la matrícula', $html);
    }

    /** El logo y el nombre de la institucion salen de la configuracion. */
    public function test_lleva_el_logo_y_el_nombre_editables_de_la_institucion(): void
    {
        $configuracion = ConfiguracionInstitucion::actual();
        $configuracion->nombre_institucion = 'Casa de la Cultura de El Santuario';
        $configuracion->save();

        $html = $this->pintar(esMenor: false, logo: 'data:image/png;base64,XXXX');

        $this->assertStringContainsString('Casa de la Cultura de El Santuario', $html);
        $this->assertStringContainsString('data:image/png;base64,XXXX', $html);
    }

    /** Y dice dónde leer la política, porque el papel viaja impreso. */
    public function test_el_formato_dice_donde_esta_la_politica(): void
    {
        $html = $this->pintar(esMenor: false);

        $this->assertStringContainsString(route('politica-datos'), $html);
        $this->assertStringContainsString('Ley 1581 de 2012', $html);
    }

    // ------------------------------------------------------------------
    // Que quepa en una hoja
    // ------------------------------------------------------------------

    /**
     * LAS DOS VERSIONES CABEN EN UNA HOJA, y con holgura medida.
     *
     * La sonda busca por biseccion la hoja MINIMA donde el formato sigue
     * cabiendo en una pagina. Contar hojas solo dice si o no; esto dice por
     * cuanto, que es lo unico que avisa de que se esta acercando al borde. El
     * caso peor es el del menor con acudiente, que lleva un bloque de datos
     * mas.
     *
     * Si esta prueba se pone roja tras tocar la plantilla, el numero que
     * devuelve dice cuanto hay que recortar.
     */
    public function test_las_dos_versiones_caben_en_una_hoja(): void
    {
        foreach ([false, true] as $esMenor) {
            $pide = $this->altoQuePide($esMenor);
            $version = $esMenor ? 'menor' : 'mayor';

            $this->assertLessThanOrEqual(
                self::CARTA,
                $pide,
                "el formato de {$version} de edad pide {$pide} pt y la carta son ".self::CARTA
                .' pt: sale en dos hojas, y la segunda se pierde al firmarlo.'
            );

            // Y con margen. Un formato que cabe por dos puntos se sale en cuanto
            // alguien tenga un nombre largo o la entidad uno mas largo aun.
            $this->assertLessThanOrEqual(
                self::CARTA - 15,
                $pide,
                "el formato de {$version} cabe por menos de 15 pt: cualquier nombre largo lo parte."
            );
        }
    }

    /** Y lo que se descarga es un PDF de verdad, de una sola hoja. */
    public function test_lo_que_se_descarga_es_un_pdf_de_una_hoja(): void
    {
        $respuesta = $this->actingAs($this->menor->user)->get(route('consentimiento'));

        $this->assertEsPdf($respuesta);
        $this->assertSame(1, $this->hojas((string) $respuesta->getContent()));
    }

    // ------------------------------------------------------------------
    // Quien baja que
    // ------------------------------------------------------------------

    /** Cada estudiante baja el suyo, con sus datos. */
    public function test_el_estudiante_baja_su_propio_formato(): void
    {
        $this->assertEsPdf($this->actingAs($this->mayor->user)->get(route('consentimiento')));
        $this->assertEsPdf($this->actingAs($this->menor->user)->get(route('consentimiento')));
    }

    /**
     * El personal no: no tiene ranura donde devolverlo.
     *
     * Los documentos cuelgan de `datos_estudiante`, asi que un profesor que
     * bajara el formato no tendria despues donde subirlo. 404 y no 403 por lo
     * mismo que las fotos: no es una cuestion de permiso sino de que ahi no hay
     * nada.
     */
    public function test_el_personal_no_baja_el_formato_propio(): void
    {
        $profesor = $this->perfil('luis', 'profesor');

        $this->actingAs($profesor->user)->get(route('consentimiento'))->assertNotFound();
        $this->actingAs($this->admin->user)->get(route('consentimiento'))->assertNotFound();
    }

    /** El formato EN BLANCO es de direccion, para imprimir y repartir. */
    public function test_el_formato_en_blanco_es_de_direccion(): void
    {
        foreach (['mayor', 'menor'] as $tipo) {
            $this->assertEsPdf(
                $this->actingAs($this->admin->user)->get(route('consentimiento-formato', $tipo))
            );
        }
    }

    /**
     * Y no de quien no dirige.
     *
     * Rebota con un aviso en vez de dar 403: es lo que hace `RequiereRol` en
     * todo el sistema, y esta prueba lo afirma tal como es para que se ponga
     * roja si algun dia esta ruta deja de pasar por esa puerta.
     */
    public function test_el_formato_en_blanco_no_lo_baja_cualquiera(): void
    {
        foreach ([$this->perfil('luis', 'profesor'), $this->mayor] as $quien) {
            $this->actingAs($quien->user)
                ->get(route('consentimiento-formato', 'mayor'))
                ->assertRedirect(route('post-login'))
                ->assertSessionHas('error');
        }
    }

    /** Una version inventada en la URL no existe. */
    public function test_una_version_que_no_existe_da_404(): void
    {
        $this->actingAs($this->admin->user)
            ->get(route('consentimiento-formato', 'abuelo'))
            ->assertNotFound();
    }

    /** Sin sesion no se baja ninguno. */
    public function test_sin_sesion_no_se_baja_nada(): void
    {
        $this->get(route('consentimiento'))->assertRedirect(route('login'));
        $this->get(route('consentimiento-formato', 'mayor'))->assertRedirect(route('login'));
    }

    // ------------------------------------------------------------------
    // La ranura de «Mi perfil»
    // ------------------------------------------------------------------

    /**
     * LA DESCARGA CUELGA DE `plantilla`, NO DEL NOMBRE.
     *
     * Es la prueba que parece de mas y es la que sostiene la decision: el
     * nombre de un requerido lo edita la entidad desde Institucion —para eso se
     * hizo configurable el 05/09/2026— y con un boton que dependiera del nombre,
     * renombrarlo lo haria desaparecer sin que nada fallara.
     */
    public function test_el_boton_de_descarga_sobrevive_a_que_lo_renombren(): void
    {
        $requerido = DocumentoRequerido::create([
            'nombre' => DocumentoRequerido::CONSENTIMIENTO,
            'plantilla' => DocumentoRequerido::FORMATO_CONSENTIMIENTO,
        ]);

        $this->assertStringContainsString(
            route('consentimiento'),
            $this->miPerfilDe($this->mayor),
            'sin botón de descarga en la ranura del consentimiento.'
        );

        $requerido->nombre = 'Autorización de datos (versión 2027)';
        $requerido->save();

        $this->assertStringContainsString(
            route('consentimiento'),
            $this->miPerfilDe($this->mayor),
            'renombrar el requerido se llevó el botón de descarga.'
        );
    }

    /** Y los demas papeles NO llevan descarga: no hay nada que imprimir. */
    public function test_los_demas_papeles_no_llevan_descarga(): void
    {
        DocumentoRequerido::create(['nombre' => 'Certificado de EPS']);

        $html = $this->miPerfilDe($this->mayor);

        $this->assertStringContainsString('Certificado de EPS', $html);
        $this->assertStringNotContainsString(route('consentimiento'), $html);
    }

    // ------------------------------------------------------------------
    // Andamiaje
    // ------------------------------------------------------------------

    /**
     * El alto minimo, en puntos, donde el formato todavia cabe en UNA hoja.
     *
     * Biseccion sobre el alto del papel: se genera el PDF con hojas cada vez
     * mas cortas hasta dar con la frontera. Es el mismo metodo que usa
     * `CertificadoTest`, y existe por lo mismo — el certificado se pasaba por
     * siete puntos y ninguna de sus ocho pruebas lo veia.
     */
    private function altoQuePide(bool $esMenor): float
    {
        $bajo = 500.0;
        $alto = 1100.0;

        while ($alto - $bajo > 1) {
            $medio = ($alto + $bajo) / 2;

            if ($this->hojas($this->generar($esMenor, $medio)) === 1) {
                $alto = $medio;
            } else {
                $bajo = $medio;
            }
        }

        return round($alto);
    }

    /** El PDF del caso peor, sobre un papel del alto que se pida. */
    private function generar(bool $esMenor, float $altoDelPapel): string
    {
        $estudiante = $esMenor ? $this->menor : $this->mayor;

        return Pdf::loadView('certificados.consentimiento', $this->datos($esMenor, $estudiante))
            ->setPaper([0, 0, 612, $altoDelPapel])
            ->output();
    }

    /** La vista tal cual, sin pasar por dompdf, para poder leer el texto. */
    private function pintar(bool $esMenor, ?string $logo = null): string
    {
        $estudiante = $esMenor ? $this->menor : $this->mayor;

        return view('certificados.consentimiento', $this->datos($esMenor, $estudiante, $logo))->render();
    }

    /** @return array<string, mixed> */
    private function datos(bool $esMenor, Perfil $estudiante, ?string $logo = null): array
    {
        return [
            'institucion' => ConfiguracionInstitucion::actual(),
            'esMenor' => $esMenor,
            'estudiante' => $estudiante,
            'documento' => $estudiante->datosEstudiante?->documento_identidad ?: null,
            'acudiente' => $esMenor ? $estudiante->datosEstudiante?->acudiente : null,
            // La mas larga que se va a dar en la practica: es la que empuja el
            // bloque legal y, con el, la segunda hoja.
            'politica' => route('politica-datos'),
            'expedido' => Carbon::now(),
            'logo' => $logo,
        ];
    }

    private function miPerfilDe(Perfil $perfil): string
    {
        return (string) $this->actingAs($perfil->user)->get(route('mi-perfil'))->assertOk()->getContent();
    }

    /** Cuantas hojas tiene un PDF: los objetos `/Type /Page`, sin los `/Pages`. */
    private function hojas(string $pdf): int
    {
        return preg_match_all('#/Type\s*/Page[^s]#', $pdf);
    }

    private function assertEsPdf(TestResponse $respuesta): void
    {
        $respuesta->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $respuesta->headers->get('content-type'));

        $contenido = (string) $respuesta->getContent();

        $this->assertStringStartsWith('%PDF-', $contenido);
        $this->assertGreaterThan(1000, strlen($contenido));
    }

    private function estudiante(string $username, int $edad, ?string $acudiente = null): Perfil
    {
        $perfil = $this->perfil($username, 'estudiante', $edad);

        DatosEstudiante::create([
            'perfil_id' => $perfil->id,
            'documento_identidad' => '10'.$perfil->id,
            'acudiente_id' => $acudiente === null
                ? null
                : Acudiente::create(['nombre' => $acudiente, 'telefono' => '3000000000'])->id,
        ]);

        return $perfil->refresh();
    }

    private function perfil(string $username, string $rol, int $edad = 30): Perfil
    {
        $user = User::create(['username' => $username, 'password' => 'x', 'activo' => true]);

        return Perfil::create([
            'user_id' => $user->id,
            'rol' => $rol,
            // Un nombre largo a proposito en las medidas de alto: el caso que
            // parte el formato en dos es el del nombre que envuelve.
            'nombre_completo' => ucfirst($username).' Restrepo Villegas de la Cuesta',
            'fecha_nacimiento' => Carbon::today()->subYears($edad)->toDateString(),
            'telefono' => '3000000000',
        ]);
    }
}
