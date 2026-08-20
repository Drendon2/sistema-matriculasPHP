<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Una sesion de clase concreta, registrada por quien la dicta al darla.
 *
 * No se programa por adelantado ni se deduce del `horario` del grupo: se oprime
 * "Iniciar clase" cuando la clase empieza y lo que queda guardado es la hora
 * REAL en que se oprimio. Esa es toda la diferencia entre el horario (lo que
 * deberia pasar cada semana) y esta tabla (lo que paso).
 *
 * Va atada al grupo y no a la promotoria porque la lista que se pasa es la de un
 * horario concreto: dos grupos de la misma promotoria se ven en dias distintos y
 * cada uno tiene su propia asistencia.
 *
 * Que la clase este registrada no significa todavia que se haya dado: eso lo dan
 * por cierto los propios estudiantes (ver ConfirmacionClase).
 */
class Clase extends Model
{
    /**
     * Cuantos estudiantes tienen que dar fe de una clase para tenerla por
     * dictada. Tres es el numero normal; en un grupo de uno o dos no se puede
     * pedir mas gente de la que hay, asi que basta uno.
     */
    public const CONFIRMACIONES_REQUERIDAS = 3;
    public const GRUPO_PEQUENO = 2;

    /**
     * Cuanto tiempo queda abierta la confirmacion despues de la clase.
     *
     * Dos dias cubren al que no llevaba el celular encima y al que solo entra al
     * sistema en la noche, sin llegar a la semana siguiente: lo que se confirma
     * es que una clase concreta se dio, y eso se recuerda con precision el mismo
     * dia, no cuando ya se mezclo con la del martes que viene. Vencido el plazo,
     * lo que haya quedado registrado es definitivo — ni se confirma ni se retira.
     */
    public const VENTANA_CONFIRMACION_HORAS = 48;

    protected $table = 'clases';

    protected $fillable = [
        'grupo_id',
        'periodo_id',
        'fecha_hora',
        'registrada_por_id',
        'confirmaciones_requeridas',
    ];

    protected function casts(): array
    {
        return [
            'fecha_hora' => 'datetime',
            'confirmaciones_requeridas' => 'integer',
        ];
    }

    /** La hora real en que se oprimio el boton. Nunca editable a mano. */
    protected static function booted(): void
    {
        static::creating(function (self $clase) {
            $clase->fecha_hora ??= now();
        });
    }

    public function grupo(): BelongsTo
    {
        return $this->belongsTo(Grupo::class);
    }

    public function periodo(): BelongsTo
    {
        return $this->belongsTo(Periodo::class);
    }

    public function registradaPor(): BelongsTo
    {
        return $this->belongsTo(Perfil::class, 'registrada_por_id');
    }

    public function asistencias(): HasMany
    {
        return $this->hasMany(Asistencia::class);
    }

    public function confirmaciones(): HasMany
    {
        return $this->hasMany(ConfirmacionClase::class);
    }

    public function scopeRecientesPrimero(Builder $query): Builder
    {
        return $query->orderByDesc('fecha_hora');
    }

    /**
     * Cuantas confirmaciones necesita una clase de un grupo de ese tamano.
     *
     * Tres es el numero normal. Un grupo de uno o dos estudiantes no puede
     * reunirlas nunca, asi que ahi basta con una: el requisito tiene que ser
     * alcanzable o deja de verificar nada.
     *
     * Un grupo VACIO devuelve cero, y eso no significa "ya esta confirmada":
     * significa que no hay nadie que pueda confirmarla (ver `estaConfirmada`,
     * que exige un requisito mayor que cero).
     */
    public static function confirmacionesPara(int $inscritos): int
    {
        if ($inscritos <= 0) {
            return 0;
        }

        return $inscritos <= self::GRUPO_PEQUENO ? 1 : self::CONFIRMACIONES_REQUERIDAS;
    }

    /**
     * Registra la clase que empieza ahora, con su requisito ya fijado.
     *
     * El numero de confirmaciones se CONGELA aqui y no se recalcula despues, a
     * diferencia de la lista de asistencia, que si se resuelve cada vez que se
     * abre. Son dos cosas distintas: la lista tiene que reflejar quien esta hoy
     * en el grupo, mientras que el requisito describe el grupo tal como era el
     * dia de la clase. Si se recalculara, una clase ya confirmada volveria a
     * quedar en falta solo porque despues entro gente nueva.
     */
    public static function abrir(Grupo $grupo, Periodo $periodo, ?Perfil $perfil): self
    {
        $inscritos = Matricula::where('grupo_id', $grupo->id)
            ->where('periodo_id', $periodo->id)
            ->whereIn('estado', Matricula::ESTADOS_INSCRITO)
            ->count();

        return static::create([
            'grupo_id' => $grupo->id,
            'periodo_id' => $periodo->id,
            'registrada_por_id' => $perfil?->id,
            'confirmaciones_requeridas' => self::confirmacionesPara($inscritos),
        ]);
    }

    /**
     * ¿Ya la dieron por dictada suficientes estudiantes?
     *
     * `$total` evita otra consulta cuando quien llama ya conto las
     * confirmaciones (los listados las traen agregadas de una vez).
     */
    public function estaConfirmada(?int $total = null): bool
    {
        if ($this->confirmaciones_requeridas <= 0) {
            return false;
        }

        $total ??= $this->confirmaciones()->count();

        return $total >= $this->confirmaciones_requeridas;
    }

    /** Hasta cuando se puede confirmar o retirar la confirmacion. */
    public function getLimiteConfirmacionAttribute(): Carbon
    {
        return $this->fecha_hora->copy()->addHours(self::VENTANA_CONFIRMACION_HORAS);
    }

    /**
     * ¿Sigue dentro del plazo?
     *
     * Rige el mismo plazo para confirmar y para retirar la confirmacion: es la
     * misma ventana en la que todo se puede corregir, y pasada ella lo
     * registrado queda como esta. Si retirar siguiera abierto despues, una clase
     * ya verificada podria dejar de estarlo semanas mas tarde.
     */
    public function confirmacionAbierta(?Carbon $ahora = null): bool
    {
        return ($ahora ?? now())->lt($this->limite_confirmacion);
    }

    /**
     * El plazo se acabo y la clase no reunio las confirmaciones que pedia.
     *
     * Es un desenlace, no un estado a medias: ya no puede cambiar. Se separa de
     * "todavia faltan" porque las dos cosas se leen igual en un conteo (1 de 3)
     * y significan lo contrario — una espera respuesta y la otra ya no la va a
     * tener.
     */
    public function verificacionVencida(?int $total = null, ?Carbon $ahora = null): bool
    {
        return ! $this->confirmacionAbierta($ahora) && ! $this->estaConfirmada($total);
    }

    /**
     * Los estudiantes a los que hay que pasar lista en esta clase.
     *
     * Se resuelve al abrir la lista y NO se congela al crear la clase: si a
     * alguien lo movieron de grupo entre una sesion y la siguiente, la lista de
     * hoy tiene que ser la de hoy.
     *
     * Incluye a quien pidio cancelar porque mientras la direccion no resuelva la
     * solicitud el estudiante sigue yendo a clase, y hay que poder marcarlo
     * igual que a los demas.
     *
     * @return Collection<int, Matricula>
     */
    public function matriculasAPasar(): Collection
    {
        return Matricula::query()
            ->where('grupo_id', $this->grupo_id)
            ->where('periodo_id', $this->periodo_id)
            ->whereIn('estado', Matricula::ESTADOS_INSCRITO)
            ->with('estudiante')
            ->join('perfiles', 'perfiles.id', '=', 'matriculas.estudiante_id')
            ->orderBy('perfiles.nombre_completo')
            ->select('matriculas.*')
            ->get();
    }

    /**
     * Las clases de este estudiante en el periodo, con su estado de verificacion.
     *
     * Devuelve filas con: clase, matricula, confirmada_por_mi, confirmaciones,
     * requeridas, verificada, abierta, vencida y limite; de la mas reciente a la
     * mas antigua.
     *
     * Solo entran las clases POSTERIORES a su matricula: quien acaba de entrar
     * al grupo no estuvo en las clases de antes y no puede dar fe de ellas. Es
     * la misma idea que sostiene todo esto — se confirma lo que uno vio.
     *
     * Se listan tambien las que ya confirmo, las que alcanzaron el numero
     * requerido y las que se les vencio el plazo, en vez de esconderlas: el
     * estudiante tiene que poder ver que confirmo (y retirarlo mientras este a
     * tiempo), y ocultar las cerradas convertiria la lista en algo que cambia de
     * contenido segun lo que hagan los demas.
     *
     * @return list<array<string, mixed>>
     */
    public static function porConfirmar(Perfil $perfil, ?Periodo $periodo): array
    {
        if ($periodo === null) {
            return [];
        }

        $matriculas = Matricula::query()
            ->where('estudiante_id', $perfil->id)
            ->where('periodo_id', $periodo->id)
            ->whereNotNull('grupo_id')
            ->whereIn('estado', Matricula::ESTADOS_INSCRITO)
            ->get()
            ->keyBy('grupo_id');

        if ($matriculas->isEmpty()) {
            return [];
        }

        $clases = static::query()
            ->where('periodo_id', $periodo->id)
            ->whereIn('grupo_id', $matriculas->keys())
            ->with(['grupo.promotoria.area', 'grupo.sesiones'])
            ->withCount('confirmaciones')
            ->orderByDesc('fecha_hora')
            ->get();

        $mias = ConfirmacionClase::query()
            ->whereIn('clase_id', $clases->pluck('id'))
            ->whereIn('matricula_id', $matriculas->pluck('id'))
            ->pluck('clase_id')
            ->all();

        // Una sola lectura del reloj para toda la lista: si se leyera por fila,
        // dos clases del mismo momento podrian caer a distinto lado del plazo.
        $ahora = now();

        $filas = [];

        foreach ($clases as $clase) {
            $matricula = $matriculas[$clase->grupo_id];

            if ($clase->fecha_hora->lt($matricula->fecha)) {
                continue;
            }

            $total = $clase->confirmaciones_count;

            $filas[] = [
                'clase' => $clase,
                'matricula' => $matricula,
                'confirmada_por_mi' => in_array($clase->id, $mias, true),
                'confirmaciones' => $total,
                'requeridas' => $clase->confirmaciones_requeridas,
                'verificada' => $clase->estaConfirmada($total),
                'abierta' => $clase->confirmacionAbierta($ahora),
                'vencida' => $clase->verificacionVencida($total, $ahora),
                'limite' => $clase->limite_confirmacion,
            ];
        }

        return $filas;
    }

    public function __toString(): string
    {
        return "{$this->grupo} — {$this->fecha_hora->format('d/m/Y H:i')}";
    }
}
