<?php

namespace App\Http\Controllers\Gestion;

use App\Http\Controllers\Controller;
use App\Models\Matricula;
use App\Models\Periodo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Iniciar y finalizar las matriculas del periodo en curso.
 *
 * La institucion no recibe gente todo el ano: abre al principio y a mitad. Este
 * interruptor es lo que separa "el periodo esta en curso" de "se admiten
 * inscripciones y renovaciones ahora mismo".
 */
class MatriculasController extends Controller
{
    /**
     * Lo que necesita la sección de Matrículas en la portada de Gestión.
     *
     * No hay pantalla propia que pintar: expuesto para que
     * `Gestion\InicioController` la incluya ahí.
     *
     * @return array<string, mixed>
     */
    public function datos(): array
    {
        $periodo = Periodo::enCurso();

        return [
            'periodo' => $periodo,
            'resumen' => $periodo === null ? null : $this->resumen($periodo),
            // `withCount`: los modales de crear/editar/eliminar periodo, en la
            // misma tarjeta, necesitan saber si cada uno tiene matrículas o
            // clases (lo que bloquea su borrado) sin una consulta aparte.
            'periodos' => Periodo::withCount(['matriculas', 'clases'])->orderByDesc('fecha_inicio')->get(),
        ];
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
        // Sin pantalla propia: la sección de Matrículas vive en la portada.
        return redirect()->route('gestion-inicio')->with($exito ? 'success' : 'error', $mensaje);
    }
}
