<?php

namespace App\Support;

/**
 * Derivacion del color de marca. Puerto directo de las funciones del original
 * (`acento_oscuro`, `acento_suave`, `contraste` en models.py), sin dependencias.
 *
 * De UN solo color de acento salen el tono de hover y el de fondo suave, en vez
 * de pedir tres colores en la configuracion: asi cambiar de marca conserva la
 * relacion entre los tres y no obliga a nadie a inventarse una paleta.
 *
 * Los tres parametros de abajo estan medidos sobre el par de verdes que el
 * sistema ya usaba (#0a7a59 -> #065a41 / #dbf2e7): derivar con estos numeros
 * reproduce esos mismos tonos.
 *
 * Ojo con el redondeo: Python redondea al par mas cercano ("banker's rounding")
 * y PHP redondea alejandose del cero. En un canal de color la diferencia solo
 * aparece en el .5 exacto, pero ahi cambiaria el hex resultante, asi que se
 * replica el redondeo de Python — el objetivo es que los dos sistemas den
 * exactamente el mismo color, no uno parecido.
 */
class Color
{
    public const FACTOR_ACENTO_OSCURO = 0.727;
    public const LUZ_ACENTO_SUAVE = 0.904;
    public const SATURACION_MAXIMA_SUAVE = 0.469;

    /** Tono de hover/activo: el mismo color con la luminosidad bajada. */
    public static function acentoOscuro(string $hex): string
    {
        [$h, $l, $s] = self::rgbAHls(self::hexARgb($hex));

        return self::rgbAHex(self::hlsARgb($h, $l * self::FACTOR_ACENTO_OSCURO, $s));
    }

    /** Tinte claro para anillos de foco y fondos de mensaje de exito. */
    public static function acentoSuave(string $hex): string
    {
        [$h, $l, $s] = self::rgbAHls(self::hexARgb($hex));

        return self::rgbAHex(self::hlsARgb(
            $h,
            self::LUZ_ACENTO_SUAVE,
            min($s, self::SATURACION_MAXIMA_SUAVE)
        ));
    }

    /** Razon de contraste WCAG entre dos colores hex. */
    public static function contraste(string $a, string $b): float
    {
        $la = self::luminancia($a);
        $lb = self::luminancia($b);

        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    /** ¿Es un hex de 6 digitos con almohadilla? */
    public static function esHexValido(string $valor): bool
    {
        return (bool) preg_match('/^#[0-9a-fA-F]{6}$/', $valor);
    }

    // -----------------------------------------------------------------------

    /** @return array{float,float,float} */
    private static function hexARgb(string $hex): array
    {
        $hex = ltrim($hex, '#');

        return [
            hexdec(substr($hex, 0, 2)) / 255,
            hexdec(substr($hex, 2, 2)) / 255,
            hexdec(substr($hex, 4, 2)) / 255,
        ];
    }

    /** @param array{float,float,float} $rgb */
    private static function rgbAHex(array $rgb): string
    {
        $canales = array_map(
            fn (float $c) => max(0, min(255, self::redondeoAlPar($c * 255))),
            $rgb
        );

        return vsprintf('#%02x%02x%02x', $canales);
    }

    /**
     * Redondeo al entero par mas cercano en el empate — el de Python.
     * PHP redondearia 0.5 a 1 y 1.5 a 2; Python da 0 y 2.
     */
    private static function redondeoAlPar(float $valor): int
    {
        $abajo = floor($valor);
        $resto = $valor - $abajo;

        if (abs($resto - 0.5) < 1e-9) {
            return (int) (fmod($abajo, 2.0) == 0.0 ? $abajo : $abajo + 1);
        }

        return (int) round($valor);
    }

    /**
     * Equivalente de `colorsys.rgb_to_hls`. Devuelve [matiz, luz, saturacion]
     * — en ese orden, que es el de Python y no el habitual HSL.
     *
     * @param array{float,float,float} $rgb
     * @return array{float,float,float}
     */
    private static function rgbAHls(array $rgb): array
    {
        [$r, $g, $b] = $rgb;
        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $suma = $max + $min;
        $rango = $max - $min;
        $l = $suma / 2.0;

        if ($min === $max) {
            return [0.0, $l, 0.0];
        }

        $s = $l <= 0.5 ? $rango / $suma : $rango / (2.0 - $max - $min);

        $rc = ($max - $r) / $rango;
        $gc = ($max - $g) / $rango;
        $bc = ($max - $b) / $rango;

        if ($r === $max) {
            $h = $bc - $gc;
        } elseif ($g === $max) {
            $h = 2.0 + $rc - $bc;
        } else {
            $h = 4.0 + $gc - $rc;
        }

        return [self::modulo1($h / 6.0), $l, $s];
    }

    /**
     * Equivalente de `colorsys.hls_to_rgb`.
     *
     * @return array{float,float,float}
     */
    private static function hlsARgb(float $h, float $l, float $s): array
    {
        if ($s === 0.0) {
            return [$l, $l, $l];
        }

        $m2 = $l <= 0.5 ? $l * (1.0 + $s) : $l + $s - ($l * $s);
        $m1 = 2.0 * $l - $m2;

        return [
            self::canal($m1, $m2, $h + 1 / 3),
            self::canal($m1, $m2, $h),
            self::canal($m1, $m2, $h - 1 / 3),
        ];
    }

    private static function canal(float $m1, float $m2, float $matiz): float
    {
        $matiz = self::modulo1($matiz);

        if ($matiz < 1 / 6) {
            return $m1 + ($m2 - $m1) * $matiz * 6.0;
        }
        if ($matiz < 0.5) {
            return $m2;
        }
        if ($matiz < 2 / 3) {
            return $m1 + ($m2 - $m1) * (2 / 3 - $matiz) * 6.0;
        }

        return $m1;
    }

    /** `x % 1.0` con el signo de Python: siempre en [0, 1). */
    private static function modulo1(float $valor): float
    {
        $resto = fmod($valor, 1.0);

        return $resto < 0 ? $resto + 1.0 : $resto;
    }

    private static function luminancia(string $hex): float
    {
        [$r, $g, $b] = array_map(
            fn (float $c) => $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4,
            self::hexARgb($hex)
        );

        return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
    }
}
