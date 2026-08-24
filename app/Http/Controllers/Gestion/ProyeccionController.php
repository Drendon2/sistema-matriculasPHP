<?php

namespace App\Http\Controllers\Gestion;

use App\Models\Actividad;
use Illuminate\Http\Request;

/**
 * Grupos de proyeccion: lo que no tiene fechas.
 *
 * Una banda sinfonica o un coro institucional no se programan por adelantado en
 * el sistema: ensayan cuando toca, y la sesion nace al oprimir "Iniciar
 * ensayo". De ahi que esta pantalla no pregunte ninguna fecha.
 *
 * El tipo no se ofrece en el formulario: esta pantalla solo crea una cosa. Va
 * por `atributosFijos()`, que ademas cierra que un POST a mano cree aqui un
 * curso.
 */
class ProyeccionController extends ActividadController
{
    protected function tipos(): array
    {
        return [Actividad::PROYECCION];
    }

    protected function atributosFijos(Request $request): array
    {
        return [
            ...parent::atributosFijos($request),
            'tipo' => Actividad::PROYECCION,
        ];
    }

    protected function textos(): array
    {
        return [
            'titulo' => 'Grupos de proyección',
            'titulo_nuevo' => 'Nuevo grupo de proyección',
            'titulo_editar' => 'Editar grupo de proyección',
            'ruta_lista' => 'actividad-proyeccion-lista',
            'ruta_nuevo' => 'actividad-proyeccion-nueva',
            'ruta_editar' => 'actividad-proyeccion-editar',
            'ruta_eliminar' => 'actividad-proyeccion-eliminar',
            'ruta_enlace' => 'actividad-proyeccion-enlace',
            'creado' => 'Grupo de proyección creado.',
            'actualizado' => 'Grupo de proyección actualizado.',
        ];
    }
}
