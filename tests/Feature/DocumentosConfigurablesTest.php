<?php

namespace Tests\Feature;

use App\Models\Acudiente;
use App\Models\DatosEstudiante;
use App\Models\DocumentoEstudiante;
use App\Models\DocumentoRequerido;
use App\Models\Perfil;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * El documento de identidad es un papel MAS, configurable desde Institucion.
 *
 * Hasta el 05/09/2026 vivia en su propia columna y se pedia siempre, sin que la
 * entidad pudiera decidir nada: ni si se pide, ni como se llama, ni si es
 * obligatorio. La pantalla de Institucion lo decia con todas las letras. Lo
 * pidio el usuario con una razon de producto: esto se vende a otras
 * instituciones y cada una pide los papeles que le exige su norma.
 *
 * LO QUE VIGILA ESTE ARCHIVO:
 *
 * 1. Que se pueda dejar de pedir. Era lo imposible y es el sentido del cambio.
 * 2. Que se pueda DESCARGAR cualquier papel, no solo el de identidad. Esto
 *    cerro un agujero que estaba al lado: los papeles variables se subian y NO
 *    habia forma de verlos —ni ruta, ni enlace, ni pantalla—, asi que la gente
 *    los entregaba y se quedaban en el disco sin que nadie pudiera abrirlos.
 * 3. Que la barrera del administrador siga puesta para TODOS. Al generalizar
 *    una ruta protegida es facil aflojarla sin darse cuenta.
 */
class DocumentosConfigurablesTest extends TestCase
{
    use RefreshDatabase;

    private Perfil $admin;

    private Perfil $ana;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->perfil('jefa', 'administrador');
        $this->ana = $this->estudiante('ana');
    }

    /**
     * LO QUE ANTES ERA IMPOSIBLE: dejar de pedir el documento de identidad.
     *
     * No se borra, se DESACTIVA, que es como funcionan sus vecinos: los
     * archivos ya subidos cuelgan de esa fila y borrarla se llevaria la prueba
     * de que en su momento cumplieron.
     */
    public function test_la_entidad_puede_dejar_de_pedir_el_documento_de_identidad(): void
    {
        $requerido = DocumentoRequerido::create(['nombre' => 'Documento de identidad']);

        $this->actingAs($this->admin->user)
            ->post(route('documento-requerido-alternar', $requerido))
            ->assertRedirect();

        $this->assertFalse($requerido->fresh()->activo, 'no se pudo dejar de pedir.');

        // Y deja de ofrecerse en «Mi perfil».
        $html = $this->actingAs($this->ana->user)->get(route('mi-perfil'))->assertOk()->getContent();

        $this->assertStringNotContainsString('Documento de identidad', $html, 'se sigue pidiendo.');
    }

    /** Y mientras se pida, sale en «Mi perfil» como los demas. */
    public function test_mientras_se_pida_sale_en_mi_perfil(): void
    {
        DocumentoRequerido::create(['nombre' => 'Documento de identidad']);

        $this->actingAs($this->ana->user)
            ->get(route('mi-perfil'))
            ->assertOk()
            ->assertSee('Documento de identidad');
    }

    /**
     * EL AGUJERO QUE SE CERRO: un papel VARIABLE ya se puede descargar.
     *
     * Antes solo el de identidad tenia ruta. Los demas se subian y no los veia
     * nadie. La sonda importa: sin ella, una prueba que solo mire el 200 pasaria
     * igual si la ruta devolviera cualquier otra cosa.
     */
    public function test_un_papel_cualquiera_se_puede_descargar(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('documentos/eps.pdf', 'contenido de prueba');

        $requerido = DocumentoRequerido::create(['nombre' => 'Certificado de EPS']);
        $entrega = DocumentoEstudiante::create([
            'datos_estudiante_id' => $this->ana->datosEstudiante->id,
            'requerido_id' => $requerido->id,
            'archivo' => 'documentos/eps.pdf',
        ]);

        $this->actingAs($this->admin->user)
            ->get(route('descargar-documento', $entrega))
            ->assertOk()
            ->assertDownload('eps.pdf');
    }

    /** Y la ficha del estudiante lo enseña con su enlace. */
    public function test_la_ficha_lista_los_papeles_con_su_enlace(): void
    {
        $requerido = DocumentoRequerido::create(['nombre' => 'Certificado de EPS']);
        $entrega = DocumentoEstudiante::create([
            'datos_estudiante_id' => $this->ana->datosEstudiante->id,
            'requerido_id' => $requerido->id,
            'archivo' => 'documentos/eps.pdf',
        ]);

        $this->actingAs($this->admin->user)
            ->get(route('detalle-estudiante', $this->ana))
            ->assertOk()
            ->assertSee('Certificado de EPS')
            ->assertSee(route('descargar-documento', $entrega), false);
    }

    /** Un papel que falta se dice, no se calla. */
    public function test_la_ficha_dice_cual_falta(): void
    {
        DocumentoRequerido::create(['nombre' => 'Certificado de EPS', 'obligatorio' => true]);

        $this->actingAs($this->admin->user)
            ->get(route('detalle-estudiante', $this->ana))
            ->assertOk()
            ->assertSee('Certificado de EPS')
            ->assertSee('Falta');
    }

    /**
     * LA BARRERA NO SE AFLOJO AL GENERALIZAR.
     *
     * Vale para cualquier papel y no solo para el de identidad: uno que pida
     * una institucion puede ser una historia clinica o un certificado de
     * discapacidad, y no son menos delicados que una cedula.
     */
    public function test_un_papel_variable_tambien_es_solo_del_administrador(): void
    {
        $requerido = DocumentoRequerido::create(['nombre' => 'Certificado de EPS']);
        $entrega = DocumentoEstudiante::create([
            'datos_estudiante_id' => $this->ana->datosEstudiante->id,
            'requerido_id' => $requerido->id,
            'archivo' => 'documentos/eps.pdf',
        ]);

        $this->actingAs($this->ana->user)
            ->get(route('descargar-documento', $entrega))
            ->assertRedirect(route('post-login'));
    }

    /** Se sube desde «Mi perfil» por el mismo camino que los demas. */
    public function test_se_sube_el_documento_de_identidad_como_un_papel_mas(): void
    {
        Storage::fake('local');

        $requerido = DocumentoRequerido::create(['nombre' => 'Documento de identidad']);

        $this->actingAs($this->ana->user)->post(route('mi-perfil.guardar'), [
            'accion' => 'papel',
            'documento_id' => $requerido->id,
            'archivo' => UploadedFile::fake()->create('cedula.pdf', 10, 'application/pdf'),
        ])->assertRedirect(route('mi-perfil'));

        $entrega = DocumentoEstudiante::where('requerido_id', $requerido->id)->first();

        $this->assertNotNull($entrega, 'no quedó guardado.');
        Storage::disk('local')->assertExists($entrega->archivo);
    }

    private function estudiante(string $username): Perfil
    {
        $perfil = $this->perfil($username, 'estudiante');

        DatosEstudiante::create([
            'perfil_id' => $perfil->id,
            'documento_identidad' => '1'.$perfil->id,
            'acudiente_id' => Acudiente::create(['nombre' => 'Tutor', 'telefono' => '300'])->id,
        ]);

        return $perfil->refresh();
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
