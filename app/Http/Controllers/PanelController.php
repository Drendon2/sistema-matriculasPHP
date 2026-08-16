<?php

namespace App\Http\Controllers;

use App\Exceptions\LoteNoCabe;
use App\Models\Clase;
use App\Models\CupoPromotoria;
use App\Models\DocumentoRequerido;
use App\Models\Grupo;
use App\Models\Matricula;
use App\Models\Perfil;
use App\Models\Periodo;
use App\Models\Promotoria;
use App\Support\Permisos;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * El Panel: la contraparte de lo que ve el estudiante.
 *
 * Aqui quien dicta una promotoria ve quien pidio entrar, confirma o rechaza esas
 * solicitudes, fija el cupo del periodo y reparte a los matriculados en grupos.
 *
 * Visibilidad (ver el recordatorio en `Perfil`): nombre, foto, edad, telefono y
 * acudiente se ensenan al personal. La encuesta demografica y la copia del
 * documento NO se asoman por aqui.
 */
class PanelController extends Controller
{
    /**
     * Listado de promotorias con sus grupos, sus matriculados y sus pendientes.
     *
     * El profesor solo ve las que dicta; direccion las ve todas.
     *
     * A diferencia del original, que lanzaba tres consultas por promotoria
     * —grupos, sin grupo, pendientes—, aqui las matriculas se traen de una vez y
     * se reparten en memoria. El reparto es identico; lo que cambia es que un
     * panel con veinte promotorias deja de costar sesenta consultas.
     */
    public function index(Request $request): View
    {
        /** @var Perfil $perfil */
        $perfil = $request->attributes->get('perfil');
        $periodo = Periodo::enCurso();
        $exigidos = DocumentoRequerido::activos()->ordenados()->get();

        $promotorias = Promotoria::query()
            ->with(['area', 'profesor', 'grupos', 'cupos'])
            ->when($perfil->rol === 'profesor', fn ($q) => $q->where('profesor_id', $perfil->id))
            ->orderBy('id')
            ->get();

        $matriculas = $this->matriculasDelPanel($promotorias);
        $renovaciones = $this->renovacionesDe($matriculas);
        $clasesDeHoy = $this->clasesDeHoy($periodo);

        $datos = [];

        foreach ($promotorias as $promotoria) {
            $suyas = $matriculas->where('promotoria_id', $promotoria->id);

            // Pendientes: solicitudes que nadie ha resuelto todavia. Se listan
            // aparte de los grupos aunque alguna tenga grupo asignado, porque
            // mientras no se confirme no esta en la clase.
            $pendientes = $suyas->where('estado', Matricula::PENDIENTE);

            // Inscritos: activas y cancelaciones en tramite. La cancelacion sin
            // resolver sigue en la lista a proposito — el estudiante sigue yendo
            // a clase hasta que direccion la tramite, y borrarlo de la vista de
            // su propio profesor antes de eso seria mentir sobre quien esta en el
            // salon.
            $inscritas = $suyas->whereIn('estado', Matricula::ESTADOS_INSCRITO);

            $grupos = [];

            foreach ($promotoria->grupos as $grupo) {
                $delGrupo = $inscritas->where('grupo_id', $grupo->id);

                $grupos[] = [
                    'grupo' => $grupo,
                    'estudiantes' => $this->fichas($delGrupo, $exigidos, $renovaciones),
                    'clase_hoy' => $clasesDeHoy[$grupo->id] ?? null,
                ];
            }

            $datos[] = [
                'promotoria' => $promotoria,
                'grupos' => $grupos,
                'sin_grupo' => $this->fichas(
                    $inscritas->whereNull('grupo_id'),
                    $exigidos,
                    $renovaciones
                ),
                'pendientes' => $this->fichas($pendientes, $exigidos, $renovaciones),
                'puede_gestionar' => Permisos::puedeGestionarPromotoria($perfil, $promotoria),
                // Mas estrecho que lo anterior: el boton de clase es solo de
                // quien la dicta (ver `Permisos::dictaLaPromotoria`).
                'puede_marcar' => Permisos::dictaLaPromotoria($perfil, $promotoria),
                'cupo' => $promotoria->cupoEn($periodo),
                // Se cuenta sobre lo que ya esta en memoria en vez de llamar a
                // `ocupadosEn()`, que consulta. Parece un detalle y no lo es:
                // era una consulta POR PROMOTORIA, asi que un catalogo de
                // veintiuna hacia veintiun `count(*)` para pintar veintiuna
                // cifras que ya se podian sumar aqui.
                //
                // El resultado es identico: `ocupadosEn` cuenta las de ese
                // periodo con estado distinto de 'retirada', y lo que se trajo
                // son exactamente esos tres estados.
                'ocupados' => $periodo === null
                    ? 0
                    : $suyas->where('periodo_id', $periodo->id)->count(),
            ];
        }

        return view('panel.index', [
            'datos' => $datos,
            'periodo' => $periodo,
        ]);
    }

    /**
     * Da por buena una solicitud: pasa de pendiente a activa.
     *
     * Es el momento en que el estudiante entra de verdad a la promotoria. Hasta
     * aqui solo habia pedido sitio.
     */
    public function confirmar(Request $request, Matricula $matricula): RedirectResponse
    {
        /** @var Perfil $perfil */
        $perfil = $request->attributes->get('perfil');

        if (! Permisos::puedeGestionarPromotoria($perfil, $matricula->promotoria)) {
            return $this->volver('No tienes acceso a esta promotoría.');
        }

        // Solo se confirma lo que sigue pendiente. Si ya la resolvio otra
        // persona mientras esta estaba mirando la pantalla, no se dice nada: el
        // resultado que queria ya esta puesto.
        if ($matricula->estado !== Matricula::PENDIENTE) {
            return $this->volver('');
        }

        // El limite de promotorias por periodo tambien se aplica AQUI, y no solo
        // al matricularse: entre la solicitud y esta confirmacion el estudiante
        // pudo llenar su cupo por otro lado, o el administrador pudo bajar el
        // limite. Confirmar sin mirar lo dejaria por encima del tope.
        $limite = Matricula::limitePromotorias();
        $ocupadas = Matricula::promotoriasOcupadas(
            $matricula->estudiante_id,
            $matricula->periodo_id,
            $matricula->id
        );

        if ($ocupadas >= $limite) {
            $permitidas = $limite === 1
                ? 'la promotoría permitida'
                : "las {$limite} promotorías permitidas";

            return $this->volver(
                "{$matricula->estudiante->nombre_completo} ya ocupa {$permitidas} en este "
                . 'periodo. Retira una de sus matrículas antes de confirmar esta.'
            );
        }

        $matricula->estado = Matricula::ACTIVA;
        $matricula->save();

        return $this->volver(
            "Matrícula de {$matricula->estudiante->nombre_completo} confirmada.",
            exito: true
        );
    }

    /**
     * Niega una solicitud pendiente.
     *
     * Queda como 'retirada', el mismo estado que si el estudiante se hubiera
     * echado atras. No hay un estado 'rechazada' aparte: para todo lo que viene
     * despues —el cupo, la ranura, el historial— las dos cosas significan lo
     * mismo, que esa matricula no siguio adelante.
     */
    public function rechazar(Request $request, Matricula $matricula): RedirectResponse
    {
        /** @var Perfil $perfil */
        $perfil = $request->attributes->get('perfil');

        if (! Permisos::puedeGestionarPromotoria($perfil, $matricula->promotoria)) {
            return $this->volver('No tienes acceso a esta promotoría.');
        }

        if ($matricula->estado !== Matricula::PENDIENTE) {
            return $this->volver('');
        }

        $matricula->estado = Matricula::RETIRADA;
        $matricula->save();

        return $this->volver(
            "Solicitud de {$matricula->estudiante->nombre_completo} rechazada.",
            exito: true
        );
    }

    /**
     * Fija (o quita) el cupo de una promotoria para el periodo en curso.
     *
     * Dejar el campo vacio quita el tope. El profesor lo hace sobre las que
     * dicta; direccion sobre cualquiera.
     */
    public function cupo(Request $request, Promotoria $promotoria): RedirectResponse
    {
        /** @var Perfil $perfil */
        $perfil = $request->attributes->get('perfil');

        if (! Permisos::puedeGestionarPromotoria($perfil, $promotoria)) {
            return $this->volver('No tienes acceso a esta promotoría.');
        }

        $periodo = Periodo::enCurso();

        if ($periodo === null) {
            return $this->volver('No hay un periodo de matrícula activo en este momento.');
        }

        $bruto = trim((string) $request->input('cupo_maximo', ''));

        if ($bruto === '') {
            CupoPromotoria::where('promotoria_id', $promotoria->id)
                ->where('periodo_id', $periodo->id)
                ->delete();

            return $this->volver("{$promotoria} queda sin tope de cupos en {$periodo}.", exito: true);
        }

        // Se comprueba a mano en vez de con el validador para que el mensaje sea
        // el del original: aqui no hay formulario donde pintar un error de campo,
        // la respuesta es siempre una vuelta al panel con un aviso.
        if (! preg_match('/^-?\d+$/', $bruto)) {
            return $this->volver('El cupo debe ser un número entero.');
        }

        $cupo = (int) $bruto;

        if ($cupo < 0) {
            return $this->volver('El cupo no puede ser negativo.');
        }

        CupoPromotoria::updateOrCreate(
            ['promotoria_id' => $promotoria->id, 'periodo_id' => $periodo->id],
            ['cupo_maximo' => $cupo]
        );

        $ocupados = $promotoria->ocupadosEn($periodo);

        // Bajar el cupo por debajo de lo ya ocupado es legitimo —el personal
        // puede necesitarlo— pero no retira a nadie: solo cierra la puerta. Se
        // avisa en rojo porque la cifra del panel se queda en "12 / 8" y sin
        // explicacion parece un error del sistema.
        if ($cupo < $ocupados) {
            return $this->volver(
                "Cupo de {$promotoria} fijado en {$cupo} para {$periodo}, pero ya hay {$ocupados} "
                . 'matrículas ocupando sitio. No se retiró a nadie: simplemente no entrarán '
                . 'estudiantes nuevos hasta que el número baje.'
            );
        }

        return $this->volver("Cupo de {$promotoria} fijado en {$cupo} para {$periodo}.", exito: true);
    }

    /**
     * Manda una matricula a un grupo, o la deja sin grupo.
     *
     * Es el segundo paso del reparto: primero se confirma la matricula, luego se
     * le asigna horario. Un `grupo_id` vacio la saca del grupo sin retirarla de
     * la promotoria — el estudiante sigue inscrito, solo que sin horario.
     */
    public function asignarGrupo(Request $request, Matricula $matricula): RedirectResponse
    {
        /** @var Perfil $perfil */
        $perfil = $request->attributes->get('perfil');

        if (! Permisos::puedeGestionarPromotoria($perfil, $matricula->promotoria)) {
            return $this->volver('No tienes acceso a esta promotoría.');
        }

        $grupoId = $request->input('grupo_id') ?: null;
        $grupo = null;

        if ($grupoId !== null) {
            // Que el grupo sea de ESTA promotoria se exige en la consulta: los
            // ids llegan del formulario y nadie garantiza que sean los que se
            // pintaron.
            $grupo = Grupo::where('id', $grupoId)
                ->where('promotoria_id', $matricula->promotoria_id)
                ->first();

            abort_if($grupo === null, 404);
        }

        $matricula->grupo_id = $grupo?->id;

        try {
            $matricula->validar();
            $matricula->save();
        } catch (ValidationException $e) {
            return $this->volver(implode(' ', Arr::flatten($e->errors())));
        }

        return $this->volver(
            $grupo !== null
                ? "{$matricula->estudiante->nombre_completo} fue asignado a {$grupo}."
                : "{$matricula->estudiante->nombre_completo} quedó sin grupo asignado.",
            exito: true
        );
    }

    /**
     * Manda al mismo grupo a varios estudiantes de una vez.
     *
     * Repartir es la tarea del principio de periodo y se hace por tandas: llegan
     * veinte matriculados y casi todos van al mismo horario. Uno por uno son
     * veinte idas y vueltas para una sola decision ya tomada.
     *
     * El lote es TODO O NADA. Si no caben, no se mete a los que quepan: dejar
     * ocho dentro y cuatro fuera obliga a averiguar a mano cuales entraron, que
     * es justo el trabajo que esto viene a evitar. Se deshace el lote entero y
     * se dice cuantos cupos habia.
     */
    public function asignarGrupoLote(Request $request, Promotoria $promotoria): RedirectResponse
    {
        /** @var Perfil $perfil */
        $perfil = $request->attributes->get('perfil');

        if (! Permisos::puedeGestionarPromotoria($perfil, $promotoria)) {
            return $this->volver('No tienes acceso a esta promotoría.');
        }

        $grupoId = $request->input('grupo_id') ?: null;

        if ($grupoId === null) {
            return $this->volver('Elige a qué grupo mandarlos antes de asignar.');
        }

        $grupo = Grupo::where('id', $grupoId)
            ->where('promotoria_id', $promotoria->id)
            ->first();

        abort_if($grupo === null, 404);

        // Se filtra por promotoria y por "sin grupo" aqui y no solo en la
        // plantilla: lo que no encaje simplemente no entra en el lote.
        $matriculas = Matricula::query()
            ->whereIn('id', (array) $request->input('matricula_ids', []))
            ->where('promotoria_id', $promotoria->id)
            ->whereNull('grupo_id')
            ->whereIn('estado', Matricula::ESTADOS_INSCRITO)
            ->with('estudiante')
            ->get();

        if ($matriculas->isEmpty()) {
            return $this->volver('No marcaste a ningún estudiante.');
        }

        $cabian = null;

        try {
            DB::transaction(function () use ($matriculas, $grupo, &$cabian) {
                $asignadas = 0;

                foreach ($matriculas as $matricula) {
                    $matricula->grupo_id = $grupo->id;

                    try {
                        // La misma puerta que usa la asignacion de a uno: el
                        // cupo del grupo lo decide `validar()`, y cuenta las que
                        // ya se guardaron en este mismo bucle.
                        $matricula->validar();
                    } catch (ValidationException $e) {
                        $cabian = $asignadas;

                        // Deshace el lote entero. La excepcion es solo el
                        // vehiculo del rollback; se atrapa justo afuera.
                        throw new LoteNoCabe();
                    }

                    $matricula->save();
                    $asignadas++;
                }
            });
        } catch (LoteNoCabe) {
            $cupos = $cabian === 1 ? 'cupo' : 'cupos';

            return $this->volver(
                "No caben {$matriculas->count()} en {$grupo}: solo quedaban {$cabian} {$cupos}. "
                . 'No se asignó a nadie — manda menos estudiantes o amplíale el cupo al grupo.'
            );
        }

        $cuantos = $matriculas->count();

        return $this->volver(
            $cuantos === 1
                ? "1 estudiante quedó en {$grupo}."
                : "{$cuantos} estudiantes quedaron en {$grupo}.",
            exito: true
        );
    }

    // -----------------------------------------------------------------------
    // Armado del listado
    // -----------------------------------------------------------------------

    /**
     * Todas las matriculas que el panel puede llegar a ensenar, de una consulta.
     *
     * A proposito SIN filtrar por periodo, como el original: el panel es la vista
     * de quien dicta sobre su promotoria entera, no sobre un semestre.
     *
     * @param  Collection<int, Promotoria>  $promotorias
     * @return Collection<int, Matricula>
     */
    private function matriculasDelPanel(Collection $promotorias): Collection
    {
        if ($promotorias->isEmpty()) {
            return new Collection();
        }

        return Matricula::query()
            ->whereIn('promotoria_id', $promotorias->pluck('id'))
            ->whereIn('estado', [...Matricula::ESTADOS_INSCRITO, Matricula::PENDIENTE])
            ->with([
                'estudiante',
                'estudiante.datosEstudiante.acudiente',
                'estudiante.datosEstudiante.documentos',
            ])
            ->orderBy('id')
            ->get();
    }

    /**
     * Las clases abiertas HOY, por grupo.
     *
     * Una sola consulta para todo el panel: el boton de clase cambia de texto
     * segun si el grupo ya tiene lista abierta hoy, y preguntarlo grupo por
     * grupo seria una consulta por fila.
     *
     * @return array<int, \App\Models\Clase>
     */
    private function clasesDeHoy(?Periodo $periodo): array
    {
        if ($periodo === null) {
            return [];
        }

        return Clase::query()
            ->where('periodo_id', $periodo->id)
            ->whereDate('fecha_hora', today())
            ->get()
            ->keyBy('grupo_id')
            ->all();
    }

    /**
     * ¿Que matriculas son una renovacion, es decir de alguien que ya curso ESA
     * promotoria en otro periodo?
     *
     * Es lo que separa a quien vuelve de quien empieza de cero, y es justo lo que
     * quien dicta necesita para decidir en que nivel de grupo lo ubica.
     *
     * Se resuelve en una sola consulta y no matricula por matricula: el original
     * preguntaba por cada fila, y un panel con cuarenta estudiantes hacia
     * cuarenta consultas para pintar cuarenta etiquetas.
     *
     * @param  Collection<int, Matricula>  $matriculas
     * @return array<string, list<int>>  "estudianteId:promotoriaId" => periodos cursados
     */
    private function renovacionesDe(Collection $matriculas): array
    {
        if ($matriculas->isEmpty()) {
            return [];
        }

        $previas = Matricula::query()
            ->whereIn('estudiante_id', $matriculas->pluck('estudiante_id')->unique())
            ->whereIn('promotoria_id', $matriculas->pluck('promotoria_id')->unique())
            ->where('estado', Matricula::ACTIVA)
            ->get(['estudiante_id', 'promotoria_id', 'periodo_id']);

        $mapa = [];

        foreach ($previas as $previa) {
            $mapa["{$previa->estudiante_id}:{$previa->promotoria_id}"][] = $previa->periodo_id;
        }

        return $mapa;
    }

    /**
     * Convierte matriculas en las filas que pinta la tabla.
     *
     * @param  \Illuminate\Support\Collection<int, Matricula>  $matriculas
     * @param  Collection<int, DocumentoRequerido>  $exigidos
     * @param  array<string, list<int>>  $renovaciones
     * @return list<array<string, mixed>>
     */
    private function fichas($matriculas, Collection $exigidos, array $renovaciones): array
    {
        return $matriculas
            ->map(fn (Matricula $m) => $this->ficha($m, $exigidos, $renovaciones))
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    private function ficha(Matricula $matricula, Collection $exigidos, array $renovaciones): array
    {
        $datos = $matricula->estudiante->datosEstudiante;

        $cursados = $renovaciones["{$matricula->estudiante_id}:{$matricula->promotoria_id}"] ?? [];

        return [
            'matricula' => $matricula,
            'perfil' => $matricula->estudiante,
            'acudiente' => $datos?->acudiente,
            // Renovacion = ya la curso en OTRO periodo. El propio periodo de esta
            // matricula no cuenta; si contara, toda matricula activa se marcaria
            // a si misma como renovacion.
            'renovacion' => count(array_diff($cursados, [$matricula->periodo_id])) > 0,
            // Quien dicta se entera de que el estudiante esta de salida, pero la
            // decision no es suya: la marca es informativa y no trae botones.
            'cancelacion' => $matricula->cancelacion_pendiente,
            // Solo los OBLIGATORIOS: un papel opcional que falta no deja la
            // matricula incompleta, y marcarla igual haria que la etiqueta dejara
            // de significar "hay que llamar a esta persona".
            'papeles_pendientes' => $datos
                ? array_values(array_filter(
                    $datos->documentosFaltantes($exigidos),
                    fn (DocumentoRequerido $d) => $d->obligatorio
                ))
                : [],
        ];
    }

    private function volver(string $mensaje, bool $exito = false): RedirectResponse
    {
        $respuesta = redirect()->route('panel');

        if ($mensaje !== '') {
            $respuesta->with($exito ? 'success' : 'error', $mensaje);
        }

        return $respuesta;
    }
}
