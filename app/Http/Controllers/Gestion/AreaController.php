<?php

namespace App\Http\Controllers\Gestion;

use App\Models\Area;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Departamentos: el primer nivel del catalogo (Musica, Danza, Artes plasticas).
 */
class AreaController extends RecursoController
{
    protected function modelo(): string
    {
        return Area::class;
    }

    protected function textos(): array
    {
        return [
            'titulo' => 'Departamentos',
            'titulo_nuevo' => 'Nueva área',
            'titulo_editar' => 'Editar área',
            // No hay pantalla propia de listado: Departamentos vive en la
            // portada de Gestión, y crear/editar/eliminar vuelve ahí.
            'ruta_lista' => 'gestion-inicio',
            'ruta_nuevo' => 'area-nueva',
            'ruta_editar' => 'area-editar',
            'ruta_eliminar' => 'area-eliminar',
            'creado' => 'Área creada.',
            'actualizado' => 'Área actualizada.',
        ];
    }

    /** Expuesto para que `Gestion\InicioController` pinte esta tabla en su portada. */
    public function listado(Request $request): array
    {
        return [
            'objetos' => Area::withCount('promotorias')->orderBy('nombre')->get()
                ->map(fn (Area $area) => [
                    'objeto' => $area,
                    'hijos' => $area->promotorias_count,
                    // Un area sin promotorias se borra sin mas: lo que la
                    // bloquea son precisamente sus promotorias, que ya se
                    // cuentan arriba.
                    'protegido' => $area->promotorias_count,
                ])
                ->all(),
            'etiqueta_singular' => 'promotoría',
            'etiqueta_plural' => 'promotorías',
            'etiqueta_protegido' => 'promotorías',
            'ruta_fila' => 'promotorias-por-area',
            // Sin punto de color y sin su propio botón "Nuevo": el título de
            // cada fila ya lleva el acento (ver gestion.inicio) y el botón
            // vive junto al encabezado "Departamentos", no aquí abajo.
            'ocultar_boton_nuevo' => true,
        ];
    }

    protected function campos(Request $request, ?Model $objeto): array
    {
        return [
            'nombre' => ['etiqueta' => 'Nombre', 'tipo' => 'text', 'max' => 60],
        ];
    }

    protected function reglas(Request $request, ?Model $objeto): array
    {
        return [
            'nombre' => ['required', 'string', 'max:60', Rule::unique('areas', 'nombre')->ignore($objeto?->id)],
        ];
    }
}
