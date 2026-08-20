<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\ConfiguracionInstitucion;
use App\Models\DatosEstudiante;
use App\Models\Grupo;
use App\Models\Matricula;
use App\Models\Perfil;
use App\Models\Periodo;
use App\Models\Promotoria;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * El certificado de matricula en PDF y la firma que lo sella.
 *
 * Lo que se prueba aqui es sobre todo QUE se puede certificar y QUIEN puede
 * bajarlo, porque las dos cosas son afirmaciones sobre una persona que salen de
 * casa en un papel. El contenido se comprueba de la unica forma razonable con
 * un PDF: que se genere, que sea un PDF de verdad y que pese algo.
 */
class CertificadoTest extends TestCase
{
    use RefreshDatabase;

    private Periodo $periodo;

    private Promotoria $violin;

    private Promotoria $danza;

    private User $userAna;

    private Perfil $ana;

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
        $this->violin = Promotoria::create(['nombre' => 'Violin', 'area_id' => $area->id]);
        $this->danza = Promotoria::create(['nombre' => 'Danza', 'area_id' => $area->id]);

        [$this->userAna, $this->ana] = $this->crearPerfil('ana', 'estudiante', '1111');
    }

    /** @return array{0: User, 1: Perfil} */
    private function crearPerfil(string $username, string $rol, ?string $documento = null): array
    {
        $user = User::create(['username' => $username, 'password' => 'x', 'activo' => true]);

        $perfil = Perfil::create([
            'user_id' => $user->id,
            'rol' => $rol,
            'nombre_completo' => ucfirst($username).' Perez',
            'fecha_nacimiento' => Carbon::today()->subYears(25)->toDateString(),
            'telefono' => '3000000000',
        ]);

        if ($documento !== null) {
            DatosEstudiante::create([
                'perfil_id' => $perfil->id,
                'documento_identidad' => $documento,
            ]);
        }

        return [$user, $perfil->refresh()];
    }

    private function matricular(
        Promotoria $promotoria,
        string $estado,
        ?Periodo $periodo = null,
        ?Perfil $estudiante = null
    ): Matricula {
        return Matricula::create([
            'estudiante_id' => ($estudiante ?? $this->ana)->id,
            'promotoria_id' => $promotoria->id,
            'periodo_id' => ($periodo ?? $this->periodo)->id,
            'estado' => $estado,
        ]);
    }

    private function periodoCerrado(): Periodo
    {
        return Periodo::create([
            'nombre' => '2025-2',
            'fecha_inicio' => '2025-07-01',
            'fecha_fin' => '2025-12-15',
            'activo' => false,
        ]);
    }

    /** Un PDF de verdad empieza por %PDF- y no viene vacio. */
    private function assertEsPdf(TestResponse $respuesta): void
    {
        $respuesta->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $respuesta->headers->get('content-type'));

        $contenido = $respuesta->getContent();

        $this->assertStringStartsWith('%PDF-', $contenido);
        $this->assertGreaterThan(1000, strlen($contenido));
    }

    // -----------------------------------------------------------------------
    // Que se puede certificar
    // -----------------------------------------------------------------------

    public function test_el_estudiante_baja_el_certificado_de_su_matricula_activa(): void
    {
        $matricula = $this->matricular($this->violin, Matricula::ACTIVA);

        $respuesta = $this->actingAs($this->userAna)
            ->get(route('certificado-matricula', $matricula));

        $this->assertEsPdf($respuesta);
        $this->assertStringContainsString(
            'certificado-matricula-ana-perez-violin.pdf',
            (string) $respuesta->headers->get('content-disposition')
        );
    }

    /** Una activa de un periodo cerrado acredita haber cursado. */
    public function test_una_matricula_finalizada_se_certifica(): void
    {
        $matricula = $this->matricular($this->violin, Matricula::ACTIVA, $this->periodoCerrado());

        $this->assertSame(Matricula::FINALIZADA, $matricula->fresh()->estado_visible);

        $this->assertEsPdf(
            $this->actingAs($this->userAna)->get(route('certificado-matricula', $matricula))
        );
    }

    /**
     * Lo que nadie ha confirmado no se certifica: el papel diria que esta
     * persona esta en el curso cuando todavia no lo esta.
     */
    public function test_una_matricula_pendiente_no_se_certifica(): void
    {
        $matricula = $this->matricular($this->violin, Matricula::PENDIENTE);

        $this->actingAs($this->userAna)
            ->get(route('certificado-matricula', $matricula))
            ->assertNotFound();
    }

    public function test_una_matricula_retirada_no_se_certifica(): void
    {
        $matricula = $this->matricular($this->violin, Matricula::RETIRADA);

        $this->actingAs($this->userAna)
            ->get(route('certificado-matricula', $matricula))
            ->assertNotFound();
    }

    // -----------------------------------------------------------------------
    // Quien puede bajarlo
    // -----------------------------------------------------------------------

    public function test_otro_estudiante_no_baja_el_certificado_ajeno(): void
    {
        $matricula = $this->matricular($this->violin, Matricula::ACTIVA);
        [$userBeto] = $this->crearPerfil('beto', 'estudiante', '2222');

        $this->actingAs($userBeto)
            ->get(route('certificado-matricula', $matricula))
            ->assertNotFound();
    }

    public function test_sin_sesion_no_se_baja_nada(): void
    {
        $matricula = $this->matricular($this->violin, Matricula::ACTIVA);

        $this->get(route('certificado-matricula', $matricula))->assertRedirect(route('login'));
    }

    public function test_el_administrador_baja_el_certificado_de_cualquiera(): void
    {
        $matricula = $this->matricular($this->violin, Matricula::ACTIVA);
        [$userAdmin] = $this->crearPerfil('admin', 'administrador');

        $this->assertEsPdf(
            $this->actingAs($userAdmin)->get(route('certificado-matricula', $matricula))
        );
    }

    /** El profesor entra por el vinculo con la promotoria, no por el rol. */
    public function test_el_profesor_baja_el_de_la_promotoria_que_dicta_y_no_el_de_otra(): void
    {
        [$userProfe, $profe] = $this->crearPerfil('profe', 'profesor');

        $this->violin->profesor_id = $profe->id;
        $this->violin->save();

        $suya = $this->matricular($this->violin, Matricula::ACTIVA);
        $ajena = $this->matricular($this->danza, Matricula::ACTIVA);

        $this->assertEsPdf(
            $this->actingAs($userProfe)->get(route('certificado-matricula', $suya))
        );

        $this->actingAs($userProfe)
            ->get(route('certificado-matricula', $ajena))
            ->assertNotFound();
    }

    // -----------------------------------------------------------------------
    // El certificado reunido
    // -----------------------------------------------------------------------

    public function test_el_reunido_junta_las_activas_del_periodo_en_curso(): void
    {
        $this->matricular($this->violin, Matricula::ACTIVA);
        $this->matricular($this->danza, Matricula::ACTIVA);

        $respuesta = $this->actingAs($this->userAna)
            ->get(route('certificado-todo', $this->ana));

        $this->assertEsPdf($respuesta);
        // Sin nombre de promotoria en el archivo: son varias.
        $this->assertStringContainsString(
            'certificado-matricula-ana-perez.pdf',
            (string) $respuesta->headers->get('content-disposition')
        );
    }

    /**
     * El profesor NO baja el reunido ni siquiera del estudiante que tiene en
     * clase: listaria las promotorias que la ficha le esconde.
     */
    public function test_el_profesor_no_baja_el_reunido(): void
    {
        [$userProfe, $profe] = $this->crearPerfil('profe', 'profesor');

        $this->violin->profesor_id = $profe->id;
        $this->violin->save();

        $this->matricular($this->violin, Matricula::ACTIVA);
        $this->matricular($this->danza, Matricula::ACTIVA);

        $this->actingAs($userProfe)
            ->get(route('certificado-todo', $this->ana))
            ->assertNotFound();
    }

    public function test_sin_matriculas_activas_el_reunido_avisa_en_vez_de_romperse(): void
    {
        $this->matricular($this->violin, Matricula::PENDIENTE);

        $this->actingAs($this->userAna)
            ->get(route('certificado-todo', $this->ana))
            ->assertRedirect()
            ->assertSessionHas('error');
    }

    /** Una activa de un periodo cerrado no entra en el reunido, que es del presente. */
    public function test_el_reunido_ignora_los_periodos_cerrados(): void
    {
        $this->matricular($this->violin, Matricula::ACTIVA, $this->periodoCerrado());

        $this->actingAs($this->userAna)
            ->get(route('certificado-todo', $this->ana))
            ->assertRedirect()
            ->assertSessionHas('error');
    }

    // -----------------------------------------------------------------------
    // El PDF sigue saliendo cuando falta algo
    // -----------------------------------------------------------------------

    /**
     * Sin firma cargada el certificado se genera igual, con el hueco en blanco
     * para firmarlo a mano. Una institucion recien instalada no puede quedarse
     * sin constancias hasta que alguien pase por el escaner.
     */
    public function test_sin_firma_cargada_el_certificado_se_genera_igual(): void
    {
        $matricula = $this->matricular($this->violin, Matricula::ACTIVA);

        $this->assertSame('', ConfiguracionInstitucion::actual()->firma);

        $this->assertEsPdf(
            $this->actingAs($this->userAna)->get(route('certificado-matricula', $matricula))
        );
    }

    /** Una ruta de firma que ya no esta en disco tampoco tumba la descarga. */
    public function test_una_firma_que_falta_en_disco_no_rompe_el_certificado(): void
    {
        Storage::fake('local');

        $configuracion = ConfiguracionInstitucion::actual();
        $configuracion->firma = 'institucion/firma-que-no-existe.png';
        $configuracion->firmante_nombre = 'Marta Ruiz';
        $configuracion->firmante_cargo = 'Directora';
        $configuracion->save();

        $matricula = $this->matricular($this->violin, Matricula::ACTIVA);

        $this->assertEsPdf(
            $this->actingAs($this->userAna)->get(route('certificado-matricula', $matricula))
        );
    }

    public function test_el_certificado_sale_con_grupo_asignado(): void
    {
        $grupo = Grupo::create([
            'promotoria_id' => $this->violin->id,
            'nombre' => 'Martes 4:00 p. m.',
            'nivel' => 'basico',
            'horario' => 'Martes 4:00 p. m.',
            'salon' => '3',
            'cupo_maximo' => 10,
        ]);

        $matricula = $this->matricular($this->violin, Matricula::ACTIVA);
        $matricula->grupo_id = $grupo->id;
        $matricula->save();

        $this->assertEsPdf(
            $this->actingAs($this->userAna)->get(route('certificado-matricula', $matricula))
        );
    }

    // -----------------------------------------------------------------------
    // La firma, en Institucion
    // -----------------------------------------------------------------------

    public function test_el_administrador_carga_la_firma_y_sus_dos_textos(): void
    {
        Storage::fake('local');
        [$userAdmin] = $this->crearPerfil('admin', 'administrador');

        $this->actingAs($userAdmin)->post(route('gestion-configuracion'), [
            'nombre_institucion' => 'Casa de la Cultura',
            'color_acento' => '#0a7a59',
            'limite_promotorias_por_periodo' => 2,
            'firma' => UploadedFile::fake()->image('firma.png', 600, 200),
            'firmante_nombre' => 'Marta Ruiz',
            'firmante_cargo' => 'Directora',
        ])->assertRedirect(route('gestion-configuracion'));

        $configuracion = ConfiguracionInstitucion::actual()->refresh();

        $this->assertSame('Marta Ruiz', $configuracion->firmante_nombre);
        $this->assertSame('Directora', $configuracion->firmante_cargo);
        // PNG y no WebP: el generador de PDF no entiende WebP, y una firma en
        // WebP saldria como un hueco sin que nada fallara en pantalla.
        $this->assertStringEndsWith('.png', $configuracion->firma);
        Storage::disk('local')->assertExists($configuracion->firma);
    }

    public function test_quitar_la_firma_la_borra_del_disco(): void
    {
        Storage::fake('local');
        [$userAdmin] = $this->crearPerfil('admin', 'administrador');

        $this->actingAs($userAdmin)->post(route('gestion-configuracion'), [
            'nombre_institucion' => 'Casa de la Cultura',
            'color_acento' => '#0a7a59',
            'limite_promotorias_por_periodo' => 2,
            'firma' => UploadedFile::fake()->image('firma.png', 600, 200),
        ]);

        $anterior = ConfiguracionInstitucion::actual()->refresh()->firma;
        $this->assertNotSame('', $anterior);

        $this->actingAs($userAdmin)->post(route('gestion-configuracion'), [
            'nombre_institucion' => 'Casa de la Cultura',
            'color_acento' => '#0a7a59',
            'limite_promotorias_por_periodo' => 2,
            'quitar_firma' => '1',
        ]);

        $this->assertSame('', ConfiguracionInstitucion::actual()->refresh()->firma);
        Storage::disk('local')->assertMissing($anterior);
    }

    /**
     * La firma NO se sirve como el logo. Una firma escaneada en una URL abierta
     * se la lleva cualquiera para estampar el papel que quiera.
     */
    public function test_la_firma_no_es_publica_como_el_logo(): void
    {
        $this->get(route('firma-institucion'))->assertRedirect(route('login'));

        $this->actingAs($this->userAna)
            ->get(route('firma-institucion'))
            ->assertRedirect();
    }

    public function test_el_administrador_ve_la_firma_cargada(): void
    {
        Storage::fake('local');
        [$userAdmin] = $this->crearPerfil('admin', 'administrador');

        $this->actingAs($userAdmin)->post(route('gestion-configuracion'), [
            'nombre_institucion' => 'Casa de la Cultura',
            'color_acento' => '#0a7a59',
            'limite_promotorias_por_periodo' => 2,
            'firma' => UploadedFile::fake()->image('firma.png', 600, 200),
        ]);

        $this->actingAs($userAdmin)->get(route('firma-institucion'))->assertOk();
    }

    // -----------------------------------------------------------------------
    // Los botones
    // -----------------------------------------------------------------------

    public function test_mi_perfil_ofrece_el_certificado_solo_con_matriculas_activas(): void
    {
        $this->actingAs($this->userAna)
            ->get(route('mi-perfil'))
            ->assertOk()
            ->assertDontSee('Certificado de matrícula', false);

        $this->matricular($this->violin, Matricula::ACTIVA);

        $this->actingAs($this->userAna)
            ->get(route('mi-perfil'))
            ->assertOk()
            ->assertSee('Certificado de matrícula', false)
            ->assertSee(route('certificado-todo', $this->ana), false);
    }

    public function test_mis_matriculas_enlaza_el_certificado_de_la_activa_y_no_el_de_la_pendiente(): void
    {
        $activa = $this->matricular($this->violin, Matricula::ACTIVA);
        $pendiente = $this->matricular($this->danza, Matricula::PENDIENTE);

        $this->actingAs($this->userAna)
            ->get(route('mis-matriculas'))
            ->assertOk()
            ->assertSee(route('certificado-matricula', $activa), false)
            ->assertDontSee(route('certificado-matricula', $pendiente), false);
    }
}
