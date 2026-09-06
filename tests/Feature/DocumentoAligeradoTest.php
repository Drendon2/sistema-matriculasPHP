<?php

namespace Tests\Feature;

use App\Models\Acudiente;
use App\Models\DatosEstudiante;
use App\Models\DocumentoEstudiante;
use App\Models\DocumentoRequerido;
use App\Models\Perfil;
use App\Models\User;
use App\Support\Documento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Los papeles que sube el estudiante se guardan aligerados y en PDF.
 *
 * ─── Por que existe ────────────────────────────────────────────────────────
 *
 * Medido en produccion el 06/09/2026: 70 documentos ocupaban 100 MB, y 56 de
 * ellos eran fotos de celular de 4000x3000 que sumaban 88,5. Esto INVIERTE la
 * decision anterior —«se guarda tal cual llega»— y por eso lo que hay que
 * vigilar no es solo que pese menos, sino que siga sirviendo como documento.
 *
 * ─── LO QUE VIGILA ESTE ARCHIVO ────────────────────────────────────────────
 *
 * 1. QUE EL PDF QUE SE ESCRIBE SEA UN PDF DE VERDAD. Se escribe a mano, sin
 *    libreria: si un byte del `xref` se descuadra, los lectores tolerantes lo
 *    abren igual y los estrictos no, y eso se descubre el dia que alguien de
 *    fuera pide el papel.
 * 2. QUE LOS PDF SUBIDOS NO SE TOQUEN. Recomprimirlos los deja mas grandes
 *    —medido— asi que tienen que llegar al disco byte por byte.
 * 3. QUE MANDE EL CONTENIDO Y NO LA EXTENSION. Las dos las escribe quien sube
 *    el archivo.
 * 4. QUE LA FOTO NO SE GUARDE TUMBADA. La orientacion vive en el EXIF, y si no
 *    se aplica el documento se guarda de lado: no falla, no avisa, y solo se
 *    ve al abrirlo. Es la clase de fallo que este proyecto ya ha pagado.
 * 5. QUE UNA IMAGEN CON TRANSPARENCIA NO SALGA CON EL FONDO NEGRO. Pasar a
 *    JPEG sin aplanar hace exactamente eso, y una captura de pantalla de un
 *    documento es el caso corriente.
 */
class DocumentoAligeradoTest extends TestCase
{
    use RefreshDatabase;

    private Perfil $ana;

    private DocumentoRequerido $requerido;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->ana = $this->estudiante('ana');
        $this->requerido = DocumentoRequerido::create(['nombre' => 'Documento de identidad']);
    }

    // ------------------------------------------------------------------
    // Lo que se guarda
    // ------------------------------------------------------------------

    /** Una foto subida se guarda como PDF, y como un PDF que abre. */
    public function test_una_foto_se_guarda_como_pdf(): void
    {
        $this->subir($this->foto(2400, 1800));

        $ruta = $this->rutaGuardada();

        $this->assertStringEndsWith('.pdf', $ruta, 'no se guardó como PDF.');

        $pdf = Storage::disk('local')->get($ruta);

        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertStringContainsString("\nxref\n", $pdf, 'el PDF no trae tabla de referencias.');
        $this->assertStringContainsString('/Root 1 0 R', $pdf);
        $this->assertStringEndsWith("%%EOF\n", $pdf, 'el PDF no está cerrado.');
    }

    /**
     * Y pesa mucho menos.
     *
     * El umbral es flojo —la mitad— a proposito: lo que se afirma es que la
     * conversion sirve para algo, no un porcentaje concreto que dependeria del
     * ruido de la imagen de prueba y se rompería sin que nada estuviera mal.
     * Sobre las fotos reales de produccion el ahorro medido fue del 80%.
     */
    public function test_el_pdf_pesa_mucho_menos_que_la_foto(): void
    {
        $foto = $this->foto(4000, 3000);
        $pesoOriginal = strlen((string) file_get_contents($foto->getRealPath()));

        $this->subir($foto);

        $pesoPdf = strlen(Storage::disk('local')->get($this->rutaGuardada()));

        $this->assertLessThan(
            $pesoOriginal / 2,
            $pesoPdf,
            "el PDF pesa {$pesoPdf} y la foto {$pesoOriginal}: la conversión no está aligerando."
        );
    }

    /** La imagen se reduce a 2000 px de lado mayor, ni mas ni menos. */
    public function test_la_imagen_se_acota_al_lado_maximo(): void
    {
        $this->subir($this->foto(4000, 3000));

        [$ancho, $alto] = $this->medidasDentroDelPdf($this->rutaGuardada());

        $this->assertSame(Documento::LADO_MAXIMO, $ancho);
        $this->assertSame(1500, $alto, 'no se conservó la proporción.');
    }

    /** Una foto pequena NO se agranda: solo pesaria mas y se veria peor. */
    public function test_una_foto_pequena_no_se_agranda(): void
    {
        $this->subir($this->foto(800, 600));

        [$ancho, $alto] = $this->medidasDentroDelPdf($this->rutaGuardada());

        $this->assertSame(800, $ancho);
        $this->assertSame(600, $alto);
    }

    /**
     * UN PDF SUBIDO LLEGA AL DISCO BYTE POR BYTE.
     *
     * Recomprimirlos los deja MAS grandes —medido sobre los de produccion: uno
     * de 480 KB salia en 5.832— y solo encogen bajando a una resolucion donde
     * un numero de cedula deja de leerse. Asi que no se tocan, y esta prueba
     * es lo que impide que alguien los meta en el mismo saco «por coherencia».
     */
    public function test_un_pdf_subido_no_se_toca(): void
    {
        $original = $this->pdfDePrueba();

        $this->subir(UploadedFile::fake()->createWithContent('escaneo.pdf', $original));

        $this->assertSame(
            $original,
            Storage::disk('local')->get($this->rutaGuardada()),
            'el PDF subido se modificó al guardarlo.'
        );
    }

    /**
     * MANDA EL CONTENIDO, NO LA EXTENSION.
     *
     * La extension y el tipo que declara el navegador los escribe quien sube el
     * archivo. Una foto renombrada a «.pdf» se convierte igual.
     */
    public function test_una_foto_disfrazada_de_pdf_se_convierte_igual(): void
    {
        // El `UploadedFile` se guarda en una variable y no se encadena: su
        // archivo temporal vive lo que viva el objeto, y encadenando se borra
        // antes de poder leerlo.
        $subida = $this->foto(2400, 1800);
        $foto = (string) file_get_contents((string) $subida->getRealPath());

        $this->subir(UploadedFile::fake()->createWithContent('cedula.pdf', $foto));

        $guardado = Storage::disk('local')->get($this->rutaGuardada());

        $this->assertStringStartsWith('%PDF-', $guardado);
        $this->assertLessThan(strlen($foto), strlen($guardado));
    }

    // ------------------------------------------------------------------
    // Que el documento siga sirviendo como documento
    // ------------------------------------------------------------------

    /**
     * LA FOTO NO SE GUARDA TUMBADA.
     *
     * El celular guarda la foto como la leyo el sensor y anota en el EXIF cuanto
     * hay que girarla. Sin aplicar ese giro, un documento vertical se guarda de
     * lado: no falla nada, no avisa nadie, y solo se ve al abrirlo — que es
     * justo la forma de los fallos que este proyecto ya ha pagado.
     *
     * La imagen entra vertical (600x900) con orientacion 6, que significa «gira
     * un cuarto a la derecha». Enderezada tiene que salir apaisada.
     */
    public function test_una_foto_con_giro_en_el_exif_se_endereza(): void
    {
        $vertical = $this->foto(600, 900);
        $conExif = self::conOrientacionExif(
            (string) file_get_contents($vertical->getRealPath()),
            6
        );

        $this->subir(UploadedFile::fake()->createWithContent('cedula.jpg', $conExif));

        [$ancho, $alto] = $this->medidasDentroDelPdf($this->rutaGuardada());

        $this->assertSame(900, $ancho, 'la foto se guardó sin enderezar: quedó de lado.');
        $this->assertSame(600, $alto);
    }

    /**
     * UNA IMAGEN CON TRANSPARENCIA NO SALE CON EL FONDO NEGRO.
     *
     * Pasar a JPEG sin aplanar contra blanco hace exactamente eso, y la captura
     * de pantalla de un documento —un PNG con esquinas transparentes— es el caso
     * corriente. Se comprueba sacando el JPEG de dentro del PDF y mirando un
     * pixel del borde.
     */
    public function test_una_imagen_transparente_se_aplana_sobre_blanco(): void
    {
        $lienzo = imagecreatetruecolor(400, 300);
        imagealphablending($lienzo, false);
        imagesavealpha($lienzo, true);
        imagefill($lienzo, 0, 0, (int) imagecolorallocatealpha($lienzo, 0, 0, 0, 127));

        ob_start();
        imagepng($lienzo);
        $png = (string) ob_get_clean();
        imagedestroy($lienzo);

        $this->subir(UploadedFile::fake()->createWithContent('captura.png', $png));

        $jpeg = $this->jpegDentroDelPdf($this->rutaGuardada());
        $imagen = imagecreatefromstring($jpeg);

        $this->assertNotFalse($imagen);

        $color = imagecolorsforindex($imagen, imagecolorat($imagen, 5, 5));
        imagedestroy($imagen);

        $this->assertGreaterThan(
            200,
            $color['red'],
            'el fondo transparente salió oscuro: falta aplanar contra blanco.'
        );
    }

    // ------------------------------------------------------------------
    // El comando de los que ya estaban subidos
    // ------------------------------------------------------------------

    /** El simulacro NO escribe nada, que es toda su razon de ser. */
    public function test_el_simulacro_no_toca_nada(): void
    {
        $entrega = $this->entregaYaSubida($this->foto(3000, 2000));
        $antes = Storage::disk('local')->get($entrega->archivo);

        $this->artisan('documentos:aligerar')
            ->expectsOutputToContain('SIMULACRO')
            ->assertSuccessful();

        $entrega->refresh();

        $this->assertStringEndsWith('.jpg', $entrega->archivo, 'el simulacro cambió la fila.');
        $this->assertSame($antes, Storage::disk('local')->get($entrega->archivo));
    }

    /** Con `--ejecutar` convierte, apunta la fila al nuevo y borra el viejo. */
    public function test_ejecutar_convierte_y_deja_la_fila_apuntando_al_nuevo(): void
    {
        $entrega = $this->entregaYaSubida($this->foto(3000, 2000));
        $viejo = $entrega->archivo;

        $this->artisan('documentos:aligerar --ejecutar')->assertSuccessful();

        $entrega->refresh();

        $this->assertStringEndsWith('.pdf', $entrega->archivo);
        $this->assertNotSame($viejo, $entrega->archivo);
        $this->assertTrue(Storage::disk('local')->exists($entrega->archivo), 'el archivo nuevo no está.');
        $this->assertFalse(Storage::disk('local')->exists($viejo), 'el original quedó ocupando sitio.');
        $this->assertStringStartsWith('%PDF-', Storage::disk('local')->get($entrega->archivo));
    }

    /**
     * Y deja un respaldo del original antes de reemplazarlo.
     *
     * Esto reescribe documentos de identidad de menores. El respaldo es lo que
     * permite volver atras si al abrir uno se ve que la conversion no valia.
     */
    public function test_ejecutar_respalda_el_original(): void
    {
        $entrega = $this->entregaYaSubida($this->foto(3000, 2000));
        $original = Storage::disk('local')->get($entrega->archivo);

        $this->artisan('documentos:aligerar --ejecutar')->assertSuccessful();

        $respaldos = Storage::disk('local')->allFiles('respaldos');

        $this->assertCount(1, $respaldos, 'no se respaldó el original.');
        $this->assertSame($original, Storage::disk('local')->get($respaldos[0]));
    }

    /** Y con `--sin-respaldo` no lo deja. */
    public function test_se_puede_pedir_que_no_respalde(): void
    {
        $this->entregaYaSubida($this->foto(3000, 2000));

        $this->artisan('documentos:aligerar --ejecutar --sin-respaldo')->assertSuccessful();

        $this->assertSame([], Storage::disk('local')->allFiles('respaldos'));
    }

    /** El comando tampoco toca los PDF que ya estaban subidos. */
    public function test_el_comando_no_toca_los_pdf(): void
    {
        $pdf = $this->pdfDePrueba();
        $entrega = $this->entregaYaSubida(
            UploadedFile::fake()->createWithContent('escaneo.pdf', $pdf)
        );
        $ruta = $entrega->archivo;

        $this->artisan('documentos:aligerar --ejecutar')->assertSuccessful();

        $entrega->refresh();

        $this->assertSame($ruta, $entrega->archivo, 'el comando movió un PDF.');
        $this->assertSame($pdf, Storage::disk('local')->get($ruta));
    }

    /** Una fila que apunta a un archivo que no está se cuenta y no revienta. */
    public function test_una_fila_sin_archivo_no_tumba_el_comando(): void
    {
        $entrega = $this->entregaYaSubida($this->foto(1200, 900));
        Storage::disk('local')->delete($entrega->archivo);

        $this->artisan('documentos:aligerar --ejecutar')
            ->expectsOutputToContain('1 con problemas')
            ->assertSuccessful();
    }

    // ------------------------------------------------------------------
    // Andamiaje
    // ------------------------------------------------------------------

    private function subir(UploadedFile $archivo): void
    {
        $this->actingAs($this->ana->user)
            ->post(route('mi-perfil.guardar'), [
                'accion' => 'papel',
                'documento_id' => $this->requerido->id,
                'archivo' => $archivo,
            ])
            ->assertRedirect(route('mi-perfil'));
    }

    private function rutaGuardada(): string
    {
        $entrega = DocumentoEstudiante::firstOrFail();

        return $entrega->archivo;
    }

    /** Una entrega ya escrita en disco, como las que habia antes del cambio. */
    private function entregaYaSubida(UploadedFile $archivo): DocumentoEstudiante
    {
        $ruta = $archivo->store('documentos', 'local');

        return DocumentoEstudiante::create([
            'datos_estudiante_id' => $this->ana->datosEstudiante->id,
            'requerido_id' => $this->requerido->id,
            'archivo' => $ruta,
        ]);
    }

    /**
     * Una foto de prueba con ruido.
     *
     * El ruido no es adorno: una imagen de un solo color se comprime a casi
     * nada y la prueba del ahorro pasaria por la razon equivocada.
     *
     * Y la DENSIDAD tampoco: con un pixel de cada doce, una de 4000x3000 salia
     * de 9 MB y la rechazaba la propia validacion de subida —«no puede pesar
     * mas de 8192 kilobytes»—, o sea que la prueba fallaba antes de llegar a
     * lo que queria probar. Uno de cada sesenta se parece mas a una foto de
     * celular de verdad, que son de 3 a 5 MB.
     */
    private function foto(int $ancho, int $alto): UploadedFile
    {
        $lienzo = imagecreatetruecolor($ancho, $alto);
        imagefilledrectangle($lienzo, 0, 0, $ancho, $alto, (int) imagecolorallocate($lienzo, 230, 228, 220));

        mt_srand(7);

        for ($i = 0; $i < (int) ($ancho * $alto / 60); $i++) {
            imagesetpixel(
                $lienzo,
                mt_rand(0, $ancho - 1),
                mt_rand(0, $alto - 1),
                (int) imagecolorallocate($lienzo, mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255))
            );
        }

        ob_start();
        imagejpeg($lienzo, null, 92);
        $jpeg = (string) ob_get_clean();
        imagedestroy($lienzo);

        return UploadedFile::fake()->createWithContent('cedula.jpg', $jpeg);
    }

    /** Un PDF minimo y valido, para el caso «lo que llega ya es PDF». */
    private function pdfDePrueba(): string
    {
        return "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";
    }

    /**
     * Las medidas de la imagen que va dentro del PDF.
     *
     * @return array{0: int, 1: int}
     */
    private function medidasDentroDelPdf(string $ruta): array
    {
        $pdf = Storage::disk('local')->get($ruta);

        preg_match('#/Width (\d+) /Height (\d+)#', (string) $pdf, $coincide);

        $this->assertNotEmpty($coincide, 'el PDF no declara las medidas de su imagen.');

        return [(int) $coincide[1], (int) $coincide[2]];
    }

    /** El JPEG incrustado, sacado del objeto imagen del PDF. */
    private function jpegDentroDelPdf(string $ruta): string
    {
        $pdf = (string) Storage::disk('local')->get($ruta);

        preg_match('#/Length (\d+) >>\nstream\n#', $pdf, $coincide, PREG_OFFSET_CAPTURE);

        $this->assertNotEmpty($coincide, 'no encontré el flujo de la imagen dentro del PDF.');

        $largo = (int) $coincide[1][0];
        $inicio = $coincide[0][1] + strlen($coincide[0][0]);

        return substr($pdf, $inicio, $largo);
    }

    /**
     * Le pega a un JPEG una etiqueta EXIF de orientacion.
     *
     * GD no sabe escribir EXIF, asi que se inserta a mano un segmento APP1 justo
     * detras del marcador de inicio. Son treinta y cuatro bytes: la cabecera
     * TIFF en orden Intel y un solo campo, el 0x0112 —«Orientation»—.
     *
     * Se hace aqui y no con un archivo de ejemplo guardado en el repositorio
     * porque un JPEG binario en `tests/` es algo que nadie vuelve a mirar y que
     * no dice por que esta.
     */
    private static function conOrientacionExif(string $jpeg, int $orientacion): string
    {
        $tiff = "II*\x00\x08\x00\x00\x00"          // cabecera TIFF, orden Intel
            ."\x01\x00"                             // un campo
            ."\x12\x01"                             // etiqueta 0x0112: Orientation
            ."\x03\x00"                             // tipo SHORT
            ."\x01\x00\x00\x00"                     // un valor
            .pack('v', $orientacion)."\x00\x00"     // el valor, y relleno
            ."\x00\x00\x00\x00";                    // no hay otro IFD

        $carga = "Exif\x00\x00".$tiff;
        $app1 = "\xFF\xE1".pack('n', strlen($carga) + 2).$carga;

        // Detras del marcador de inicio de imagen (FFD8).
        return substr($jpeg, 0, 2).$app1.substr($jpeg, 2);
    }

    private function estudiante(string $username): Perfil
    {
        $user = User::create(['username' => $username, 'password' => 'x', 'activo' => true]);

        $perfil = Perfil::create([
            'user_id' => $user->id,
            'rol' => 'estudiante',
            'nombre_completo' => ucfirst($username),
            'fecha_nacimiento' => Carbon::today()->subYears(20)->toDateString(),
            'telefono' => '3000000000',
        ]);

        DatosEstudiante::create([
            'perfil_id' => $perfil->id,
            'documento_identidad' => '10'.$perfil->id,
            'acudiente_id' => Acudiente::create(['nombre' => 'Tutor', 'telefono' => '300'])->id,
        ]);

        return $perfil->refresh();
    }
}
