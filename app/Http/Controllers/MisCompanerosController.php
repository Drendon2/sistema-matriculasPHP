<?php

namespace App\Http\Controllers;

use App\Models\Matricula;
use App\Models\Perfil;
use App\Support\Companeros;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
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
            // `grupos` en plural: desde el 10/09/2026 una matricula puede estar
            // en varios de la misma promotoria, y `Companeros` empareja por el
            // par (grupo, periodo) de cada uno.
            ->with(['promotoria.area', 'periodo', 'grupos.sesiones'])
            ->get();

        // El bucle recorre MIS matriculas para conservar su orden en la
        // pantalla, pero ya no pregunta dentro: los companeros vienen resueltos
        // de una vez, una lista por matricula (C-04).
        $companerosDe = Companeros::porMatricula($perfil, $mias);

        $clases = [];

        /*
         * UNA SECCION POR GRUPO Y NO POR MATRICULA. Los companeros son distintos
         * en cada uno, y desde el 10/09/2026 una matricula puede estar en dos
         * grupos de la misma promotoria: quien va los martes no se cruza con
         * quien va los jueves, que es lo que esta pantalla lleva diciendo desde
         * el 27/08.
         *
         * La matricula SIN grupo sigue dando una seccion, con el grupo en nulo:
         * es lo que la vista usa para decir «todavia no tienes grupo», que es un
         * mensaje distinto de «no tienes companeros». Recorriendo solo los
         * grupos, esa persona se quedaria sin pantalla.
         */
        foreach ($mias as $matricula) {
            if ($matricula->grupos->isEmpty()) {
                $clases[] = [
                    'promotoria' => $matricula->promotoria,
                    'grupo' => null,
                    'companeros' => new Collection,
                ];

                continue;
            }

            foreach ($matricula->grupos as $grupo) {
                $clave = $matricula->id.'-'.$grupo->id.'-'.$matricula->periodo_id;

                $clases[] = [
                    'promotoria' => $matricula->promotoria,
                    'grupo' => $grupo,
                    'companeros' => $companerosDe[$clave] ?? new Collection,
                ];
            }
        }

        return view('estudiante.mis-companeros', ['clases' => $clases]);
    }
}
