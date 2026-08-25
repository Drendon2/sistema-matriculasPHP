<?php

namespace Tests\Feature;

use App\Models\Actividad;
use App\Models\Area;
use App\Models\DatosEstudiante;
use App\Models\Matricula;
use App\Models\Perfil;
use App\Models\Periodo;
use App\Models\Promotoria;
use App\Models\User;
use App\Support\Auditoria;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Las acciones que no se pueden deshacer dejan rastro (F-02).
 *
 * El diagnostico conto CERO llamadas a `Log::` en 14.255 lineas. Cuando algo
 * falle en produccion solo queda la excepcion de Laravel, sin contexto de
 * negocio: no hay forma de responder a «¿quien retiro esta matricula?».
 *
 * Las dos que mas valen son la del NIVEL y la de los TRES CAMINOS:
 *
 * - El nivel, porque en produccion `LOG_LEVEL=error` y todos los canales de
 *   `config/logging.php` cuelgan de esa variable. Un `Log::info` suelto se
 *   descartaria justo en el unico sitio donde el rastro hace falta, y el cambio
 *   entero no habria servido para nada sin que ninguna prueba lo notara.
 *
 * - Los tres caminos, porque hay tres formas de retirar una matricula y el
 *   registro vive en el modelo precisamente para que no haya que acordarse en
 *   cada una. Si alguien lo mueve a los controladores, dos de estas tres se
 *   ponen rojas.
 */
class AuditoriaTest extends TestCase
{
    use RefreshDatabase;

    private Perfil $admin;

    private Perfil $estudiante;

    private Periodo $periodo;

    private Promotoria $violin;

    private string $archivo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->archivo = storage_path('logs/auditoria-'.now()->format('Y-m-d').'.log');
        File::delete($this->archivo);

        $this->periodo = Periodo::create([
            'nombre' => '2026-1',
            'fecha_inicio' => '2026-01-15',
            'fecha_fin' => '2026-06-30',
            'activo' => true,
            'matriculas_abiertas' => true,
        ]);

        $area = Area::create(['nombre' => 'Musica']);
        $this->violin = Promotoria::create(['nombre' => 'Violin', 'area_id' => $area->id]);

        $this->admin = $this->crearPerfil('jefa', 'administrador');
        $this->estudiante = $this->crearEstudiante('ana');
    }

    protected function tearDown(): void
    {
        File::delete($this->archivo);

        parent::tearDown();
    }

    /**
     * El canal no hereda `LOG_LEVEL`, que en produccion vale `error`.
     *
     * Sin esto, todo lo demas de esta clase seguiria pasando en local --donde
     * `LOG_LEVEL` es `debug`-- y no escribiria una sola linea en el servidor.
     */
    public function test_el_canal_de_auditoria_no_depende_del_nivel_del_entorno(): void
    {
        $this->assertSame('info', config('logging.channels.auditoria.level'));
        $this->assertNotSame(
            config('logging.channels.daily.level'),
            config('logging.channels.auditoria.level'),
            'El canal de auditoria volvio a colgar de LOG_LEVEL.'
        );
    }

    /** Camino 1: el estudiante retira una solicitud que aun no le habian confirmado. */
    public function test_deja_rastro_cuando_el_estudiante_retira_su_solicitud(): void
    {
        $matricula = $this->matricular(Matricula::PENDIENTE);

        $this->actingAs($this->estudiante->user)
            ->post(route('mis-matriculas.retirar', $matricula));

        $linea = $this->rastro('matricula.retirada');

        $this->assertStringContainsString('"desde":"pendiente"', $linea);
        $this->assertStringContainsString('"quien":'.$this->estudiante->id, $linea);
    }

    /** Camino 2: direccion aprueba una cancelacion, de una en una. */
    public function test_deja_rastro_cuando_direccion_aprueba_una_cancelacion(): void
    {
        $matricula = $this->matricular(Matricula::CANCELACION_SOLICITADA);

        $this->actingAs($this->admin->user)
            ->post('/gestion/cancelaciones/'.$matricula->id.'/aprobar');

        $linea = $this->rastro('matricula.retirada');

        $this->assertStringContainsString('"desde":"cancelacion_solicitada"', $linea);
        $this->assertStringContainsString('"quien":'.$this->admin->id, $linea);
    }

    /** Camino 3: el lote. Es el que se olvidaria si esto viviera en los controladores. */
    public function test_deja_rastro_cuando_se_aprueban_cancelaciones_en_lote(): void
    {
        $matricula = $this->matricular(Matricula::CANCELACION_SOLICITADA);

        $this->actingAs($this->admin->user)->post(route('gestion-cancelaciones-lote'), [
            'decision' => 'aprobar',
            'matricula_ids' => [$matricula->id],
        ]);

        $this->assertStringContainsString(
            '"matricula_id":'.$matricula->id,
            $this->rastro('matricula.retirada')
        );
    }

    /**
     * Rechazar tampoco se deshace, y por el modelo no pasa: no hay retirada.
     *
     * Con un MENOR a proposito: a un mayor de edad no se le rechaza la salida
     * --el sistema solo admite aprobarla-- asi que con el estudiante de 20 anos
     * del setUp esta prueba no probaba nada, porque la accion ni ocurria.
     */
    public function test_deja_rastro_cuando_se_rechaza_una_cancelacion(): void
    {
        $this->estudiante->fecha_nacimiento = Carbon::today()->subYears(12)->toDateString();
        $this->estudiante->save();

        $matricula = $this->matricular(Matricula::CANCELACION_SOLICITADA);

        $this->actingAs($this->admin->user)
            ->post('/gestion/cancelaciones/'.$matricula->id.'/rechazar');

        $this->assertStringContainsString(
            '"matricula_id":'.$matricula->id,
            $this->rastro('cancelacion.rechazada')
        );
    }

    public function test_deja_rastro_al_cerrar_el_enlace_de_una_actividad(): void
    {
        $taller = Actividad::create([
            'tipo' => Actividad::TALLER,
            'nombre' => 'Taller de cajón',
            'responsable_id' => $this->admin->id,
            'periodo_id' => $this->periodo->id,
        ]);

        $this->actingAs($this->admin->user)
            ->post(route('actividad-curso-enlace', $taller));

        $this->assertStringContainsString('"abierta":false', $this->rastro('actividad.enlace'));
    }

    /** Lo que NO se guarda: nombres y documentos se quedan en la base. */
    public function test_el_rastro_no_lleva_datos_personales(): void
    {
        $matricula = $this->matricular(Matricula::PENDIENTE);

        $this->actingAs($this->estudiante->user)
            ->post(route('mis-matriculas.retirar', $matricula));

        $linea = $this->rastro('matricula.retirada');

        $this->assertStringNotContainsString($this->estudiante->nombre_completo, $linea);
        $this->assertStringNotContainsString('1'.$this->estudiante->id.'"', $linea);
    }

    // -----------------------------------------------------------------------

    /** La linea del archivo de auditoria que registra `$accion`. */
    private function rastro(string $accion): string
    {
        $this->assertFileExists($this->archivo, "No se escribio nada en el canal {$accion}.");

        foreach (file($this->archivo, FILE_IGNORE_NEW_LINES) as $linea) {
            if (str_contains($linea, $accion)) {
                return $linea;
            }
        }

        $this->fail("El archivo de auditoria no registra «{$accion}».");
    }

    private function matricular(string $estado): Matricula
    {
        $matricula = new Matricula([
            'estudiante_id' => $this->estudiante->id,
            'promotoria_id' => $this->violin->id,
            'periodo_id' => $this->periodo->id,
            'estado' => $estado,
        ]);
        $matricula->save();

        return $matricula;
    }

    private function crearEstudiante(string $username): Perfil
    {
        $perfil = $this->crearPerfil($username, 'estudiante');

        DatosEstudiante::create([
            'perfil_id' => $perfil->id,
            'documento_identidad' => '1'.$perfil->id,
        ]);

        return $perfil;
    }

    private function crearPerfil(string $username, string $rol): Perfil
    {
        $user = User::create(['username' => $username, 'password' => 'x', 'activo' => true]);

        return Perfil::create([
            'user_id' => $user->id,
            'rol' => $rol,
            'nombre_completo' => ucfirst($username).' Pérez',
            'fecha_nacimiento' => Carbon::today()->subYears(20)->toDateString(),
            'telefono' => '3000000000',
        ]);
    }
}
