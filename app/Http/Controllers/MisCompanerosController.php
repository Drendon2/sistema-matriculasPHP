<?php

namespace App\Http\Controllers;

use App\Models\Matricula;
use App\Models\Perfil;
use App\Support\Companeros;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Nombre y foto de los companeros de la MISMA promotoria.
 *
 * Es todo lo que un estudiante ve de otro: ni edad, ni telefono, ni acudiente.
 * "Companero" significa promotoria Y periodo en comun, las dos cosas y con
 * matricula activa — haber coincidido en Guitarra el semestre pasado no lo
 * convierte a uno en companero de este.
 */
class MisCompanerosController extends Controller
{
    public function __invoke(Request $request): View
    {
        /** @var Perfil $perfil */
        $perfil = $request->attributes->get('perfil');

        $mias = Matricula::query()
            ->where('estudiante_id', $perfil->id)
            ->where('estado', Matricula::ACTIVA)
            ->with(['promotoria.area', 'periodo'])
            ->get();

        // El bucle recorre MIS matriculas para conservar su orden en la
        // pantalla, pero ya no pregunta dentro: los companeros vienen resueltos
        // de una vez, una lista por matricula (C-04).
        $companerosDe = Companeros::porMatricula($perfil, $mias);

        $promotorias = [];

        foreach ($mias as $matricula) {
            $promotorias[] = [
                'promotoria' => $matricula->promotoria,
                'companeros' => $companerosDe[$matricula->id],
            ];
        }

        return view('estudiante.mis-companeros', ['promotorias' => $promotorias]);
    }
}
