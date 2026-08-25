<?php

namespace App\Http\Controllers;

use App\Models\Asistencia;
use App\Models\Clase;
use App\Models\Grupo;
use App\Models\Perfil;
use App\Models\Periodo;
use App\Support\PaseDeLista;
use App\Support\Permisos;
use App\Support\ResumenAsistencia;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Clases dictadas y asistencia.
 *
 * Lo LEE todo el panel (quien dicta la promotoria, direccion y administracion);
 * lo ESCRIBE solo quien la dicta. Ver `Permisos::dictaLaPromotoria`.
 */
class ClaseController extends Controller
{
    private const SOLO_EL_PROFESOR =
        'Registrar clases y pasar lista es de quien dicta la promotoría. Si no tiene a '
        .'nadie asignado —o si eres tú quien la dicta—, asígnala en Gestión → Promotorías.';

    /**
     * Registra la clase que empieza ahora y lleva a pasar lista.
     *
     * La hora que queda guardada es la de este momento, que es justo el dato que
     * el boton existe para capturar: no se pide ni se puede escribir a mano.
     *
     * Si el grupo ya tiene una clase registrada HOY no se crea otra: se lleva a
     * la lista de esa. Un grupo tiene un solo horario, asi que dos registros el
     * mismo dia son casi siempre el mismo boton pulsado dos veces —y partir la
     * asistencia del dia en dos listas a medias es peor que cualquier caso raro
     * que esto impida.
     */
    public function nueva(Request $request, Grupo $grupo): RedirectResponse
    {
        /** @var Perfil $perfil */
        $perfil = $request->attributes->get('perfil');

        if (! Permisos::dictaLaPromotoria($perfil, $grupo->promotoria)) {
            return redirect()->route('panel')->with('error', self::SOLO_EL_PROFESOR);
        }

        $periodo = Periodo::enCurso();

        if ($periodo === null) {
            return redirect()->route('panel')->with(
                'error',
                'No hay un periodo en curso, así que la clase no se puede registrar en ninguno. '
                .'Pide que marquen el periodo en curso desde Gestión.'
            );
        }

        $deHoy = Clase::where('grupo_id', $grupo->id)
            ->whereDate('fecha_hora', today())
            ->first();

        if ($deHoy !== null) {
            // `success` y no un aviso: esto no es un error, quien pulso acaba
            // donde queria, en la lista de su clase de hoy.
            return redirect()->route('clase-asistencia', $deHoy)->with(
                'success',
                'Ya habías registrado una clase de este grupo hoy a las '
                .$deHoy->fecha_hora->format('H:i').'. Esta es su lista.'
            );
        }

        $clase = Clase::abrir($grupo, $periodo, $perfil);

        return redirect()->route('clase-asistencia', $clase);
    }

    /**
     * Pasar lista: quien vino, quien falto y quien falto con excusa.
     *
     * Se puede volver a abrir y corregir cuantas veces haga falta —el que llega
     * tarde y el que trae la excusa al dia siguiente son el caso normal, no la
     * excepcion—, asi que guardar actualiza o crea, no da de alta.
     *
     * Dejar a alguien sin marcar es valido a proposito (ver `Asistencia`): la
     * pantalla lo avisa, pero no bloquea el guardado.
     */
    public function asistencia(Request $request, Clase $clase): View|RedirectResponse
    {
        /** @var Perfil $perfil */
        $perfil = $request->attributes->get('perfil');

        $clase->load(['grupo.promotoria.area', 'grupo.sesiones', 'periodo']);

        if (! Permisos::puedeGestionarPromotoria($perfil, $clase->grupo->promotoria)) {
            return redirect()->route('panel')->with('error', 'No tienes acceso a esta promotoría.');
        }

        $puedeMarcar = Permisos::dictaLaPromotoria($perfil, $clase->grupo->promotoria);
        $matriculas = $clase->matriculasAPasar();

        $yaMarcado = $clase->asistencias()->pluck('estado', 'matricula_id')->all();
        $estudiantes = [];

        foreach ($matriculas as $matricula) {
            $estado = $yaMarcado[$matricula->id] ?? '';

            $estudiantes[] = [
                'matricula' => $matricula,
                'perfil' => $matricula->estudiante,
                'estado' => $estado,
                // Quien dicta se entera de que esta de salida, igual que en el
                // panel: la marca es informativa y no cambia que haya que
                // pasarle lista.
                'cancelacion' => $matricula->cancelacion_pendiente,
                // Para quien mira sin poder editar: lo mismo, pero como marcador
                // de estado en vez de opcion marcable.
                'marca' => ResumenAsistencia::MARCA[$estado] ?? '',
                'marca_texto' => Asistencia::ESTADOS[$estado] ?? '',
            ];
        }

        // Del lado de quien dicta se ensena CUANTAS confirmaciones lleva la
        // clase, nunca quien la confirmo. El numero le dice lo que necesita
        // saber —si la clase ya quedo verificada—, mientras que la lista de
        // nombres convertiria una verificacion en algo que el verificado puede
        // reclamarle a cada estudiante por su nombre.
        $confirmaciones = $clase->confirmaciones()->count();

        return view('panel.asistencia', [
            'clase' => $clase,
            'estudiantes' => $estudiantes,
            'estados' => Asistencia::ESTADOS,
            'puedeMarcar' => $puedeMarcar,
            'sinPasar' => count(array_filter($estudiantes, fn ($e) => $e['estado'] === '')),
            'confirmaciones' => $confirmaciones,
            'requeridas' => $clase->confirmaciones_requeridas,
            'verificada' => $clase->estaConfirmada($confirmaciones),
            'vencida' => $clase->verificacionVencida($confirmaciones),
            'limiteConfirmacion' => $clase->limite_confirmacion,
        ]);
    }

    public function guardarAsistencia(Request $request, Clase $clase): RedirectResponse
    {
        /** @var Perfil $perfil */
        $perfil = $request->attributes->get('perfil');

        if (! Permisos::puedeGestionarPromotoria($perfil, $clase->grupo->promotoria)) {
            return redirect()->route('panel')->with('error', 'No tienes acceso a esta promotoría.');
        }

        // Se comprueba aqui y no solo escondiendo los controles: la peticion
        // llega igual si alguien la envia a mano.
        if (! Permisos::dictaLaPromotoria($perfil, $clase->grupo->promotoria)) {
            return redirect()->route('clase-asistencia', $clase)->with('error', self::SOLO_EL_PROFESOR);
        }

        $matriculas = $clase->matriculasAPasar();

        $marcados = PaseDeLista::guardar(
            request: $request,
            asistencias: Asistencia::class,
            sesion: ['clase_id' => $clase->id],
            quien: 'matricula_id',
            ids: $matriculas->pluck('id'),
        );

        $sinMarcar = $matriculas->count() - $marcados;

        return redirect()->route('clase-asistencia', $clase)->with(
            'success',
            $sinMarcar
                ? "Asistencia guardada. Quedaron {$sinMarcar} "
                    .($sinMarcar === 1 ? 'estudiante' : 'estudiantes')
                    .' sin marcar: puedes volver a esta clase y completarlos.'
                : 'Asistencia guardada.'
        );
    }

    /**
     * Las clases dictadas de un grupo en el periodo en curso, y como va cada
     * quien.
     *
     * Es la otra mitad del boton de "Iniciar clase": sin esta pantalla la
     * asistencia seria un dato que solo se escribe. Sirve para las dos preguntas
     * que se hacen de verdad —que dias hubo clase y quien esta dejando de
     * venir— y es el unico camino para volver a una lista y corregirla.
     *
     * La ve todo el panel, incluida direccion: la supervision es justamente para
     * lo que existe este registro. Lo que ellos no tienen es el boton de abrir
     * una clase.
     */
    public function delGrupo(Request $request, Grupo $grupo): View|RedirectResponse
    {
        /** @var Perfil $perfil */
        $perfil = $request->attributes->get('perfil');

        $grupo->load('promotoria.area');

        if (! Permisos::puedeGestionarPromotoria($perfil, $grupo->promotoria)) {
            return redirect()->route('panel')->with('error', 'No tienes acceso a esta promotoría.');
        }

        $periodo = Periodo::enCurso();
        [$clases, $filas] = ResumenAsistencia::deGrupo($grupo, $periodo);

        return view('panel.grupo-clases', [
            'grupo' => $grupo,
            'periodo' => $periodo,
            'clases' => $clases,
            'filas' => $filas,
            'puedeMarcar' => Permisos::dictaLaPromotoria($perfil, $grupo->promotoria),
        ]);
    }
}
