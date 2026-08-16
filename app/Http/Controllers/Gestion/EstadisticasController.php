<?php

namespace App\Http\Controllers\Gestion;

use App\Http\Controllers\Controller;
use App\Models\Area;
use App\Models\EncuestaDemografica;
use App\Models\Grupo;
use App\Models\Matricula;
use App\Models\Perfil;
use App\Models\Periodo;
use App\Models\Promotoria;
use App\Support\Grafica;
use Illuminate\View\View;

/**
 * Panel de estadisticas. Solo administrador.
 *
 * Matricula por departamento y promotoria, grupos por nivel, y la encuesta
 * demografica agregada — nunca datos de una persona identificable.
 */
class EstadisticasController extends Controller
{
    public function __invoke(): View
    {
        $periodoActual = Periodo::enCurso();

        $periodoPrevio = $periodoActual === null ? null : Periodo::query()
            ->where('fecha_inicio', '<', $periodoActual->fecha_inicio)
            ->orderByDesc('fecha_inicio')
            ->first();

        [$baseRenovacion, $noRenovaron] = $this->renovacion($periodoActual, $periodoPrevio);

        $encuestas = EncuestaDemografica::all();
        $totalEncuestas = $encuestas->count();

        return view('gestion.estadisticas', [
            'arbolDepartamentos' => $this->arbol($periodoActual, $baseRenovacion, $noRenovaron),
            'periodoActual' => $periodoActual,
            'periodoPrevio' => $periodoPrevio,
            'gruposPorCurso' => Grafica::conPorcentaje($this->gruposPorNivel()),
            'estudiantesPorPeriodo' => Grafica::conPorcentaje($this->porPeriodo()),
            'totalEstudiantesActivos' => Matricula::where('estado', Matricula::ACTIVA)
                ->distinct()
                ->count('estudiante_id'),
            'totalPromotorias' => Promotoria::count(),
            'totalGrupos' => Grupo::count(),
            'totalEncuestas' => $totalEncuestas,
            // Tener encuesta no es tenerla contestada. La cuenta se hace en PHP
            // porque `estrato` es entero y un filtro de "vacio" que sirva para
            // texto y para numero a la vez sale peor que recorrer una tabla que
            // tiene una fila por persona.
            'encuestasIncompletas' => $encuestas->reject(fn ($e) => $e->esta_completa)->count(),
            'totalConRol' => Perfil::where('rol', '!=', '')->count(),
            // Genero y zona van en torta y no en barras: en las dos la pregunta
            // es que parte del total es cada opcion, y son pocas (4 y 3). El
            // resto de escalas sigue en barras, que es lo correcto para comparar
            // magnitudes y para escalas con orden propio como el estrato o el
            // nivel educativo, donde una torta obligaria a comparar angulos
            // parecidos.
            'generoTorta' => Grafica::torta(
                // Sin `$totalEncuestas`: la torta pone su propio sector gris y
                // contaria dos veces esa fila.
                Grafica::porOpcion($this->conteo('genero'), EncuestaDemografica::GENEROS),
                $totalEncuestas
            ),
            'zonaTorta' => Grafica::torta(
                Grafica::porOpcion($this->conteo('zona'), EncuestaDemografica::ZONAS),
                $totalEncuestas
            ),
            'estratoStats' => Grafica::porOpcion(
                $this->conteo('estrato'),
                array_map(fn ($e) => "Estrato {$e}", EncuestaDemografica::ESTRATOS),
                $totalEncuestas
            ),
            'nivelEducativoStats' => Grafica::porOpcion(
                $this->conteo('nivel_educativo'),
                EncuestaDemografica::NIVELES_EDUCATIVOS,
                $totalEncuestas
            ),
            'ocupacionStats' => Grafica::porOpcion(
                $this->conteo('ocupacion'),
                EncuestaDemografica::OCUPACIONES,
                $totalEncuestas
            ),
            'afiliacionSaludStats' => Grafica::porOpcion(
                $this->conteo('afiliacion_salud'),
                EncuestaDemografica::AFILIACIONES_SALUD,
                $totalEncuestas
            ),
            'grupoEtnicoStats' => Grafica::porOpcion(
                $this->conteo('grupo_etnico'),
                EncuestaDemografica::GRUPOS_ETNICOS,
                $totalEncuestas
            ),
            'discapacidadStats' => Grafica::porOpcion(
                $this->conteo('discapacidad'),
                EncuestaDemografica::DISCAPACIDADES,
                $totalEncuestas
            ),
            'victimaConflictoStats' => Grafica::porOpcion(
                $this->conteo('victima_conflicto_armado'),
                EncuestaDemografica::VICTIMAS_CONFLICTO,
                $totalEncuestas
            ),
            ...$this->autorizacion($totalEncuestas),
        ]);
    }

    /**
     * Quien NO volvio: estudiantes con matricula activa en el periodo anterior
     * que no tienen ninguna viva en el actual, contados por promotoria.
     *
     * Se resuelve de una vez para todas las promotorias en lugar de una consulta
     * por fila del arbol.
     *
     * @return array{0: array<int, int>, 1: array<int, int>}
     */
    private function renovacion(?Periodo $actual, ?Periodo $previo): array
    {
        if ($actual === null || $previo === null) {
            return [[], []];
        }

        $siguen = Matricula::where('periodo_id', $actual->id)
            ->where('estado', '!=', Matricula::RETIRADA)
            ->get(['estudiante_id', 'promotoria_id'])
            ->map(fn (Matricula $m) => "{$m->estudiante_id}:{$m->promotoria_id}")
            ->flip();

        $base = [];
        $noRenovaron = [];

        $anteriores = Matricula::where('periodo_id', $previo->id)
            ->where('estado', Matricula::ACTIVA)
            ->get(['estudiante_id', 'promotoria_id']);

        foreach ($anteriores as $matricula) {
            $base[$matricula->promotoria_id] = ($base[$matricula->promotoria_id] ?? 0) + 1;

            if (! $siguen->has("{$matricula->estudiante_id}:{$matricula->promotoria_id}")) {
                $noRenovaron[$matricula->promotoria_id] = ($noRenovaron[$matricula->promotoria_id] ?? 0) + 1;
            }
        }

        return [$base, $noRenovaron];
    }

    /**
     * Arbol Departamento → Promotoria.
     *
     * Una sola estructura responde "por departamento" y "por promotoria" a la
     * vez, en vez de dos listas planas que repetirian la misma informacion.
     *
     * Se acota al PERIODO EN CURSO: sin acotar, las dos cifras de permanencia no
     * significarian nada — mezclarian a quien se retiro hace tres anos con quien
     * sigue yendo esta semana. El desglose historico vive en "Estudiantes por
     * periodo".
     *
     * @param  array<int, int>  $baseRenovacion
     * @param  array<int, int>  $noRenovaron
     * @return list<array<string, mixed>>
     */
    private function arbol(?Periodo $periodo, array $baseRenovacion, array $noRenovaron): array
    {
        if ($periodo === null) {
            return [];
        }

        $inscritos = implode("','", Matricula::ESTADOS_INSCRITO);

        $filas = Matricula::query()
            ->where('matriculas.periodo_id', $periodo->id)
            ->where('matriculas.estado', '!=', Matricula::PENDIENTE)
            ->join('promotorias', 'promotorias.id', '=', 'matriculas.promotoria_id')
            ->join('areas', 'areas.id', '=', 'promotorias.area_id')
            ->groupBy('promotorias.id', 'promotorias.nombre', 'areas.id', 'areas.nombre')
            ->selectRaw("
                promotorias.id as promotoria_id,
                promotorias.nombre as promotoria,
                areas.id as area_id,
                areas.nombre as area,
                SUM(matriculas.estado IN ('{$inscritos}')) as continuan,
                SUM(matriculas.estado = ?) as retirados
            ", [Matricula::RETIRADA])
            ->orderBy('areas.nombre')
            ->orderByDesc('continuan')
            ->get();

        $departamentos = [];

        foreach ($filas as $fila) {
            $areaId = (int) $fila->area_id;
            $promotoriaId = (int) $fila->promotoria_id;
            $continuan = (int) $fila->continuan;
            $retirados = (int) $fila->retirados;
            $base = $baseRenovacion[$promotoriaId] ?? 0;
            $noVolvieron = $noRenovaron[$promotoriaId] ?? 0;

            $departamentos[$areaId] ??= [
                // Para el id del <details>: es lo que lo deja abierto cuando la
                // pagina se vuelve a pintar sin recargar.
                'id' => $areaId,
                'nombre' => $fila->area,
                'tag_class' => 'tag-'.($areaId % Area::NUM_COLORES_ETIQUETA),
                'total' => 0,
                'retirados' => 0,
                'base_renovacion' => 0,
                'no_renovaron' => 0,
                'promotorias' => [],
            ];

            $departamentos[$areaId]['total'] += $continuan;
            $departamentos[$areaId]['retirados'] += $retirados;
            $departamentos[$areaId]['base_renovacion'] += $base;
            $departamentos[$areaId]['no_renovaron'] += $noVolvieron;
            $departamentos[$areaId]['promotorias'][] = [
                'etiqueta' => $fila->promotoria,
                'total' => $continuan,
                'retirados' => $retirados,
                'base_renovacion' => $base,
                'no_renovaron' => $noVolvieron,
            ];
        }

        $arbol = array_values($departamentos);
        usort($arbol, fn (array $a, array $b) => $b['total'] <=> $a['total']);

        return array_map(
            fn (array $d) => Grafica::conPermanencia([
                ...$d,
                'promotorias' => array_map(
                    Grafica::conPermanencia(...),
                    Grafica::conPorcentaje($d['promotorias'])
                ),
            ]),
            Grafica::conPorcentaje($arbol)
        );
    }

    /** @return list<array<string, mixed>> */
    private function gruposPorNivel(): array
    {
        $conteos = Grupo::groupBy('nivel')
            ->selectRaw('nivel, COUNT(*) as total')
            ->pluck('total', 'nivel')
            ->all();

        $filas = [];

        foreach (Grupo::NIVELES as $codigo => $etiqueta) {
            if (isset($conteos[$codigo])) {
                $filas[] = ['etiqueta' => $etiqueta, 'total' => (int) $conteos[$codigo]];
            }
        }

        return $filas;
    }

    /** @return list<array<string, mixed>> */
    private function porPeriodo(): array
    {
        return Matricula::query()
            ->where('matriculas.estado', Matricula::ACTIVA)
            ->join('periodos', 'periodos.id', '=', 'matriculas.periodo_id')
            ->groupBy('periodos.id', 'periodos.nombre', 'periodos.fecha_inicio')
            ->selectRaw('periodos.nombre as etiqueta, COUNT(*) as total')
            ->orderByDesc('periodos.fecha_inicio')
            ->get()
            ->map(fn ($fila) => ['etiqueta' => $fila->etiqueta, 'total' => (int) $fila->total])
            ->all();
    }

    /**
     * Cuantas encuestas hay por cada valor de un campo.
     *
     * @return array<int|string, int>
     */
    private function conteo(string $campo): array
    {
        return EncuestaDemografica::groupBy($campo)
            ->selectRaw("{$campo} as valor, COUNT(*) as total")
            ->pluck('total', 'valor')
            ->map(fn ($t) => (int) $t)
            ->all();
    }

    /** @return array<string, int> */
    private function autorizacion(int $total): array
    {
        $si = EncuestaDemografica::where('autoriza_tratamiento_datos', true)->count();
        $pctSi = $total ? (int) round($si / $total * 100) : 0;

        return [
            'autorizaSi' => $si,
            'autorizaNo' => $total - $si,
            'pctAutorizaSi' => $pctSi,
            'pctAutorizaNo' => 100 - $pctSi,
        ];
    }
}
