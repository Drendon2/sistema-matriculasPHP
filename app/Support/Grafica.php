<?php

namespace App\Support;

/**
 * Las cifras de las graficas de Gestion → Estadisticas, ya listas para pintar.
 *
 * Barras y tortas se calculan aqui y no en la plantilla porque las dos comparten
 * una regla que es lo unico verdaderamente delicado de esta pantalla: **el todo
 * es la poblacion, no la suma de respuestas**. Si tres de dieciseis contestaron
 * la zona, una grafica de solo esos tres diria que el 100 % de la gente vive
 * donde vivan ellos.
 */
class Grafica
{
    /**
     * Paleta de las tortas.
     *
     * NO son los `--tag-*` del proyecto por dos razones: en esta misma pagina
     * esos colores ya significan "Área" en el arbol de arriba, y ademas su teal
     * y su magenta son indistinguibles para daltonismo protan. Estos cuatro
     * pasan las seis comprobaciones comparando TODOS los pares entre si, que es
     * lo que exige una torta: en ella cualquier sector se compara con cualquier
     * otro, no solo con el vecino.
     */
    private const COLORES = ['#2a78d6', '#eb6834', '#1baf7a', '#4a3aa7'];

    /**
     * El "sin responder" va en gris a proposito, fuera de la paleta categorica:
     * no es una opcion mas de la pregunta, es la ausencia de respuesta, y tiene
     * que leerse como tal sin competir con las demas.
     */
    private const COLOR_SIN_RESPUESTA = '#c3cfc7';

    /** El trazo, del mismo grosor que el diametro, rellena el disco. */
    private const RADIO = 30;

    /** Hueco en px entre sectores contiguos. */
    private const SEPARACION = 2;

    /**
     * Agrega `porcentaje` (ancho de barra 0-100, relativo al valor mas alto).
     *
     * @param  list<array<string, mixed>>  $filas
     * @return list<array<string, mixed>>
     */
    public static function conPorcentaje(array $filas, string $campo = 'total'): array
    {
        $maximo = max([0, ...array_column($filas, $campo)]);

        return array_map(
            fn (array $f) => [...$f, 'porcentaje' => $maximo ? (int) round($f[$campo] / $maximo * 100) : 0],
            $filas
        );
    }

    /**
     * Conteo de un campo de la encuesta por cada opcion declarada.
     *
     * Respeta el orden en que estan declaradas las opciones en vez de ordenar
     * por frecuencia: son escalas con un orden propio (de menos a mas estudios,
     * del estrato 1 al 6) y reordenarlas por popularidad haria ilegible la
     * comparacion entre un periodo y otro.
     *
     * Las opciones sin respuestas salen en cero en lugar de desaparecer, para
     * que el panel ensene siempre la misma lista y se vea que NO contesta la
     * gente.
     *
     * Con `$totalEncuestas`, quien no cae en ninguna opcion se anade como una
     * fila final de "Sin responder". Esa fila no es decorativa: sin ella, una
     * pregunta contestada por 2 de 5 personas se dibuja entera, sin nada que
     * avise de las otras 3, y contradice a la cabecera de la pantalla. Se omite
     * el argumento cuando el resultado alimenta a `torta()`, que pone su propio
     * sector gris y contaria dos veces esa fila.
     *
     * @param  array<int|string, int>  $conteos  codigo => cuantos
     * @param  array<int|string, string>  $opciones  codigo => etiqueta
     * @return list<array<string, mixed>>
     */
    public static function porOpcion(array $conteos, array $opciones, ?int $totalEncuestas = null): array
    {
        $filas = [];

        foreach ($opciones as $codigo => $etiqueta) {
            $filas[] = ['etiqueta' => $etiqueta, 'total' => $conteos[$codigo] ?? 0];
        }

        if ($totalEncuestas !== null) {
            // Contra el total y no contra los vacios: asi tambien caen aqui los
            // valores que no esten en la lista, que si no desaparecerian sin
            // dejar rastro en ninguna fila.
            $sinResponder = max(0, $totalEncuestas - array_sum(array_column($filas, 'total')));

            if ($sinResponder) {
                $filas[] = [
                    'etiqueta' => 'Sin responder',
                    'total' => $sinResponder,
                    'sin_responder' => true,
                ];
            }
        }

        return self::conPorcentaje($filas);
    }

    /**
     * Sectores y leyenda de una torta, a partir de un conteo por opcion.
     *
     * Se dibuja con un `<circle>` por sector y `stroke-dasharray` en vez de
     * rutas de arco: un trazo tan grueso como el diametro rellena el disco
     * entero, asi que cada sector sale sin una linea de trigonometria y el hueco
     * entre ellos es simplemente un tramo del guion que no se pinta.
     *
     * Devuelve los sectores a pintar (solo los que tienen valor: un sector de 0°
     * no se dibuja) y una leyenda con TODAS las opciones, incluidas las que
     * nadie eligio. La leyenda es la que explica un sector ausente.
     *
     * @param  list<array<string, mixed>>  $filas
     * @return array{sectores: list<array<string, mixed>>, leyenda: list<array<string, mixed>>, total: int}
     */
    public static function torta(array $filas, int $totalEncuestas): array
    {
        $respondidas = array_sum(array_column($filas, 'total'));
        $sinResponder = max(0, $totalEncuestas - $respondidas);
        $total = $respondidas + $sinResponder;

        $leyenda = [];

        foreach (array_values($filas) as $indice => $fila) {
            $leyenda[] = [...$fila, 'color' => self::COLORES[$indice % count(self::COLORES)]];
        }

        if ($sinResponder) {
            $leyenda[] = [
                'etiqueta' => 'Sin responder',
                'total' => $sinResponder,
                'color' => self::COLOR_SIN_RESPUESTA,
            ];
        }

        $leyenda = array_map(
            fn (array $e) => [...$e, 'parte' => $total ? (int) round($e['total'] / $total * 100) : 0],
            $leyenda
        );

        if (! $total) {
            return ['sectores' => [], 'leyenda' => $leyenda, 'total' => 0];
        }

        $circunferencia = 2 * M_PI * self::RADIO;
        $conValor = array_values(array_filter($leyenda, fn (array $e) => $e['total'] > 0));

        $sectores = [];
        $recorrido = 0.0;

        foreach ($conValor as $entrada) {
            $arco = $circunferencia * $entrada['total'] / $total;

            // Un unico sector ocuparia la vuelta entera: restarle el hueco solo
            // dejaria una muesca contra si mismo, asi que ahi no se separa nada.
            $visible = count($conValor) === 1 ? $arco : max($arco - self::SEPARACION, 0.5);

            $sectores[] = [
                'color' => $entrada['color'],
                'etiqueta' => $entrada['etiqueta'],
                'total' => $entrada['total'],
                'parte' => $entrada['parte'],
                'trazo' => round($visible, 2),
                'resto' => round($circunferencia, 2),
                'desfase' => round(-$recorrido, 2),
            ];

            $recorrido += $arco;
        }

        return ['sectores' => $sectores, 'leyenda' => $leyenda, 'total' => $total];
    }

    /**
     * Agrega a una fila del arbol sus dos cifras de permanencia.
     *
     * Son dos preguntas distintas y se parecen lo bastante como para
     * confundirlas al leerlas rapido, asi que cada una lleva su propia base:
     *
     * - `pct_desercion` / `pct_continuan` — DENTRO del periodo en curso: de los
     *   que llegaron a estar matriculados, cuantos se retiraron y cuantos siguen.
     * - `pct_no_renovo` — ENTRE periodos: de los que cursaron el periodo
     *   anterior, cuantos no volvieron a esta misma promotoria.
     *
     * La segunda queda en null cuando no hay periodo anterior con datos, para
     * que la plantilla escriba "sin referencia" en vez de un 0 % que se leeria
     * como "no se fue nadie".
     *
     * Ojo con lo que mide la primera: una matricula "retirada" es hoy tanto la
     * de quien se fue como la de una solicitud rechazada, porque las dos acaban
     * en el mismo estado. Mientras eso siga asi, la cifra de desercion incluye
     * rechazos.
     *
     * @param  array<string, mixed>  $fila
     * @return array<string, mixed>
     */
    public static function conPermanencia(array $fila): array
    {
        $dentro = $fila['total'] + $fila['retirados'];
        $desercion = $dentro ? (int) round($fila['retirados'] / $dentro * 100) : 0;

        return [
            ...$fila,
            'pct_desercion' => $desercion,
            'pct_continuan' => $dentro ? 100 - $desercion : 0,
            'pct_no_renovo' => $fila['base_renovacion']
                ? (int) round($fila['no_renovaron'] / $fila['base_renovacion'] * 100)
                : null,
        ];
    }
}
