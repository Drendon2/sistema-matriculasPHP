<?php

namespace App\Http\Controllers\Gestion;

use App\Http\Controllers\Controller;
use App\Models\EncuestaSatisfaccion;
use App\Models\Matricula;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** La portada de Gestion: las fichas que llevan a cada pantalla. */
class InicioController extends Controller
{
    public function __invoke(Request $request): View
    {
        return view('gestion.inicio', [
            // La cifra en la ficha de "Cancelaciones" es lo unico que avisa de
            // que hay gente esperando respuesta: sin ella habria que entrar a
            // mirar.
            'cancelacionesPendientes' => Matricula::where('estado', Matricula::CANCELACION_SOLICITADA)->count(),
            // Las dos alertas del pie de "Como va": cuantas consultas baratas
            // mas da igual, y asi la visibilidad por rol se decide en la
            // vista y no aqui.
            'seguimientoPendiente' => EncuestaSatisfaccion::conteoParaSeguimiento(),
            'gruposConBrechaDeEdad' => (new GrupoController)->gruposConBrechaDeEdad()->count(),
            // Departamentos ya no tiene pantalla propia: se pinta aqui mismo,
            // reusando el listado de AreaController.
            ...(new AreaController)->listado($request),
            // Lo mismo para la ventana de matriculas.
            ...(new MatriculasController)->datos(),
        ]);
    }
}
