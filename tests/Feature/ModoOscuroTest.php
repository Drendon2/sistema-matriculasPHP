<?php

namespace Tests\Feature;

use App\Models\ConfiguracionInstitucion;
use App\Models\Perfil;
use App\Models\User;
use App\Support\Color;
use App\Support\Tema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * MODO OSCURO — 08/09/2026.
 *
 * Tres estados: por defecto se sigue al sistema operativo, y quien quiera puede
 * forzar claro u oscuro desde Mi perfil. La preferencia vive en una galleta del
 * APARATO y no en la cuenta, y el servidor la estampa en el `<html>` antes de
 * enviar la pagina.
 *
 * Lo que estas pruebas vigilan no es que se vea bonito —eso se mira abriendo la
 * pagina, y ahi se descubrio el fallo del radio— sino las cuatro cosas que no
 * se ven:
 *
 * 1. Que el atributo lo ponga el SERVIDOR. Si algun dia alguien lo mueve a un
 *    guion de carga, vuelve el fogonazo blanco y ninguna captura lo delata,
 *    porque dura un cuadro.
 * 2. Que «sigue al sistema» sea la AUSENCIA del atributo y no una palabra.
 * 3. Que el acento de la institucion se derive tambien para el fondo oscuro, y
 *    que cumpla el contraste con CUALQUIER color de marca, no solo el verde.
 * 4. Que la hoja mantenga el respaldo antes de cada `light-dark()`: sin el, un
 *    navegador viejo se queda sin color en vez de sin modo oscuro.
 */
class ModoOscuroTest extends TestCase
{
    use RefreshDatabase;

    private function crearAdministrador(): User
    {
        $user = User::create(['username' => 'jefa', 'password' => 'demo1234', 'activo' => true]);

        Perfil::create([
            'user_id' => $user->id,
            'rol' => 'administrador',
            'nombre_completo' => 'Jefa Ruiz',
            'fecha_nacimiento' => '1990-04-04',
            'telefono' => '3001112233',
        ]);

        return $user;
    }

    // --------------------------------------------------------------------
    // Los tres estados
    // --------------------------------------------------------------------

    /**
     * Sin galleta NO hay atributo, y eso es lo que significa «sigue al sistema».
     *
     * Se comprueba la ausencia y no un valor porque el CSS depende de ella: con
     * `data-tema` puesto a cualquier cosa, `color-scheme` deja de valer
     * `light dark` y la pagina se queda fija en un modo, ignorando el sistema.
     */
    public function test_sin_preferencia_el_html_no_lleva_el_atributo(): void
    {
        $html = (string) $this->actingAs($this->crearAdministrador())
            ->get(route('mi-perfil'))->assertOk()->getContent();

        $this->assertStringContainsString('<html lang="es">', $html);
        $this->assertStringNotContainsString('data-tema', $html);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function losDosForzados(): array
    {
        return ['claro' => ['claro'], 'oscuro' => ['oscuro']];
    }

    #[DataProvider('losDosForzados')]
    public function test_con_preferencia_el_servidor_estampa_el_html(string $tema): void
    {
        $html = (string) $this->actingAs($this->crearAdministrador())
            ->withCookie(Tema::GALLETA, $tema)
            ->get(route('mi-perfil'))->assertOk()->getContent();

        $this->assertStringContainsString('<html lang="es" data-tema="'.$tema.'">', $html);
    }

    /** Y tambien antes de entrar: la galleta es del aparato, no de la cuenta. */
    public function test_la_pantalla_de_entrar_tambien_respeta_la_preferencia(): void
    {
        $html = (string) $this->withCookie(Tema::GALLETA, 'oscuro')
            ->get(route('login'))->assertOk()->getContent();

        $this->assertStringContainsString('data-tema="oscuro"', $html);
    }

    /** Una galleta con basura dentro se ignora, no rompe la pagina. */
    public function test_una_galleta_con_un_valor_inventado_se_ignora(): void
    {
        $html = (string) $this->withCookie(Tema::GALLETA, 'fucsia')
            ->get(route('login'))->assertOk()->getContent();

        $this->assertStringNotContainsString('data-tema', $html);
    }

    // --------------------------------------------------------------------
    // Guardar la eleccion
    // --------------------------------------------------------------------

    public function test_elegir_oscuro_guarda_la_galleta(): void
    {
        $this->actingAs($this->crearAdministrador())
            ->from(route('mi-perfil'))
            ->post(route('tema'), ['tema' => 'oscuro'])
            ->assertRedirect(route('mi-perfil'))
            ->assertCookie(Tema::GALLETA, 'oscuro');
    }

    /**
     * Volver a «sistema» BORRA la galleta en vez de escribir la palabra.
     *
     * El estado por defecto no deja rastro: si se guardara «sistema», un aparato
     * que nunca eligio nada y otro que volvio al principio quedarian distintos
     * en la base de galletas sin ninguna razon.
     */
    public function test_volver_al_sistema_borra_la_galleta(): void
    {
        $respuesta = $this->actingAs($this->crearAdministrador())
            ->from(route('mi-perfil'))
            ->withCookie(Tema::GALLETA, 'oscuro')
            ->post(route('tema'), ['tema' => 'sistema'])
            ->assertRedirect(route('mi-perfil'));

        $galleta = collect($respuesta->headers->getCookies())
            ->first(fn ($c) => $c->getName() === Tema::GALLETA);

        // Lo que borra una galleta es su FECHA DE CADUCIDAD en el pasado, no
        // su valor: `forget()` la manda a 2021 y deja el valor nulo. Comprobar
        // el valor daria verde tambien con una galleta vacia pero VIVA, que no
        // borraria nada.
        $this->assertNotNull($galleta);
        $this->assertLessThan(time(), $galleta->getExpiresTime());
    }

    public function test_un_tema_inventado_se_rechaza(): void
    {
        $this->actingAs($this->crearAdministrador())
            ->from(route('mi-perfil'))
            ->post(route('tema'), ['tema' => 'fucsia'])
            ->assertSessionHasErrors('tema');
    }

    /**
     * La ruta NO pide sesion, y esa prueba parece tonta hasta que se piensa: la
     * galleta sobrevive al cierre de sesion, asi que quien eligio oscuro tiene
     * que poder cambiarlo desde la pantalla de entrar el dia que ese selector
     * se ponga tambien alli.
     */
    public function test_se_puede_cambiar_el_tema_sin_sesion(): void
    {
        $this->from(route('login'))
            ->post(route('tema'), ['tema' => 'oscuro'])
            ->assertRedirect(route('login'))
            ->assertCookie(Tema::GALLETA, 'oscuro');
    }

    // --------------------------------------------------------------------
    // El acento de la institucion
    // --------------------------------------------------------------------

    /**
     * @return array<string, array{string}>
     */
    public static function coloresDeMarca(): array
    {
        return [
            'verde de fabrica' => ['#0a7a59'],
            'azul' => ['#2458d6'],
            'rojo' => ['#b22e22'],
            'ocre' => ['#8f5e10'],
            'violeta' => ['#7c4fce'],
            'casi negro' => ['#111111'],
            'casi blanco' => ['#f4f4f4'],
        ];
    }

    /**
     * El acento derivado CUMPLE con cualquier color de marca.
     *
     * Es la prueba que justifica buscar la luminosidad por pasos en vez de usar
     * una constante: este producto se vende a otras instituciones y cada una
     * elige el suyo. Una constante afinada con el verde de fabrica dejaria un
     * azul marino ilegible sobre el fondo oscuro, y nadie de aqui lo veria.
     */
    #[DataProvider('coloresDeMarca')]
    public function test_el_acento_derivado_se_lee_sobre_el_fondo_oscuro(string $marca): void
    {
        $superficie = '#1c2421';
        $trio = Color::acentoParaFondoOscuro($marca, $superficie);

        $this->assertGreaterThanOrEqual(
            Color::CONTRASTE_MINIMO_ACENTO,
            Color::contraste($trio['claro'], $superficie),
            "el acento derivado de {$marca} no se lee sobre el fondo oscuro."
        );

        // El hover tiene que DISTINGUIRSE del reposo: una version anterior
        // devolvia el mismo color y el boton se quedaba sin respuesta al tacto.
        $this->assertNotSame($trio['claro'], $trio['hover']);

        // Y el texto de acento sobre su propio tinte, que es la pastilla.
        $this->assertGreaterThanOrEqual(
            Color::CONTRASTE_MINIMO_ACENTO,
            Color::contraste($trio['claro'], $trio['suave']),
            "la pastilla de {$marca} no se lee."
        );
    }

    /** El envoltorio pinta las dos versiones del acento de la entidad. */
    public function test_el_envoltorio_lleva_el_acento_en_sus_dos_versiones(): void
    {
        $config = ConfiguracionInstitucion::actual();
        $config->color_acento = '#2458d6';
        $config->save();

        $html = (string) $this->actingAs($this->crearAdministrador())
            ->get(route('mi-perfil'))->assertOk()->getContent();

        $oscuro = Color::acentoParaFondoOscuro('#2458d6');

        $this->assertStringContainsString('light-dark(#2458d6, '.$oscuro['claro'].')', $html);
    }

    // --------------------------------------------------------------------
    // La hoja de estilo
    // --------------------------------------------------------------------

    /**
     * CADA `light-dark()` VA PRECEDIDO DE SU RESPALDO.
     *
     * Es la prueba mas importante del archivo y la que no delata ninguna
     * captura. Un navegador que no conoce `light-dark()` descarta esa
     * declaracion por invalida; si no hay otra antes con el valor claro, el
     * token se queda SIN VALOR y la pagina pierde el color entero — bastante
     * peor que no tener modo oscuro. Se soporta desde 2024 y aqui se entra
     * desde telefonos que pueden ser mas viejos.
     */
    #[DataProvider('lasDosHojas')]
    public function test_cada_light_dark_lleva_su_respaldo(string $hoja): void
    {
        $css = (string) file_get_contents(public_path($hoja));
        $sinComentarios = (string) preg_replace('#/\*.*?\*/#s', '', $css);

        preg_match_all('/^\s*(--[a-z0-9-]+):\s*light-dark\(/mi', $sinComentarios, $conFuncion);

        $this->assertNotEmpty($conFuncion[1], "{$hoja} no usa light-dark().");

        foreach (array_unique($conFuncion[1]) as $token) {
            // Se busca el respaldo POR POSICION y no con un patron de dos
            // renglones seguidos: los ocho tokens de etiqueta declaran el suyo
            // en la misma linea que su `-bg`, que es CSS correcto, y el patron
            // rigido daba ocho falsos positivos.
            $conFuncionEn = mb_strpos($sinComentarios, $token.': light-dark(');
            $respaldoEn = mb_strpos($sinComentarios, $token.': #');

            if ($respaldoEn === false) {
                $respaldoEn = mb_strpos($sinComentarios, $token.': rgba(');
            }

            $this->assertNotFalse($respaldoEn, "{$token} usa light-dark() sin ningun respaldo en {$hoja}.");
            $this->assertLessThan(
                $conFuncionEn,
                $respaldoEn,
                "el respaldo de {$token} va DESPUES de su light-dark() en {$hoja}: no serviria de nada."
            );
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function lasDosHojas(): array
    {
        return ['con sesion' => ['css/app.css'], 'publica' => ['css/publico.css']];
    }

    /**
     * `light-dark()` toma DOS argumentos, y una sombra lleva comas dentro.
     *
     * Con una sombra completa dentro de la funcion el navegador lee cuatro
     * argumentos, descarta la declaracion y la tarjeta se queda plana. Pasó al
     * escribirlo; por eso las sombras se componen de tintas tokenizadas.
     */
    #[DataProvider('lasDosHojas')]
    public function test_ningun_light_dark_lleva_mas_de_dos_argumentos(string $hoja): void
    {
        $css = (string) preg_replace('#/\*.*?\*/#s', '', (string) file_get_contents(public_path($hoja)));

        preg_match_all('/light-dark\(([^;]*)\)\s*;/', $css, $usos);

        foreach ($usos[1] as $argumentos) {
            $sinParentesis = (string) preg_replace('/\([^()]*\)/', '', $argumentos);

            $this->assertSame(
                1,
                substr_count($sinParentesis, ','),
                "light-dark({$argumentos}) no tiene exactamente dos argumentos."
            );
        }
    }

    /** Los tres estados existen, que es lo que hace que el interruptor mande. */
    #[DataProvider('lasDosHojas')]
    public function test_la_hoja_declara_los_tres_estados(string $hoja): void
    {
        $css = (string) file_get_contents(public_path($hoja));

        $this->assertStringContainsString('color-scheme: light dark;', $css);
        $this->assertStringContainsString('[data-tema="claro"] { color-scheme: light; }', $css);
        $this->assertStringContainsString('[data-tema="oscuro"] { color-scheme: dark; }', $css);
    }
}
