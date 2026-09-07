<?php

namespace App\Rules;

use App\Support\Documento;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

/**
 * Comprueba que GD sepa decodificar la imagen de verdad, no solo que lo parezca,
 * y que quepa en la memoria de esta maquina.
 *
 * ─── Lo que `image` y `mimes:` no ven ──────────────────────────────────────
 *
 * Las dos miran la cabecera del archivo, no que se pueda abrir entero. Un JPEG
 * truncado a media descarga o un WebP animado las pasan las dos y revientan
 * despues, dentro de `Imagen::aWebp`/`aPng`, que lanzan `RuntimeException`
 * cuando `imagecreatefromstring` devuelve false. Ninguna de las cuatro llamadas
 * la capturaba: el usuario veia un 500 en vez de un error de formulario, y en
 * produccion con APP_DEBUG=false no quedaba ni rastro de que el problema
 * hubiera sido su foto.
 *
 * Se resuelve como regla y no con un try/catch en cada sitio por dos razones:
 * el fallo sale como error del campo, que es lo que hace el resto de la
 * aplicacion; y ocurre durante la validacion, o sea ANTES de que
 * `UsuarioController::guardar` abra su transaccion.
 *
 * ─── EL ORDEN DE LAS DOS COMPROBACIONES ES EL ARREGLO ──────────────────────
 *
 * Primero se miden los lados leyendo la CABECERA y solo despues se decodifica.
 * Al reves —que es como estuvo hasta el 06/09/2026— esta regla era ella misma
 * el peligro: `imagecreatefromstring` reserva cuatro bytes por pixel ANTES de
 * que nadie haya podido decir que son demasiados, asi que una imagen enorme
 * agotaba la memoria dentro de la propia validacion, que es justo el sitio
 * donde se supone que se rechazan las cosas.
 *
 * Y no hace falta que la imagen sea grande de verdad: un JPEG puede declarar
 * 200 megapixeles y pesar unos cientos de kilobytes, asi que el tope de 8 MB
 * del formulario no para nada. Lo que es grande es lo que sale al
 * descomprimir. El tope en si —cuantos megapixeles admite esta maquina— se
 * deduce del `memory_limit` y vive en `Documento`.
 *
 * ─── El campo que admite PDF ───────────────────────────────────────────────
 *
 * Los papeles del estudiante pueden ser una imagen O un PDF. Ahi la regla se
 * construye con `puedeNoSerImagen: true` y deja pasar lo que no sea una imagen,
 * porque un PDF no se convierte y este tope no le incumbe.
 */
class ImagenProcesable implements ValidationRule
{
    public function __construct(private bool $puedeNoSerImagen = false) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // De que sea un archivo y de que sea una imagen ya se quejan `file` e
        // `image`. Aqui solo se mira lo que ellas no pueden ver, y sin repetir
        // sus mensajes.
        if (! $value instanceof UploadedFile || ! $value->isValid()) {
            return;
        }

        $binario = (string) file_get_contents($value->getRealPath());
        $medidas = Documento::medidas($binario);

        if ($medidas === null) {
            // Un PDF en el campo de los papeles: no es asunto de esta regla.
            if ($this->puedeNoSerImagen) {
                return;
            }

            $fail('No se pudo procesar esa imagen. Prueba con otra.');

            return;
        }

        [$ancho, $alto] = $medidas;
        $megapixeles = $ancho * $alto / 1000000;
        $maximo = Documento::megapixelesMaximos();

        if ($megapixeles > $maximo) {
            $fail(sprintf(
                'Esa imagen es demasiado grande para procesarla: mide %s×%s píxeles, '
                .'o sea %s megapíxeles, y el máximo son %s. Vuelve a tomar la foto con '
                .'menos resolución desde los ajustes de la cámara, o envía una copia '
                .'más pequeña.',
                self::enCastellano($ancho), self::enCastellano($alto),
                self::enCastellano($megapixeles), self::enCastellano($maximo),
            ));

            return;
        }

        // Ahora si: que ABRA, y no solo que lo diga la cabecera. Cuesta una
        // decodificacion de mas —la conversion vuelve a hacerla despues— y es
        // el precio de comprobarlo con el mismo motor que luego lo procesa.
        // Cualquier otra cosa seria adivinar.
        $lienzo = @imagecreatefromstring($binario);

        if ($lienzo === false) {
            $fail('No se pudo procesar esa imagen. Prueba con otra.');

            return;
        }

        // Se descarta en el acto: aqui solo interesaba saber si abria. Sin esto
        // una foto grande se queda ocupando memoria durante el resto de la
        // validacion, y despues la conversion reserva la suya encima.
        imagedestroy($lienzo);
    }

    /**
     * Un numero como se escribe aqui: el punto separa los miles y la coma los
     * decimales, al reves que `number_format` por defecto.
     *
     * Sin esto el rechazo decia «mide 20,000x20,000 puntos y el maximo son
     * 13.3», que en castellano son veinte con cero y trece con tres. Se vio
     * abriendo la pagina.
     *
     * Y los decimales se quitan cuando no dicen nada: «400 megapixeles», y no
     * «400,0».
     */
    private static function enCastellano(float $numero): string
    {
        $texto = number_format($numero, 1, ',', '.');

        return str_ends_with($texto, ',0') ? substr($texto, 0, -2) : $texto;
    }
}
