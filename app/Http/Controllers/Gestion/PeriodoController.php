<?php

namespace App\Http\Controllers\Gestion;

use App\Models\Periodo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Periodos semestrales.
 *
 * `activo` NO va en el formulario: solo puede haber uno en curso, asi que
 * marcarlo aqui chocaria contra el indice unico sin salida posible. Cambiar cual
 * esta en curso es una accion propia —Gestion → Iniciar / finalizar
 * matriculas— que apaga el anterior en la misma transaccion.
 */
class PeriodoController extends RecursoController
{
    protected function modelo(): string
    {
        return Periodo::class;
    }

    protected function textos(): array
    {
        return [
            'titulo' => 'Periodos',
            'titulo_nuevo' => 'Nuevo periodo',
            'titulo_editar' => 'Editar periodo',
            'ruta_lista' => 'periodo-lista',
            'ruta_nuevo' => 'periodo-nuevo',
            'ruta_editar' => 'periodo-editar',
            'ruta_eliminar' => 'periodo-eliminar',
            'creado' => 'Periodo creado.',
            'actualizado' => 'Periodo actualizado.',
        ];
    }

    protected function listado(Request $request): array
    {
        return [
            'objetos' => Periodo::withCount(['matriculas', 'clases'])
                ->orderByDesc('fecha_inicio')
                ->get()
                ->map(fn (Periodo $periodo) => [
                    'objeto' => $periodo,
                    'hijos' => null,
                    'protegido' => $periodo->matriculas_count + $periodo->clases_count,
                ])
                ->all(),
            'etiqueta_protegido' => 'registros',
        ];
    }

    protected function campos(Request $request, ?Model $objeto): array
    {
        return [
            'nombre' => ['etiqueta' => 'Nombre', 'tipo' => 'text', 'max' => 20, 'ayuda' => 'Por ejemplo, 2026-1'],
            'fecha_inicio' => ['etiqueta' => 'Fecha de inicio', 'tipo' => 'date'],
            'fecha_fin' => ['etiqueta' => 'Fecha de fin', 'tipo' => 'date'],
        ];
    }

    protected function reglas(Request $request, ?Model $objeto): array
    {
        return [
            'nombre' => ['required', 'string', 'max:20', Rule::unique('periodos', 'nombre')->ignore($objeto?->id)],
            'fecha_inicio' => ['required', 'date'],
            'fecha_fin' => ['required', 'date', 'after:fecha_inicio'],
        ];
    }
}
