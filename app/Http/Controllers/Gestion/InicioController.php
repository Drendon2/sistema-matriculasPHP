<?php

namespace App\Http\Controllers\Gestion;

use App\Http\Controllers\Controller;
use App\Models\Matricula;
use Illuminate\View\View;

/** La portada de Gestion: las fichas que llevan a cada pantalla. */
class InicioController extends Controller
{
    public function __invoke(): View
    {
        return view('gestion.inicio', [
            // La cifra en la ficha de "Cancelaciones" es lo unico que avisa de
            // que hay gente esperando respuesta: sin ella habria que entrar a
            // mirar.
            'cancelacionesPendientes' => Matricula::where('estado', Matricula::CANCELACION_SOLICITADA)->count(),
        ]);
    }
}
