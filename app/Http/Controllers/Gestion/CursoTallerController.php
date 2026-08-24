<?php

namespace App\Http\Controllers\Gestion;

use App\Models\Actividad;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Cursos y talleres: lo que tiene fechas.
 *
 * Van juntos en una pantalla porque son la misma cosa contada en dias: un
 * taller es un curso de un solo dia. Separarlos en dos botones habria obligado
 * a elegir entre ellos ANTES de saber cuantos dias va a durar, que es al reves
 * de como se piensa.
 */
class CursoTallerController extends ActividadController
{
    protected function tipos(): array
    {
        return Actividad::TIPOS_CON_FECHAS;
    }

    protected function textos(): array
    {
        return [
            'titulo' => 'Cursos y talleres',
            'titulo_nuevo' => 'Nuevo curso o taller',
            'titulo_editar' => 'Editar curso o taller',
            'ruta_lista' => 'actividad-curso-lista',
            'ruta_nuevo' => 'actividad-curso-nueva',
            'ruta_editar' => 'actividad-curso-editar',
            'ruta_eliminar' => 'actividad-curso-eliminar',
            'creado' => 'Curso creado.',
            'actualizado' => 'Curso actualizado.',
        ];
    }

    protected function campos(Request $request, ?Model $objeto): array
    {
        $campos = parent::campos($request, $objeto);

        // El tipo va PRIMERO: decide si despues hay que pedir una fecha o
        // varias, y preguntarlo al final obliga a releer el formulario entero.
        return [
            'tipo' => [
                'etiqueta' => 'Tipo',
                'tipo' => 'select',
                'opciones' => [
                    Actividad::TALLER => 'Taller — un solo día',
                    Actividad::CURSO => 'Curso — varios días',
                ],
                'valor' => $objeto?->tipo ?? Actividad::TALLER,
            ],
            ...$campos,
        ];
    }

    protected function reglas(Request $request, ?Model $objeto): array
    {
        return [
            'tipo' => ['required', Rule::in(Actividad::TIPOS_CON_FECHAS)],
            ...parent::reglas($request, $objeto),
        ];
    }
}
