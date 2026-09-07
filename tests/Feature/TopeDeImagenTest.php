<?php

namespace Tests\Feature;

use App\Models\Acudiente;
use App\Models\DatosEstudiante;
use App\Models\DocumentoRequerido;
use App\Models\Perfil;
use App\Models\User;
use App\Support\Documento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\TestCase;

/**
 * El tope de tamano de una imagen, y lo que se le dice a quien la sube.
 *
 * ─── Por que existe ────────────────────────────────────────────────────────
 *
 * Lo pidio el usuario el 06/09/2026: «pon topes para las fotos, con el mensaje
 * al usuario sobre el peso de la imagen».
 *
 * El tope por BYTES ya estaba —`max:8192`— y no protege de nada de esto: lo que
 * cuesta memoria no es lo que llega, sino lo que sale al descomprimir. Un
 * lienzo son CUATRO bytes por pixel, asi que una foto de 12 megapixeles ocupa
 * 48 MB pese lo que pese el archivo. Medido: convertir cuesta unos 6 MB por
 * megapixel.
 *
 * Y hay un caso peor que una foto grande de verdad. Un PNG de 66 BYTES puede
 * declarar en su cabecera 20.000x20.000 puntos: pasa el tope de 8 MB sin
 * despeinarse y al abrirlo pide 1,6 GB. Comprobado el 06/09/2026 con el codigo
 * de entonces: no devuelve false ni lanza nada que se pueda atrapar, es un
 * «Fatal error: Allowed memory size exhausted». Cualquiera con una cuenta podia
 * mandarlo, y repetirlo.
 *
 * ─── LO QUE VIGILA ESTE ARCHIVO ────────────────────────────────────────────
 *
 * 1. QUE SE MIDA SIN DESCOMPRIMIR. Es el arreglo entero: la comprobacion tiene
 *    que ocurrir leyendo la cabecera, porque la que descomprime para poder
 *    decidir ya se ha gastado la memoria antes de decidir nada.
 * 2. Que el tope salga del `memory_limit` de la maquina y no de un numero
 *    escrito a mano. Este producto se instala en hostings ajenos.
 * 3. Que una foto de celular normal SIGA PASANDO, incluso en la maquina mas
 *    apretada. Un tope que rechaza lo corriente no es una proteccion, es una
 *    pantalla rota.
 * 4. Que el rechazo se explique en palabras que sirvan desde un telefono.
 */
class TopeDeImagenTest extends TestCase
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
    // La bomba
    // ------------------------------------------------------------------

    /**
     * UNA BOMBA DE DESCOMPRESION SE RECHAZA SIN DESCOMPRIMIRLA.
     *
     * La prueba mide la memoria a los dos lados: si algo por el camino abriera
     * la imagen, este proceso pediria 1,6 GB y no llegaria a la asercion. Que
     * termine ya es media prueba; la otra media es el pico.
     */
    public function test_una_bomba_de_descompresion_se_rechaza_sin_descomprimirla(): void
    {
        $bomba = $this->pngQueMiente(20000, 20000);

        $this->assertLessThan(1000, strlen($bomba), 'la sonda no vale: la bomba tiene que ser diminuta.');

        $antes = memory_get_peak_usage(true);

        $this->subir(UploadedFile::fake()->createWithContent('bomba.png', $bomba))
            ->assertSessionHasErrors('archivo');

        $gastado = (memory_get_peak_usage(true) - $antes) / 1048576;

        $this->assertLessThan(
            64,
            $gastado,
            "la petición gastó {$gastado} MB: alguien está descomprimiendo la imagen antes de rechazarla."
        );
    }

    /** Y no se guarda nada. */
    public function test_la_bomba_no_deja_archivo(): void
    {
        $this->subir(UploadedFile::fake()->createWithContent('bomba.png', $this->pngQueMiente(20000, 20000)));

        $this->assertSame([], Storage::disk('local')->files('documentos'));
    }

    /**
     * `Documento` la ve venir por su cuenta, sin pasar por el formulario.
     *
     * Es la puerta por la que entra el comando de aligerar los ya subidos.
     */
    public function test_la_conversion_se_niega_a_lo_que_no_cabe(): void
    {
        $this->expectException(RuntimeException::class);

        Documento::aPdf($this->pngQueMiente(20000, 20000));
    }

    // ------------------------------------------------------------------
    // El tope sale de la maquina
    // ------------------------------------------------------------------

    /**
     * EL TOPE SE DEDUCE DEL `memory_limit`, y esa es la decision.
     *
     * Un numero fijo seria mentira en los dos sentidos: rechazaria fotos buenas
     * en un servidor holgado y aceptaria las que tumban uno apretado. Este
     * producto se instala en hostings ajenos, cada uno con el suyo — produccion
     * tiene 2048 MB y esta maquina 128.
     */
    public function test_el_tope_crece_con_la_memoria_de_la_maquina(): void
    {
        $apretada = Documento::topeParaMemoria(128 * 1024 * 1024);
        $holgada = Documento::topeParaMemoria(256 * 1024 * 1024);

        $this->assertGreaterThan(
            $apretada,
            $holgada,
            'el tope no mira la memoria: es el mismo número en las dos máquinas.'
        );
    }

    /**
     * PERO NO CRECE SIN FIN, y esta es la mitad que no es sobre memoria.
     *
     * Produccion tiene 2 GB, o sea que por memoria admitiria 330 megapixeles.
     * Nada de lo que salga de una camara se acerca a eso, asi que por encima
     * del techo lo unico que puede llegar es una bomba — y sin techo tendria
     * dos gigas para jugar.
     */
    public function test_el_tope_no_crece_sin_fin(): void
    {
        $this->assertSame(
            Documento::topeParaMemoria(2048 * 1024 * 1024),
            Documento::topeParaMemoria(null),
            'con memoria de sobra el tope se desmadra: una máquina generosa se queda sin techo.'
        );

        $this->assertLessThan(100, Documento::topeParaMemoria(null));
    }

    /**
     * UNA FOTO DE CELULAR NORMAL PASA, incluso en la maquina mas apretada.
     *
     * 12 megapixeles es lo que sale de un celular corriente, y 128 MB es el
     * `memory_limit` que trae PHP de fabrica. Si el tope no dejara pasar eso,
     * seria una pantalla rota y no una proteccion. Es la calibracion entera:
     * `RESERVA_MB` y `BYTES_POR_MEGAPIXEL` estan puestos para que esta
     * asercion se cumpla con margen.
     */
    public function test_una_foto_de_celular_normal_pasa_en_la_maquina_mas_apretada(): void
    {
        $this->assertGreaterThan(
            12.0,
            Documento::topeParaMemoria(128 * 1024 * 1024),
            'con el memory_limit de fábrica se rechazaría una foto de celular corriente.'
        );
    }

    /** El `memory_limit` se lee en bytes, y no como la cadena que es. */
    public function test_el_limite_se_lee_como_numero_y_no_como_cadena(): void
    {
        $limite = Documento::limiteDeMemoria();

        if ($limite === null) {
            $this->markTestSkipped('esta máquina no tiene memory_limit.');
        }

        // «128M» leido como numero da 128 BYTES, que es el fallo que se vigila.
        $this->assertGreaterThan(1024 * 1024, $limite, 'el memory_limit se está leyendo como si fuera bytes.');
    }

    // ------------------------------------------------------------------
    // Lo que se le dice a quien sube
    // ------------------------------------------------------------------

    /**
     * EL RECHAZO DICE EL TAMANO Y QUE HACER.
     *
     * «No se pudo procesar esa imagen» deja a alguien mirando el telefono sin
     * nada que intentar. Aqui hay dos cosas que no puede tener ningun otro
     * sitio: cuanto mide la suya, y la salida — bajar la resolucion en la
     * camara.
     */
    public function test_el_rechazo_dice_el_tamano_y_la_salida(): void
    {
        $mensaje = $this->primerError(
            $this->subir(UploadedFile::fake()->createWithContent('grande.png', $this->pngQueMiente(9000, 9000)))
        );

        $this->assertStringContainsString('9.000×9.000', $mensaje, 'no dice cuánto mide la imagen que mandó.');
        $this->assertStringContainsString('megapíxeles', $mensaje);
        $this->assertStringContainsString('resolución', $mensaje, 'no dice qué hacer para arreglarlo.');
    }

    /**
     * Y el de PESO no habla de kilobytes.
     *
     * El de Laravel dice «no debe ser mayor que 8192 kilobytes». Casi todo el
     * uso de este sistema es desde un telefono, y ahi nadie sabe cuantos
     * kilobytes tiene su foto.
     */
    public function test_el_mensaje_de_peso_esta_en_megas_y_dice_que_hacer(): void
    {
        $mensaje = $this->primerError(
            $this->subir(UploadedFile::fake()->create('pesada.pdf', 9000, 'application/pdf'))
        );

        $this->assertStringContainsString('8 MB', $mensaje);
        $this->assertStringNotContainsString('kilobytes', $mensaje);
    }

    /**
     * UN PDF NO LO TOCA EL TOPE.
     *
     * El campo de los papeles admite las dos cosas, y un PDF no se convierte:
     * este tope no le incumbe. Sin `puedeNoSerImagen`, la regla lo rechazaria
     * por «no se pudo procesar esa imagen» y dejaria de poder subirse un
     * escaneo — que son 14 de los 70 papeles que hay en produccion.
     */
    public function test_un_pdf_se_sigue_pudiendo_subir(): void
    {
        $this->subir(UploadedFile::fake()->createWithContent('escaneo.pdf', "%PDF-1.4\n%%EOF\n"))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('mi-perfil'));
    }

    /**
     * EL RECHAZO SE PINTA, Y SOLO EN LA FILA QUE SE INTENTO.
     *
     * Las dos mitades importan y las dos estuvieron mal hasta el 06/09/2026.
     *
     * Que se pinte: esta pantalla no tenia NINGUN `@error` en la seccion de
     * papeles, asi que el aviso de arriba decia «marcado en rojo mas abajo» y
     * no habia nada rojo en ninguna parte. Es el fallo que ya costo un profesor
     * en produccion, en la pantalla desde la que 740 personas tienen que subir
     * su consentimiento. Se vio abriendo la pagina, no aqui.
     *
     * Y que sea SOLO la suya: hay un formulario por papel y todos mandan un
     * campo llamado `archivo`, asi que un `@error('archivo')` a secas lo pinta
     * en todos. Quien falla al subir la cedula veria «demasiado grande» tambien
     * bajo el consentimiento, que no ha tocado.
     */
    public function test_el_rechazo_se_pinta_solo_en_la_fila_que_se_intento(): void
    {
        $otro = DocumentoRequerido::create(['nombre' => 'Certificado médico', 'orden' => 2]);

        // El `referer` no es adorno: un rechazo vuelve con `back()`, y sin él
        // una peticion de prueba aterriza en la portada — donde no hay ningun
        // formulario de papeles y la prueba pasaria por no encontrar nada. Un
        // navegador lo manda siempre.
        $html = (string) $this->followingRedirects()
            ->withHeader('referer', route('mi-perfil'))
            ->actingAs($this->ana->user)
            ->post(route('mi-perfil.guardar'), [
                'accion' => 'papel',
                'documento_id' => $this->requerido->id,
                'archivo' => UploadedFile::fake()->createWithContent('x.png', $this->pngQueMiente(9000, 9000)),
            ])
            ->getContent();

        $this->assertStringContainsString('papel-fila', $html, 'la sonda no vale: no se volvió a Mi perfil.');

        $this->assertSame(
            1,
            substr_count($html, 'class="errorlist"'),
            'el error o no se pinta, o se pinta en todos los papeles a la vez.'
        );

        // Y es el de la fila buena: el trozo entre el `documento_id` intentado y
        // el del OTRO papel es donde tiene que caer.
        $desde = strpos($html, 'value="'.$this->requerido->id.'"');
        $hasta = strpos($html, 'value="'.$otro->id.'"');

        $this->assertNotFalse($desde);
        $this->assertNotFalse($hasta);
        $this->assertGreaterThan($desde, $hasta, 'la sonda no vale: los papeles no salen en ese orden.');
        $this->assertStringContainsString(
            'errorlist',
            substr($html, $desde, $hasta - $desde),
            'el error se pintó, pero no en la fila del papel que se intentó.'
        );
    }

    /**
     * `.errorlist` TIENE COLOR EN LA HOJA, y no solo en 44 `style=` a mano.
     *
     * Faltaba: 25 de los 69 errores de la aplicacion —el inicio de sesion, el
     * registro, la inscripcion publica y este— salian del color del texto
     * normal, mientras el aviso de arriba prometia «marcado en rojo». Una
     * prueba de PHP no puede ver un color; lo que si puede es que la regla siga
     * escrita, que es lo que se lleva por delante quien limpie la hoja.
     */
    public function test_la_hoja_le_da_color_al_error_de_un_campo(): void
    {
        $css = (string) file_get_contents(public_path('css/app.css'));

        $this->assertMatchesRegularExpression(
            '/\.errorlist\s*\{[^}]*color:\s*var\(--danger\)/',
            $css,
            'el error de un campo se quedó sin rojo en la hoja.'
        );
    }

    /**
     * Y el selector de archivo no estira la pantalla.
     *
     * Un `input[type=file]` no encoge solo: trae un ancho intrinseco que
     * `flex: 0 1 auto` no baja. Medido a 390 px, llegaba a 380 sobre 375 de
     * pagina util, o sea que Mi perfil se arrastraba a lo ancho en un telefono
     * — desde donde se sube casi todo. No lo ve ninguna prueba de PHP: lo unico
     * comprobable desde aqui es que el tope siga puesto.
     */
    public function test_el_selector_de_archivo_no_estira_la_fila(): void
    {
        $css = (string) file_get_contents(public_path('css/app.css'));

        $this->assertMatchesRegularExpression(
            '/\.papel-fila input\[type="file"\]\s*\{[^}]*max-width/',
            $css,
            'sin tope, el selector de archivo desborda Mi perfil en un teléfono.'
        );
    }

    // ------------------------------------------------------------------
    // Andamiaje
    // ------------------------------------------------------------------

    private function subir(UploadedFile $archivo): TestResponse
    {
        return $this->actingAs($this->ana->user)->post(route('mi-perfil.guardar'), [
            'accion' => 'papel',
            'documento_id' => $this->requerido->id,
            'archivo' => $archivo,
        ]);
    }

    private function primerError(TestResponse $respuesta): string
    {
        $respuesta->assertSessionHasErrors('archivo');

        /** @var ViewErrorBag $bolsa */
        $bolsa = session()->get('errors');

        return $bolsa->get('archivo')[0];
    }

    /**
     * Un PNG diminuto cuya CABECERA declara un tamano enorme.
     *
     * Se fabrica a mano y no con `imagecreatetruecolor` por lo evidente: para
     * generar de verdad 20.000x20.000 haria falta el gigabyte y medio que esta
     * prueba existe para no gastar. La cabecera IHDR de un PNG son los dos
     * lados y poco mas, y `getimagesizefromstring` no necesita nada del resto.
     */
    private function pngQueMiente(int $ancho, int $alto): string
    {
        $trozo = function (string $tipo, string $datos): string {
            return pack('N', strlen($datos)).$tipo.$datos.pack('N', crc32($tipo.$datos));
        };

        return "\x89PNG\r\n\x1a\n"
            .$trozo('IHDR', pack('NN', $ancho, $alto)."\x08\x02\x00\x00\x00")
            .$trozo('IDAT', "\x78\x9c\x63\x00\x00\x00\x01\x00\x01")
            .$trozo('IEND', '');
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
