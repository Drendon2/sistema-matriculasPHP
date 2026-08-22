<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * Una promotoria (ej. Violin). La dicta una sola persona, o nadie todavia.
 *
 * Quien la dicta NO tiene por que tener el rol "profesor": un director de
 * escuela que ademas da su propia promotoria es un caso real, y con el rol como
 * unico criterio no podria ni quedar asignado aqui ni pasar lista en su propio
 * grupo. Lo que manda es este vinculo, no el rol.
 *
 * Los estudiantes si quedan fuera: el filtro es `Perfil::ROLES_PERSONAL`.
 */
class Promotoria extends Model
{
    /** Una sola clase: la gente se inscribe y va una vez. */
    public const TALLER = 'taller';

    /** Varias clases, pero sin llegar al final del periodo. */
    public const CURSO = 'curso';

    /**
     * Clases durante todo el periodo.
     *
     * Es lo que habia antes de que hubiera tipos, y el UNICO que consume plaza
     * del limite de matriculas. Lo que se esta contando con ese limite es el
     * compromiso de un periodo entero: cuantas cosas puede sostener alguien de
     * enero a junio sin abandonarlas a mitad. Nada de lo demas es eso.
     */
    public const PROGRAMA = 'programa';

    /**
     * Actividad alineada con la matricula que el estudiante ya tiene.
     *
     * Una banda sinfonica o un coro institucional no compite con sus clases:
     * sale de ellas. Por eso no consume plaza, igual que el taller y el curso,
     * aunque el motivo sea otro —esos no la consumen porque no duran el periodo.
     */
    public const PROYECCION = 'proyeccion';

    public const TIPOS = [self::TALLER, self::CURSO, self::PROGRAMA, self::PROYECCION];

    /**
     * Como se llama cada tipo en pantalla.
     *
     * Con tildes, que es texto de interfaz. Las constantes de arriba son lo que
     * viaja a la base y van sin ellas.
     */
    public const ETIQUETA_TIPO = [
        self::TALLER => 'Taller',
        self::CURSO => 'Curso',
        self::PROGRAMA => 'Programa',
        self::PROYECCION => 'Grupo de proyección',
    ];

    /** Una linea por tipo, para que quien crea una no tenga que adivinar. */
    public const DESCRIPCION_TIPO = [
        self::TALLER => 'Una sola clase. No ocupa plaza del límite.',
        self::CURSO => 'Varias clases, sin llegar al final del periodo. No ocupa plaza.',
        self::PROGRAMA => 'Clases durante todo el periodo. Ocupa una plaza del límite.',
        self::PROYECCION => 'Actividad alineada con lo que ya cursa. No ocupa plaza.',
    ];

    protected $table = 'promotorias';

    protected $fillable = [
        'nombre',
        'area_id',
        'tipo',
        'profesor_id',
    ];

    /**
     * El mismo defecto que declara la migracion, repetido aqui a proposito.
     *
     * Es la trampa que ya esta documentada en `ConfiguracionInstitucion`: el
     * defecto lo pone la BASE al insertar, y el modelo en memoria no lo ha
     * leido. Sin esto, una promotoria recien creada vuelve con `tipo` a null
     * hasta que alguien la relea, y `exentaDelLimite()` decidiria sobre un null
     * justo en la peticion que la estrena.
     */
    protected $attributes = [
        'tipo' => self::PROGRAMA,
    ];

    /**
     * Cambiar el tipo reencuadra las matriculas que ya existen.
     *
     * El tipo vive en `promotorias` y no en la matricula justamente para esto:
     * el CONTEO se corrige solo, porque `promotoriasOcupadas()` lo pregunta por
     * `join`. Pero la RANURA si esta escrita en cada matricula, y sin esto se
     * quedaria como estaba: un programa convertido en taller seguiria ocupando
     * un numero de ranura que ya no le toca.
     *
     * Se descubrio con una prueba, no leyendo el codigo: el comentario de al
     * lado prometia que cambiar el tipo reencuadra, y a medias no lo hacia.
     *
     * Al pasar a un tipo que SI cuenta puede no quedar ranura libre, y entonces
     * se queda sin ella. Es deliberado y es la misma politica que ya tenia bajar
     * el limite: lo que ya existe no se rompe, solo se impide pedir mas.
     */
    protected static function booted(): void
    {
        static::updated(function (self $promotoria) {
            if (! $promotoria->wasChanged('tipo')) {
                return;
            }

            // `lazy()` y no `lazyById()`: es una trampa que ya costo una vez —
            // `lazyById` pagina preguntando por el id mayor que el ultimo, y las
            // tandas se solapan si la consulta lleva orden propio.
            $promotoria->matriculas()
                ->where('estado', '!=', Matricula::RETIRADA)
                ->lazy()
                ->each(function (Matricula $matricula) use ($promotoria) {
                    // La relacion, puesta a mano: si se dejara cargar sola
                    // vendria de la base con el tipo VIEJO en el mismo request.
                    $matricula->setRelation('promotoria', $promotoria);
                    $matricula->save();
                });
        });
    }

    /**
     * Si esta promotoria se salta el limite de matriculas del estudiante.
     *
     * Se pregunta en positivo por el UNICO que si lo consume, y no enumerando
     * los tres que no: un tipo nuevo nace exento salvo que alguien decida lo
     * contrario, que es el lado seguro por el que equivocarse — un tipo que
     * deberia contar y no cuenta se ve en la primera matricula, mientras que uno
     * que cuenta sin deber hacerlo bloquea a gente en silencio.
     *
     * Una promotoria exenta no gasta plaza NI ranura, asi que no tiene tope: se
     * pueden acumular las que haya. Cada una sigue respetando el cupo de su
     * propio salon, que lo decide el trigger de `matriculas` mirando la
     * promotoria y no la carga del estudiante.
     */
    public function exentaDelLimite(): bool
    {
        return $this->tipo !== self::PROGRAMA;
    }

    /** El nombre del tipo tal como se pinta. */
    public function etiquetaTipo(): string
    {
        return self::ETIQUETA_TIPO[$this->tipo] ?? self::ETIQUETA_TIPO[self::PROGRAMA];
    }

    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class);
    }

    /** Quien la dicta y pasa lista en sus grupos. Puede ser un director. */
    public function profesor(): BelongsTo
    {
        return $this->belongsTo(Perfil::class, 'profesor_id');
    }

    public function grupos(): HasMany
    {
        return $this->hasMany(Grupo::class);
    }

    public function cupos(): HasMany
    {
        return $this->hasMany(CupoPromotoria::class);
    }

    public function matriculas(): HasMany
    {
        return $this->hasMany(Matricula::class);
    }

    /**
     * Cupo maximo fijado para ese periodo, o null si la promotoria no tiene tope.
     *
     * Si la relacion ya viene cargada se usa esa —los listados del catalogo la
     * traen con `with('cupos')` y aqui se llama una vez por fila—; si no, se
     * consulta solo la que hace falta en vez de arrastrar todos los periodos.
     */
    public function cupoEn(?Periodo $periodo): ?int
    {
        if ($periodo === null) {
            return null;
        }

        $cupo = $this->relationLoaded('cupos')
            ? $this->cupos->firstWhere('periodo_id', $periodo->id)
            : $this->cupos()->where('periodo_id', $periodo->id)->first();

        return $cupo?->cupo_maximo;
    }

    /**
     * Matriculas que ocupan cupo: pendientes y activas.
     *
     * Las retiradas lo liberan. Una cancelacion en tramite NO: mientras nadie
     * la apruebe, el estudiante sigue inscrito y su sitio sigue tomado.
     */
    public function ocupadosEn(?Periodo $periodo, ?int $excluirMatriculaId = null): int
    {
        if ($periodo === null) {
            return 0;
        }

        return $this->matriculas()
            ->where('periodo_id', $periodo->id)
            ->where('estado', '!=', Matricula::RETIRADA)
            ->when($excluirMatriculaId !== null, fn ($q) => $q->where('id', '!=', $excluirMatriculaId))
            ->count();
    }

    /**
     * Lo mismo que `ocupadosEn()`, pero para VARIAS promotorias de una vez.
     *
     * Existe porque `ocupadosEn()` siempre consulta, asi que llamarla dentro de
     * un bucle cuesta tantas consultas como filas haya. Las pantallas que
     * pintan un listado entero —el catalogo del estudiante y el reparto de
     * cupos— usan esta.
     *
     * Vive aqui pegada a `ocupadosEn()` a proposito: las condiciones de las dos
     * tienen que ser LAS MISMAS —las retiradas liberan cupo, una cancelacion en
     * tramite no— y separarlas por archivos es como se acaban desincronizando.
     * Si alguna vez cambia una, tiene que cambiar la otra tres lineas mas
     * arriba, a la vista.
     *
     * Devuelve un mapa `promotoria_id => total`. Las promotorias sin ninguna
     * matricula NO aparecen: un GROUP BY solo devuelve las que tienen alguna,
     * asi que quien lea el mapa debe tratar la ausencia como cero.
     *
     * @param  Collection<int, self>  $promotorias
     * @return Collection<int, int>
     */
    public static function ocupadosEnLote(?Periodo $periodo, Collection $promotorias): Collection
    {
        if ($periodo === null || $promotorias->isEmpty()) {
            return collect();
        }

        return Matricula::query()
            ->whereIn('promotoria_id', $promotorias->pluck('id'))
            ->where('periodo_id', $periodo->id)
            ->where('estado', '!=', Matricula::RETIRADA)
            ->groupBy('promotoria_id')
            ->selectRaw('promotoria_id, COUNT(*) as total')
            ->pluck('total', 'promotoria_id');
    }

    /** Cupos libres en el periodo, o null si no hay tope definido. */
    public function cuposDisponibles(?Periodo $periodo): ?int
    {
        $maximo = $this->cupoEn($periodo);

        if ($maximo === null) {
            return null;
        }

        return $maximo - $this->ocupadosEn($periodo);
    }

    public function __toString(): string
    {
        return "{$this->nombre} ({$this->area->nombre})";
    }
}
