<?php

namespace App\Http\Controllers\Gestion;

use App\Models\Area;
use App\Models\Perfil;
use App\Models\Promotoria;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Promotorias: el segundo nivel del catalogo (Violin, dentro de Musica).
 *
 * Al crear o editar se vuelve a la lista del DEPARTAMENTO y no al listado
 * plano: quien esta armando el catalogo entra por Departamentos → un area → sus
 * promotorias, y devolverlo a la lista general lo saca de donde estaba
 * trabajando.
 */
class PromotoriaController extends RecursoController
{
    protected function modelo(): string
    {
        return Promotoria::class;
    }

    protected function textos(): array
    {
        return [
            'titulo' => 'Promotorías',
            'titulo_nuevo' => 'Nueva promotoría',
            'titulo_editar' => 'Editar promotoría',
            'ruta_lista' => 'promotoria-lista',
            'ruta_nuevo' => 'promotoria-nueva',
            'ruta_editar' => 'promotoria-editar',
            'ruta_eliminar' => 'promotoria-eliminar',
            'creado' => 'Promotoría creada.',
            'actualizado' => 'Promotoría actualizada.',
        ];
    }

    protected function listado(Request $request): array
    {
        return [
            'objetos' => $this->filas(Promotoria::query()),
            ...$this->columnas(),
        ];
    }

    /** Las promotorias de un solo departamento, llegando desde Departamentos. */
    public function porArea(Area $area): View
    {
        return view('gestion.lista', [
            ...$this->textos(),
            'titulo' => "Promotorías de {$area->nombre}",
            'objetos' => $this->filas(Promotoria::where('area_id', $area->id)),
            ...$this->columnas(),
            'preset_campo' => 'area_id',
            'preset_valor' => $area->id,
            'migas' => [['texto' => 'Departamentos', 'url' => route('area-lista')]],
        ]);
    }

    /** @return array<string, mixed> */
    private function columnas(): array
    {
        return [
            'etiqueta_singular' => 'grupo',
            'etiqueta_plural' => 'grupos',
            'etiqueta_protegido' => 'matrículas',
            'ruta_fila' => 'grupos-por-promotoria',
            // Quien dicta cada una. `gestion.lista` sirve a cuatro catalogos y
            // solo las promotorias tienen profesor, asi que va por bandera.
            'mostrar_profesor' => true,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function filas($consulta): array
    {
        return $consulta
            ->with(['area', 'profesor'])
            ->withCount(['grupos', 'matriculas'])
            ->join('areas', 'areas.id', '=', 'promotorias.area_id')
            ->orderBy('areas.nombre')
            ->orderBy('promotorias.nombre')
            ->select('promotorias.*')
            ->get()
            ->map(fn (Promotoria $p) => [
                'objeto' => $p,
                'hijos' => $p->grupos_count,
                // Las matriculas son RESTRICT y ninguna se borra al terminar el
                // periodo, asi que aqui van TODAS —retiradas incluidas—: son
                // exactamente las que haran fallar el borrado.
                'protegido' => $p->matriculas_count,
            ])
            ->all();
    }

    protected function campos(Request $request, ?Model $objeto): array
    {
        return [
            'nombre' => ['etiqueta' => 'Nombre', 'tipo' => 'text', 'max' => 60],
            'area_id' => [
                'etiqueta' => 'Departamento',
                'tipo' => 'select',
                'opciones' => Area::orderBy('nombre')->pluck('nombre', 'id')->all(),
                // Al llegar desde un departamento se preselecciona: quien esta
                // armando ese departamento no tiene por que volver a elegirlo.
                'valor' => $objeto?->area_id ?? $request->query('area_id'),
            ],
            'profesor_id' => [
                'etiqueta' => 'Profesor',
                'tipo' => 'select',
                // Quien puede quedar a cargo es el PERSONAL, no solo los
                // profesores: un director que ademas dicta su propia promotoria
                // es un caso real.
                'opciones' => Perfil::whereIn('rol', Perfil::ROLES_PERSONAL)
                    ->orderBy('nombre_completo')
                    ->pluck('nombre_completo', 'id')
                    ->all(),
                'vacio' => '-- sin asignar --',
            ],
        ];
    }

    protected function reglas(Request $request, ?Model $objeto): array
    {
        return [
            'nombre' => ['required', 'string', 'max:60'],
            'area_id' => ['required', 'exists:areas,id'],
            'profesor_id' => [
                'nullable',
                Rule::exists('perfiles', 'id')->whereIn('rol', Perfil::ROLES_PERSONAL),
            ],
        ];
    }

    protected function urlExito(Model $objeto): string
    {
        return route('promotorias-por-area', $objeto->area_id);
    }
}
