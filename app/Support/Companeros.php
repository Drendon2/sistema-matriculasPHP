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
 * «Companero» significa GRUPO Y PERIODO en comun, las dos cosas y con matricula
 * activa. Es la unica cosa que un estudiante ve de otro --nombre y foto, ni edad
 * ni telefono ni acudiente-- asi que la regla decide tambien que se puede mirar:
 * de aqui cuelga la puerta de `ArchivoController::foto`.
 *
 * **El grupo, y no la promotoria.** Hasta el 27/08 era la promotoria entera, que
 * es lo que hace el Django (`views.py:716`). Se cambio por peticion de la
 * institucion: quien va a Guitarra los martes no comparte clase con quien va los
 * jueves, y ensenarle su nombre y su cara no lo hace companero suyo. Con el
 * cambio, quien reparte los grupos reparte tambien quien se ve con quien.
 *
 * **Sin grupo asignado no hay companeros**, aunque la promotoria no tenga ningun
 * grupo creado y sea ella misma la clase. Es la regla que se pidio, de una sola
 * linea y sin excepciones que explicar, y se tomo a sabiendas de lo que cuesta:
 * en la base de desarrollo son 97 matriculas de 280, y siete de las veintiuna
 * promotorias no tienen ni un grupo. A esos la pantalla no les miente --dice que
 * les falta grupo, no que no tengan companeros-- pero hasta que alguien los
 * reparta no ven a nadie.
 *
 * **El periodo sigue en la clave, y no sobra**: un grupo cuelga de la promotoria
 * y NO del periodo (ver la migracion de `grupos`), asi que el mismo grupo existe
 * semestre tras semestre. Como las matriculas tampoco se retiran al cerrar un
 * periodo --se quedan en `activa`--, con el grupo solo un estudiante de tercer
 * ano veria juntos a sus companeros de ahora y a los de hace dos semestres.
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
 * Aquella vez se unieron dos de las TRES que habia. La tercera --la puerta de la
 * foto-- se quedo fuera porque responde otra pregunta, un si o un no sobre dos
 * personas, y el 27/08 se trajo aqui: al estrechar la regla al grupo, una puerta
 * que siguiera abierta a la promotoria entera habria seguido entregando la foto
 * de quien la pantalla ya no ensena, y nada lo habria delatado.
 *
 * Los tres metodos publicos piden cosas distintas a proposito: la pantalla de
 * companeros necesita los perfiles ordenados, la tarjeta del perfil solo un
 * numero, y la puerta de la foto un booleano. Traer perfiles enteros para
 * contarlos seria cambiar un derroche por otro. Lo que comparten --que es lo que
 * importa-- es la condicion.
 */
class Companeros
{
    /**
     * Los companeros de $perfil, una lista por cada matricula suya.
     *
     * Dos consultas fijas, sea cual sea el limite de promotorias: una trae los
     * perfiles y otra sus matriculas para saber en que lista cae cada uno.
     *
     * Devuelve una entrada por CADA matricula recibida, aunque quede vacia: las
     * que no tienen grupo no llevan companeros, y la pantalla las pinta igual
     * para poder decir que falta asignarlo.
     *
     * **La clave es MI matricula y no el grupo**, y la diferencia se ve en cuanto
     * alguien renueva: las matriculas no se retiran al cerrar un periodo --se
     * quedan en `activa`-- asi que un estudiante de tercer ano tiene el mismo
     * grupo activo en dos o tres periodos a la vez. Agrupando por grupo, esas
     * secciones --que la pantalla pinta por separado, una por periodo-- recibian
     * todas la MISMA lista mezclada, con los de este semestre y los del pasado
     * juntos y repetido quien estuvo en los dos.
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
        /** @var array<int, Collection<int, Perfil>> $porMatricula */
        $porMatricula = [];

        foreach ($mias as $mia) {
            $porMatricula[$mia->id] = new Collection;
        }

        $condicion = self::activasEnLosMismos($mias);

        if ($condicion === null) {
            return $porMatricula;
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
            // solo en una. Quien comparta DOS grupos sale en las dos, que es lo
            // que la pantalla ensena.
            //
            // El @var es para PHPStan: `matriculas()` no declara el tipo que
            // devuelve, asi que la relacion sale como Model a secas. Se anota
            // aqui y no en el modelo a proposito: anotarla alli arreglaria de
            // paso errores que estan en la linea base, y entonces PHPStan se
            // queja de los patrones que ya no casan y hay que regenerarla — que
            // es una decision aparte y no de este cambio.
            /** @var Matricula $suya */
            foreach ($companero->matriculas as $suya) {
                $clave = self::clave($suya);

                if ($clave !== null) {
                    $porPar[$clave][] = $companero;
                }
            }
        }

        foreach ($mias as $mia) {
            $clave = self::clave($mia);

            if ($clave !== null) {
                $porMatricula[$mia->id] = new Collection($porPar[$clave] ?? []);
            }
        }

        return $porMatricula;
    }

    /**
     * Cuantas personas distintas son companeras de $perfil. Una sola consulta.
     *
     * Cuenta PERSONAS y no matriculas: quien comparta dos grupos con $perfil es
     * un companero, no dos. Por eso el `distinct` va sobre el estudiante y no
     * sobre la fila.
     *
     * @param  Collection<int, Matricula>  $mias  las matriculas activas de $perfil
     */
    public static function cuantos(Perfil $perfil, Collection $mias): int
    {
        $condicion = self::activasEnLosMismos($mias);

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
     * ¿Son companeros estas dos personas? Dos consultas.
     *
     * Es la puerta de la foto, y por eso vive aqui y no en el controlador que la
     * entrega: si la definicion de «companero» se estrecha en un sitio y en el
     * otro no, la pantalla deja de ensenar a alguien pero su foto se sigue
     * sirviendo a quien pida la direccion a mano.
     *
     * No reutiliza `cuantos()` porque la pregunta es otra: aqui hay dos personas
     * concretas y no hay que contar a nadie.
     */
    public static function sonCompaneros(Perfil $solicitante, Perfil $objetivo): bool
    {
        $suyos = self::paresDe($objetivo);

        if ($suyos === []) {
            return false;
        }

        return array_intersect(self::paresDe($solicitante), $suyos) !== [];
    }

    /**
     * Los pares (grupo, periodo) activos de alguien, ya como texto.
     *
     * Sin grupo asignado la matricula no cuenta, y el `whereNotNull` es donde
     * eso se aplica de verdad: dejar pasar el nulo emparejaria entre si a todos
     * los que estan sin repartir, que es justo lo que la regla no quiere.
     *
     * @return list<string>
     */
    private static function paresDe(Perfil $perfil): array
    {
        return Matricula::where('estudiante_id', $perfil->id)
            ->where('estado', Matricula::ACTIVA)
            ->whereNotNull('grupo_id')
            ->get(['grupo_id', 'periodo_id'])
            ->map(fn (Matricula $m) => "{$m->grupo_id}:{$m->periodo_id}")
            ->unique()
            ->values()
            ->all();
    }

    /**
     * El par (grupo, periodo), que es lo que de verdad define una clase.
     *
     * `null` cuando la matricula no tiene grupo: sin el no hay con quien
     * emparejarla, y una clave a medias --con un hueco donde va el grupo--
     * juntaria entre si a todos los que estan sin repartir.
     */
    private static function clave(Matricula $matricula): ?string
    {
        if ($matricula->grupo_id === null) {
            return null;
        }

        return $matricula->grupo_id.'-'.$matricula->periodo_id;
    }

    /**
     * La regla, una sola vez: matricula activa en alguno de MIS pares de
     * (grupo, periodo).
     *
     * Devuelve `null` cuando no hay ningun par --porque no hay matriculas, o
     * porque a ninguna le han asignado grupo--. Es la diferencia entre «no tiene
     * companeros» y «los tiene todos»: una condicion vacia no filtra nada, asi
     * que un estudiante sin repartir se llevaria por delante la lista entera de
     * la institucion.
     *
     * El par va como un OR de dos igualdades agrupadas y no como dos `whereIn`
     * sueltos, que es el error facil de aqui: `whereIn(grupo) AND
     * whereIn(periodo)` casa tambien las combinaciones cruzadas --mi grupo de
     * este periodo con el periodo pasado-- y devolveria como companeros a gente
     * de otro semestre, que es exactamente lo que la regla prohibe.
     *
     * @param  Collection<int, Matricula>  $mias
     */
    private static function activasEnLosMismos(Collection $mias): ?Closure
    {
        $pares = $mias
            ->filter(fn (Matricula $matricula) => $matricula->grupo_id !== null)
            ->map(fn (Matricula $matricula) => [$matricula->grupo_id, $matricula->periodo_id])
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
                    foreach ($pares as [$grupoId, $periodoId]) {
                        $grupo->orWhere(fn ($par) => $par
                            ->where('grupo_id', $grupoId)
                            ->where('periodo_id', $periodoId));
                    }
                });
        };
    }
}
