<?php

namespace App\Http\Controllers;

use App\Models\Matricula;
use App\Models\Perfil;
use App\Support\Companeros;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Nombre y foto de los companeros del MISMO grupo.
 *
 * Es todo lo que un estudiante ve de otro: ni edad, ni telefono, ni acudiente.
 * Quien es companero lo decide `Companeros`, que es donde esta escrita la regla
 * y el porque: mismo grupo y mismo periodo, los dos con matricula activa.
 *
 * Una matricula sin grupo asignado se pinta igual, con su aviso: la pantalla
 * tiene que poder decir que falta repartir el grupo, y no que uno no tenga
 * companeros, que son dos cosas distintas para quien la mira.
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
            // Con las sesiones: el rotulo del grupo deriva el horario de
            // ellas, y sin traerlas aqui la pantalla pregunta una vez por
            // matricula justo despues de haberse ahorrado ese bucle.
            ->with(['promotoria.area', 'periodo', 'grupo.sesiones'])
            ->get();

        // El bucle recorre MIS matriculas para conservar su orden en la
        // pantalla, pero ya no pregunta dentro: los companeros vienen resueltos
        // de una vez, una lista por matricula (C-04).
        $companerosDe = Companeros::porMatricula($perfil, $mias);

        $clases = [];

        foreach ($mias as $matricula) {
            $clases[] = [
                'promotoria' => $matricula->promotoria,
                'grupo' => $matricula->grupo,
                'companeros' => $companerosDe[$matricula->id],
            ];
        }

        return view('estudiante.mis-companeros', ['clases' => $clases]);
    }
}
