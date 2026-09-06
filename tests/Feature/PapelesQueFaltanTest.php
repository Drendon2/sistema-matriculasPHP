<?php

namespace Tests\Feature;

use App\Http\Controllers\Gestion\UsuarioController;
use App\Models\Acudiente;
use App\Models\DatosEstudiante;
use App\Models\DocumentoEstudiante;
use App\Models\DocumentoRequerido;
use App\Models\Perfil;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Saber A QUIEN LE FALTA un papel, desde Gestion → Usuarios.
 *
 * ─── Por que existe ────────────────────────────────────────────────────────
 *
 * Hasta el 06/09/2026 no habia forma de saberlo. Gestion → Institucion dice
 * CUANTOS han entregado cada papel —«49 entregados»— pero no quienes, y la
 * unica alternativa era abrir la ficha de cada estudiante uno por uno. Con 800
 * usuarios eso es adivinar, y son las palabras del usuario al pedirlo.
 *
 * Se volvio urgente ese mismo dia: el consentimiento de tratamiento de datos
 * nacio obligatorio, o sea 775 personas a las que perseguir.
 *
 * ─── LO QUE VIGILA ESTE ARCHIVO ────────────────────────────────────────────
 *
 * 1. QUE EL PERSONAL NO SALGA. Es la forma en que este filtro se estropea sin
 *    que nada falle: a un profesor no se le piden papeles, asi que «no ha
 *    entregado ninguno» es verdad para toda la plantilla, y quien filtre para
 *    armar la lista de a quien llamar se la encuentra dentro.
 * 2. Que «alguno obligatorio» signifique eso y no «alguno».
 * 3. Que se pueda perseguir UN papel concreto, que es el caso real: el
 *    consentimiento.
 */
class PapelesQueFaltanTest extends TestCase
{
    use RefreshDatabase;

    private Perfil $admin;

    private DocumentoRequerido $identidad;

    private DocumentoRequerido $consentimiento;

    private DocumentoRequerido $opcional;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->perfil('jefa', 'administrador');

        $this->identidad = DocumentoRequerido::create(['nombre' => 'Documento de identidad', 'orden' => 1]);
        $this->consentimiento = DocumentoRequerido::create(['nombre' => 'Autorización de datos', 'orden' => 2]);
        $this->opcional = DocumentoRequerido::create([
            'nombre' => 'Foto reciente',
            'orden' => 3,
            'obligatorio' => false,
        ]);
    }

    /** A quien le falta alguno de los obligatorios sale; a quien no, no. */
    public function test_ensena_a_quien_le_falta_alguno_obligatorio(): void
    {
        $completa = $this->estudiante('completa');
        $this->entregar($completa, $this->identidad);
        $this->entregar($completa, $this->consentimiento);

        $aMedias = $this->estudiante('amedias');
        $this->entregar($aMedias, $this->identidad);

        $sinNada = $this->estudiante('sinnada');

        $html = $this->listar(UsuarioController::PAPELES_OBLIGATORIOS);

        $this->assertStringContainsString('Amedias', $html, 'no salió quien tiene uno de dos.');
        $this->assertStringContainsString('Sinnada', $html, 'no salió quien no ha entregado nada.');
        $this->assertStringNotContainsString('Completa', $html, 'salió quien ya entregó todo.');
    }

    /**
     * EL PERSONAL NO SALE NUNCA.
     *
     * Sin esta garantia, quien filtre para armar la lista de a quien llamar se
     * encuentra la plantilla entera dentro: un profesor no ha entregado ningun
     * papel, luego «le faltan».
     *
     * COMPROBADO QUITANDO EL `where('rol', 'estudiante')` del controlador: se
     * pone roja. Y eso NO era cierto en la primera version de este filtro —ahi
     * pasaba igual sin esa linea, porque quien dejaba fuera al personal era el
     * `whereHas('datosEstudiante')`—. Lo que la volvio imprescindible fue
     * cubrir al estudiante sin ficha de datos: esa rama la cumple todo el
     * personal, asi que ahora el corte por rol es lo unico que lo sostiene.
     *
     * Se cuenta porque es el orden natural de las cosas aqui: una prueba puede
     * pasar por la razon equivocada durante un rato y volverse util despues sin
     * que nadie la toque. La unica forma de saber cual de las dos es hoy es
     * romper la linea y mirar.
     */
    public function test_el_personal_no_sale_aunque_no_tenga_ningun_papel(): void
    {
        // Nombres que NO aparecen en ninguna otra parte de la pantalla. Con
        // «director» esta prueba fallaba sola: esa palabra sale en el
        // desplegable de roles, y la comprobacion habria sido sobre la etiqueta
        // de un filtro y no sobre una fila de la lista.
        $this->perfil('luisverdugo', 'profesor');
        $this->perfil('zulemapaz', 'director');

        $html = $this->listar(UsuarioController::PAPELES_OBLIGATORIOS);

        $this->assertStringNotContainsString('Luisverdugo', $html, 'salió un profesor.');
        $this->assertStringNotContainsString('Zulemapaz', $html, 'salió un director.');
        // Y la administradora que está mirando tampoco.
        $this->assertStringNotContainsString('Jefa', $html);
    }

    /**
     * Un papel OPCIONAL que falta no mete a nadie en la lista.
     *
     * «Le falta alguno obligatorio» tiene que significar eso. Si contara los
     * opcionales, la lista serían todos y no serviría para perseguir a nadie.
     */
    public function test_un_papel_opcional_que_falta_no_cuenta(): void
    {
        $alDia = $this->estudiante('aldia');
        $this->entregar($alDia, $this->identidad);
        $this->entregar($alDia, $this->consentimiento);
        // La foto reciente, que es opcional, NO la entregó.

        $html = $this->listar(UsuarioController::PAPELES_OBLIGATORIOS);

        $this->assertStringNotContainsString('Aldia', $html, 'un papel opcional metió a alguien en la lista.');
    }

    /** Y se puede perseguir UN papel concreto: el caso real. */
    public function test_se_puede_filtrar_por_un_papel_concreto(): void
    {
        $sinConsentimiento = $this->estudiante('falta');
        $this->entregar($sinConsentimiento, $this->identidad);

        $conConsentimiento = $this->estudiante('tiene');
        $this->entregar($conConsentimiento, $this->consentimiento);

        $html = $this->listar((string) $this->consentimiento->id);

        $this->assertStringContainsString('Falta', $html);
        $this->assertStringNotContainsString('Tiene', $html);
    }

    /**
     * Una entrega con el archivo VACIO no cuenta como entregada.
     *
     * La fila puede existir sin archivo detrás, y eso no es haber entregado.
     */
    public function test_una_entrega_sin_archivo_no_cuenta(): void
    {
        $vacia = $this->estudiante('vacia');
        $this->entregar($vacia, $this->identidad, '');
        $this->entregar($vacia, $this->consentimiento);

        $html = $this->listar(UsuarioController::PAPELES_OBLIGATORIOS);

        $this->assertStringContainsString('Vacia', $html, 'una entrega sin archivo pasó por entregada.');
    }

    /**
     * UN ESTUDIANTE SIN FICHA DE DATOS TAMPOCO SE ESCAPA.
     *
     * No es que le falte «alguno»: le faltan TODOS, y sin cubrirlo se quedaba
     * invisible justo en la lista que existe para no dejar a nadie fuera. En
     * produccion no habia ninguno el 06/09/2026, pero en la base de desarrollo
     * habia ocho — y a eso se llego mirando por que el filtro devolvia 271
     * cuando habia 279 estudiantes y nadie habia entregado nada.
     */
    public function test_un_estudiante_sin_ficha_de_datos_tambien_sale(): void
    {
        $huerfano = $this->perfil('sinficha', 'estudiante');

        $this->assertNull($huerfano->datosEstudiante, 'la sonda no vale: se le creó la ficha.');

        $html = $this->listar(UsuarioController::PAPELES_OBLIGATORIOS);

        $this->assertStringContainsString('Sinficha', $html, 'un estudiante sin ficha no aparece: le faltan todos.');
    }

    /** Sin filtro puesto, la lista es la de siempre. */
    public function test_sin_filtro_salen_todos(): void
    {
        $this->estudiante('ana');
        $this->perfil('luis', 'profesor');

        $html = $this->listar('');

        $this->assertStringContainsString('Ana', $html);
        $this->assertStringContainsString('Luis', $html);
    }

    /** El desplegable ofrece los papeles activos, y no los apagados. */
    public function test_el_desplegable_no_ofrece_un_papel_apagado(): void
    {
        $this->opcional->activo = false;
        $this->opcional->save();

        $html = $this->listar('');

        $this->assertStringContainsString('Le falta: Documento de identidad', $html);
        $this->assertStringNotContainsString('Le falta: Foto reciente', $html);
    }

    // ------------------------------------------------------------------
    // Andamiaje
    // ------------------------------------------------------------------

    private function listar(string $papeles): string
    {
        return (string) $this->actingAs($this->admin->user)
            ->get(route('usuario-lista', $papeles === '' ? [] : ['papeles' => $papeles]))
            ->assertOk()
            ->getContent();
    }

    private function entregar(Perfil $estudiante, DocumentoRequerido $papel, string $archivo = 'documentos/x.pdf'): void
    {
        DocumentoEstudiante::create([
            'datos_estudiante_id' => $estudiante->datosEstudiante->id,
            'requerido_id' => $papel->id,
            'archivo' => $archivo,
        ]);
    }

    private function estudiante(string $username): Perfil
    {
        $perfil = $this->perfil($username, 'estudiante');

        DatosEstudiante::create([
            'perfil_id' => $perfil->id,
            'documento_identidad' => '10'.$perfil->id,
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
            'fecha_nacimiento' => Carbon::today()->subYears(20)->toDateString(),
            'telefono' => '3000000000',
        ]);
    }
}
