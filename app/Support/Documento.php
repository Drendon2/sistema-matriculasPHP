<?php

namespace App\Support;

use RuntimeException;

/**
 * Los papeles que sube el estudiante, aligerados antes de tocar el disco.
 *
 * ─── Por que existe ────────────────────────────────────────────────────────
 *
 * Medido en produccion el 06/09/2026: 70 documentos, 100 MB. De ellos 56 eran
 * FOTOS DE CELULAR —88,5 MB, de 4000x3000 px y hasta 4,9 MB cada una— y 14
 * PDF, que sumaban 10,9. O sea que el 89% del peso eran fotos sin reducir, en
 * un hosting compartido con disco y ancho de banda contados.
 *
 * Reducidas a 2000 px de lado mayor y calidad 78, esas mismas seis fotos
 * pasaron de 23,2 MB a unos 4: en torno al 80% menos, y todavia con margen de
 * sobra para leer un numero de cedula o una firma.
 *
 * ─── Por que a PDF y no a JPEG a secas ─────────────────────────────────────
 *
 * Lo pidio el usuario, y ademas deja UN solo tipo de archivo en la carpeta: lo
 * que llega es una foto o un PDF, y lo que se guarda siempre se abre igual.
 *
 * ─── POR QUE LOS PDF NO SE TOCAN ───────────────────────────────────────────
 *
 * Esta es la parte que va al reves de lo que parece, y esta medida sobre los
 * PDF reales de produccion: recomprimirlos los deja MAS GRANDES. Uno de 3.084
 * KB salia en 9.607; uno de 480, en 5.832 —doce veces—. Son escaneos que la
 * aplicacion que los genero ya comprimio bien, y volver a rasterizarlos cuesta
 * mas de lo que ahorra. Encogen solo bajando la resolucion: a 120 dpi apenas un
 * 27%, y a 72 dpi —donde si bajan de verdad— un numero de cedula deja de leerse
 * con garantias. Uno de dos paginas ademas agoto la memoria a mitad.
 *
 * Asi que se guardan TAL CUAL. Son 10,9 MB de 100, y el riesgo era estropear la
 * legibilidad de un documento de identidad para ahorrar unos megas. Decision
 * del usuario del 06/09/2026, con los numeros delante.
 *
 * ─── POR QUE GD Y UN ESCRITOR DE PDF PROPIO ────────────────────────────────
 *
 * Produccion tiene Imagick y local NO. Con Imagick esto funcionaria solo en el
 * servidor, y entonces las pruebas de aqui y las del CI no comprobarian nada de
 * lo que de verdad corre — que es la forma exacta de los fallos que este
 * proyecto ya ha pagado varias veces.
 *
 * GD esta en los tres sitios, y meter un JPEG dentro de un PDF no necesita
 * libreria: el JPEG viaja tal cual, como un objeto imagen con filtro
 * `/DCTDecode`. No se vuelve a comprimir nada, asi que el PDF pesa lo que pesa
 * el JPEG mas un pellizco de estructura.
 *
 * ─── Lo que este archivo NO hace ───────────────────────────────────────────
 *
 * No toca las fotos de PERFIL ni el logo ni la firma: de eso se ocupa
 * `Imagen`, con otros tamanos y otro formato, y por otras razones.
 */
class Documento
{
    /**
     * El lado mayor de la imagen guardada, en pixeles.
     *
     * 2000 es la eleccion PRUDENTE de las tres que se midieron, y la escogio el
     * usuario. Sobre una foto de 4000x3000 deja la mitad de lado, que sobre un
     * documento de identidad enfocado son unos 200 px por centimetro: mas que
     * suficiente para el numero y para una firma. Con 1654 se ahorraba un 90%
     * en vez de un 80%, pero sin margen para quien fotografia de lejos o con
     * mala luz — y esa foto no se puede volver a pedir.
     */
    public const LADO_MAXIMO = 2000;

    /** Calidad JPEG. Misma decision y mismo margen que el lado mayor. */
    public const CALIDAD = 78;

    /** Margen de la hoja, en puntos. Un cuarto de pulgada. */
    private const MARGEN = 18.0;

    /** La hoja: carta, que es el papel de oficina en Colombia. */
    private const HOJA_CORTA = 612.0;

    private const HOJA_LARGA = 792.0;

    /**
     * ¿Es esto una imagen que GD sepa abrir?
     *
     * Se pregunta por el CONTENIDO y no por la extension ni por el tipo que
     * declara el navegador: los dos los pone quien sube el archivo.
     */
    public static function esImagen(string $binario): bool
    {
        $lienzo = @imagecreatefromstring($binario);

        if ($lienzo === false) {
            return false;
        }

        imagedestroy($lienzo);

        return true;
    }

    /**
     * Una imagen -> un PDF de una pagina, enderezada y reducida.
     *
     * `$rutaOriginal` es solo para leer la etiqueta EXIF, que dice si el
     * celular tomo la foto girada. Sin ella la imagen se procesa igual, pero
     * una foto vertical puede salir tumbada — y un documento tumbado es un
     * documento que hay que girar a mano para leerlo.
     *
     * @throws RuntimeException si el binario no es una imagen que GD entienda
     */
    public static function aPdf(string $binario, ?string $rutaOriginal = null): string
    {
        [$jpeg, $ancho, $alto] = self::aJpeg($binario, $rutaOriginal);

        return self::envolverEnPdf($jpeg, $ancho, $alto);
    }

    /**
     * La imagen ya lista: derecha, acotada, aplanada sobre blanco y en JPEG.
     *
     * @return array{0: string, 1: int, 2: int} el JPEG y sus dos lados
     *
     * @throws RuntimeException
     */
    private static function aJpeg(string $binario, ?string $rutaOriginal): array
    {
        $lienzo = @imagecreatefromstring($binario);

        if ($lienzo === false) {
            throw new RuntimeException('El archivo no es una imagen que se pueda procesar.');
        }

        try {
            $lienzo = self::enderezar($lienzo, $rutaOriginal);
            $lienzo = self::reducirYAplanar($lienzo, self::LADO_MAXIMO);

            $ancho = imagesx($lienzo);
            $alto = imagesy($lienzo);

            ob_start();
            imagejpeg($lienzo, null, self::CALIDAD);
            $jpeg = (string) ob_get_clean();

            return [$jpeg, $ancho, $alto];
        } finally {
            imagedestroy($lienzo);
        }
    }

    /**
     * Encoge y aplana EN UN SOLO PASO, sobre un lienzo nuevo con fondo blanco.
     *
     * Van juntos por MEMORIA, y no por elegancia. Separados eran tres lienzos
     * vivos a la vez —el original, el reducido y el aplanado— y una foto de
     * 4000x3000 ocupa 48 MB en cada uno: con el limite de 128 MB que trae PHP
     * por defecto, la conversion moria antes de escribir nada. Comprobado el
     * 06/09/2026. Asi son dos, y el segundo es pequeno.
     *
     * Nunca AGRANDA: una foto pequena ampliada solo pesa mas y se ve peor. Aun
     * asi se copia siempre a un lienzo nuevo, porque el aplanado hace falta
     * tambien cuando no hay que reducir.
     *
     * Y el aplanado hace dos cosas, las dos necesarias:
     *
     * - Un PNG con transparencia —la captura de pantalla de un documento, por
     *   ejemplo— pasado a JPEG sin aplanar sale con los huecos en NEGRO.
     * - Garantiza que el JPEG salga de tres componentes. Una imagen en escala
     *   de grises o con paleta daria un JPEG que el PDF de mas abajo declara
     *   como `/DeviceRGB` sin serlo, y eso se ve como un documento con los
     *   colores cambiados o ilegible. El PDF no avisa: lo pinta mal y ya.
     *
     * @param  \GdImage  $lienzo
     * @return \GdImage
     */
    private static function reducirYAplanar($lienzo, int $ladoMaximo)
    {
        $ancho = imagesx($lienzo);
        $alto = imagesy($lienzo);
        $mayor = max($ancho, $alto);

        $escala = $mayor > $ladoMaximo ? $ladoMaximo / $mayor : 1.0;
        $nuevoAncho = max(1, (int) round($ancho * $escala));
        $nuevoAlto = max(1, (int) round($alto * $escala));

        $destino = imagecreatetruecolor($nuevoAncho, $nuevoAlto);

        if ($destino === false) {
            return $lienzo;
        }

        $blanco = (int) imagecolorallocate($destino, 255, 255, 255);
        imagefilledrectangle($destino, 0, 0, $nuevoAncho, $nuevoAlto, $blanco);
        imagealphablending($destino, true);
        imagecopyresampled($destino, $lienzo, 0, 0, 0, 0, $nuevoAncho, $nuevoAlto, $ancho, $alto);
        imagedestroy($lienzo);

        return $destino;
    }

    /**
     * Aplica el giro que pide la etiqueta EXIF.
     *
     * Es la misma tabla que usa `Imagen` para las fotos de perfil, y esta
     * repetida a proposito en vez de compartida: aquella trabaja sobre un
     * `UploadedFile` y esta tiene que servir tambien al comando que recorre
     * archivos que ya estan en disco.
     *
     * @param  \GdImage  $lienzo
     * @return \GdImage
     */
    private static function enderezar($lienzo, ?string $ruta)
    {
        if ($ruta === null || ! is_file($ruta) || ! function_exists('exif_read_data')) {
            return $lienzo;
        }

        // Solo los JPEG llevan EXIF, y no todos: con cualquier otra cosa
        // `exif_read_data` emite un aviso, de ahi el arroba.
        $exif = @exif_read_data($ruta);
        $orientacion = is_array($exif) ? ($exif['Orientation'] ?? null) : null;

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
     * El JPEG dentro de un PDF de una pagina.
     *
     * EL JPEG NO SE RECOMPRIME: viaja tal cual como objeto imagen con filtro
     * `/DCTDecode`, que es justamente lo que un PDF sabe leer sin ayuda. De ahi
     * que el PDF pese lo que pesa el JPEG mas unos cientos de bytes, y que esto
     * no necesite ninguna libreria.
     *
     * LA HOJA GIRA CON LA IMAGEN: una foto apaisada va en una carta apaisada.
     * Metida en una vertical se encogeria a la mitad para caber a lo ancho, y
     * lo que se pierde ahi es justo la resolucion que se ha cuidado arriba.
     *
     * Los desplazamientos del `xref` se calculan sobre la cadena que se va
     * construyendo, y por eso los objetos se concatenan en orden y midiendo
     * conforme se anaden: un `xref` con un byte de mas no rompe nada visible en
     * los lectores tolerantes y revienta en los estrictos.
     */
    private static function envolverEnPdf(string $jpeg, int $ancho, int $alto): string
    {
        $apaisada = $ancho > $alto;
        $hojaAncho = $apaisada ? self::HOJA_LARGA : self::HOJA_CORTA;
        $hojaAlto = $apaisada ? self::HOJA_CORTA : self::HOJA_LARGA;

        $cajaAncho = $hojaAncho - 2 * self::MARGEN;
        $cajaAlto = $hojaAlto - 2 * self::MARGEN;

        // Se dibuja lo mas grande que quepa conservando la proporcion, centrado.
        $escala = min($cajaAncho / $ancho, $cajaAlto / $alto);
        $dibujoAncho = $ancho * $escala;
        $dibujoAlto = $alto * $escala;
        $x = ($hojaAncho - $dibujoAncho) / 2;
        $y = ($hojaAlto - $dibujoAlto) / 2;

        $contenido = sprintf(
            "q %s 0 0 %s %s %s cm /Im0 Do Q\n",
            self::numero($dibujoAncho),
            self::numero($dibujoAlto),
            self::numero($x),
            self::numero($y)
        );

        $objetos = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            sprintf(
                '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %s %s] '
                .'/Resources << /XObject << /Im0 4 0 R >> >> /Contents 5 0 R >>',
                self::numero($hojaAncho),
                self::numero($hojaAlto)
            ),
            sprintf(
                '<< /Type /XObject /Subtype /Image /Width %d /Height %d /ColorSpace /DeviceRGB '
                ."/BitsPerComponent 8 /Filter /DCTDecode /Length %d >>\nstream\n%s\nendstream",
                $ancho,
                $alto,
                strlen($jpeg),
                $jpeg
            ),
            sprintf(
                "<< /Length %d >>\nstream\n%s\nendstream",
                strlen($contenido),
                $contenido
            ),
        ];

        $pdf = "%PDF-1.4\n";
        // Un comentario con bytes altos: le dice a cualquier herramienta que el
        // archivo es binario y no debe tratarse como texto. Es la convencion de
        // la propia especificacion.
        $pdf .= "%\xE2\xE3\xCF\xD3\n";

        $desplazamientos = [];

        foreach ($objetos as $i => $cuerpo) {
            $desplazamientos[] = strlen($pdf);
            $pdf .= ($i + 1)." 0 obj\n".$cuerpo."\nendobj\n";
        }

        $inicioXref = strlen($pdf);
        $total = count($objetos) + 1;

        $pdf .= "xref\n0 {$total}\n";
        $pdf .= "0000000000 65535 f \n";

        foreach ($desplazamientos as $desplazamiento) {
            $pdf .= sprintf("%010d 00000 n \n", $desplazamiento);
        }

        $pdf .= "trailer\n<< /Size {$total} /Root 1 0 R >>\n";
        $pdf .= "startxref\n{$inicioXref}\n%%EOF\n";

        return $pdf;
    }

    /**
     * Un numero para el PDF: sin notacion cientifica y sin coma decimal.
     *
     * `sprintf('%.2f')` respeta la configuracion regional en algunas versiones
     * de PHP, y en una maquina en espanol eso escribe «612,00» — que dentro de
     * un PDF no es un numero sino dos, y desplaza todo lo que venga detras.
     */
    private static function numero(float $valor): string
    {
        return rtrim(rtrim(number_format($valor, 2, '.', ''), '0'), '.') ?: '0';
    }
}
