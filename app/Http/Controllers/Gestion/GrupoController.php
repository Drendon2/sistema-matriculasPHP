<?php

namespace App\Http\Controllers\Gestion;

use App\Models\Grupo;
use App\Models\Matricula;
use App\Models\Promotoria;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Grupos vistos desde Gestion: el tercer nivel del catalogo.
 *
 * Es la misma tabla que administra quien dicta desde su Panel, pero por otro
 * camino y con otro alcance: aqui direccion recorre el catalogo entero —incluido
 * el de promotorias que no dicta nadie— mientras que en el Panel cada quien ve
 * lo suyo.
 */
class GrupoController extends RecursoController
{
    protected function modelo(): string
    {
        return Grupo::class;
    }

    protected function textos(): array
    {
        return [
            'titulo' => 'Grupos',
            'titulo_nuevo' => 'Nuevo grupo',
            'titulo_editar' => 'Editar grupo',
            'ruta_lista' => 'grupo-lista',
            'ruta_nuevo' => 'grupo-nuevo',
            'ruta_editar' => 'grupo-editar',
            'ruta_eliminar' => 'grupo-eliminar',
            'creado' => 'Grupo creado.',
            'actualizado' => 'Grupo actualizado.',
        ];
    }

    protected function listado(Request $request): array
    {
        return [
            'objetos' => $this->filas(Grupo::query()),
            ...$this->columnas(),
        ];
    }

    /** Los grupos de una sola promotoria, llegando desde su departamento. */
    public function porPromotoria(Promotoria $promotoria): View
    {
        $promotoria->load('area');

        return view('gestion.lista', [
            ...$this->textos(),
            'titulo' => "Grupos de {$promotoria->nombre}",
            'objetos' => $this->filas(Grupo::where('promotoria_id', $promotoria->id)),
            ...$this->columnas(),
            'preset_campo' => 'promotoria_id',
            'preset_valor' => $promotoria->id,
            'migas' => [
                ['texto' => 'Departamentos', 'url' => route('area-lista')],
                ['texto' => $promotoria->area->nombre, 'url' => route('promotorias-por-area', $promotoria->area_id)],
            ],
        ]);
    }

    /** El listado de quien esta en un grupo. Solo lectura. */
    public function estudiantes(Grupo $grupo): View
    {
        $grupo->load('promotoria.area');

        $matriculas = Matricula::query()
            ->where('grupo_id', $grupo->id)
            ->whereIn('estado', Matricula::ESTADOS_INSCRITO)
            ->with(['estudiante.datosEstudiante.acudiente'])
            ->get();

        return view('gestion.grupo-estudiantes', [
            'grupo' => $grupo,
            'estudiantes' => $matriculas->map(fn (Matricula $m) => [
                'matricula' => $m,
                'perfil' => $m->estudiante,
                'acudiente' => $m->estudiante->datosEstudiante?->acudiente,
            ])->all(),
            'migas' => [
                ['texto' => 'Departamentos', 'url' => route('area-lista')],
                ['texto' => $grupo->promotoria->area->nombre, 'url' => route('promotorias-por-area', $grupo->promotoria->area_id)],
                ['texto' => $grupo->promotoria->nombre, 'url' => route('grupos-por-promotoria', $grupo->promotoria_id)],
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function columnas(): array
    {
        return [
            'etiqueta_singular' => 'estudiante',
            'etiqueta_plural' => 'estudiantes',
            'etiqueta_protegido' => 'matrículas',
            'ruta_fila' => 'grupo-estudiantes',
        ];
    }

    /** @return list<array<string, mixed>> */
    private function filas($consulta): array
    {
        return $consulta
            ->with('promotoria.area')
            ->withCount([
                'matriculas as activas_count' => fn ($q) => $q->where('estado', Matricula::ACTIVA),
                'matriculas',
            ])
            ->join('promotorias', 'promotorias.id', '=', 'grupos.promotoria_id')
            ->join('areas', 'areas.id', '=', 'promotorias.area_id')
            ->orderBy('areas.nombre')
            ->orderBy('promotorias.nombre')
            ->orderBy('grupos.nivel')
            ->select('grupos.*')
            ->get()
            ->map(fn (Grupo $g) => [
                'objeto' => $g,
                'hijos' => $g->activas_count,
                // Ojo a la diferencia con la de al lado: la del nombre cuenta
                // estudiantes ACTIVOS, y un grupo del que todos se retiraron
                // muestra cero y aun asi no se deja borrar. Esta cuenta todas.
                'protegido' => $g->matriculas_count,
            ])
            ->all();
    }

    protected function campos(Request $request, ?Model $objeto): array
    {
        $promotorias = Promotoria::with('area')
            ->join('areas', 'areas.id', '=', 'promotorias.area_id')
            ->orderBy('areas.nombre')
            ->orderBy('promotorias.nombre')
            ->select('promotorias.*')
            ->get()
            ->mapWithKeys(fn (Promotoria $p) => [$p->id => (string) $p])
            ->all();

        return [
            'promotoria_id' => [
                'etiqueta' => 'Promotoría',
                'tipo' => 'select',
                'opciones' => $promotorias,
                'valor' => $objeto?->promotoria_id ?? $request->query('promotoria_id'),
            ],
            'nivel' => ['etiqueta' => 'Nivel', 'tipo' => 'select', 'opciones' => Grupo::NIVELES],
            'horario' => [
                'etiqueta' => 'Horario', 'tipo' => 'text', 'max' => 60,
                'ayuda' => 'Por ejemplo, Martes y jueves 4:00–6:00 p. m.',
            ],
            'salon' => ['etiqueta' => 'Salón', 'tipo' => 'text', 'max' => 40],
            'cupo_maximo' => ['etiqueta' => 'Cupo máximo', 'tipo' => 'number', 'min' => 0],
        ];
    }

    protected function reglas(Request $request, ?Model $objeto): array
    {
        return [
            'promotoria_id' => ['required', 'exists:promotorias,id'],
            'nivel' => [
                'required',
                Rule::in(array_keys(Grupo::NIVELES)),
                Rule::unique('grupos', 'nivel')
                    ->where('promotoria_id', $request->input('promotoria_id'))
                    ->ignore($objeto?->id),
            ],
            'horario' => ['required', 'string', 'max:60'],
            'salon' => ['required', 'string', 'max:40'],
            'cupo_maximo' => ['required', 'integer', 'min:0'],
        ];
    }

    protected function urlExito(Model $objeto): string
    {
        return route('grupos-por-promotoria', $objeto->promotoria_id);
    }
}
