<?php

namespace App\Support;

use App\Models\Actividad;
use App\Models\Grupo;
use App\Models\Matricula;
use App\Models\Perfil;
use App\Models\Periodo;
use App\Models\Promotoria;

/**
 * Las cifras de «como va la escuela», en un solo sitio.
 *
 * POR QUE EXISTE: desde el 04/09/2026 las pinta la portada de Gestion —que es
 * donde aterriza el administrador— y las sigue pintando Estadisticas. Dos
 * pantallas con la misma cifra calculada en dos sitios es una cifra que acaba
 * diciendo dos cosas distintas, y de eso este proyecto ya tiene historia: en
 * agosto habia cuatro cuentas distintas de las pruebas repartidas por el
 * repositorio y ninguna cuadraba.
 *
 * ESTUDIANTES ACTIVOS SE ACOTA AL PERIODO, y esa es la unica de las cinco que
 * tiene truco. Una matricula NO se retira al cerrar un periodo —el dato no
 * cambio, cambio el calendario, y de ahi cuelgan la renovacion, los
 * certificados y la antiguedad—, asi que contar activas sin filtrar responde
 * «cuantos han cursado alguna vez» y no «cuantos hay ahora». Medido en la base
 * de desarrollo: 251 contra 231. La etiqueta dice «activos», asi que manda el
 * periodo. Se aparta del Django, que cuenta igual de mal.
 *
 * Las otras cuatro son totales del catalogo y no dependen del periodo: cuantas
 * promotorias, cuantos grupos, cuanto personal que ensena y cuantas actividades
 * hay montadas.
 */
class ResumenInstitucion
{
    /**
     * @return array{
     *     estudiantesActivos: int,
     *     profesores: int,
     *     promotorias: int,
     *     grupos: int,
     *     actividades: int,
     * }
     */
    public static function cifras(?Periodo $periodo): array
    {
        return [
            'estudiantesActivos' => $periodo === null ? 0 : Matricula::query()
                ->where('estado', Matricula::ACTIVA)
                ->where('periodo_id', $periodo->id)
                // DISTINCT sobre el estudiante: quien cursa dos promotorias es
                // una persona, no dos.
                ->distinct()
                ->count('estudiante_id'),
            'profesores' => Perfil::where('rol', 'profesor')->count(),
            'promotorias' => Promotoria::count(),
            'grupos' => Grupo::count(),
            'actividades' => Actividad::count(),
        ];
    }
}
