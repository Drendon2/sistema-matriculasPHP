<?php

namespace App\Console\Commands;

use App\Models\DocumentoEstudiante;
use App\Support\Documento;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Convierte a PDF y aligera los papeles que YA estaban subidos.
 *
 * Desde el 06/09/2026 los que llegan nuevos se aligeran solos al guardarse (ver
 * `MiPerfilController::guardarAligerado`). Este comando es para los de antes:
 * el dia que se escribio habia en produccion 56 fotos de celular ocupando 88,5
 * MB de los 100 que pesaba la carpeta entera.
 *
 * ─── Por que es un comando y no una migracion ──────────────────────────────
 *
 * Una migracion corre SOLA en cada despliegue, y esto reescribe documentos de
 * identidad de menores de edad: tiene que lanzarlo una persona, mirando lo que
 * va a pasar antes de que pase. Ademas tarda —son decenas de fotos de 4 MB— y
 * un despliegue que se alarga deja el sitio en mantenimiento mas tiempo.
 *
 * ─── Las tres barreras ─────────────────────────────────────────────────────
 *
 * 1. SIMULACRO POR DEFECTO. Sin `--ejecutar` no toca nada: dice cuanto pesa
 *    cada papel, cuanto pesaria y cuanto se ahorraria en total. Es la misma
 *    forma que ya tienen los guiones de `database/`, y por la misma razon —un
 *    comando que se llama «aligerar» no deberia poder borrar nada de primeras.
 * 2. RESPALDO ANTES DE TOCAR NADA. Con `--ejecutar` el original se copia entero
 *    a `respaldos/documentos-<fecha>/` antes de reemplazarlo, y ahi se queda
 *    hasta que alguien lo borre a mano. Se puede saltar con `--sin-respaldo`,
 *    que existe para el segundo pase y no para el primero.
 * 3. EL ORDEN DE LAS TRES ESCRITURAS. Se escribe el PDF nuevo, LUEGO se apunta
 *    la fila a el, y solo al final se borra el viejo. Si algo se cae en medio
 *    lo peor que queda es un archivo huerfano ocupando sitio; al reves —borrar
 *    primero— lo que queda es una fila apuntando a un archivo que ya no existe,
 *    o sea el documento perdido.
 *
 * ─── Lo que NO hace ────────────────────────────────────────────────────────
 *
 * No toca los PDF. Recomprimirlos los deja mas grandes, medido sobre los de
 * produccion; el porque entero esta en `Support\Documento`.
 *
 * Y no toca nada si el resultado no pesa menos. Una foto que ya venia pequena
 * puede salir mas grande al pasar por PDF, y cambiarla seria trabajar para
 * empeorar.
 */
class AligerarDocumentos extends Command
{
    protected $signature = 'documentos:aligerar
        {--ejecutar : Escribe de verdad. Sin esto solo dice lo que haria.}
        {--sin-respaldo : No copia los originales antes de reemplazarlos.}
        {--limite= : Procesa solo los N primeros, para probar.}';

    protected $description = 'Convierte a PDF y aligera los documentos ya subidos por los estudiantes.';

    public function handle(): int
    {
        // Una foto de 4000x3000 ocupa 48 MB de lienzo, y hacen falta dos a la
        // vez. Con el limite de 128 MB que trae PHP por defecto esto muere a
        // mitad, asi que se sube aqui: en CLI se puede, y es preferible a que
        // quien lo lance tenga que acordarse de la bandera.
        if (self::enBytes((string) ini_get('memory_limit')) < 512 * 1024 * 1024) {
            @ini_set('memory_limit', '512M');
        }

        $ejecutar = (bool) $this->option('ejecutar');
        $disco = Storage::disk('local');
        $carpetaRespaldo = 'respaldos/documentos-'.now()->format('Y-m-d-His');

        $entregas = DocumentoEstudiante::query()
            ->where('archivo', '!=', '')
            ->orderBy('id')
            ->get();

        if ($limite = (int) $this->option('limite')) {
            $entregas = $entregas->take($limite);
        }

        if ($entregas->isEmpty()) {
            $this->info('No hay documentos subidos.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->line($ejecutar
            ? '<fg=yellow>EJECUTANDO.</> Los originales '
                .($this->option('sin-respaldo') ? 'NO se respaldan.' : "se respaldan en {$carpetaRespaldo}.")
            : '<fg=cyan>SIMULACRO.</> No se va a escribir nada. Añade --ejecutar para hacerlo de verdad.');
        $this->newLine();

        $antes = $despues = 0;
        $convertidos = $saltados = $fallidos = 0;

        foreach ($entregas as $entrega) {
            if (! $disco->exists($entrega->archivo)) {
                $this->line("  <fg=red>falta</>    {$entrega->archivo} — la fila apunta a un archivo que no está.");
                $fallidos++;

                continue;
            }

            $original = (string) $disco->get($entrega->archivo);
            $pesoOriginal = strlen($original);
            $antes += $pesoOriginal;

            if (! Documento::esImagen($original)) {
                $despues += $pesoOriginal;
                $saltados++;

                continue;
            }

            try {
                $pdf = Documento::aPdf($original, $disco->path($entrega->archivo));
            } catch (Throwable $e) {
                $this->line("  <fg=red>falló</>    {$entrega->archivo} — ".$e->getMessage());
                $despues += $pesoOriginal;
                $fallidos++;

                continue;
            }

            // Si no pesa menos, no se toca: cambiarla seria trabajar para
            // empeorar, y encima reescribiendo evidencia de un tramite.
            if (strlen($pdf) >= $pesoOriginal) {
                $despues += $pesoOriginal;
                $saltados++;

                continue;
            }

            $despues += strlen($pdf);
            $convertidos++;

            $this->line(sprintf(
                '  %s %7s KB → %6s KB  (%2d%% menos)  %s',
                $ejecutar ? '<fg=green>hecho</>' : '<fg=cyan>haría</>',
                number_format($pesoOriginal / 1024, 0),
                number_format(strlen($pdf) / 1024, 0),
                100 - (int) round(strlen($pdf) * 100 / $pesoOriginal),
                basename($entrega->archivo)
            ));

            if (! $ejecutar) {
                continue;
            }

            $viejo = $entrega->archivo;

            if (! $this->option('sin-respaldo')) {
                $disco->put($carpetaRespaldo.'/'.basename($viejo), $original);
            }

            // El orden importa: nuevo, luego la fila, y el viejo al final.
            $nuevo = 'documentos/'.Str::random(40).'.pdf';
            $disco->put($nuevo, $pdf);

            $entrega->archivo = $nuevo;
            $entrega->save();

            $disco->delete($viejo);
        }

        $this->newLine();
        $this->line(sprintf(
            '  %d convertidos, %d sin tocar (PDF o ya pequeños), %d con problemas.',
            $convertidos,
            $saltados,
            $fallidos
        ));
        $this->line(sprintf(
            '  %.1f MB → %.1f MB   (%d%% menos, %.1f MB ahorrados)',
            $antes / 1048576,
            $despues / 1048576,
            $antes > 0 ? 100 - (int) round($despues * 100 / $antes) : 0,
            ($antes - $despues) / 1048576
        ));

        if (! $ejecutar) {
            $this->newLine();
            $this->comment('  Nada de esto se ha escrito. Para hacerlo: php artisan documentos:aligerar --ejecutar');
        } elseif (! $this->option('sin-respaldo') && $convertidos > 0) {
            $this->newLine();
            $this->comment("  Los originales quedaron en storage/app/private/{$carpetaRespaldo}.");
            $this->comment('  Bórralos a mano cuando hayas comprobado que los nuevos se abren bien.');
        }

        return self::SUCCESS;
    }

    /** `memory_limit` viene como «128M» o «-1»; aqui hace falta en bytes. */
    private static function enBytes(string $valor): int
    {
        $valor = trim($valor);

        if ($valor === '' || $valor === '-1') {
            // Sin limite: no hay nada que subir.
            return PHP_INT_MAX;
        }

        $numero = (int) $valor;

        return match (strtolower(substr($valor, -1))) {
            'g' => $numero * 1024 * 1024 * 1024,
            'm' => $numero * 1024 * 1024,
            'k' => $numero * 1024,
            default => $numero,
        };
    }
}
