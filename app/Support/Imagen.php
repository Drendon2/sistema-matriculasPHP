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
     * Compresion PNG: 6 es el valor por defecto de zlib, el punto donde
     * comprimir mas cuesta tiempo de CPU sin bajar apenas el archivo. Un PNG es
     * sin perdida, asi que este numero no afecta a como se ve.
     */
    private const COMPRESION_PNG = 6;

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
     * Convierte una imagen subida a PNG, ya girada y acotada, CONSERVANDO la
     * transparencia.
     *
     * Existe aparte de `aWebp` por dos razones que van juntas:
     *
     * - El generador de PDF no entiende WebP. Una firma guardada con el metodo
     *   de las fotos saldria como un hueco en el certificado, y en pantalla se
     *   veria bien: el fallo solo aparece en el papel.
     * - Una firma escaneada se recorta con fondo transparente para que se apoye
     *   sobre la linea del certificado. `aWebp` aplana contra blanco a
     *   proposito —una foto de cara no gana nada con alfa—, y aqui eso dibuja
     *   un recuadro blanco encima de la linea.
     *
     * @throws RuntimeException si el archivo no es una imagen que GD entienda
     */
    public static function aPng(UploadedFile $archivo, int $ladoMaximo = self::LADO_MAXIMO): string
    {
        $lienzo = @imagecreatefromstring(file_get_contents($archivo->getRealPath()));

        if ($lienzo === false) {
            throw new RuntimeException('El archivo no es una imagen que se pueda procesar.');
        }

        try {
            $lienzo = self::enderezar($lienzo, $archivo->getRealPath());
            $lienzo = self::reducir($lienzo, $ladoMaximo);

            // Las dos lineas juntas o ninguna: sin `alphablending(false)` el
            // canal alfa se mezcla al escribir y `savealpha` guarda un alfa ya
            // aplastado.
            imagealphablending($lienzo, false);
            imagesavealpha($lienzo, true);

            ob_start();
            imagepng($lienzo, null, self::COMPRESION_PNG);

            return (string) ob_get_clean();
        } finally {
            imagedestroy($lienzo);
        }
    }

    /**
     * Una imagen ya guardada, como data URI PNG listo para incrustar en el HTML
     * que se convierte a PDF.
     *
     * Dos problemas de una vez. El generador de PDF no sabe pedir una URL con
     * la sesion del usuario, y estos archivos viven fuera del docroot detras de
     * un controlador que comprueba permisos; y aunque supiera, el logo esta
     * guardado en WebP, que no entiende. Se lee del disco, se pasa a PNG en
     * memoria y se incrusta.
     *
     * Devuelve null cuando no hay nada que incrustar o el archivo no se puede
     * leer: quien llama decide, y en el certificado la decision es seguir sin
     * la imagen antes que negar la descarga.
     */
    public static function aDataUriPng(string $binario): ?string
    {
        $lienzo = @imagecreatefromstring($binario);

        if ($lienzo === false) {
            return null;
        }

        try {
            imagealphablending($lienzo, false);
            imagesavealpha($lienzo, true);

            ob_start();
            imagepng($lienzo, null, self::COMPRESION_PNG);
            $png = (string) ob_get_clean();
        } finally {
            imagedestroy($lienzo);
        }

        return 'data:image/png;base64,'.base64_encode($png);
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
