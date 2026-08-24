<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Un curso, un taller o un grupo de proyeccion.
 *
 * Lo que separa esto de una promotoria no es el tamano ni la duracion: es COMO
 * se entra. A una promotoria se entra con una matricula que alguien confirma; a
 * una actividad se entra por un enlace que alguien comparte, sin cuenta y sin
 * matricula. Por eso esta clase no habla con `Matricula` en ninguna parte, y no
 * deberia empezar a hacerlo.
 *
 * El responsable puede ser profesor, director o administrador. No se pide el
 * rol "profesor" por lo mismo que en `Promotoria`: quien dirige una banda
 * sinfonica es a menudo el director de la escuela, y con el rol como unico
 * criterio no podria ni quedar a cargo ni pasar lista.
 */
class Actividad extends Model
{
    /** Un solo dia. */
    public const TALLER = 'taller';

    /** Varios dias, con sus fechas decididas al crearlo. */
    public const CURSO = 'curso';

    /** Sin fechas: se ensaya cuando toque. */
    public const PROYECCION = 'proyeccion';

    public const TIPOS = [self::TALLER, self::CURSO, self::PROYECCION];

    /** Los que se administran juntos, en el boton "Cursos y talleres". */
    public const TIPOS_CON_FECHAS = [self::CURSO, self::TALLER];

    /**
     * Como se llama cada tipo en pantalla.
     *
     * Con tildes, que es texto de interfaz; las constantes de arriba viajan a la
     * base y van sin ellas.
     */
    public const ETIQUETA_TIPO = [
        self::TALLER => 'Taller',
        self::CURSO => 'Curso',
        self::PROYECCION => 'Grupo de proyección',
    ];

    /**
     * Como se llama UNA reunion de cada tipo.
     *
     * El boton dice "Iniciar clase" en un curso y "Iniciar ensayo" en una banda,
     * porque es como lo llama quien lo va a oprimir.
     */
    public const ETIQUETA_SESION = [
        self::TALLER => 'taller',
        self::CURSO => 'clase',
        self::PROYECCION => 'ensayo',
    ];

    protected $table = 'actividades';

    protected $fillable = [
        'tipo',
        'nombre',
        'responsable_id',
        'periodo_id',
        'cupo_maximo',
    ];

    protected function casts(): array
    {
        return [
            'cupo_maximo' => 'integer',
            'abierta' => 'boolean',
        ];
    }

    /**
     * El defecto que declara la migracion, repetido aqui a proposito.
     *
     * Es la trampa que ya esta documentada en `ConfiguracionInstitucion`: el
     * defecto lo pone la BASE al insertar, y el modelo en memoria no lo ha
     * leido. Sin esto, una actividad recien creada responde `null` a `abierta`
     * en la misma peticion que la estrena, y eso es exactamente lo contrario de
     * lo que acaba de pasar.
     */
    protected $attributes = [
        'abierta' => true,
    ];

    /**
     * El enlace se sortea al crear y no se vuelve a tocar.
     *
     * Va en un hook y no en el controlador porque el token no es un dato que
     * alguien elija: es la identidad publica de la actividad, y una creada
     * desde un seeder o desde una prueba tiene que tener el suyo igual. Sin
     * esto, `token` seria NOT NULL sin valor y la insercion fallaria lejos de
     * aqui, con un mensaje del motor.
     */
    protected static function booted(): void
    {
        static::creating(function (self $actividad) {
            $actividad->token ??= Str::random(32);
        });
    }

    /** @param  Builder<self>  $consulta */
    public function scopeConFechas(Builder $consulta): void
    {
        $consulta->whereIn('tipo', self::TIPOS_CON_FECHAS);
    }

    /** @param  Builder<self>  $consulta */
    public function scopeDeProyeccion(Builder $consulta): void
    {
        $consulta->where('tipo', self::PROYECCION);
    }

    /** Quien la dirige y le pasa lista. Puede ser un director o el administrador. */
    public function responsable(): BelongsTo
    {
        return $this->belongsTo(Perfil::class, 'responsable_id');
    }

    public function periodo(): BelongsTo
    {
        return $this->belongsTo(Periodo::class);
    }

    /** Sus dias, del primero al ultimo. */
    public function sesiones(): HasMany
    {
        return $this->hasMany(SesionActividad::class, 'actividad_id')->orderBy('fecha');
    }

    /**
     * Que es una actividad de tantas clases.
     *
     * El tipo NO se elige: se deduce de cuantos dias tiene, porque un taller es
     * exactamente eso —"los talleres son solo de un dia"—. Preguntarlo aparte
     * dejaba crear un taller de cuatro dias y un curso de uno, y entonces el
     * nombre del tipo dejaba de querer decir nada.
     *
     * Se aplica al crear, con el numero que se pidio, y otra vez cada vez que se
     * guardan las fechas: quitarle dias a un curso hasta dejarlo en uno lo
     * convierte en taller, que es lo que ha pasado de verdad.
     */
    public static function tipoSegunClases(int $clases): string
    {
        return $clases <= 1 ? self::TALLER : self::CURSO;
    }

    /** El nombre del tipo tal como se pinta. */
    public function etiquetaTipo(): string
    {
        return self::ETIQUETA_TIPO[$this->tipo] ?? $this->tipo;
    }

    /** Como se llama una reunion suya: clase, taller o ensayo. */
    public function etiquetaSesion(): string
    {
        return self::ETIQUETA_SESION[$this->tipo] ?? 'sesión';
    }

    /** Si lleva fechas propias, que es lo que separa un curso de una proyeccion. */
    public function llevaFechas(): bool
    {
        return in_array($this->tipo, self::TIPOS_CON_FECHAS, true);
    }

    public function __toString(): string
    {
        return "{$this->nombre} ({$this->etiquetaTipo()})";
    }
}
