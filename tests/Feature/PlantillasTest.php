<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Que TODAS las plantillas compilen, se rendericen alguna vez o no.
 *
 * Existe por un fallo concreto que ya mordio dos veces: Blade no compila una
 * directiva pegada a una letra —`cupo@endif` lo trata como parte de un correo y
 * lo deja literal—, y el bloque abierto revienta al compilar. Es invisible en el
 * codigo y no lo detecta ninguna prueba funcional, porque una pantalla solo
 * falla si esa rama concreta llega a pintarse: la primera vez se colo en el
 * catalogo, y la segunda en la renovacion y en el panel de asistencia, dos
 * caminos que las pruebas de entonces nunca ejercitaban.
 *
 * Compilar no es renderizar: no hacen falta datos ni sesion, asi que esto cubre
 * de una vez las 60 plantillas y todas sus ramas. No dice que la pantalla este
 * bien; dice que no esta rota de una forma que nadie veria hasta produccion.
 */
class PlantillasTest extends TestCase
{
    public function test_todas_las_plantillas_compilan(): void
    {
        $rotas = [];

        foreach (File::allFiles(resource_path('views')) as $archivo) {
            if (! str_ends_with($archivo->getFilename(), '.blade.php')) {
                continue;
            }

            $error = $this->errorDeSintaxis(Blade::compileString($archivo->getContents()));

            if ($error !== null) {
                $relativa = str_replace(base_path().DIRECTORY_SEPARATOR, '', $archivo->getPathname());
                $rotas[] = "{$relativa}: {$error}";
            }
        }

        $this->assertSame([], $rotas, "Plantillas que no compilan:\n".implode("\n", $rotas));
    }

    /**
     * El mensaje del error de sintaxis del PHP compilado, o null si esta bien.
     *
     * Se usa `php -l` sobre un archivo temporal y no `eval()` porque eval
     * EJECUTA lo que compila: una plantilla con un `<?php` suelto haria cosas
     * dentro de la suite. `-l` solo mira la sintaxis.
     */
    private function errorDeSintaxis(string $php): ?string
    {
        $ruta = tempnam(sys_get_temp_dir(), 'blade').'.php';
        file_put_contents($ruta, $php);

        $salida = [];
        $codigo = 0;
        exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($ruta).' 2>&1', $salida, $codigo);

        @unlink($ruta);

        if ($codigo === 0) {
            return null;
        }

        // La primera linea trae el motivo; el resto es la ruta del temporal, que
        // no le dice nada a quien lea el fallo.
        return trim(str_replace($ruta, '', $salida[0] ?? 'error de sintaxis'));
    }
}
