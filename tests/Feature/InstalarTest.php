<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\ConfiguracionInstitucion;
use App\Models\DocumentoRequerido;
use App\Models\Perfil;
use App\Models\Periodo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\PendingCommand;
use Tests\TestCase;

/**
 * `php artisan instalar`: montar una institucion nueva de una sentada.
 *
 * Lo que se comprueba aqui no es que las filas queden escritas --eso lo veria
 * cualquier prueba-- sino las tres cosas que este comando puede estropear:
 *
 * 1. Que la BARRERA distinga una base nueva de una que ya tiene datos, y que la
 *    fila de `configuracion_institucion` NO cuente como dato. Esa fila la crea
 *    sola la primera visita a cualquier pagina, asi que usarla como senal
 *    dejaria el comando inservible en cuanto alguien abriera la pantalla de
 *    entrar --que es exactamente lo que hace quien acaba de instalar--.
 * 2. Que el periodo quede EN CURSO. Es el paso que mas se olvida a mano y el que
 *    deja media aplicacion invisible sin dar ningun error.
 * 3. Que la ruta preguntada no termine con un solo administrador. La cuenta de
 *    un administrador solo la edita otro: con uno solo, perder el acceso obliga
 *    a entrar a la base a mano.
 */
class InstalarTest extends TestCase
{
    use RefreshDatabase;

    public function test_el_ejemplo_deja_la_institucion_montada_y_el_periodo_en_curso(): void
    {
        $this->artisan('instalar --ejemplo')->assertSuccessful();

        $this->assertSame('Casa de la Cultura', ConfiguracionInstitucion::actual()->nombre_institucion);
        $this->assertSame(4, Area::count());
        $this->assertSame(3, DocumentoRequerido::count());

        $periodo = Periodo::enCurso();

        $this->assertNotNull($periodo);
        $this->assertSame((string) Carbon::today()->year, $periodo->nombre);
        $this->assertTrue($periodo->admite_matriculas);
    }

    /**
     * La instalacion se comprueba abriendo Gestion, no contando filas.
     *
     * Es la unica forma de ver lo que de verdad se pedia: que despues del
     * comando alguien pueda ENTRAR y seguir montando el catalogo. Contar dos
     * perfiles con rol administrador no dice nada sobre si la contrasena quedo
     * utilizable ni sobre si la pantalla se abre.
     */
    public function test_despues_de_instalar_se_entra_a_gestion_con_la_cuenta_creada(): void
    {
        $this->artisan('instalar --ejemplo')->assertSuccessful();

        $this->post('/entrar', ['username' => 'admin', 'password' => 'administrador'])
            ->assertRedirect();

        $admin = User::where('username', 'admin')->firstOrFail();

        $this->actingAs($admin)->get('/gestion')->assertOk();
        $this->actingAs($admin)->get('/gestion/areas')->assertOk()->assertSee('Música');
    }

    public function test_el_ejemplo_crea_los_dos_administradores(): void
    {
        $this->artisan('instalar --ejemplo')->assertSuccessful();

        $this->assertSame(2, Perfil::where('rol', 'administrador')->count());
    }

    public function test_se_niega_si_ya_hay_cuentas_y_no_escribe_nada(): void
    {
        $user = User::create(['username' => 'alguien', 'password' => 'lo-que-sea-8', 'activo' => true]);

        Perfil::create([
            'user_id' => $user->id,
            'rol' => 'administrador',
            'nombre_completo' => 'Alguien Que Ya Estaba',
            'fecha_nacimiento' => '1990-01-01',
            'telefono' => '3000000000',
        ]);

        $this->artisan('instalar --ejemplo')
            ->expectsOutputToContain('Esto no parece una instalación nueva')
            ->expectsOutputToContain('1 cuenta de usuario')
            ->assertFailed();

        $this->assertSame(1, User::count());
        $this->assertSame(0, Area::count());
        $this->assertNull(Periodo::enCurso());
    }

    public function test_se_niega_si_ya_hay_catalogo(): void
    {
        Area::create(['nombre' => 'Música']);

        $this->artisan('instalar --ejemplo')
            ->expectsOutputToContain('1 departamento')
            ->assertFailed();

        $this->assertSame(0, User::count());
    }

    /**
     * La trampa que da nombre a media clase: la fila de configuracion no es un
     * dato instalado.
     *
     * Quita el comentario de `loQueYaHay` y anade `ConfiguracionInstitucion` a
     * la lista de tablas: esta prueba se pone roja y las otras siguen verdes.
     * En la vida real el sintoma seria peor que un fallo --el comando se
     * negaria a instalar una base recien migrada solo porque alguien abrio la
     * pantalla de entrar-- y el mensaje no daria ninguna pista.
     */
    public function test_haber_abierto_una_pagina_antes_no_cuenta_como_instalacion(): void
    {
        $this->get('/entrar')->assertOk();

        $this->assertSame(1, ConfiguracionInstitucion::count());

        $this->artisan('instalar --ejemplo')->assertSuccessful();

        $this->assertSame('Casa de la Cultura', ConfiguracionInstitucion::actual()->nombre_institucion);
        $this->assertSame(1, ConfiguracionInstitucion::count());
    }

    public function test_el_ejemplo_se_niega_en_produccion(): void
    {
        app()['env'] = 'production';

        $this->artisan('instalar --ejemplo')
            ->expectsOutputToContain('contraseñas conocidas')
            ->assertFailed();

        $this->assertSame(0, User::count());
    }

    /**
     * Si algo revienta a mitad de la escritura, no queda NADA escrito.
     *
     * Importa mas de lo que parece, y no por pulcritud: una instalacion a medias
     * --con departamentos y periodo, pero sin administrador-- es el unico estado
     * del que este comando ya no puede salir, porque su propia barrera le
     * cerraria la puerta al segundo intento. Quien la sufriera se quedaria con
     * una base que no admite el instalador y a la que tampoco puede entrar.
     *
     * El fallo se provoca en las cuentas, que son lo ULTIMO que se escribe: asi
     * la prueba mira si se deshace todo lo anterior, que es lo que se afirma.
     */
    public function test_un_fallo_a_media_escritura_no_deja_media_institucion(): void
    {
        Perfil::creating(function () {
            throw new \RuntimeException('Se cayó la base a mitad de la instalación.');
        });

        try {
            $this->artisan('instalar --ejemplo')->run();
            $this->fail('El comando debía propagar el fallo.');
        } catch (\RuntimeException) {
            // Es el que acabamos de provocar.
        }

        $this->assertSame(0, User::count());
        $this->assertSame(0, Area::count());
        $this->assertSame(0, DocumentoRequerido::count());
        $this->assertSame(0, Periodo::count());
    }

    /**
     * Sin terminal, la ruta preguntada se planta antes de escribir nada.
     *
     * Esta prueba tapa un CUELGUE, no una fealdad. Sin la puerta que comprueba
     * `isInteractive()`, un `php artisan instalar --no-interaction` --lo que
     * escribiria cualquiera en un guion de despliegue-- se queda dando vueltas
     * para siempre: `ask()` no pregunta, devuelve el valor por defecto (null en
     * las que no tienen), la validacion lo rechaza y el bucle que vuelve a
     * preguntar recibe otra vez lo mismo. Se comprobo a mano contra MariaDB:
     * tres minutos, cero lineas de salida y cero filas escritas.
     *
     * Lo que esta prueba NO cubre es el otro camino sin terminal, el de
     * canalizarle las respuestas desde un archivo: ahi la consola sigue
     * contando como interactiva, la puerta no salta y Symfony corta con un
     * «Aborted.» pelado. No se cuelga, que era lo grave.
     */
    public function test_sin_terminal_la_ruta_preguntada_no_instala(): void
    {
        $this->artisan('instalar', ['--no-interaction' => true])
            ->expectsOutputToContain('aquí no hay terminal')
            ->assertFailed();

        $this->assertSame(0, User::count());
        $this->assertSame(0, Area::count());
    }

    public function test_la_ruta_preguntada_escribe_lo_que_se_le_dice(): void
    {
        $this->instalarPreguntando()->assertSuccessful();

        $configuracion = ConfiguracionInstitucion::actual();

        $this->assertSame('Escuela de Artes del Santuario', $configuracion->nombre_institucion);
        $this->assertSame('#8a1538', $configuracion->color_acento);
        $this->assertSame(3, $configuracion->limite_promotorias_por_periodo);
        $this->assertFalse($configuracion->promotorias_visibles_para_estudiantes);

        $this->assertSame(['Danza', 'Música'], Area::orderBy('nombre')->pluck('nombre')->all());
        $this->assertSame(
            ['Documento de identidad', 'Recibo de servicios'],
            DocumentoRequerido::ordenados()->pluck('nombre')->all()
        );

        $periodo = Periodo::enCurso();

        $this->assertNotNull($periodo);
        $this->assertSame('2026-2', $periodo->nombre);

        // Las matriculas quedan CERRADAS a proposito: faltan promotorias, cupos
        // y grupos, y abrirlas antes deja entrar gente a un catalogo vacio.
        $this->assertFalse($periodo->matriculas_abiertas);
    }

    public function test_la_ruta_preguntada_no_termina_con_un_solo_administrador(): void
    {
        $this->instalarPreguntando()->assertSuccessful();

        $this->assertSame(
            ['directora', 'rector'],
            Perfil::where('rol', 'administrador')
                ->with('user')
                ->get()
                ->map(fn (Perfil $perfil) => $perfil->user->username)
                ->sort()
                ->values()
                ->all()
        );

        $this->post('/entrar', ['username' => 'rector', 'password' => 'clave-larga-1'])
            ->assertRedirect();
    }

    /**
     * Una respuesta que no vale se rechaza y se vuelve a preguntar, en vez de
     * llegar a la base y reventar la transaccion con todo lo demas ya tecleado.
     *
     * Las tres que se prueban son las tres que se equivocan de verdad: el color
     * sin almohadilla, la fecha escrita al reves y la contrasena que no coincide
     * con su repeticion --esta ultima es la que dejaria fuera del sistema al
     * administrador que acaba de instalarlo, porque no se ve al teclearla--.
     */
    public function test_una_respuesta_invalida_se_rechaza_y_se_vuelve_a_preguntar(): void
    {
        $this->instalarPreguntando(
            colorMalo: true,
            fechaMala: true,
            claveDescuadrada: true,
        )->assertSuccessful();

        $this->assertSame('#8a1538', ConfiguracionInstitucion::actual()->color_acento);
        $this->assertSame('2026-2', Periodo::enCurso()?->nombre);
        $this->assertSame(2, Perfil::where('rol', 'administrador')->count());
    }

    /**
     * El mismo recorrido de preguntas para todas las pruebas interactivas, con
     * tres dedazos opcionales intercalados.
     *
     * Van juntas a proposito: si manana se anade una pregunta al comando, esta
     * funcion es el unico sitio donde hay que tocarla, y las cinco pruebas que
     * cuelgan de ella siguen diciendo lo mismo.
     */
    private function instalarPreguntando(
        bool $colorMalo = false,
        bool $fechaMala = false,
        bool $claveDescuadrada = false,
    ): PendingCommand {
        $comando = $this->artisan('instalar')
            ->expectsQuestion('Nombre de la institución', 'Escuela de Artes del Santuario');

        if ($colorMalo) {
            $comando->expectsQuestion('Color de acento, en formato #rrggbb', '8a1538')
                ->expectsOutputToContain('formato #rrggbb');
        }

        $comando
            ->expectsQuestion('Color de acento, en formato #rrggbb', '#8a1538')
            ->expectsQuestion('Cuántas promotorías puede cursar una persona a la vez', '3')
            ->expectsConfirmation('¿El catálogo de promotorías se ve desde fuera, sin cuenta?', 'no')
            ->expectsQuestion('Documentos', 'Documento de identidad, Recibo de servicios')
            ->expectsQuestion('Departamentos', 'Música, Danza')
            ->expectsQuestion('Nombre del periodo', '2026-2');

        if ($fechaMala) {
            $comando->expectsQuestion('Fecha de inicio (AAAA-MM-DD)', '01/07/2026')
                ->expectsOutputToContain('AAAA-MM-DD');
        }

        $comando
            ->expectsQuestion('Fecha de inicio (AAAA-MM-DD)', '2026-07-01')
            ->expectsQuestion('Fecha de fin (AAAA-MM-DD)', '2026-12-15')
            ->expectsQuestion('  Nombre de usuario', 'rector');

        if ($claveDescuadrada) {
            $comando->expectsQuestion('  Contraseña (mínimo 8 caracteres)', 'clave-larga-1')
                ->expectsQuestion('  Repítela', 'clave-larga-2')
                ->expectsOutputToContain('no coinciden');
        }

        return $comando
            ->expectsQuestion('  Contraseña (mínimo 8 caracteres)', 'clave-larga-1')
            ->expectsQuestion('  Repítela', 'clave-larga-1')
            ->expectsQuestion('  Nombre completo', 'Quien Dirige La Casa')
            ->expectsQuestion('  Fecha de nacimiento (AAAA-MM-DD)', '1980-05-09')
            ->expectsQuestion('  Teléfono', '3001112233')
            ->expectsQuestion('  Nombre de usuario', 'directora')
            ->expectsQuestion('  Contraseña (mínimo 8 caracteres)', 'otra-clave-2')
            ->expectsQuestion('  Repítela', 'otra-clave-2')
            ->expectsQuestion('  Nombre completo', 'Quien Dirige La Escuela')
            ->expectsQuestion('  Fecha de nacimiento (AAAA-MM-DD)', '1985-11-30')
            ->expectsQuestion('  Teléfono', '3004445566');
    }
}
