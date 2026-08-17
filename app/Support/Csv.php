<?php

namespace App\Support;

use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Genera los informes descargables como CSV.
 *
 * CSV y no .xlsx a proposito. Un .xlsx de verdad pide `phpoffice/phpspreadsheet`
 * —unos 10 MB en `vendor/` y bastante memoria por descarga—, y el destino es
 * hosting compartido. Un CSV lo abre Excel con doble clic y Google Sheets al
 * importar, que es todo lo que hace falta aqui. Lo que se pierde son negritas y
 * anchos de columna; lo que se gana es que el informe no tumbe el plan.
 *
 * Dos detalles sin los cuales el archivo se abre MAL, y ninguno es cosmetico:
 *
 * 1. El BOM. Sin el, Excel lee el archivo como Latin-1 y «Promotoría» sale
 *    «PromotorÃ­a». Es la queja numero uno de cualquier CSV en espanol.
 *
 * 2. El separador es punto y coma, no coma. Excel usa el separador de lista del
 *    sistema, y en configuracion regional espanola —la de esta institucion— ese
 *    separador es `;`. Con comas, la hoja entera cae en una sola columna y el
 *    informe parece roto. Google Sheets detecta el separador solo, asi que el
 *    punto y coma sirve para los dos.
 */
class Csv
{
    private const BOM = "\xEF\xBB\xBF";

    private const SEPARADOR = ';';

    /**
     * Descarga en streaming: las filas salen segun se generan.
     *
     * Se hace asi y no armando el texto en memoria porque el informe de la
     * institucion es una fila por persona y promotoria, y con la base llena eso
     * son varios miles. En un plan compartido con memoria contada, la diferencia
     * entre ir escribiendo y acumularlo todo es que el informe salga o de un 500.
     *
     * @param  iterable<int, list<string|int|null>>  $filas
     * @param  list<string>  $cabecera
     */
    public static function descargar(string $nombre, array $cabecera, iterable $filas): StreamedResponse
    {
        $sello = now()->format('Y-m-d');
        $archivo = "{$nombre}-{$sello}.csv";

        return response()->streamDownload(function () use ($cabecera, $filas) {
            $salida = fopen('php://output', 'w');

            echo self::BOM;

            fputcsv($salida, $cabecera, self::SEPARADOR);

            foreach ($filas as $fila) {
                fputcsv($salida, $fila, self::SEPARADOR);
            }

            fclose($salida);
        }, $archivo, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            // Sin cache: el informe cambia con cada matricula, y una copia
            // guardada por el navegador es un informe que miente sin avisar.
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ]);
    }

    /**
     * Deja un valor listo para una celda.
     *
     * Lo unico que se toca es el texto que Excel interpretaria como formula: una
     * celda que empieza por `=`, `+`, `-` o `@` se ejecuta al abrir el archivo.
     * Aqui los nombres y los barrios los escribe el publico, asi que es una via
     * real de inyeccion de formulas —y ninguna de esas celdas necesita empezar
     * por esos caracteres. Se les antepone un apostrofo, que Excel entiende como
     * «esto es texto» y no pinta.
     */
    public static function celda(string|int|float|null $valor): string
    {
        $texto = (string) ($valor ?? '');

        if ($texto !== '' && in_array($texto[0], ['=', '+', '-', '@'], true)) {
            return "'".$texto;
        }

        return $texto;
    }
}
