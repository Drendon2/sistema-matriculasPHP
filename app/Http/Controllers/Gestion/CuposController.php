<?php

namespace App\Http\Controllers\Gestion;

use App\Http\Controllers\Controller;
use App\Models\CupoPromotoria;
use App\Models\Periodo;
use App\Models\Promotoria;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Fijar de una vez el cupo de todas las promotorias para un periodo.
 *
 * Es la pantalla de "abrir matriculas": se elige el periodo, se reparten los
 * cupos y se guarda todo junto. Dejar una casilla vacia deja esa promotoria sin
 * tope. Los periodos pasados se pueden consultar, pero no editar: su cupo es
 * parte del historico.
 */
class CuposController extends Controller
{
    public function mostrar(?Periodo $periodo = null): View
    {
        $periodos = Periodo::orderByDesc('activo')->orderByDesc('id')->get();
        $periodo ??= $periodos->firstWhere('activo', true) ?? $periodos->first();

        if ($periodo === null) {
            return view('gestion.cupos', ['periodo' => null, 'periodos' => $periodos, 'filas' => []]);
        }

        $filas = $this->promotorias()
            ->map(fn (Promotoria $p) => [
                'promotoria' => $p,
                'cupo' => $p->cupoEn($periodo),
                'ocupados' => $p->ocupadosEn($periodo),
            ])
            ->all();

        return view('gestion.cupos', compact('periodo', 'periodos', 'filas'));
    }

    public function guardar(Request $request, Periodo $periodo): RedirectResponse
    {
        if (! $periodo->activo) {
            return $this->volver(
                $periodo,
                "{$periodo} no es el periodo activo: sus cupos son histórico y no se editan."
            );
        }

        $promotorias = $this->promotorias();
        $errores = [];
        $nuevos = [];

        // Se lee y se comprueba TODO antes de escribir nada. El original hacia
        // las dos cosas a la vez y deshacia la transaccion al encontrar el
        // primer valor malo; separarlo consigue lo mismo —nada a medias— sin
        // depender de un rollback a mitad de bucle, y ademas permite listar
        // todos los errores de una vez en lugar de solo el primero.
        foreach ($promotorias as $promotoria) {
            $bruto = trim((string) $request->input("cupo_{$promotoria->id}", ''));

            if ($bruto === '') {
                $nuevos[$promotoria->id] = null;

                continue;
            }

            if (! preg_match('/^-?\d+$/', $bruto)) {
                $errores[] = "{$promotoria}: «{$bruto}» no es un número entero.";

                continue;
            }

            if ((int) $bruto < 0) {
                $errores[] = "{$promotoria}: el cupo no puede ser negativo.";

                continue;
            }

            $nuevos[$promotoria->id] = (int) $bruto;
        }

        if ($errores !== []) {
            return $this->volver($periodo, 'No se guardó ningún cupo. '.implode(' ', $errores));
        }

        $avisos = [];
        $guardados = 0;
        $quitados = 0;

        DB::transaction(function () use ($periodo, $promotorias, $nuevos, &$avisos, &$guardados, &$quitados) {
            foreach ($promotorias as $promotoria) {
                $cupo = $nuevos[$promotoria->id];

                if ($cupo === null) {
                    $borrados = CupoPromotoria::where('promotoria_id', $promotoria->id)
                        ->where('periodo_id', $periodo->id)
                        ->delete();

                    $quitados += $borrados ? 1 : 0;

                    continue;
                }

                CupoPromotoria::updateOrCreate(
                    ['promotoria_id' => $promotoria->id, 'periodo_id' => $periodo->id],
                    ['cupo_maximo' => $cupo]
                );

                $guardados++;
                $ocupados = $promotoria->ocupadosEn($periodo);

                if ($cupo < $ocupados) {
                    $avisos[] = "{$promotoria}: cupo {$cupo} por debajo de las {$ocupados} "
                        . 'matrículas ya ocupando sitio.';
                }
            }
        });

        $respuesta = redirect()->route('gestion-cupos-periodo', $periodo)->with(
            'success',
            "Cupos de {$periodo} guardados: {$guardados} con tope"
            . ($quitados ? ", {$quitados} sin tope" : '') . '.'
        );

        if ($avisos !== []) {
            $respuesta->with(
                'error',
                implode(' ', $avisos)
                . ' No se retiró a nadie, pero no entrarán estudiantes nuevos.'
            );
        }

        return $respuesta;
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, Promotoria> */
    private function promotorias()
    {
        return Promotoria::with(['area', 'profesor', 'cupos'])
            ->join('areas', 'areas.id', '=', 'promotorias.area_id')
            ->orderBy('areas.nombre')
            ->orderBy('promotorias.nombre')
            ->select('promotorias.*')
            ->get();
    }

    private function volver(Periodo $periodo, string $mensaje): RedirectResponse
    {
        return redirect()->route('gestion-cupos-periodo', $periodo)->with('error', $mensaje);
    }
}
