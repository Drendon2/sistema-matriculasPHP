<?php

namespace App\Rules;

use App\Support\Documento;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

/**
 * Que lo subido sea de verdad un PDF o una imagen, mirando el CONTENIDO.
 *
 * ─── Por que no basta con `mimes:pdf,jpg,...` ──────────────────────────────
 *
 * Porque en produccion mira el contenido y en las PRUEBAS no. `UploadedFile`
 * distingue dos modos: el de verdad adivina el tipo leyendo los primeros bytes,
 * y el de prueba —el que fabrica `UploadedFile::fake()`— se cree el tipo que
 * declara el cliente, que sale de la extension del nombre. O sea que una
 * prueba que suba un `formato.pdf` con cualquier basura dentro pasa `mimes:pdf`
 * en verde, y con ella pasaria en verde una regla que en produccion si rechaza.
 *
 * Eso es exactamente una prueba que no comprueba nada, de las que este proyecto
 * ya ha pagado varias veces. La regla se escribe aqui para que lo que corre en
 * las pruebas sea lo mismo que corre en el servidor.
 *
 * ─── Que hace ──────────────────────────────────────────────────────────────
 *
 * Una imagen la reconoce GD, que es quien va a convertirla despues; un PDF, sus
 * cinco primeros bytes. No se valida el PDF mas alla de eso a proposito: no hay
 * lector de PDF en este proyecto, y uno propio para comprobar un archivo que se
 * guarda tal cual seria mas superficie de la que ahorra. Lo que esta regla
 * evita es lo que de verdad pasa —un archivo equivocado, un ZIP renombrado, una
 * descarga a medias— y no un PDF malicioso, que no lo para ninguna cabecera.
 *
 * Va JUNTO a `ImagenProcesable`, no en su lugar: aquella mira que la imagen
 * quepa en la memoria de esta maquina, que es otro problema.
 */
class PdfOImagen implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // De que sea un archivo ya se queja `file`. Aqui solo se mira lo que
        // ella no puede ver, y sin repetir su mensaje.
        if (! $value instanceof UploadedFile || ! $value->isValid()) {
            return;
        }

        // Solo la cabecera: un PDF de veinte megas no hace falta leerlo entero
        // para saber que empieza por «%PDF-», y las medidas de una imagen salen
        // de sus primeros bytes.
        $principio = (string) file_get_contents($value->getRealPath(), length: 1024);

        if (str_starts_with($principio, '%PDF-')) {
            return;
        }

        if (Documento::esImagen((string) file_get_contents($value->getRealPath()))) {
            return;
        }

        $fail('Ese archivo no es un PDF ni una imagen. Sube el formato en PDF, o una foto del papel.');
    }
}
