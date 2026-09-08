<?php

namespace App\Support;

use App\Models\Actividad;
use App\Models\CupoPromotoria;
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
     *     cursosYTalleres: int,
     *     proyeccion: int,
     *     cuposDisponibles: int,
     *     promotoriasSinTope: int,
     * }
     */
    public static function cifras(?Periodo $periodo): array
    {
        $cupos = self::cupos($periodo);

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
            'cursosYTalleres' => Actividad::whereIn('tipo', Actividad::TIPOS_CON_FECHAS)->count(),
            'proyeccion' => Actividad::where('tipo', Actividad::PROYECCION)->count(),
            'cuposDisponibles' => $cupos['disponibles'],
            'promotoriasSinTope' => $cupos['sinTope'],
        ];
    }

    /**
     * Cuantos sitios quedan libres en toda la institucion, y cuantas
     * promotorias NO entran en esa cuenta.
     *
     * ─── LO SEGUNDO NO ES UN EXTRA ─────────────────────────────────────────
     *
     * Una promotoria SIN fila en `cupos_promotoria` no tiene tope: admite a
     * quien llegue. O sea que no aporta un numero a esta suma, y decir
     * «1.196 cupos disponibles» a secas seria una cifra CORRECTA con una
     * etiqueta que miente por omision — que es exactamente el fallo que ya tuvo
     * esta misma cinta con «1 · Cursos y talleres». Por eso salen las dos y la
     * pantalla avisa cuando la segunda no es cero. En produccion el 07/09/2026:
     * 25 promotorias, 24 con tope y UNA sin el.
     *
     * ─── SE SUMA SOLO LO POSITIVO ──────────────────────────────────────────
     *
     * Una promotoria puede acabar pasada de cupo —se baja el tope despues de
     * matricular, y el trigger no retira a nadie—. Ese exceso NO puede restar
     * de las demas: un sitio de menos en Violin no llena uno de Danza, y
     * sumando en crudo la cifra diria que hay menos libres de los que se pueden
     * ocupar de verdad. Hoy no hay ninguna asi; el dia que la haya, esta linea
     * es la diferencia entre una cifra util y una que nadie sabe leer.
     *
     * ─── DOS CONSULTAS FIJAS ───────────────────────────────────────────────
     *
     * Los topes por un lado y los ocupados agrupados por el otro, cruzados en
     * memoria. `Promotoria::cuposDisponibles()` consulta por promotoria, asi que
     * llamarla en un bucle costaria dos consultas por fila.
     *
     * Las condiciones de lo OCUPADO son las mismas que en
     * `Promotoria::ocupadosEn()` —todo lo que no esta retirado, incluida una
     * cancelacion en tramite— y tienen que seguir siendolo: dos definiciones de
     * «ocupa un cupo» acaban dando dos cifras.
     *
     * @return array{disponibles: int, sinTope: int}
     */
    private static function cupos(?Periodo $periodo): array
    {
        if ($periodo === null) {
            return ['disponibles' => 0, 'sinTope' => Promotoria::count()];
        }

        $topes = CupoPromotoria::where('periodo_id', $periodo->id)
            ->pluck('cupo_maximo', 'promotoria_id');

        $ocupados = Matricula::query()
            ->where('periodo_id', $periodo->id)
            ->where('estado', '!=', Matricula::RETIRADA)
            ->groupBy('promotoria_id')
            ->selectRaw('promotoria_id, COUNT(*) as total')
            ->pluck('total', 'promotoria_id');

        $disponibles = 0;

        foreach ($topes as $promotoriaId => $tope) {
            $disponibles += max(0, ((int) $tope) - ((int) ($ocupados[$promotoriaId] ?? 0)));
        }

        return [
            'disponibles' => $disponibles,
            'sinTope' => max(0, Promotoria::count() - $topes->count()),
        ];
    }
}
