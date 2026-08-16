<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

/**
 * Inscripcion de un estudiante en una PROMOTORIA, para un periodo dado.
 *
 * El estudiante no elige grupo/horario al matricularse: `grupo_id` queda en
 * blanco hasta que quien dicta divide a los matriculados segun su horario.
 *
 * Toda matricula nace 'pendiente'. Quien dicta la promotoria debe confirmarla
 * antes de que cuente como activa, y solo entonces se le puede asignar grupo.
 *
 * Un estudiante no puede acumular mas promotorias sin retirar en el mismo
 * periodo que las que permita la configuracion de la institucion.
 */
class Matricula extends Model
{
    public const PENDIENTE = 'pendiente';
    public const ACTIVA = 'activa';
    public const CANCELACION_SOLICITADA = 'cancelacion_solicitada';
    public const RETIRADA = 'retirada';

    /**
     * Etiquetas legibles. Van acentuadas porque se pintan tal cual en pantalla
     * — los comentarios de este proyecto van sin tildes, el texto de cara al
     * usuario no.
     */
    public const ESTADOS = [
        self::PENDIENTE => 'Pendiente de confirmación',
        self::ACTIVA => 'Activa',
        self::CANCELACION_SOLICITADA => 'Cancelación solicitada',
        self::RETIRADA => 'Retirada',
    ];

    /**
     * "Sigue inscrito ahora mismo": la activa y la que esta pidiendo salir.
     *
     * Se usa donde importa quien esta EN la clase —los listados de quien dicta,
     * el cupo de un grupo— para que una cancelacion en tramite no borre al
     * estudiante de la vista de su propio profesor antes de resolverse.
     */
    public const ESTADOS_INSCRITO = [self::ACTIVA, self::CANCELACION_SOLICITADA];

    /**
     * 'finalizada' NO es un estado guardado, y no esta en ESTADOS a proposito:
     * es lo que se MUESTRA de una matricula activa cuyo periodo ya quedo atras.
     *
     * No se guarda porque el dato no cambia —la matricula siguio activa hasta el
     * final—, lo que cambia es el calendario. Convertirlo en un cuarto valor
     * real obligaria a migrar las filas y a reescribir la veintena de consultas
     * que filtran por 'activa'; entre ellas la de renovacion, que busca
     * precisamente matriculas ACTIVAS de periodos anteriores. Con las filas
     * migradas no encontraria ninguna y ningun estudiante antiguo podria
     * renovar.
     */
    public const FINALIZADA = 'finalizada';

    protected $table = 'matriculas';

    protected $fillable = [
        'estudiante_id',
        'promotoria_id',
        'grupo_id',
        'periodo_id',
        'fecha',
        'estado',
        'ranura',
    ];

    protected $attributes = [
        'estado' => self::PENDIENTE,
        'ranura' => 1,
    ];

    protected function casts(): array
    {
        return [
            'fecha' => 'datetime',
            'ranura' => 'integer',
        ];
    }

    // La tabla tiene ademas `ranura_activa`, la columna generada que emula el
    // indice unico parcial de Postgres. La calcula la base de datos: se lee,
    // nunca se escribe.

    protected static function booted(): void
    {
        static::creating(function (self $matricula) {
            $matricula->fecha ??= now();
        });

        // Colocar la ranura antes de escribir, tanto al crear como al guardar:
        // dos matriculas nuevas nacen ambas con la ranura por defecto y
        // chocarian entre si.
        static::saving(function (self $matricula) {
            $matricula->colocarEnRanuraLibre();
        });
    }

    public function estudiante(): BelongsTo
    {
        return $this->belongsTo(Perfil::class, 'estudiante_id');
    }

    public function promotoria(): BelongsTo
    {
        return $this->belongsTo(Promotoria::class);
    }

    public function grupo(): BelongsTo
    {
        return $this->belongsTo(Grupo::class);
    }

    public function periodo(): BelongsTo
    {
        return $this->belongsTo(Periodo::class);
    }

    public function asistencias(): HasMany
    {
        return $this->hasMany(Asistencia::class);
    }

    public function confirmacionesClase(): HasMany
    {
        return $this->hasMany(ConfirmacionClase::class);
    }

    // -----------------------------------------------------------------------
    // Ranuras y limite de promotorias
    // -----------------------------------------------------------------------

    /**
     * Cuantas promotorias puede cursar un estudiante en un mismo periodo.
     *
     * Sale de la configuracion, no de una constante: el administrador lo cambia
     * sin tocar codigo ni migrar. Cuentan las pendientes y las activas —una
     * solicitud pendiente ya ocupa cupo, para que nadie pida otra mientras
     * espera confirmacion— y las retiradas (incluidas las rechazadas, que
     * quedan retiradas) lo liberan.
     */
    public static function limitePromotorias(): int
    {
        return ConfiguracionInstitucion::actual()->limite_promotorias_por_periodo;
    }

    /** Cuantos cupos de promotoria tiene ocupados el estudiante en el periodo. */
    public static function promotoriasOcupadas(int $estudianteId, int $periodoId, ?int $excluirId = null): int
    {
        return static::where('estudiante_id', $estudianteId)
            ->where('periodo_id', $periodoId)
            ->where('estado', '!=', self::RETIRADA)
            ->when($excluirId !== null, fn ($q) => $q->where('id', '!=', $excluirId))
            ->count();
    }

    /**
     * Ranuras que ya usan las otras matriculas sin retirar del estudiante.
     *
     * @return list<int>
     */
    private function ranurasOcupadasPorOtras(): array
    {
        return static::where('estudiante_id', $this->estudiante_id)
            ->where('periodo_id', $this->periodo_id)
            ->where('estado', '!=', self::RETIRADA)
            ->when($this->exists, fn ($q) => $q->where('id', '!=', $this->id))
            ->pluck('ranura')
            ->map(fn ($r) => (int) $r)
            ->all();
    }

    /**
     * Primera ranura libre para el estudiante, o null si ya no tiene cupo.
     *
     * Sin cupo = tiene tantas matriculas sin retirar como permite el limite
     * configurado. Se mira la CANTIDAD y no si quedan huecos en 1..limite,
     * porque desde que el limite es configurable las dos cosas dejaron de ser
     * equivalentes: si el administrador lo baja de 2 a 1, un estudiante con su
     * unica matricula en la ranura 2 tiene libre la 1 y aun asi esta en el tope.
     *
     * Las ranuras se numeran hasta RANURA_MAXIMA_ABSOLUTA para poder colocar las
     * que ya existan por encima del limite nuevo.
     */
    private function primeraRanuraLibre(?int $limite = null): ?int
    {
        $limite ??= self::limitePromotorias();
        $ocupadas = $this->ranurasOcupadasPorOtras();

        if (count($ocupadas) >= $limite) {
            return null;
        }

        for ($ranura = 1; $ranura <= ConfiguracionInstitucion::RANURA_MAXIMA_ABSOLUTA; $ranura++) {
            if (! in_array($ranura, $ocupadas, true)) {
                return $ranura;
            }
        }

        return null;
    }

    /**
     * Mueve la matricula a una ranura libre si otra ya ocupa la suya.
     *
     * Pasa al crear la segunda matricula (ambas nacen con la ranura por defecto)
     * y al reactivar una retirada. Si no queda ninguna libre se deja como esta a
     * proposito: asi el indice unico de la base de datos rechaza el guardado.
     *
     * Colocar bien la ranura ya NO basta para imponer el limite de promotorias:
     * cuando la ranura por defecto esta libre esto no hace nada, y el estudiante
     * podria pasarse del tope configurado sin que ningun indice se queje. De eso
     * se ocupa `validar()`.
     */
    private function colocarEnRanuraLibre(?int $limite = null): bool
    {
        if ($this->estado === self::RETIRADA || ! $this->estudiante_id || ! $this->periodo_id) {
            return false;
        }

        if (! in_array((int) $this->ranura, $this->ranurasOcupadasPorOtras(), true)) {
            return false;
        }

        $libre = $this->primeraRanuraLibre($limite);

        if ($libre === null) {
            return false;
        }

        $this->ranura = $libre;

        return true;
    }

    /**
     * ¿Guardar esta matricula SUMA un sitio al cupo de la promotoria?
     *
     * Confirmar, rechazar, asignar grupo o mover de grupo NO cambian cuantos
     * sitios se ocupan, asi que no se les puede aplicar el tope: si no, bajar el
     * cupo dejaria al personal sin poder tocar las matriculas que ya existen.
     * Solo suman: crear una matricula, reactivar una retirada, o moverla a otra
     * promotoria/periodo.
     *
     * Es la misma condicion que aplica el trigger de la base de datos. Las dos
     * tienen que decir lo mismo o una de las dos sobra.
     */
    public function aumentaOcupacion(): bool
    {
        if ($this->estado === self::RETIRADA) {
            return false;
        }

        if (! $this->exists) {
            return true;
        }

        $anterior = static::withoutGlobalScopes()
            ->where('id', $this->id)
            ->first(['estado', 'promotoria_id', 'periodo_id']);

        if ($anterior === null) {
            return true;
        }

        if ($anterior->estado === self::RETIRADA) {
            return true;
        }

        return $anterior->promotoria_id !== $this->promotoria_id
            || $anterior->periodo_id !== $this->periodo_id;
    }

    // -----------------------------------------------------------------------
    // Validacion
    // -----------------------------------------------------------------------

    /**
     * Las reglas que el esquema no puede imponer por si solo.
     *
     * Hay que llamarla ANTES de guardar. No va en un hook de `saving` porque el
     * trigger de cupo y el indice unico ya cubren el caso de que algo se salte
     * esta capa, y porque los mensajes de aqui son los que ve el usuario: tienen
     * que llegar al formulario, no como excepcion suelta.
     */
    public function validar(): void
    {
        // Se resuelve una vez y se reparte: leer la configuracion es una
        // consulta, y aqui hacen falta dos usos del mismo numero.
        $limite = self::limitePromotorias();

        // Colocar la ranura va antes de validar: si no, la segunda matricula
        // chocaria todavia con la ranura por defecto.
        $this->colocarEnRanuraLibre($limite);

        $aumenta = $this->aumentaOcupacion();

        // -- Limite de promotorias por periodo -------------------------------
        // Se comprueba AQUI. Antes bastaba el indice unico parcial sobre
        // `ranura`, porque habia exactamente tantas ranuras como permitia el
        // limite; desde que el limite es configurable el esquema solo conoce el
        // techo absoluto y no puede consultar la configuracion. El indice sigue
        // detras como red: acota cualquier fuga a RANURA_MAXIMA_ABSOLUTA y
        // cierra la carrera entre dos peticiones simultaneas.
        if ($this->estudiante_id && $this->periodo_id && $aumenta) {
            $ocupadas = self::promotoriasOcupadas(
                $this->estudiante_id,
                $this->periodo_id,
                $this->exists ? $this->id : null
            );

            if ($ocupadas >= $limite) {
                $palabra = $limite === 1 ? 'promotoría' : 'promotorías';

                throw ValidationException::withMessages([
                    'promotoria' => "Un estudiante puede estar en un máximo de {$limite} {$palabra} "
                        . 'por periodo, y este ya tiene ese cupo ocupado. Retira una matrícula '
                        . 'antes de agregar otra.',
                ]);
            }
        }

        // -- Cupo de la promotoria en el periodo -----------------------------
        // Pendientes y activas ocupan cupo por igual: una solicitud sin
        // confirmar ya reserva el sitio, para que quien dicta no acabe
        // rechazando una lista de espera entera. Sin cupo definido no hay tope.
        // El mismo tope lo reaplica el trigger, que ademas serializa las
        // peticiones simultaneas.
        if ($this->promotoria_id && $this->periodo_id && $aumenta) {
            $promotoria = $this->promotoria ?? Promotoria::find($this->promotoria_id);
            $periodo = $this->periodo ?? Periodo::find($this->periodo_id);
            $maximo = $promotoria?->cupoEn($periodo);

            if ($maximo !== null) {
                $ocupados = $promotoria->ocupadosEn($periodo, $this->exists ? $this->id : null);

                if ($ocupados >= $maximo) {
                    throw ValidationException::withMessages([
                        'promotoria' => "{$promotoria} no tiene cupos disponibles para {$periodo}: "
                            . "{$ocupados} de {$maximo} ocupados, contando las solicitudes pendientes.",
                    ]);
                }
            }
        }

        // -- Coherencia del grupo --------------------------------------------
        if ($this->grupo_id !== null) {
            $grupo = $this->grupo ?? Grupo::find($this->grupo_id);

            if ($grupo->promotoria_id !== $this->promotoria_id) {
                throw ValidationException::withMessages([
                    'grupo' => 'El grupo elegido no pertenece a esta promotoría.',
                ]);
            }

            $ocupados = $grupo->matriculas()
                ->where('periodo_id', $this->periodo_id)
                ->where('estado', self::ACTIVA)
                ->when($this->exists, fn ($q) => $q->where('id', '!=', $this->id))
                ->count();

            if ($ocupados >= $grupo->cupo_maximo) {
                throw ValidationException::withMessages([
                    'grupo' => 'El grupo no tiene cupos disponibles para este periodo.',
                ]);
            }
        }
    }

    // -----------------------------------------------------------------------
    // Renovacion
    // -----------------------------------------------------------------------

    /**
     * Que puede renovar un estudiante antiguo, y de que periodo viene.
     *
     * Antiguo = tuvo al menos una matricula ACTIVA en un periodo anterior. Se
     * toma el ultimo periodo que curso (no todos), asi que quien se salto un
     * semestre puede renovar igual desde el ultimo en el que si estuvo.
     *
     * La lista excluye las promotorias en las que ya tiene matricula viva en el
     * periodo actual, para que renovar dos veces no sea posible. Un estudiante
     * nuevo recibe [null, []].
     *
     * @return array{0: ?Periodo, 1: list<self>}
     */
    public static function renovables(Perfil $perfil, ?Periodo $periodoActual): array
    {
        if ($periodoActual === null) {
            return [null, []];
        }

        $anteriores = static::query()
            ->where('estudiante_id', $perfil->id)
            ->where('estado', self::ACTIVA)
            ->whereHas('periodo', fn ($q) => $q->where('fecha_inicio', '<', $periodoActual->fecha_inicio))
            ->with(['periodo', 'promotoria.area'])
            ->join('periodos', 'periodos.id', '=', 'matriculas.periodo_id')
            ->orderByDesc('periodos.fecha_inicio')
            ->select('matriculas.*')
            ->get();

        if ($anteriores->isEmpty()) {
            return [null, []];
        }

        $periodoAnterior = $anteriores->first()->periodo;

        $delUltimoPeriodo = $anteriores
            ->filter(fn (self $m) => $m->periodo_id === $periodoAnterior->id);

        // Lo que ya tiene vivo en el periodo actual no se puede volver a pedir.
        $yaTiene = static::query()
            ->where('estudiante_id', $perfil->id)
            ->where('periodo_id', $periodoActual->id)
            ->where('estado', '!=', self::RETIRADA)
            ->pluck('promotoria_id')
            ->all();

        return [
            $periodoAnterior,
            $delUltimoPeriodo
                ->reject(fn (self $m) => in_array($m->promotoria_id, $yaTiene, true))
                ->values()
                ->all(),
        ];
    }

    // -----------------------------------------------------------------------
    // Historial
    // -----------------------------------------------------------------------

    /**
     * Todas las matriculas de un estudiante, agrupadas por periodo, del mas
     * reciente al mas antiguo.
     *
     * No hace falta ningun modelo de historial: cada matricula ya guarda su
     * periodo y nunca se borra al terminar (quien se va queda como 'retirada'),
     * asi que la trayectoria completa ya esta en la tabla y esto solo la ordena.
     *
     * Incluye a proposito las retiradas y las rechazadas —que tambien quedan
     * 'retirada'—: un historial que solo ensena lo que prospero no sirve para
     * entender de donde viene el estudiante, que es justo para lo que se
     * consulta.
     *
     * `en_curso` marca el periodo activo para que la plantilla separe lo vigente
     * del pasado sin volver a consultarlo, y para que solo ahi se ofrezcan
     * acciones: un periodo terminado es historial cerrado.
     *
     * @return list<array{periodo: Periodo, matriculas: list<self>, en_curso: bool}>
     */
    public static function historialPorPeriodo(Perfil $perfil): array
    {
        $periodoActual = Periodo::enCurso();

        $matriculas = static::query()
            ->where('estudiante_id', $perfil->id)
            ->with(['periodo', 'promotoria.area', 'promotoria.profesor', 'grupo'])
            ->join('periodos', 'periodos.id', '=', 'matriculas.periodo_id')
            ->join('promotorias', 'promotorias.id', '=', 'matriculas.promotoria_id')
            ->join('areas', 'areas.id', '=', 'promotorias.area_id')
            // El id del periodo desempata para que las filas de un mismo periodo
            // queden contiguas aunque dos periodos compartan fecha de inicio; si
            // se intercalaran, el agrupado de abajo abriria dos bloques para uno.
            ->orderByDesc('periodos.fecha_inicio')
            ->orderByDesc('matriculas.periodo_id')
            ->orderBy('areas.nombre')
            ->orderBy('promotorias.nombre')
            ->select('matriculas.*')
            ->get();

        $bloques = [];

        foreach ($matriculas as $matricula) {
            $ultimo = count($bloques) - 1;

            if ($ultimo < 0 || $bloques[$ultimo]['periodo']->id !== $matricula->periodo_id) {
                $bloques[] = [
                    'periodo' => $matricula->periodo,
                    'matriculas' => [],
                    'en_curso' => $periodoActual !== null && $matricula->periodo_id === $periodoActual->id,
                ];
                $ultimo++;
            }

            $bloques[$ultimo]['matriculas'][] = $matricula;
        }

        return $bloques;
    }

    /**
     * Cifras de cabecera del historial: cuanto lleva el estudiante y cuanto ha
     * cursado.
     *
     * Solo cuentan las matriculas ACTIVAS, es decir las que un profesor
     * confirmo: eso es lo que el estudiante realmente curso. Las pendientes y
     * las retiradas siguen apareciendo en el detalle, pero no inflan estas
     * cifras — si contaran, quien pidio cinco promotorias y no entro a ninguna
     * se leeria como el estudiante mas veterano de la casa.
     *
     * @return array{periodos: int, promotorias: int, desde: ?Periodo}
     */
    public static function resumenTrayectoria(Perfil $perfil): array
    {
        $activas = static::query()
            ->where('estudiante_id', $perfil->id)
            ->where('estado', self::ACTIVA);

        return [
            'periodos' => (clone $activas)->distinct()->count('periodo_id'),
            'promotorias' => (clone $activas)->distinct()->count('promotoria_id'),
            'desde' => Periodo::query()
                ->whereHas('matriculas', fn ($q) => $q
                    ->where('estudiante_id', $perfil->id)
                    ->where('estado', self::ACTIVA))
                ->orderBy('fecha_inicio')
                ->first(),
        ];
    }

    // -----------------------------------------------------------------------
    // Estado
    // -----------------------------------------------------------------------

    public function getCancelacionPendienteAttribute(): bool
    {
        return $this->estado === self::CANCELACION_SOLICITADA;
    }

    /**
     * ¿Se puede NEGAR esta cancelacion, o solo aprobarla?
     *
     * Depende de la edad, y las dos ramas persiguen cosas distintas:
     *
     * - Menor de edad: si se puede rechazar. La aprobacion es una pausa real,
     *   para dar tiempo a hablar con el acudiente antes de que un nino se salga
     *   de la promotoria por su cuenta. Rechazar la devuelve a activa.
     * - Mayor de edad: no. Irse es decision suya y el sistema no se la discute;
     *   el paso por direccion existe para que quede constancia de en que
     *   promotorias se esta yendo la gente, no para retenerla.
     */
    public function getCancelacionEsRechazableAttribute(): bool
    {
        return $this->estudiante->es_menor;
    }

    /**
     * El estado tal como se LEE en pantalla, no como esta guardado.
     *
     * Solo se transforma la matricula ACTIVA de un periodo que ya termino: pasa
     * a "finalizada". Una pendiente sigue pendiente —fue una solicitud que nadie
     * llego a confirmar, y decir que finalizo seria inventarse un desenlace— y
     * una retirada sigue retirada, porque irse a mitad de camino es justo lo que
     * ese estado cuenta del historial.
     */
    public function getEstadoVisibleAttribute(): string
    {
        if ($this->estado === self::ACTIVA && $this->periodo->termino) {
            return self::FINALIZADA;
        }

        return $this->estado;
    }

    public function getEstadoVisibleDisplayAttribute(): string
    {
        $etiquetas = self::ESTADOS + [self::FINALIZADA => 'Finalizada'];

        return $etiquetas[$this->estado_visible];
    }

    public function __toString(): string
    {
        $destino = $this->grupo ?? $this->promotoria;

        return "{$this->estudiante->nombre_completo} -> {$destino}";
    }
}
