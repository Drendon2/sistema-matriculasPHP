<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Actividad;
use App\Models\Area;
use App\Models\Matricula;
use App\Models\Periodo;
use App\Models\Promotoria;
use App\Models\SesionGrupo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Lo publico que consulta el agente de voz: el catalogo y la disponibilidad.
 *
 * El catalogo es UNA peticion y no una por promotoria a proposito: en
 * produccion cada viaje por el CDN cuesta entre 1,3 y 1,9 s, y en una llamada
 * eso es silencio. El agente lo guarda y solo pregunta en vivo la
 * disponibilidad, que es lo que cambia mientras se habla.
 *
 * NUNCA LLEVA EL NIVEL de un grupo: la persona se inscribe a la promotoria y el
 * profesor le asigna el grupo despues, asi que un nivel en la respuesta es
 * algo que el agente acabaria ofreciendo.
 */
class CatalogoAgenteController extends Controller
{
    public function catalogo(Request $request): JsonResponse
    {
        $edad = $this->edad($request);
        $periodo = Periodo::enCurso();

        $promotorias = Promotoria::with(['grupos.sesiones'])
            // La edad se filtra AQUI y no en el prompt: aunque el modelo se
            // equivoque, no puede ofrecer lo que no le llega. Sin edad minima
            // declarada no se filtra — vacia es «no se ha dicho», no «cero».
            ->when($edad !== null, fn ($q) => $q->where(
                fn ($q) => $q->whereNull('edad_minima')->orWhere('edad_minima', '<=', $edad)
            ))
            ->orderBy('nombre')
            ->get()
            ->groupBy('area_id');

        $departamentos = Area::orderBy('nombre')->get()
            ->map(fn (Area $area) => [
                'id' => $area->id,
                'nombre' => $area->nombre,
                'promotorias' => ($promotorias[$area->id] ?? collect())
                    ->map(fn (Promotoria $p) => $this->promotoria($p))
                    ->values(),
            ])
            ->filter(fn (array $d) => $d['promotorias']->isNotEmpty())
            ->values();

        return response()->json([
            'periodo' => $periodo === null ? null : [
                'nombre' => $periodo->nombre,
                'matriculas_abiertas' => $periodo->admite_matriculas,
            ],
            'limite_programas_por_persona' => Matricula::limitePromotorias(),
            'departamentos' => $departamentos,
            'cursos_y_talleres' => $this->cursosYTalleres($periodo, $edad),
        ]);
    }

    /**
     * ¿Puede entrar alguien HOY a esta promotoria?
     *
     * Dice POR QUE no, porque «no hay cupo» y «no hay inscripciones abiertas»
     * se contestan distinto por telefono: lo segundo tiene fecha.
     */
    public function disponibilidad(Request $request, Promotoria $promotoria): JsonResponse
    {
        $edad = $this->edad($request);
        $periodo = Periodo::enCurso();

        $cupo = $promotoria->cupoEn($periodo);
        $ocupados = $periodo === null ? 0 : $promotoria->ocupadosEn($periodo);
        $disponibles = $cupo === null ? null : max(0, $cupo - $ocupados);
        $aptaParaEdad = ($edad === null || $promotoria->edad_minima === null)
            ? null
            : $edad >= $promotoria->edad_minima;

        // El enlace de la promotoria matricula con la ventana cerrada
        // (29/09/2026), asi que tambien cuenta como puerta abierta.
        $puertaAbierta = $periodo !== null && ($periodo->admite_matriculas || $promotoria->enlace_abierto);

        $motivo = match (true) {
            $periodo === null => 'sin_periodo',
            $aptaParaEdad === false => 'edad',
            ! $puertaAbierta => 'inscripciones_cerradas',
            $disponibles === 0 => 'sin_cupo',
            default => null,
        };

        return response()->json([
            'promotoria_id' => $promotoria->id,
            'nombre' => $promotoria->nombre,
            'edad_minima' => $promotoria->edad_minima,
            'apta_para_edad' => $aptaParaEdad,
            'matriculas_abiertas' => (bool) $periodo?->admite_matriculas,
            'enlace_propio_abierto' => (bool) $promotoria->enlace_abierto,
            // Sin fila de cupo la promotoria no tiene tope: `null`, no 0.
            'cupo' => $cupo,
            'disponibles' => $disponibles,
            'puede_inscribirse_ahora' => $motivo === null,
            'motivo' => $motivo,
        ]);
    }

    /** @return array<string, mixed> */
    private function promotoria(Promotoria $p): array
    {
        return [
            'id' => $p->id,
            'nombre' => $p->nombre,
            'edad_minima' => $p->edad_minima,
            'descripcion' => $p->descripcion,
            'palabras_clave' => $p->palabras_clave_lista,
            'grupos' => $p->grupos->map(fn ($g) => [
                'nombre' => $g->nombre,
                'salon' => $g->salon,
                'horario' => $g->sesiones->map(fn (SesionGrupo $s) => $s->paraLaApi())->values(),
            ])->values(),
        ];
    }

    /**
     * Cursos y talleres del periodo en curso (o sin periodo), con las fechas
     * que quedan. Los grupos de proyeccion y los programas externos no entran:
     * por telefono se pregunta por un curso o un taller.
     *
     * @return list<array<string, mixed>>
     */
    private function cursosYTalleres(?Periodo $periodo, ?int $edad): array
    {
        $hoy = Carbon::today();

        return Actividad::whereIn('tipo', [Actividad::CURSO, Actividad::TALLER])
            ->where(fn ($q) => $q->whereNull('periodo_id')->when(
                $periodo !== null,
                fn ($q) => $q->orWhere('periodo_id', $periodo->id)
            ))
            ->when($edad !== null, fn ($q) => $q->where(
                fn ($q) => $q->whereNull('edad_minima')->orWhere('edad_minima', '<=', $edad)
            ))
            ->with(['sesiones' => fn ($q) => $q->whereDate('fecha', '>=', $hoy)])
            ->withCount('inscritos')
            ->orderBy('nombre')
            ->get()
            ->map(fn (Actividad $a) => [
                'id' => $a->id,
                'tipo' => $a->tipo,
                'nombre' => $a->nombre,
                'edad_minima' => $a->edad_minima,
                'recibe_inscripciones' => (bool) $a->abierta,
                'cupo' => $a->cupo_maximo,
                'inscritos' => $a->inscritos_count,
                'fechas_proximas' => $a->sesiones->map(fn ($s) => $s->fecha->toDateString())->values(),
            ])
            ->all();
    }

    private function edad(Request $request): ?int
    {
        $request->validate(['edad' => ['nullable', 'integer', 'min:0', 'max:120']]);

        return $request->filled('edad') ? (int) $request->query('edad') : null;
    }
}
