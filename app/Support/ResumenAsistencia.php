<?php

namespace App\Support;

use App\Models\Asistencia;
use App\Models\Clase;
use App\Models\Grupo;
use App\Models\Matricula;
use App\Models\Perfil;
use App\Models\Periodo;
use Illuminate\Support\Carbon;

/**
 * Las cifras de asistencia que alimentan las fichas y el calendario.
 *
 * Vive aqui y no en los controladores porque son preguntas sobre el modelo
 * —"cuantas falto", "cuantas dicto sin que nadie las verificara"— y las hace mas
 * de una pantalla.
 *
 * `deEstudiante` y `deProfesor` comparten la forma del resultado a proposito:
 * `fichas` son las cifras de cabecera y `celdas` el calendario, de modo que la
 * misma plantilla sirve para las dos sin preguntar de quien es la ficha.
 */
class ResumenAsistencia
{
    /**
     * El orden en que un dia "gana" cuando hay varias clases.
     *
     * Lo que se busca al recorrer un calendario de asistencia es donde estan los
     * problemas, asi que una falta tapa a una asistencia del mismo dia y no al
     * reves.
     */
    private const PRIORIDAD_DIA = ['falto', 'excusa', 'sin_marcar', 'asistio'];

    private const ETIQUETA_DIA = [
        'asistio' => 'Asistió',
        'falto' => 'Faltó',
        'excusa' => 'Faltó con excusa',
        'sin_marcar' => 'Hubo clase, sin marcar',
    ];

    /**
     * Como se pinta una marca de asistencia cuando NO se puede editar.
     *
     * Reusa el vocabulario de forma de `.estado`, el mismo que llevan las
     * opciones marcables: solida = vino, tachada = falto, punteada = aviso pero
     * tampoco estuvo. Sin esto habria que inventar un segundo lenguaje para
     * decir lo mismo en modo lectura.
     */
    public const MARCA = [
        'asistio' => 'estado-activa',
        'falto' => 'estado-retirada',
        'excusa' => 'estado-cancelacion_solicitada',
    ];

    /**
     * Asistencia del grupo en el periodo: por clase y por estudiante.
     *
     * Devuelve [clases, filas]:
     *
     * - `clases`: las sesiones registradas, de la mas reciente a la mas antigua,
     *   cada una con su conteo por estado y cuantos quedaron sin marcar.
     * - `filas`: una por estudiante inscrito, con sus conteos y el porcentaje de
     *   asistencia sobre las clases dictadas.
     *
     * Todo sale de consultas agregadas y no de recorrer las asistencias en PHP:
     * un grupo de veinte personas con un semestre de clases son cientos de
     * filas, y esta pantalla se abre a menudo.
     *
     * El porcentaje se calcula sobre TODAS las clases del grupo, no sobre las
     * veces que a esa persona la marcaron: si no, quien nunca fue y a quien
     * nadie llego a marcar apareceria con un 100 % vacio. La excusa cuenta como
     * falta para el porcentaje —no estuvo en clase— pero se muestra aparte, que
     * es lo que permite distinguir a quien avisa de quien no.
     *
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>}
     */
    public static function deGrupo(Grupo $grupo, ?Periodo $periodo): array
    {
        if ($periodo === null) {
            return [[], []];
        }

        $clasesQs = Clase::query()
            ->where('grupo_id', $grupo->id)
            ->where('periodo_id', $periodo->id)
            ->withCount('confirmaciones')
            ->recientesPrimero()
            ->get();

        $matriculas = Matricula::query()
            ->where('grupo_id', $grupo->id)
            ->where('periodo_id', $periodo->id)
            ->whereIn('estado', Matricula::ESTADOS_INSCRITO)
            ->with('estudiante')
            ->join('perfiles', 'perfiles.id', '=', 'matriculas.estudiante_id')
            ->orderBy('perfiles.nombre_completo')
            ->select('matriculas.*')
            ->get();

        $inscritos = $matriculas->count();

        $porClase = self::conteos($grupo, $periodo, 'clase_id');
        $porMatricula = self::conteos($grupo, $periodo, 'matricula_id');

        $clases = [];

        foreach ($clasesQs as $clase) {
            $conteo = $porClase[$clase->id] ?? [];
            $marcados = array_sum($conteo);
            $total = $clase->confirmaciones_count;

            $clases[] = [
                'clase' => $clase,
                'asistio' => $conteo['asistio'] ?? 0,
                'falto' => $conteo['falto'] ?? 0,
                'excusa' => $conteo['excusa'] ?? 0,
                // Puede salir negativo si alguien entro al grupo DESPUES de una
                // clase ya pasada: se acota a cero en vez de ensenar un numero
                // imposible.
                'sin_marcar' => max(0, $inscritos - $marcados),
                'confirmaciones' => $total,
                'requeridas' => $clase->confirmaciones_requeridas,
                'verificada' => $clase->estaConfirmada($total),
                'vencida' => $clase->verificacionVencida($total),
            ];
        }

        $totalClases = count($clases);
        $filas = [];

        foreach ($matriculas as $matricula) {
            $conteo = $porMatricula[$matricula->id] ?? [];
            $asistio = $conteo['asistio'] ?? 0;

            $filas[] = [
                'matricula' => $matricula,
                'asistio' => $asistio,
                'falto' => $conteo['falto'] ?? 0,
                'excusa' => $conteo['excusa'] ?? 0,
                'porcentaje' => $totalClases ? (int) round(100 * $asistio / $totalClases) : null,
            ];
        }

        return [$clases, $filas];
    }

    /**
     * Conteo de asistencias del grupo agrupado por la columna que se pida.
     *
     * @return array<int, array<string, int>>
     */
    private static function conteos(Grupo $grupo, Periodo $periodo, string $columna): array
    {
        $filas = Asistencia::query()
            ->join('clases', 'clases.id', '=', 'asistencias.clase_id')
            ->where('clases.grupo_id', $grupo->id)
            ->where('clases.periodo_id', $periodo->id)
            ->groupBy("asistencias.{$columna}", 'asistencias.estado')
            ->selectRaw("asistencias.{$columna} as clave, asistencias.estado as estado, COUNT(*) as total")
            ->get();

        $mapa = [];

        foreach ($filas as $fila) {
            $mapa[(int) $fila->clave][$fila->estado] = (int) $fila->total;
        }

        return $mapa;
    }

    /**
     * Como le ha ido a un estudiante en las clases de un periodo.
     *
     * `$promotorias` acota a las de quien consulta: un profesor ve la asistencia
     * de las suyas y no la de las demas disciplinas del estudiante. Con null se
     * ve todo, que es lo que corresponde a direccion.
     *
     * "Sin marcar" es una categoria propia y no se suma a las faltas. La
     * ausencia de fila significa que hubo clase y a esa persona nadie la paso
     * (ver `Asistencia`), y eso no es lo mismo que faltar: contarlo como falta
     * le cargaria al estudiante un descuido del profesor.
     *
     * @param  list<int>|null  $promotorias
     * @return array<string, mixed>|null
     */
    public static function deEstudiante(Perfil $perfil, ?Periodo $periodo, ?array $promotorias = null): ?array
    {
        if ($periodo === null) {
            return null;
        }

        $matriculas = Matricula::query()
            ->where('estudiante_id', $perfil->id)
            ->where('periodo_id', $periodo->id)
            ->whereNotNull('grupo_id')
            ->when($promotorias !== null, fn ($q) => $q->whereIn('promotoria_id', $promotorias))
            ->get();

        if ($matriculas->isEmpty()) {
            return null;
        }

        $marcas = [];

        foreach (Asistencia::whereIn('matricula_id', $matriculas->pluck('id'))->get() as $asistencia) {
            $marcas["{$asistencia->clase_id}:{$asistencia->matricula_id}"] = $asistencia->estado;
        }

        // Una clase le "toca" a una matricula cuando es del grupo de esa
        // matricula. Se recorre asi —y no por las asistencias— porque las clases
        // sin marca tienen que aparecer, y esas no tienen fila que recorrer.
        $clases = Clase::query()
            ->whereIn('grupo_id', $matriculas->pluck('grupo_id')->unique())
            ->where('periodo_id', $periodo->id)
            ->orderBy('fecha_hora')
            ->get();

        $porGrupo = [];

        foreach ($matriculas as $matricula) {
            $porGrupo[$matricula->grupo_id] ??= $matricula->id;
        }

        $cuenta = ['asistio' => 0, 'falto' => 0, 'excusa' => 0, 'sin_marcar' => 0];
        $dias = [];
        $cronologia = [];

        foreach ($clases as $clase) {
            $matriculaId = $porGrupo[$clase->grupo_id] ?? null;
            $estado = $marcas["{$clase->id}:{$matriculaId}"] ?? 'sin_marcar';

            $cuenta[$estado]++;
            $cronologia[] = $estado;

            $dia = $clase->fecha_hora->toDateString();
            $previo = $dias[$dia] ?? null;

            if ($previo === null || self::prioridad($estado) < self::prioridad($previo)) {
                $dias[$dia] = $estado;
            }
        }

        $marcadas = $cuenta['asistio'] + $cuenta['falto'] + $cuenta['excusa'];

        // La racha se cuenta hacia atras desde la ultima clase y la rompe
        // cualquier cosa que no sea haber ido — una excusa justifica la falta,
        // no la asistencia.
        $racha = 0;

        foreach (array_reverse($cronologia) as $estado) {
            if ($estado !== 'asistio') {
                break;
            }

            $racha++;
        }

        return [
            'tipo' => 'estudiante',
            'fichas' => [
                ['etiqueta' => 'Clases', 'valor' => count($cronologia)],
                ['etiqueta' => 'Asistió', 'valor' => $cuenta['asistio']],
                ['etiqueta' => 'Faltó', 'valor' => $cuenta['falto']],
                ['etiqueta' => 'Con excusa', 'valor' => $cuenta['excusa']],
                ['etiqueta' => 'Sin marcar', 'valor' => $cuenta['sin_marcar']],
                [
                    'etiqueta' => 'Asistencia',
                    'valor' => $marcadas ? round($cuenta['asistio'] / $marcadas * 100).'%' : '—',
                    'nota' => 'de las clases con marca',
                ],
                ['etiqueta' => 'Racha', 'valor' => $racha ?: '—', 'nota' => 'seguidas asistiendo'],
            ],
            'celdas' => self::celdas(
                $dias,
                $periodo,
                fn (string $estado) => "cal-{$estado}",
                fn (string $estado) => self::ETIQUETA_DIA[$estado]
            ),
            'leyenda' => array_map(
                fn (string $estado) => [
                    'clase' => "cal-{$estado}",
                    'etiqueta' => self::ETIQUETA_DIA[$estado],
                    'valor' => $cuenta[$estado],
                ],
                ['asistio', 'excusa', 'falto', 'sin_marcar']
            ),
        ];
    }

    /**
     * Cuanto ha dictado alguien en un periodo, y cuanto se le verifico.
     *
     * Va por `registrada_por` y no por las promotorias que tiene asignadas: lo
     * que describe es lo que esa persona hizo, no lo que le corresponde hacer.
     * Un director que cubrio unas clases aparece con ellas aunque la promotoria
     * no sea suya.
     *
     * @return array<string, mixed>|null
     */
    public static function deProfesor(Perfil $perfil, ?Periodo $periodo): ?array
    {
        if ($periodo === null) {
            return null;
        }

        $clases = Clase::query()
            ->where('registrada_por_id', $perfil->id)
            ->where('periodo_id', $periodo->id)
            ->withCount('confirmaciones')
            ->orderBy('fecha_hora')
            ->get();

        if ($clases->isEmpty()) {
            return null;
        }

        $verificadas = $clases->filter(fn (Clase $c) => $c->estaConfirmada($c->confirmaciones_count))->count();
        $dias = [];

        foreach ($clases as $clase) {
            $dia = $clase->fecha_hora->toDateString();
            $dias[$dia] = ($dias[$dia] ?? 0) + 1;
        }

        return [
            'tipo' => 'profesor',
            'fichas' => [
                ['etiqueta' => 'Clases dictadas', 'valor' => $clases->count()],
                ['etiqueta' => 'Verificadas', 'valor' => $verificadas],
                ['etiqueta' => 'Sin verificar', 'valor' => $clases->count() - $verificadas],
                [
                    'etiqueta' => 'Verificación',
                    'valor' => round($verificadas / $clases->count() * 100).'%',
                    'nota' => 'confirmadas por sus estudiantes',
                ],
                ['etiqueta' => 'Grupos', 'valor' => $clases->pluck('grupo_id')->unique()->count()],
                ['etiqueta' => 'Días con clase', 'valor' => count($dias)],
            ],
            // Magnitud, no identidad: aqui la celda dice CUANTAS clases hubo ese
            // dia, asi que va una sola tinta en tres pasos y no la paleta de
            // estados del estudiante. Son dos preguntas distintas y se ven
            // distintas a proposito.
            'celdas' => self::celdas(
                $dias,
                $periodo,
                fn (int $cuantas) => 'cal-n'.min($cuantas, 3),
                fn (int $cuantas) => $cuantas.($cuantas === 1 ? ' clase' : ' clases')
            ),
            'leyenda' => [
                ['clase' => 'cal-n1', 'etiqueta' => '1 clase', 'valor' => null],
                ['clase' => 'cal-n2', 'etiqueta' => '2', 'valor' => null],
                ['clase' => 'cal-n3', 'etiqueta' => '3 o más', 'valor' => null],
            ],
        ];
    }

    /**
     * El mapa de calor de la institucion entera: que dias hubo mas movimiento.
     *
     * Es el mismo calendario que la ficha de quien dicta, pero contando TODAS
     * las clases del periodo en vez de las de una persona. Y por eso la escala
     * es RELATIVA y no absoluta: en una ficha «3 o mas» distingue bien, porque
     * nadie da diez clases en un dia; en la casa entera un martes normal ya
     * pasa de diez, y con una escala fija el mapa saldria todo del tono mas
     * oscuro y no diria nada. Los cuatro pasos se reparten contra el dia mas
     * cargado del propio periodo.
     *
     * @return array<string, mixed>|null
     */
    public static function deInstitucion(?Periodo $periodo): ?array
    {
        if ($periodo === null) {
            return null;
        }

        $porDia = Clase::query()
            ->where('periodo_id', $periodo->id)
            ->selectRaw('DATE(fecha_hora) as dia, COUNT(*) as total')
            ->groupBy('dia')
            ->pluck('total', 'dia')
            ->map(fn ($t) => (int) $t)
            ->all();

        if ($porDia === []) {
            return null;
        }

        $maximo = max($porDia);
        $cuantas = array_sum($porDia);

        // Los dias de la SEMANA que mas se repiten. Es la pregunta que la
        // rejilla contesta de un vistazo pero no en numeros, y es la que sirve
        // para decidir horarios: si el sabado concentra la mitad de las clases,
        // abrir un grupo nuevo el sabado es pelearse por el salon.
        $porDiaSemana = array_fill(1, 7, 0);

        foreach ($porDia as $iso => $total) {
            $porDiaSemana[Carbon::parse($iso)->dayOfWeekIso] += $total;
        }

        arsort($porDiaSemana);

        return [
            'tipo' => 'institucion',
            'fichas' => [
                ['etiqueta' => 'Clases dictadas', 'valor' => $cuantas],
                ['etiqueta' => 'Días con clase', 'valor' => count($porDia)],
                ['etiqueta' => 'Día más cargado', 'valor' => $maximo, 'nota' => 'clases en un solo día'],
                [
                    'etiqueta' => 'Promedio por día',
                    'valor' => round($cuantas / count($porDia), 1),
                    'nota' => 'contando solo los días con clase',
                ],
            ],
            'celdas' => self::celdas(
                $porDia,
                $periodo,
                fn (int $total) => 'cal-n'.self::escalon($total, $maximo),
                fn (int $total) => $total.($total === 1 ? ' clase' : ' clases')
            ),
            'leyenda' => [
                ['clase' => 'cal-n1', 'etiqueta' => 'Poco movimiento', 'valor' => null],
                ['clase' => 'cal-n2', 'etiqueta' => '·', 'valor' => null],
                ['clase' => 'cal-n3', 'etiqueta' => '·', 'valor' => null],
                ['clase' => 'cal-n4', 'etiqueta' => "Hasta {$maximo} clases", 'valor' => null],
            ],
            'diasSemana' => self::nombresDeDia($porDiaSemana, $cuantas),
        ];
    }

    /**
     * En cual de los cuatro pasos cae un dia, contra el mas cargado.
     *
     * Se reparte por cuartos y no en tramos fijos porque el objetivo es que el
     * mapa se lea en cualquier periodo: uno con quince clases y otro con
     * trescientas tienen que ensenar el mismo contraste entre sus dias flojos y
     * sus dias fuertes.
     */
    private static function escalon(int $total, int $maximo): int
    {
        if ($maximo <= 0) {
            return 1;
        }

        return max(1, (int) ceil($total / $maximo * 4));
    }

    /**
     * @param  array<int, int>  $porDiaSemana  ya ordenado de mas a menos
     * @return list<array<string, mixed>>
     */
    private static function nombresDeDia(array $porDiaSemana, int $total): array
    {
        $nombres = [1 => 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'];
        $filas = [];

        foreach ($porDiaSemana as $dia => $clases) {
            if ($clases === 0) {
                continue;
            }

            $filas[] = [
                'etiqueta' => $nombres[$dia],
                'total' => $clases,
                'porcentaje' => (int) round($clases / $total * 100),
            ];
        }

        return $filas;
    }

    /**
     * Por que periodos puede caminar esta persona, y en cual empieza.
     *
     * Solo se ofrecen los periodos donde HAY algo suyo, y esa es la decision de
     * fondo. Un estudiante que curso dos semestres no tiene por que pasar por
     * los seis que existen: cuatro de esas flechas llevarian a un panel vacio
     * sin ninguna explicacion, y el que navega no puede saber si es que no fue a
     * clase o que no estaba matriculado. Con la lista acotada, todo destino
     * tiene algo que ensenar.
     *
     * Arranca en el periodo en curso si esa persona tiene algo en el; si no, en
     * el mas reciente que si —entre dos semestres, o para alguien que ya no
     * cursa, lo que se quiere ver es lo ultimo que hizo, no una pantalla vacia.
     *
     * `$comoEstudiante` decide donde mirar, con el mismo criterio que usa el
     * panel: los estudiantes tienen matriculas, el resto registra clases. Un
     * director que ademas dicta entra por la segunda, igual que en su ficha.
     *
     * @return array{periodo: ?Periodo, atras: ?Periodo, adelante: ?Periodo}
     */
    public static function navegacionDePeriodos(
        Perfil $perfil,
        ?string $pedido,
        bool $comoEstudiante
    ): array {
        // Periodos con CLASES suyas, no con matriculas suyas. Estar matriculado
        // en un semestre donde nadie registro una sola clase da un panel de
        // ceros, y este proyecto ya tiene decidido que eso no se pinta: no
        // informa y ademas miente por omision. Con este filtro, cada flecha
        // lleva a algo que se puede leer.
        //
        // Para el estudiante se cruzan grupo Y periodo: las clases del grupo en
        // un semestre en el que no estaba no son suyas.
        $ids = $comoEstudiante
            ? Clase::query()
                ->join('matriculas', function ($union) use ($perfil) {
                    $union->on('matriculas.grupo_id', '=', 'clases.grupo_id')
                        ->on('matriculas.periodo_id', '=', 'clases.periodo_id')
                        ->where('matriculas.estudiante_id', '=', $perfil->id);
                })
                ->distinct()
                ->pluck('clases.periodo_id')
            : Clase::where('registrada_por_id', $perfil->id)->distinct()->pluck('periodo_id');

        // Del mas reciente al mas antiguo, para que la flecha izquierda sea
        // siempre «hacia atras».
        $periodos = Periodo::whereIn('id', $ids)
            ->orderByDesc('fecha_inicio')
            ->orderByDesc('id')
            ->get();

        if ($periodos->isEmpty()) {
            return ['periodo' => null, 'atras' => null, 'adelante' => null];
        }

        $enCurso = Periodo::enCurso();

        $periodo = ($pedido ? $periodos->firstWhere('id', (int) $pedido) : null)
            ?? ($enCurso ? $periodos->firstWhere('id', $enCurso->id) : null)
            ?? $periodos->first();

        $indice = $periodos->search(fn (Periodo $p) => $p->id === $periodo->id);

        return [
            'periodo' => $periodo,
            'atras' => $periodos->get($indice + 1),
            'adelante' => $indice === 0 ? null : $periodos->get($indice - 1),
        ];
    }

    private static function prioridad(string $estado): int
    {
        $posicion = array_search($estado, self::PRIORIDAD_DIA, true);

        return $posicion === false ? count(self::PRIORIDAD_DIA) : $posicion;
    }

    /**
     * Una celda por dia del periodo, con su clase CSS y su texto al pasar.
     *
     * El titulo va en TODAS las celdas, incluidas las vacias. La rejilla dice el
     * estado por color y por forma, pero un cuadro de once pixeles no dice que
     * dia es: sin el texto al pasar, el calendario se puede mirar y no se puede
     * leer.
     *
     * @param  array<string, mixed>  $dias  clave = fecha ISO
     * @return list<array<string, string>>
     */
    private static function celdas(array $dias, ?Periodo $periodo, callable $clase, callable $titulo): array
    {
        return array_map(
            function (Carbon $dia) use ($dias, $clase, $titulo) {
                $iso = $dia->toDateString();
                $legible = $dia->format('d/m/Y');

                return [
                    'fecha' => $iso,
                    'clase' => isset($dias[$iso]) ? $clase($dias[$iso]) : '',
                    'titulo' => isset($dias[$iso])
                        ? "{$legible} — {$titulo($dias[$iso])}"
                        : "{$legible} — sin clase",
                ];
            },
            self::diasDelPeriodo($periodo, array_keys($dias))
        );
    }

    /**
     * Los dias a pintar, empezando el lunes de la primera semana.
     *
     * Arranca en lunes porque el calendario se pinta en columnas de siete: si
     * empezara el dia 1 del periodo, cada fila seria un dia de la semana
     * distinto y el patron semanal —que es justo lo que se va a mirar— dejaria
     * de verse.
     *
     * `$conDatos` ensancha la ventana hasta cubrir los dias que traen algo. Las
     * fechas de las clases no siempre caen dentro de fecha_inicio–fecha_fin: una
     * clase pertenece al periodo por su columna `periodo`, no por su fecha, y
     * una registrada la semana previa a la apertura sigue siendo de ese periodo.
     * Sin esto el calendario se la comia en silencio y contradecia a la ficha de
     * cifras de al lado, que si la contaba.
     *
     * @param  list<string>  $conDatos  fechas ISO
     * @return list<Carbon>
     */
    public static function diasDelPeriodo(?Periodo $periodo, array $conDatos = []): array
    {
        if ($periodo === null) {
            return [];
        }

        $fechas = array_map(fn (string $iso) => Carbon::parse($iso)->startOfDay(), $conDatos);

        $primero = collect([$periodo->fecha_inicio->copy()->startOfDay(), ...$fechas])->min();
        $primero = $primero->copy()->startOfWeek(Carbon::MONDAY);

        $finDelPeriodo = $periodo->fecha_fin->copy()->startOfDay();
        $hoy = Carbon::today();
        $ultimo = collect([$finDelPeriodo->lt($hoy) ? $finDelPeriodo : $hoy, ...$fechas])->max();

        if ($ultimo->lt($primero)) {
            return [];
        }

        $dias = [];

        for ($dia = $primero->copy(); $dia->lte($ultimo); $dia->addDay()) {
            $dias[] = $dia->copy();
        }

        return $dias;
    }
}
