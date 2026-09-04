<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\DatosEstudiante;
use App\Models\Grupo;
use App\Models\Matricula;
use App\Models\Perfil;
use App\Models\Periodo;
use App\Models\Promotoria;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
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
    use RefreshDatabase;

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

    /**
     * LA BANDA DE ENMEDIO SE CIERRA ESCONDIENDO DOS COLUMNAS, no arrastrando.
     *
     * Medido en Chrome el 04/09/2026 con datos reales: la lista de quienes no
     * tienen grupo pedia 856px de minimo y la de pendientes 764, contra una caja
     * que es la ventana menos 55. O sea que de 641 a 911px de ventana ninguna de
     * las dos cabia. Sin Telefono ni Acudiente las dos bajan por debajo de 643 y
     * caben en toda la banda.
     *
     * El tope es 1000 y no 911 porque el contenedor del Panel esta topado en
     * 920px: entre 911 y 1000 la tabla completa cabe con 64px de holgura como
     * mucho, y a 912 con uno.
     */
    public function test_la_banda_de_enmedio_esconde_telefono_y_acudiente(): void
    {
        $this->assertMatchesRegularExpression(
            '/@media \(max-width: 1000px\) \{\s*'
            .'\[data-cuerpo-destino\] \.tabla-personas \[data-col="telefono"\],\s*'
            .'\[data-cuerpo-destino\] \.tabla-personas \[data-col="acudiente"\] \{[^}]*display:\s*none/',
            $this->css(),
            'la banda dejó de esconder Teléfono y Acudiente: las dos tablas del Panel '
            .'vuelven a pedir más ancho del que hay entre el teléfono y el escritorio.'
        );
    }

    /**
     * Y ademas estrecha el desplegable de «Asignar a».
     *
     * Quitar las dos columnas no basta abajo del todo: sin ellas la lista sigue
     * pidiendo 634 y a 660px de ventana solo tiene 603. Esos 48px que faltaban
     * —de 641 a 689— son un iPhone 8 o un SE en horizontal, que miden 667.
     *
     * Sigue siendo `width` y no `max-width`, por lo mismo que la regla de
     * escritorio: con un tope el control se dibuja estrecho y sigue reclamando
     * el ancho de su opcion mas larga para el reparto de columnas.
     */
    public function test_en_la_banda_el_desplegable_de_asignar_se_estrecha(): void
    {
        $this->assertMatchesRegularExpression(
            '/\[data-cuerpo-destino\] \.tabla-personas td\[data-celda="accion"\] select \{'
            .'[^}]*(?<!-)\bwidth:\s*\d+(\.\d+)?rem/',
            $this->css(),
            'el desplegable de la banda perdió su ancho fijo más estrecho: a 660px la lista '
            .'de sin grupo vuelve a pedir 634 dentro de 603.'
        );
    }

    /**
     * LAS DOS CONSULTAS DE ANCHO SE TOCAN, sin hueco en medio.
     *
     * EL FALLO, visto en Chrome el 04/09/2026: con `max-width: 640px` de un lado
     * y `min-width: 641px` del otro queda un hueco de 1px en el que no aplica
     * NINGUNA de las dos. Y es alcanzable: el ancho del viewport es fraccionario
     * en cuanto la pantalla va escalada —Windows al 125% es lo corriente—, y a
     * 640,5 las dos daban false. Ahi el Panel desbordaba el documento entero
     * —878px dentro de 626— y encima sin barra en la tabla, porque la red que la
     * pone vive dentro de la consulta de escritorio y tampoco aplicaba.
     *
     * `not all and (...)` es el complemento exacto y no admite hueco por
     * construccion. Dos numeros distintos, si.
     */
    public function test_no_queda_hueco_entre_la_vista_de_telefono_y_la_de_escritorio(): void
    {
        $css = $this->css();

        $this->assertStringContainsString(
            '@media not all and (max-width: 640px) {',
            $css,
            'la consulta de escritorio dejó de ser el complemento exacto de la del teléfono.'
        );

        $this->assertDoesNotMatchRegularExpression(
            '/@media[^{]*\(min-width:\s*641px\)/',
            $css,
            'volvió un `min-width: 641px`: entre 640 y 641 no aplicará ninguna de las dos.'
        );
    }

    /**
     * Los marcadores siguen puestos en las DOS tablas que desbordan.
     *
     * Esta es la mitad fragil: `data-col` no cambia una sola pantalla ancha, asi
     * que quien lo quite creyendo que sobra no rompera nada que vaya a mirar.
     * Sin el, la regla de arriba compila, la pagina carga, y la tabla vuelve a
     * salirse justo en los anchos que nadie usa para desarrollar.
     */
    public function test_las_dos_tablas_que_desbordan_llevan_sus_marcadores(): void
    {
        $html = $this->cuerpoDelPanel();

        $this->assertSame(
            4,
            substr_count($html, 'data-col="telefono"'),
            'faltan marcadores de teléfono: son el encabezado y la celda de las DOS tablas anchas.'
        );

        $this->assertSame(
            4,
            substr_count($html, 'data-col="acudiente"'),
            'faltan marcadores de acudiente: son el encabezado y la celda de las DOS tablas anchas.'
        );
    }

    /**
     * La lista de UN GRUPO no los lleva, y es a proposito.
     *
     * Cabe: medida, pide 603 y nunca desborda. Esconder una columna que cabe es
     * perder un dato a cambio de nada.
     */
    public function test_la_lista_de_un_grupo_conserva_telefono_y_acudiente(): void
    {
        $html = $this->cuerpoDelPanel();

        $lista = $this->trozoEntre($html, '<details class="grupo-lista"', '</details>');

        $this->assertStringContainsString('Teléfono', $lista, 'la sonda no vale: no es la lista de un grupo.');
        $this->assertStringNotContainsString(
            'data-col=',
            $lista,
            'la lista de un grupo se llevó marcadores: pierde dos columnas en una banda donde cabe.'
        );
    }

    /** El cuerpo de una promotoria con las tres tablas pintadas. */
    private function cuerpoDelPanel(): string
    {
        $periodo = Periodo::create([
            'nombre' => '2026-1',
            'fecha_inicio' => '2026-01-15',
            'fecha_fin' => '2026-06-30',
            'activo' => true,
            'matriculas_abiertas' => true,
        ]);

        $admin = $this->perfil('jefa', 'administrador');
        $area = Area::create(['nombre' => 'Musica']);

        $promotoria = Promotoria::create([
            'nombre' => 'Violin',
            'area_id' => $area->id,
            'profesor_id' => $this->perfil('profe', 'profesor')->id,
        ]);

        $grupo = Grupo::create([
            'promotoria_id' => $promotoria->id,
            'nombre' => 'Grupo 1',
            'nivel' => 'basico',
            'salon' => 'A1',
            'cupo_maximo' => 10,
        ]);

        // Una de cada: pendiente, con grupo y sin grupo. Las tres tablas.
        $this->matricula($promotoria, $periodo, 'ana', Matricula::PENDIENTE);
        $this->matricula($promotoria, $periodo, 'beto', Matricula::ACTIVA, $grupo);
        $this->matricula($promotoria, $periodo, 'carla', Matricula::ACTIVA);

        $html = $this->actingAs($admin->user)
            ->get(route('panel-promotoria-cuerpo', $promotoria))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Sin grupo asignado', $html, 'la sonda no vale: falta una de las tablas.');
        $this->assertStringContainsString('Pendientes de confirmación', $html, 'la sonda no vale: falta una de las tablas.');

        return $html;
    }

    private function trozoEntre(string $html, string $desde, string $hasta): string
    {
        $i = strpos($html, $desde);
        $this->assertNotFalse($i, "la sonda no vale: no aparece «{$desde}».");

        $j = strpos($html, $hasta, $i);
        $this->assertNotFalse($j, "la sonda no vale: no aparece «{$hasta}».");

        return substr($html, $i, $j - $i);
    }

    private function matricula(
        Promotoria $promotoria,
        Periodo $periodo,
        string $nombre,
        string $estado,
        ?Grupo $grupo = null,
    ): void {
        $estudiante = $this->perfil($nombre, 'estudiante');

        DatosEstudiante::create([
            'perfil_id' => $estudiante->id,
            'documento_identidad' => '1'.$estudiante->id,
        ]);

        $matricula = new Matricula([
            'estudiante_id' => $estudiante->id,
            'promotoria_id' => $promotoria->id,
            'periodo_id' => $periodo->id,
            'grupo_id' => $grupo?->id,
            'estado' => $estado,
        ]);
        $matricula->save();
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
