<?php

namespace Tests\Unit;

use App\Support\Grafica;
use PHPUnit\Framework\TestCase;

/**
 * La geometria de la torta.
 *
 * Va en Unit y sin base de datos porque `Grafica::torta` es aritmetica pura: se
 * le dan unos totales y devuelve los arcos. No hace falta levantar nada.
 *
 * Existe por un motivo concreto: los sectores llevaban un hueco de 2 px entre
 * ellos que a este tamano se veia como una gráfica mal terminada, y quitarlo es
 * el tipo de cambio que ninguna prueba funcional nota —el HTML se genera igual,
 * lo que cambia son unos numeros dentro de un atributo—. Aqui quedan fijados.
 */
class GraficaTortaTest extends TestCase
{
    /** La circunferencia del circulo que dibuja la plantilla (radio 30). */
    private const CIRCUNFERENCIA = 2 * M_PI * 30;

    /** @param  list<array{etiqueta: string, total: int}>  $filas */
    private function torta(array $filas, ?int $totalEncuestas = null): array
    {
        return Grafica::torta($filas, $totalEncuestas ?? array_sum(array_column($filas, 'total')));
    }

    /**
     * Los sectores CASAN: cada uno arranca donde termina el anterior.
     *
     * Es la propiedad que se rompio durante meses sin que nadie la viera: con el
     * hueco puesto, cada sector empezaba donde tocaba pero terminaba 2 px antes,
     * y esos huecos sumaban mas del 4 % del disco en una torta de cuatro.
     */
    public function test_los_sectores_no_dejan_hueco_entre_si(): void
    {
        $torta = $this->torta([
            ['etiqueta' => 'A', 'total' => 61],
            ['etiqueta' => 'B', 'total' => 68],
            ['etiqueta' => 'C', 'total' => 44],
            ['etiqueta' => 'D', 'total' => 54],
        ]);

        $total = 61 + 68 + 44 + 54;
        $recorrido = 0.0;

        foreach ($torta['sectores'] as $indice => $sector) {
            // El desfase es negativo: es lo que SVG desplaza el patron.
            $this->assertEqualsWithDelta(-$recorrido, $sector['desfase'], 0.01, "sector {$indice}");

            $arcoReal = self::CIRCUNFERENCIA * $sector['total'] / $total;

            // Lo dibujado nunca es MENOR que lo que le toca: si lo fuera, ahi
            // habria un hueco. Puede ser un pelo mayor, que es el solape.
            $this->assertGreaterThanOrEqual($arcoReal - 0.01, $sector['trazo'], "sector {$indice}");

            $recorrido += $arcoReal;
        }

        // Y la vuelta se cierra entera.
        $this->assertEqualsWithDelta(self::CIRCUNFERENCIA, $recorrido, 0.01);
    }

    /**
     * Cada sector se mete un pelo debajo del siguiente, salvo el ultimo.
     *
     * El solape tapa la costura del suavizado. El ultimo no lo lleva porque se
     * dibuja al final y lo que invadiera quedaria por debajo del primero, que ya
     * esta pintado: no taparia nada y se pasaria de la vuelta.
     */
    public function test_todos_solapan_menos_el_ultimo(): void
    {
        $torta = $this->torta([
            ['etiqueta' => 'A', 'total' => 50],
            ['etiqueta' => 'B', 'total' => 30],
            ['etiqueta' => 'C', 'total' => 20],
        ]);

        $total = 100;
        $sectores = $torta['sectores'];
        $ultimo = count($sectores) - 1;

        foreach ($sectores as $indice => $sector) {
            $arcoReal = self::CIRCUNFERENCIA * $sector['total'] / $total;
            $sobra = $sector['trazo'] - $arcoReal;

            if ($indice === $ultimo) {
                $this->assertEqualsWithDelta(0, $sobra, 0.01, 'el ultimo no solapa');
            } else {
                $this->assertGreaterThan(0, $sobra, "sector {$indice} deberia solapar");
            }
        }
    }

    /** Un solo sector da la vuelta entera, sin muesca contra si mismo. */
    public function test_un_solo_sector_cierra_el_circulo(): void
    {
        $torta = $this->torta([['etiqueta' => 'Unica', 'total' => 40]]);

        $this->assertCount(1, $torta['sectores']);
        $this->assertEqualsWithDelta(
            self::CIRCUNFERENCIA,
            $torta['sectores'][0]['trazo'],
            0.01
        );
    }

    /**
     * Un sector diminuto no desaparece.
     *
     * Una respuesta entre trescientas mide medio pixel de arco. Sin suelo se
     * borraria del dibujo mientras la leyenda sigue diciendo que existe, y eso
     * es peor que dibujarlo un pelo mas grande de lo que le toca.
     */
    public function test_un_sector_diminuto_sigue_dibujandose(): void
    {
        $torta = $this->torta([
            ['etiqueta' => 'Casi todo', 'total' => 2999],
            ['etiqueta' => 'Una sola', 'total' => 1],
        ]);

        $diminuto = $torta['sectores'][1];

        $this->assertGreaterThan(0, $diminuto['trazo']);
        $this->assertSame(1, $diminuto['total']);
    }

    /** Las opciones en cero no dibujan sector, pero siguen en la leyenda. */
    public function test_una_opcion_en_cero_no_dibuja_pero_se_lista(): void
    {
        $torta = $this->torta([
            ['etiqueta' => 'Con respuestas', 'total' => 10],
            ['etiqueta' => 'Sin ninguna', 'total' => 0],
        ]);

        $this->assertCount(1, $torta['sectores']);
        $this->assertCount(2, $torta['leyenda']);
        $this->assertSame('Sin ninguna', $torta['leyenda'][1]['etiqueta']);
        $this->assertSame(0, $torta['leyenda'][1]['parte']);
    }

    /**
     * Las respuestas que faltan entran como un sector propio.
     *
     * No es una opcion mas de la pregunta sino la ausencia de respuesta, y la
     * torta tiene que sumar el total de gente y no solo el de quien contesto.
     */
    public function test_lo_no_respondido_entra_como_sector_aparte(): void
    {
        $torta = $this->torta([['etiqueta' => 'Sí', 'total' => 30]], totalEncuestas: 50);

        $this->assertSame(50, $torta['total']);
        $this->assertCount(2, $torta['sectores']);
        $this->assertSame('Sin responder', $torta['leyenda'][1]['etiqueta']);
        $this->assertSame(20, $torta['leyenda'][1]['total']);
    }
}
