<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

/**
 * Comprueba que GD sepa decodificar la imagen de verdad, no solo que lo parezca.
 *
 * Las reglas `image` y `mimes:` miran la cabecera del archivo, no que se pueda
 * abrir entero. Un JPEG truncado a media descarga o un WebP animado las pasan
 * las dos y revientan despues, dentro de `Imagen::aWebp`/`aPng`, que lanzan
 * `RuntimeException` cuando `imagecreatefromstring` devuelve false. Ninguna de
 * las cuatro llamadas la capturaba: el usuario veia un 500 en vez de un error de
 * formulario, y en produccion con APP_DEBUG=false no quedaba ni rastro de que
 * el problema hubiera sido su foto.
 *
 * Se resuelve como regla y no con un try/catch en cada sitio por dos razones:
 * el fallo sale como error del campo, que es lo que hace el resto de la
 * aplicacion; y ocurre durante la validacion, o sea ANTES de que
 * `UsuarioController::guardar` abra su transaccion.
 *
 * Decodifica una vez de mas —la conversion vuelve a hacerlo despues—, que es el
 * precio de comprobarlo con el mismo motor que luego lo procesa. Cualquier otra
 * cosa seria adivinar.
 */
class ImagenProcesable implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // De que sea un archivo y de que sea una imagen ya se quejan `file` e
        // `image`. Aqui solo se mira lo que ellas no pueden ver, y sin repetir
        // sus mensajes.
        if (! $value instanceof UploadedFile || ! $value->isValid()) {
            return;
        }

        $lienzo = @imagecreatefromstring((string) file_get_contents($value->getRealPath()));

        if ($lienzo === false) {
            $fail('No se pudo procesar esa imagen. Prueba con otra.');

            return;
        }

        // Se descarta en el acto: aqui solo interesaba saber si abria. Sin esto
        // una foto grande se queda ocupando memoria durante el resto de la
        // validacion, y despues la conversion reserva la suya encima.
        imagedestroy($lienzo);
    }
}
