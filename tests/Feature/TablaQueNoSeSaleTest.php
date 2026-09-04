<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Que la lista de quienes no tienen grupo no se salga de la pantalla.
 *
 * EL FALLO, del 04/09/2026: en el Panel, la lista de estudiantes sin grupo
 * medía 1087px dentro de un contenedor de 920, y esos 167 de más no se
 * recortaban con barra sino sin ella — sobre 640px manda
 * `table { overflow: hidden }`. La última columna, «Ver detalle», no se veía NI
 * se podía alcanzar. En escritorio, que es donde se reparte a la gente en
 * grupos.
 *
 * LA CAUSA no era la tabla sino el desplegable de «Asignar a». Un `select` no
 * encoge por debajo de su opción más larga, y aquí cada opción es el rótulo
 * entero del grupo —«Grupo A · Básico · Miércoles 8:00 a. m. a 10:00 a. m.
 * (17/20)», sesenta caracteres—. Pedía 461px él solo.
 *
 * LO QUE ESTA CLASE VIGILA es la parte frágil del arreglo, que es una sola
 * palabra: tiene que ser `width` y no `max-width`. Con un tope el control se
 * DIBUJA estrecho y sigue RECLAMANDO el ancho de su opción más larga para el
 * reparto de columnas; probado, la tabla se quedaba en los mismos 1087. Quien
 * lo «arregle» devolviéndolo a `max-width` no romperá nada que vea: la página
 * carga, la tabla se pinta, y la columna que desaparece es la última.
 *
 * LO QUE NO PUEDE VER: los anchos. PHPUnit no tiene navegador. La medición se
 * hizo con Chrome sin cabeza sobre las dieciocho tablas del sistema a 1440,
 * 1280, 1024 y 768px. Esto solo vigila que las reglas de las que depende sigan
 * escritas.
 */
class TablaQueNoSeSaleTest extends TestCase
{
    private function css(): string
    {
        return (string) File::get(public_path('css/app.css'));
    }

    /**
     * El desplegable lleva un ancho FIJO, no un tope.
     *
     * Es la línea que sostiene todo lo demás.
     */
    public function test_el_desplegable_de_asignar_lleva_ancho_fijo(): void
    {
        preg_match('/\.tabla-personas td select \{([^}]*)\}/', $this->css(), $regla);

        $this->assertNotEmpty($regla, 'desapareció la regla del desplegable de una fila.');

        $this->assertMatchesRegularExpression(
            '/(?<!-)\bwidth:\s*\d+(\.\d+)?rem/',
            $regla[1],
            'el desplegable volvió a medirse por su contenido: con `max-width` la columna '
            .'sigue reclamando el ancho de la opción más larga y la tabla se sale otra vez.'
        );
    }

    /**
     * En la ficha de móvil el mismo control va a ancho completo.
     *
     * Es la guarda de la prueba de arriba: si las dos reglas se fundieran en una
     * sola, el ancho fijo de escritorio se llevaría por delante la ficha del
     * teléfono —un desplegable de 13rem dentro de una tarjeta de 390px— y la
     * prueba anterior seguiría verde.
     */
    public function test_en_la_ficha_de_movil_el_desplegable_sigue_a_ancho_completo(): void
    {
        $this->assertMatchesRegularExpression(
            '/\.tabla-personas td\[data-celda="accion"\] select \{[^}]*width:\s*100%/',
            $this->css(),
            'la ficha del teléfono perdió su desplegable a ancho completo.'
        );
    }

    /** Una acción de fila no se parte en dos renglones. */
    public function test_la_accion_de_una_fila_no_se_parte_en_dos_renglones(): void
    {
        $this->assertMatchesRegularExpression(
            '/\.tabla-personas td\[data-celda="accion"\] > a \{[^}]*white-space:\s*nowrap/',
            $this->css(),
            '«Ver detalle» volvió a poder partirse después de «Ver».'
        );
    }

    /**
     * La red de la banda de enmedio sigue puesta, y sigue acotada al Panel.
     *
     * Las dos mitades importan. Sin ella, entre 641 y 1000px lo que sobra se
     * recorta sin barra. Y sin el `[data-cuerpo-destino]` delante alcanzaría a
     * las tablas de Usuarios, los catálogos y las actividades, que llevan menús
     * de fila colocados por encima: `overflow` en la tabla los recortaría, que
     * es un fallo que este proyecto ya tuvo una vez.
     */
    public function test_la_red_de_la_banda_de_enmedio_va_acotada_al_panel(): void
    {
        $css = $this->css();

        $this->assertMatchesRegularExpression(
            '/\[data-cuerpo-destino\] \.tabla-personas \{[^}]*overflow-x:\s*auto/',
            $css,
            'se fue la red que hace alcanzable lo que sobra entre el teléfono y el escritorio.'
        );

        $this->assertDoesNotMatchRegularExpression(
            '/^\s*\.tabla-personas \{[^}]*overflow-x:\s*auto/m',
            $css,
            'la red se soltó a TODAS las tablas de personas: eso recorta los menús de fila.'
        );
    }
}
