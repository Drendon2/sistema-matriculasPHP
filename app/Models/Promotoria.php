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

    /** Clases durante todo el periodo. Es lo que habia antes de que hubiera tipos. */
    public const PROGRAMA = 'programa';

    /**
     * No consume plaza del limite de matriculas.
     *
     * Es el UNICO tipo que cambia una regla de negocio, y no es un descuido:
     * una banda sinfonica o un coro institucional es una actividad alineada con
     * la matricula que el estudiante ya tiene, no una matricula mas que compita
     * con ella. Quien ya llego a su tope puede entrar igual.
     *
     * Sigue respetando el cupo de su propio salon: eso lo decide el trigger de
     * `matriculas`, que mira la promotoria y no la carga del estudiante.
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
        self::TALLER => 'Una sola clase. Se inscriben y van una vez.',
        self::CURSO => 'Varias clases, sin llegar al final del periodo.',
        self::PROGRAMA => 'Clases durante todo el periodo.',
        self::PROYECCION => 'No ocupa plaza del límite de matrículas.',
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
     * Si esta promotoria se salta el limite de matriculas del estudiante.
     *
     * Se pregunta asi y no comparando el tipo a mano en cada sitio, para que el
     * dia que haya otro tipo exento haya UN lugar donde decirlo.
     */
    public function exentaDelLimite(): bool
    {
        return $this->tipo === self::PROYECCION;
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
