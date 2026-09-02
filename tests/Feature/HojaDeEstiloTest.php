<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Que las hojas de estilo no tengan un comentario partido.
 *
 * Existe por un fallo que mordio DOS veces el 01/09/2026 en la misma tarde, y
 * las dos de la misma forma. Al ampliar un comentario largo se acaba con dos
 * cierres y un solo `/*`:
 *
 *     ... la razon de esto. FIN-DE-COMENTARIO
 *        Y esta segunda parte se quedo suelta. FIN-DE-COMENTARIO
 *     .una-clase { position: relative; }
 *
 * El navegador no se queja: lee «Y esta segunda parte...» como un selector
 * invalido y, para recuperarse, se traga TODO hasta el final del primer bloque
 * que encuentre — o sea la regla siguiente entera. La hoja carga, la pagina se
 * pinta y una regla ha desaparecido. La primera vez dejo el menu de fila
 * colocandose contra la ventana en vez de contra su fila; se vio en una captura,
 * no en la suite.
 *
 * No comprueba estilo ni gusto: solo que cada cierre de comentario tenga su
 * apertura. Es lo unico de esto que una prueba puede saber.
 */
class HojaDeEstiloTest extends TestCase
{
    public function test_ninguna_hoja_tiene_un_comentario_partido(): void
    {
        $rotas = [];

        foreach (File::files(public_path('css')) as $archivo) {
            if ($archivo->getExtension() !== 'css') {
                continue;
            }

            $linea = $this->cierreSuelto($archivo->getContents());

            if ($linea !== null) {
                $rotas[] = "{$archivo->getFilename()}: cierre de comentario sin apertura en la línea {$linea}";
            }
        }

        $this->assertSame([], $rotas, implode("\n", $rotas));
    }

    /**
     * La linea del primer `*` + `/` que no tenga su `/` + `*` delante, o null.
     *
     * Se recorre a mano y no con una expresion regular porque lo que importa es
     * el ANIDAMIENTO, y CSS no anida comentarios: dos aperturas seguidas siguen
     * necesitando un solo cierre, asi que la profundidad no pasa de uno.
     */
    private function cierreSuelto(string $css): ?int
    {
        $dentro = false;
        $largo = strlen($css);

        for ($i = 0; $i < $largo - 1; $i++) {
            $par = $css[$i].$css[$i + 1];

            if (! $dentro && $par === '/*') {
                $dentro = true;
                $i++;

                continue;
            }

            if ($par === '*/') {
                if (! $dentro) {
                    return substr_count(substr($css, 0, $i), "\n") + 1;
                }

                $dentro = false;
                $i++;
            }
        }

        return null;
    }
}
