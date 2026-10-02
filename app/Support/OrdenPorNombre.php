<?php

namespace App\Support;

use Collator;
use Illuminate\Support\Str;

/**
 * Como se desempatan por NOMBRE las listas que se ordenan en PHP.
 *
 * Los dos rankings de Estadisticas se ordenan por una cifra y despues se
 * RECORTAN a los diez primeros, y con un empate en el corte entraba quien
 * trajera antes la consulta: un orden que nadie puede explicar a quien se
 * queda fuera. Decision del usuario (01/10/2026, al verlo en la version
 * multi-institucion): entre empatados, por nombre.
 *
 * Compara como la base, sin distinguir mayusculas ni tildes (como
 * `utf8mb4_unicode_ci`). Con `intl`, con un cotejo ICU de fuerza primaria; sin
 * `intl` —que este proyecto no exige—, quitando tildes y mayusculas a mano, que
 * da el mismo orden en todo nombre corriente.
 */
class OrdenPorNombre
{
    private static ?Collator $cotejo = null;

    /** Negativo si `$a` va antes que `$b`, cero si son el mismo nombre. */
    public static function comparar(string $a, string $b): int
    {
        if (class_exists(Collator::class)) {
            self::$cotejo ??= self::cotejo();

            return (int) self::$cotejo->compare($a, $b);
        }

        return strcmp(Str::lower(Str::ascii($a)), Str::lower(Str::ascii($b)));
    }

    private static function cotejo(): Collator
    {
        $cotejo = new Collator('root');
        $cotejo->setStrength(Collator::PRIMARY);

        return $cotejo;
    }
}
