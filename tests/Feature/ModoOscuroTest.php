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
 * Dos opciones desde el 12/09/2026 —claro u oscuro, en un boton del menu— y un
 * estado inicial que no se elige: sin galleta se sigue al sistema. Antes habia
 * TRES y «lo que diga mi dispositivo» era una de ellas, en un selector de radios
 * en Mi perfil; el porque del cambio esta en `Support\Tema`.
 *
 * La preferencia vive en una galleta del APARATO y no en la cuenta, y el
 * servidor la estampa en el `<html>` antes de enviar la pagina.
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
     *
     * SE BUSCA `data-tema="` Y NO `data-tema`, y el detalle importa: desde el
     * 12/09/2026 el menu trae un `data-tema-forma` —el marcador del formulario
     * del boton— que contiene esa cadena sin ser el atributo. Buscarla pelada
     * ponia roja esta prueba con el codigo bueno.
     */
    public function test_sin_preferencia_el_html_no_lleva_el_atributo(): void
    {
        $html = (string) $this->actingAs($this->crearAdministrador())
            ->get(route('mi-perfil'))->assertOk()->getContent();

        $this->assertStringContainsString('<html lang="es">', $html);
        $this->assertStringNotContainsString('data-tema="', $html);
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

        // `data-tema="` por lo mismo que arriba, aunque esta pantalla use el
        // envoltorio publico y hoy no traiga el boton: la cadena pelada es una
        // trampa esperando a que alguien lo ponga tambien ahi.
        $this->assertStringNotContainsString('data-tema="', $html);
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
     * «sistema» YA NO SE ACEPTA, y esta prueba sustituye a la que comprobaba que
     * volver a el borraba la galleta.
     *
     * El 12/09/2026 el selector de tres radios de Mi perfil se cambio por un
     * boton de sol y luna en el menu, y con el se fue esa tercera opcion. Esta
     * prueba es el testigo de esa decision: se pone roja el dia que alguien
     * devuelva «sistema» a `Tema::OPCIONES` sin devolver tambien una pantalla
     * que lo ofrezca.
     */
    public function test_volver_al_sistema_ya_no_es_una_opcion(): void
    {
        $this->actingAs($this->crearAdministrador())
            ->from(route('mi-perfil'))
            ->post(route('tema'), ['tema' => 'sistema'])
            ->assertSessionHasErrors('tema');
    }

    // --------------------------------------------------------------------
    // El boton del menu (12/09/2026)
    // --------------------------------------------------------------------

    /**
     * SON DOS BOTONES EN EL HTML, uno por tema, y de eso depende que funcione
     * sin JavaScript.
     *
     * Con uno solo habria que calcular en el SERVIDOR el tema contrario al que
     * se esta pintando, y eso es exactamente lo que el servidor no puede saber
     * cuando no hay galleta: quien no ha elegido nunca sigue a su sistema
     * operativo, que solo conoce el navegador. Aqui se comprueba que los dos
     * valores viajan en el HTML; cual se VE lo decide el CSS, y eso lo vigila la
     * prueba de abajo.
     */
    public function test_el_menu_trae_los_dos_botones_de_tema(): void
    {
        $html = $this->actingAs($this->crearAdministrador())
            ->get(route('mi-perfil'))
            ->assertOk()
            ->getContent();

        $this->assertIsString($html);

        // El formulario, con su marcador para el guion.
        $this->assertStringContainsString('data-tema-forma', $html);

        // Un boton por tema, cada uno con su valor.
        $this->assertStringContainsString('name="tema" value="oscuro"', $html);
        $this->assertStringContainsString('name="tema" value="claro"', $html);

        // Y con nombre para quien no ve el icono. El `aria-label` no es adorno:
        // el boton es solo un dibujo, asi que sin el no dice nada.
        $this->assertStringContainsString('aria-label="Cambiar a modo oscuro"', $html);
        $this->assertStringContainsString('aria-label="Cambiar a modo claro"', $html);
    }

    /**
     * La hoja decide cual de los dos botones se ve, y las reglas del atributo
     * van DESPUES de la consulta de medios.
     *
     * Las dos pesan lo mismo (0,3,0) porque una consulta de medios NO anade
     * especificidad —trampa escrita de este proyecto—, asi que lo unico que
     * hace ganar a la eleccion de la persona sobre la de su sistema es el ORDEN.
     * Invertirlo no rompe nada en un sistema en claro, que es donde se mira.
     */
    public function test_la_hoja_ordena_las_reglas_del_boton_de_tema(): void
    {
        $css = file_get_contents(public_path('css/app.css'));
        $this->assertIsString($css);

        $consulta = strpos($css, ':root:not([data-tema="claro"]) .tema-a-claro');
        $atributo = strpos($css, ':root[data-tema="oscuro"] .tema-a-claro');

        $this->assertNotFalse($consulta, 'falta la regla que sigue al sistema');
        $this->assertNotFalse($atributo, 'falta la regla que obedece al atributo');
        $this->assertLessThan(
            $atributo,
            $consulta,
            'las reglas de `data-tema` tienen que ir DESPUES de la consulta de medios: pesan lo mismo y decide el orden'
        );
    }

    /**
     * El guion del cambio instantaneo esta cargado.
     *
     * No comprueba que funcione —PHPUnit no tiene navegador— sino que siga
     * enganchado: sin el, el boton sigue funcionando pero cuesta una recarga, y
     * eso es una degradacion que nadie ve fallar.
     */
    public function test_el_guion_del_cambio_instantaneo_va_cargado(): void
    {
        $this->actingAs($this->crearAdministrador())
            ->get(route('mi-perfil'))
            ->assertOk()
            ->assertSee('js/tema.js', escape: false);
    }

    /**
     * Y Mi perfil YA NO trae el selector.
     *
     * Es la otra mitad de la decision: si se quedaran los dos, habria dos sitios
     * donde elegir el tema y uno de ellos con una opcion que el otro no tiene.
     */
    public function test_mi_perfil_ya_no_trae_el_selector_de_tema(): void
    {
        $this->actingAs($this->crearAdministrador())
            ->get(route('mi-perfil'))
            ->assertOk()
            ->assertDontSee('tema-opciones')
            ->assertDontSee('Lo que diga mi dispositivo');
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
