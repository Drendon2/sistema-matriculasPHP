<?php

namespace App\Support;

use App\Models\Actividad;
use App\Models\Area;
use App\Models\Grupo;
use App\Models\Periodo;
use App\Models\Promotoria;
use Illuminate\Database\Eloquent\Model;

/**
 * Que impediria borrar algo, y que se iria con ello.
 *
 * Es lo que permite que la pantalla de confirmacion diga la verdad ANTES de que
 * nadie pulse nada, en vez de preguntar «¿seguro?» para negarse despues.
 *
 * En el original esto lo resolvia el `Collector` de Django, que sabe recorrer el
 * grafo de relaciones solo. Aqui las dependencias van declaradas a mano en
 * `MAPA` porque Eloquent no las conoce: quien sabe si una clave foranea borra en
 * cascada o bloquea es el esquema, y esa informacion no sube al modelo.
 *
 * El precio de declararlo a mano es que hay que acordarse de tocar este mapa al
 * agregar una tabla. A cambio, el mapa es tambien la documentacion de que se
 * lleva por delante cada borrado, que es justo lo que la pantalla necesita
 * contar.
 */
class Dependencias
{
    /**
     * Por modelo: que relaciones BLOQUEAN el borrado y cuales se van CON el.
     *
     * `bloquean` son las claves foraneas declaradas RESTRICT; `arrastran`, las
     * CASCADE. Cada entrada es [relacion => [singular, plural]].
     */
    private const MAPA = [
        Area::class => [
            'bloquean' => ['promotorias' => ['promotoría', 'promotorías']],
            'arrastran' => [],
        ],
        Periodo::class => [
            'bloquean' => [
                'matriculas' => ['matrícula', 'matrículas'],
                'clases' => ['clase', 'clases'],
            ],
            'arrastran' => [
                'cuposPromotoria' => ['cupo', 'cupos'],
            ],
        ],
        Promotoria::class => [
            'bloquean' => ['matriculas' => ['matrícula', 'matrículas']],
            'arrastran' => [
                'grupos' => ['grupo', 'grupos'],
                'cupos' => ['cupo', 'cupos'],
            ],
        ],
        Grupo::class => [
            'bloquean' => ['matriculas' => ['matrícula', 'matrículas']],
            'arrastran' => ['clases' => ['clase', 'clases']],
        ],
        // Una actividad no la bloquea nada: sus sesiones y sus inscritos son
        // suyos y de nadie mas, y no son historial academico de nadie —a un
        // taller se entra por un enlace, no con una matricula—. Se van con
        // ella, y la pantalla lo dice antes de preguntar.
        Actividad::class => [
            'bloquean' => [],
            'arrastran' => [
                'sesiones' => ['sesión', 'sesiones'],
                'inscritos' => ['inscrito', 'inscritos'],
            ],
        ],
    ];

    /**
     * @return array{bloqueos: string, arrastre: string} frases ya armadas, vacias si no hay nada
     */
    public static function de(Model $objeto): array
    {
        $config = self::MAPA[$objeto::class] ?? ['bloquean' => [], 'arrastran' => []];

        return [
            'bloqueos' => self::enumerar(self::contar($objeto, $config['bloquean'])),
            'arrastre' => self::enumerar(self::contar($objeto, $config['arrastran'])),
        ];
    }

    /** El aviso que se da cuando el borrado se rechaza de verdad. */
    public static function avisoDeProtegido(Model $objeto): string
    {
        $config = self::MAPA[$objeto::class] ?? ['bloquean' => []];
        $piezas = self::enumerar(self::contar($objeto, $config['bloquean']));

        // "todavía tiene …" en vez de "… depende de él": el sujeto puede ser una
        // promotoria, un area o un periodo, y asi el aviso no tiene que
        // concordar en genero con nada.
        return $piezas === ''
            ? "No se puede eliminar «{$objeto}»: todavía hay registros que dependen de él."
            : "No se puede eliminar «{$objeto}»: todavía tiene {$piezas}.";
    }

    /**
     * @param  array<string, array{0: string, 1: string}>  $relaciones
     * @return list<string> ["1 grupo", "19 matrículas"]
     */
    private static function contar(Model $objeto, array $relaciones): array
    {
        $piezas = [];

        foreach ($relaciones as $relacion => [$singular, $plural]) {
            $cuantos = $objeto->{$relacion}()->count();

            if ($cuantos > 0) {
                $piezas[] = $cuantos.' '.($cuantos === 1 ? $singular : $plural);
            }
        }

        return $piezas;
    }

    /** ["3 grupos", "41 matrículas"] -> "3 grupos y 41 matrículas". */
    private static function enumerar(array $piezas): string
    {
        if (count($piezas) <= 1) {
            return implode('', $piezas);
        }

        $ultima = array_pop($piezas);

        return implode(', ', $piezas).' y '.$ultima;
    }
}
