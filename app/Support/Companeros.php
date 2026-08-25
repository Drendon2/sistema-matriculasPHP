<?php

namespace App\Support;

use App\Models\Matricula;
use App\Models\Perfil;
use Closure;
use Illuminate\Support\Collection;

/**
 * Quien es companero de quien, en un solo sitio y en un numero fijo de
 * consultas.
 *
 * «Companero» significa promotoria Y periodo en comun, las dos cosas y con
 * matricula activa: haber coincidido en Guitarra el semestre pasado no lo
 * convierte a uno en companero de este. Es la unica cosa que un estudiante ve de
 * otro --nombre y foto, ni edad ni telefono ni acudiente-- asi que la regla
 * decide tambien que se puede mirar.
 *
 * Existe por C-04 de la auditoria del 24/08, y por dos motivos que van juntos:
 *
 * 1. **La consulta.** Los dos sitios que la calculaban preguntaban una vez POR
 *    MATRICULA dentro de un bucle. Con el limite en 2 son dos consultas y no se
 *    nota, que es justo por lo que el hallazgo era Bajo; pero el limite es
 *    configurable hasta 6 (`RANURA_MAXIMA_ABSOLUTA`) y subirlo triplicaba el
 *    coste de dos pantallas sin que nadie relacionara una cosa con la otra.
 * 2. **La regla.** Estaba escrita dos veces, y el comentario de una decia «la
 *    misma regla que Mis companeros» --que es la senal de que alguien ya vio el
 *    riesgo y lo dejo anotado en vez de atado--. Es el mismo patron que B-01:
 *    dos copias de una regla divergen en silencio en cuanto una se toca.
 *
 * Las dos pantallas piden cosas distintas a proposito y por eso hay dos metodos:
 * la de companeros necesita los perfiles ordenados, la tarjeta del perfil solo
 * necesita un numero, y traer perfiles enteros para contarlos seria cambiar un
 * derroche por otro. Lo que comparten --que es lo que importa-- es la condicion.
 */
class Companeros
{
    /**
     * Los companeros de $perfil, una lista por cada matricula suya.
     *
     * Dos consultas fijas, sea cual sea el limite de promotorias: una trae los
     * perfiles y otra sus matriculas para saber en que lista cae cada uno.
     *
     * **La clave es MI matricula y no la promotoria**, y la diferencia se ve en
     * cuanto alguien renueva: las matriculas no se retiran al cerrar un periodo
     * --se quedan en `activa`-- asi que un estudiante de tercer ano tiene la
     * misma promotoria activa en dos o tres periodos a la vez. Agrupando por
     * promotoria, esas secciones --que la pantalla pinta por separado, una por
     * periodo-- recibian todas la MISMA lista mezclada, con los de este semestre
     * y los del pasado juntos y repetido quien estuvo en los dos.
     *
     * El orden por nombre se deja en la BASE y no en PHP a proposito: la columna
     * es `utf8mb4`, que ordena «Óscar» entre «Nicolás» y «Paula», donde una
     * persona lo busca; `sortBy` de PHP lo mandaria detras de «Zulma».
     *
     * @param  Collection<int, Matricula>  $mias  las matriculas activas de $perfil
     * @return array<int, Collection<int, Perfil>> con MI matricula como clave
     */
    public static function porMatricula(Perfil $perfil, Collection $mias): array
    {
        $condicion = self::activasEnLasMismas($mias);

        if ($condicion === null) {
            return [];
        }

        $companeros = Perfil::query()
            ->where('id', '!=', $perfil->id)
            ->whereHas('matriculas', $condicion)
            ->with(['matriculas' => $condicion])
            ->orderBy('nombre_completo')
            ->get();

        $porPar = [];

        foreach ($companeros as $companero) {
            // Las matriculas que llegan cargadas son solo las que casan con
            // algun par mio, asi que cada una coloca a su dueno en una lista y
            // solo en una. Quien comparta DOS promotorias sale en las dos, que
            // es lo que la pantalla ensena.
            //
            // El @var es para PHPStan: `matriculas()` no declara el tipo que
            // devuelve, asi que la relacion sale como Model a secas. Se anota
            // aqui y no en el modelo a proposito: anotarla alli arreglaria de
            // paso errores que estan en la linea base, y entonces PHPStan se
            // queja de los patrones que ya no casan y hay que regenerarla — que
            // es una decision aparte y no de este cambio.
            /** @var Matricula $suya */
            foreach ($companero->matriculas as $suya) {
                $porPar[self::clave($suya)][] = $companero;
            }
        }

        $porMatricula = [];

        foreach ($mias as $mia) {
            $porMatricula[$mia->id] = new Collection($porPar[self::clave($mia)] ?? []);
        }

        return $porMatricula;
    }

    /** El par (promotoria, periodo), que es lo que de verdad define un grupo. */
    private static function clave(Matricula $matricula): string
    {
        return $matricula->promotoria_id.'-'.$matricula->periodo_id;
    }

    /**
     * Cuantas personas distintas son companeras de $perfil. Una sola consulta.
     *
     * Cuenta PERSONAS y no matriculas: quien comparta dos promotorias con
     * $perfil es un companero, no dos. Por eso el `distinct` va sobre el
     * estudiante y no sobre la fila.
     *
     * @param  Collection<int, Matricula>  $mias  las matriculas activas de $perfil
     */
    public static function cuantos(Perfil $perfil, Collection $mias): int
    {
        $condicion = self::activasEnLasMismas($mias);

        if ($condicion === null) {
            return 0;
        }

        return Matricula::query()
            ->where('estudiante_id', '!=', $perfil->id)
            ->tap($condicion)
            ->distinct()
            ->count('estudiante_id');
    }

    /**
     * La regla, una sola vez: matricula activa en alguno de MIS pares de
     * (promotoria, periodo).
     *
     * Devuelve `null` cuando no hay ningun par. Es la diferencia entre «no tiene
     * companeros» y «los tiene todos»: una condicion vacia no filtra nada, asi
     * que un estudiante recien inscrito y sin matricula activa se llevaria por
     * delante la lista entera de la institucion.
     *
     * El par va como un OR de dos igualdades agrupadas y no como dos `whereIn`
     * sueltos, que es el error facil de aqui: `whereIn(promotoria) AND
     * whereIn(periodo)` casa tambien las combinaciones cruzadas --mi promotoria
     * de este periodo con el periodo pasado-- y devolveria como companeros a
     * gente de otro semestre, que es exactamente lo que la regla prohibe.
     *
     * @param  Collection<int, Matricula>  $mias
     */
    private static function activasEnLasMismas(Collection $mias): ?Closure
    {
        $pares = $mias
            ->map(fn (Matricula $matricula) => [$matricula->promotoria_id, $matricula->periodo_id])
            ->unique(fn (array $par) => implode('-', $par))
            ->values()
            ->all();

        if ($pares === []) {
            return null;
        }

        // Sin tipos declarados a proposito: la misma condicion la reciben
        // `whereHas`, que pasa un Builder, y `with`, que pasa la relacion.
        return function ($consulta) use ($pares) {
            $consulta
                ->where('estado', Matricula::ACTIVA)
                ->where(function ($grupo) use ($pares) {
                    foreach ($pares as [$promotoriaId, $periodoId]) {
                        $grupo->orWhere(fn ($par) => $par
                            ->where('promotoria_id', $promotoriaId)
                            ->where('periodo_id', $periodoId));
                    }
                });
        };
    }
}
