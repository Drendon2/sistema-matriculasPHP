<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

/**
 * Encuesta que llena un estudiante ANTIGUO al renovar, sobre el periodo cursado.
 *
 * Es distinta de la demografica: aquella describe a la persona y se llena una
 * sola vez; esta evalua un periodo concreto y se repite cada vez que el
 * estudiante renueva. Por eso va atada a (perfil, periodo) y no es 1:1.
 *
 * Corta a proposito: es el tramite que acompana al boton de renovar, no un
 * estudio. El comentario es el unico campo opcional.
 */
class EncuestaSatisfaccion extends Model
{
    public const ESCALA = [1 => '1', 2 => '2', 3 => '3', 4 => '4', 5 => '5'];

    /**
     * Nota por debajo de la cual una respuesta se considera mala experiencia.
     *
     * En una escala de 1 a 5, el 3 es el "ni bien ni mal" y no pide llamar a
     * nadie: lo que hay que atender de verdad es el 1 y el 2.
     */
    public const NOTA_BAJA = 2;

    protected $table = 'encuestas_satisfaccion';

    protected $fillable = [
        'perfil_id',
        'promotoria_id',
        'periodo_id',
        'satisfaccion_general',
        'calificacion_profesor',
        'horario_funciono',
        'recomendaria',
        'comentario',
        'fecha',
    ];

    protected function casts(): array
    {
        return [
            'satisfaccion_general' => 'integer',
            'calificacion_profesor' => 'integer',
            'horario_funciono' => 'boolean',
            'recomendaria' => 'boolean',
            'fecha' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $encuesta) {
            $encuesta->fecha ??= now();
        });
    }

    public function perfil(): BelongsTo
    {
        return $this->belongsTo(Perfil::class);
    }

    /** El periodo que el estudiante EVALUA, no aquel al que se renueva. */
    public function periodo(): BelongsTo
    {
        return $this->belongsTo(Periodo::class);
    }

    /**
     * La promotoria que se valora.
     *
     * Es null solo en las respuestas anteriores a que la encuesta distinguiera
     * promotorias y que no se pudieron atribuir sin inventar (ver la migracion
     * `add_promotoria_to_encuestas_satisfaccion`). Ese null significa «no se
     * sabe de cual habla», y las pantallas lo cuentan aparte en vez de
     * repartirlo a ojo.
     */
    public function promotoria(): BelongsTo
    {
        return $this->belongsTo(Promotoria::class);
    }

    /**
     * Que promotorias le faltan por valorar a alguien de un periodo dado.
     *
     * Sale de sus matriculas ACTIVAS de ese periodo menos lo que ya contesto.
     * Lo usan los dos sitios donde se pide la encuesta —la renovacion y el
     * retiro—, para que no puedan discrepar sobre que hay que preguntar.
     *
     * @return Collection<int, Matricula>
     */
    public static function pendientesDe(Perfil $perfil, ?Periodo $periodo)
    {
        if ($periodo === null) {
            return collect();
        }

        $contestadas = static::where('perfil_id', $perfil->id)
            ->where('periodo_id', $periodo->id)
            ->pluck('promotoria_id')
            ->filter()
            ->all();

        return Matricula::query()
            ->where('estudiante_id', $perfil->id)
            ->where('periodo_id', $periodo->id)
            ->where('estado', Matricula::ACTIVA)
            ->whereNotIn('promotoria_id', $contestadas)
            ->with('promotoria.area')
            ->get();
    }

    /**
     * El periodo que evalua "Para seguimiento".
     *
     * La encuesta se contesta al renovar y evalua el periodo que TERMINO, asi
     * que es el mas reciente CON respuestas, no el que esta en curso.
     */
    public static function periodoDeSeguimiento(): ?Periodo
    {
        return Periodo::query()
            ->whereHas('encuestasSatisfaccion')
            ->orderByDesc('fecha_inicio')
            ->first();
    }

    /**
     * Cuantas respuestas de ese periodo puntuaron NOTA_BAJA o menos en
     * experiencia general o en el profesor.
     *
     * Reusado por la alerta de la portada de Gestion y por el detalle de
     * Estadisticas, para que las dos cuenten exactamente lo mismo.
     */
    public static function conteoParaSeguimiento(): int
    {
        $periodo = self::periodoDeSeguimiento();

        if ($periodo === null) {
            return 0;
        }

        return self::where('periodo_id', $periodo->id)
            ->where(fn ($q) => $q->where('satisfaccion_general', '<=', self::NOTA_BAJA)
                ->orWhere('calificacion_profesor', '<=', self::NOTA_BAJA))
            ->count();
    }
}
