<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use RuntimeException;

/**
 * Normaliza las fotos de perfil antes de guardarlas: WebP, derechas y acotadas.
 *
 * El original no hacia nada de esto —Django usaba Pillow solo para VALIDAR que
 * el archivo fuera una imagen— y es una diferencia deliberada, pedida por el
 * documento de migracion. Las razones son del destino, no del codigo:
 *
 * - El hosting compartido tiene disco y ancho de banda contados. Una foto de
 *   celular son 3–8 MB; el panel de una promotoria con cuarenta estudiantes
 *   pintaba cuarenta a la vez.
 * - Una foto tomada en vertical con el celular se guarda girada y con una
 *   etiqueta EXIF que dice como enderezarla. El navegador la respeta en un <img>
 *   suelto pero no siempre dentro de un recorte con CSS, asi que se aplica el
 *   giro de verdad y se borra la etiqueta.
 *
 * Solo se aplica a FOTOS. La copia del documento de identidad se guarda tal cual
 * llega: puede ser un PDF, y aunque sea imagen es evidencia de un tramite —
 * reescribirla la convierte en otra cosa.
 */
class Imagen
{
    /** El lado mayor de una foto de perfil, en pixeles. */
    public const LADO_MAXIMO = 800;

    /**
     * Calidad de la compresion WebP.
     *
     * 82 es el punto donde una foto de cara deja de mejorar a simple vista y el
     * archivo sigue bajando. Por encima crece el peso sin que nadie lo note.
     */
    private const CALIDAD = 82;

    /**
     * Convierte una imagen subida a WebP, ya girada y reducida.
     *
     * Devuelve el contenido binario listo para guardar.
     *
     * @throws RuntimeException si el archivo no es una imagen que GD entienda
     */
    public static function aWebp(UploadedFile $archivo, int $ladoMaximo = self::LADO_MAXIMO): string
    {
        $lienzo = @imagecreatefromstring(file_get_contents($archivo->getRealPath()));

        if ($lienzo === false) {
            throw new RuntimeException('El archivo no es una imagen que se pueda procesar.');
        }

        try {
            $lienzo = self::enderezar($lienzo, $archivo->getRealPath());
            $lienzo = self::reducir($lienzo, $ladoMaximo);

            // El fondo blanco importa: un PNG con transparencia pasado a WebP
            // sin esto sale con los huecos en negro. Una foto de perfil recortada
            // en circulo es justo el caso donde se nota.
            imagealphablending($lienzo, true);

            ob_start();
            imagewebp($lienzo, null, self::CALIDAD);

            return (string) ob_get_clean();
        } finally {
            imagedestroy($lienzo);
        }
    }

    /**
     * Aplica el giro que pide la etiqueta EXIF y la deja fuera del resultado.
     *
     * Solo los JPEG llevan EXIF, y no todos: `exif_read_data` emite un aviso con
     * cualquier otra cosa, de ahi el arroba. Sin orientacion, se devuelve tal
     * cual.
     *
     * @param  \GdImage  $lienzo
     * @return \GdImage
     */
    private static function enderezar($lienzo, string $ruta)
    {
        if (! function_exists('exif_read_data')) {
            return $lienzo;
        }

        $exif = @exif_read_data($ruta);
        $orientacion = $exif['Orientation'] ?? null;

        // imagerotate gira en sentido antihorario, de ahi los angulos.
        $grados = match ($orientacion) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => null,
        };

        if ($grados === null) {
            return $lienzo;
        }

        $girado = imagerotate($lienzo, $grados, 0);

        if ($girado === false) {
            return $lienzo;
        }

        imagedestroy($lienzo);

        return $girado;
    }

    /**
     * Encoge la imagen si su lado mayor pasa del limite. Nunca la agranda: una
     * foto pequena ampliada solo pesa mas y se ve peor.
     *
     * @param  \GdImage  $lienzo
     * @return \GdImage
     */
    private static function reducir($lienzo, int $ladoMaximo)
    {
        $ancho = imagesx($lienzo);
        $alto = imagesy($lienzo);
        $mayor = max($ancho, $alto);

        if ($mayor <= $ladoMaximo) {
            return $lienzo;
        }

        $escala = $ladoMaximo / $mayor;
        $reducido = imagescale($lienzo, (int) round($ancho * $escala), (int) round($alto * $escala));

        if ($reducido === false) {
            return $lienzo;
        }

        imagedestroy($lienzo);

        return $reducido;
    }
}
