<?php

namespace App\Http\Controllers\Gestion;

use App\Http\Controllers\Controller;
use App\Models\Matricula;
use App\Models\Periodo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Iniciar y finalizar las matriculas del periodo en curso.
 *
 * La institucion no recibe gente todo el ano: abre al principio y a mitad. Este
 * interruptor es lo que separa "el periodo esta en curso" de "se admiten
 * inscripciones y renovaciones ahora mismo".
 */
class MatriculasController extends Controller
{
    public function mostrar(): View
    {
        $periodo = Periodo::enCurso();

        return view('gestion.matriculas', [
            'periodo' => $periodo,
            'resumen' => $periodo === null ? null : $this->resumen($periodo),
            'periodos' => Periodo::orderByDesc('fecha_inicio')->get(),
        ]);
    }

    public function guardar(Request $request): RedirectResponse
    {
        $accion = $request->input('accion');

        if ($accion === 'poner_en_curso') {
            return $this->ponerEnCurso($request);
        }

        $periodo = Periodo::enCurso();

        if ($periodo === null) {
            return $this->volver('No hay un periodo en curso. Elige cuál es antes de abrir matrículas.');
        }

        $abrir = $accion === 'abrir';
        $periodo->matriculas_abiertas = $abrir;
        $periodo->save();

        return $this->volver(
            $abrir
                ? "Matrículas de {$periodo} ABIERTAS. Los estudiantes nuevos ya pueden "
                    .'inscribirse y los antiguos renovar.'
                : "Matrículas de {$periodo} CERRADAS. Las matrículas ya registradas no se "
                    .'tocan; solo deja de entrar gente nueva.',
            exito: true
        );
    }

    private function ponerEnCurso(Request $request): RedirectResponse
    {
        $request->validate(['periodo_id' => ['required', 'exists:periodos,id']]);

        $anterior = Periodo::enCurso();
        $elegido = Periodo::findOrFail($request->input('periodo_id'));

        Periodo::ponerEnCurso($elegido);

        return $this->volver(
            $anterior !== null && $anterior->id !== $elegido->id
                ? "{$elegido} es ahora el periodo en curso. {$anterior} dejó de estarlo y sus "
                    .'matrículas quedaron cerradas.'
                : "{$elegido} es ahora el periodo en curso.",
            exito: true
        );
    }

    /**
     * Las cifras de cabecera del periodo en curso.
     *
     * @return array<string, mixed>
     */
    private function resumen(Periodo $periodo): array
    {
        $matriculas = Matricula::where('periodo_id', $periodo->id);

        $anterior = Periodo::where('fecha_inicio', '<', $periodo->fecha_inicio)
            ->orderByDesc('fecha_inicio')
            ->first();

        $porRenovar = 0;

        if ($anterior !== null) {
            $yaEnCurso = (clone $matriculas)
                ->where('estado', '!=', Matricula::RETIRADA)
                ->pluck('estudiante_id');

            $porRenovar = Matricula::where('periodo_id', $anterior->id)
                ->where('estado', Matricula::ACTIVA)
                ->whereNotIn('estudiante_id', $yaEnCurso)
                ->distinct()
                ->count('estudiante_id');
        }

        return [
            'pendientes' => (clone $matriculas)->where('estado', Matricula::PENDIENTE)->count(),
            'activas' => (clone $matriculas)->where('estado', Matricula::ACTIVA)->count(),
            'estudiantes' => (clone $matriculas)
                ->where('estado', '!=', Matricula::RETIRADA)
                ->distinct()
                ->count('estudiante_id'),
            'periodo_anterior' => $anterior,
            'por_renovar' => $porRenovar,
        ];
    }

    private function volver(string $mensaje, bool $exito = false): RedirectResponse
    {
        return redirect()->route('gestion-matriculas')->with($exito ? 'success' : 'error', $mensaje);
    }
}
