<?php

namespace App\Http\Controllers;

use App\Models\Actividad;
use App\Models\AsistenciaActividad;
use App\Models\InscritoActividad;
use App\Models\Perfil;
use App\Models\SesionActividad;
use App\Support\PaseDeLista;
use App\Support\Permisos;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * El lado de quien DA la actividad, no el de quien la administra.
 *
 * Gestion crea el curso, le pone fechas y comparte el enlace. Aqui se dirige lo
 * que ya existe: se ve quien se apunto y se oprime "Iniciar" cuando la clase
 * empieza de verdad.
 *
 * Esa division es la misma que ya hay entre Gestion y el Panel del lado de las
 * promotorias, y por el mismo motivo: crear el catalogo y dar la clase son
 * trabajos de dos personas distintas, aunque a veces sean la misma.
 */
class PanelActividadController extends Controller
{
    /** Las que puede ver quien mira: direccion todas, el responsable las suyas. */
    private function visiblesPara(Perfil $perfil): Builder
    {
        return Actividad::query()
            ->when(
                ! in_array($perfil->rol, ['director', 'administrador'], true),
                fn (Builder $q) => $q->where('responsable_id', $perfil->id)
            );
    }

    public function index(Request $request): View
    {
        /** @var Perfil $perfil */
        $perfil = $request->attributes->get('perfil');

        return view('panel.actividades', [
            'actividades' => $this->visiblesPara($perfil)
                ->with(['responsable'])
                ->withCount(['inscritos', 'sesiones'])
                ->orderBy('nombre')
                ->get(),
        ]);
    }

    /**
     * Cuantos inscritos por pagina en la ficha.
     *
     * La ficha es de consulta: se abre para ver como va la cosa, no para
     * recorrer a nadie de arriba abajo. Un grupo de proyeccion institucional
     * puede tener cientos y no hay cupo que lo ate. Pasar lista SI trae la
     * lista entera, y eso no es una excepcion olvidada: ahi hay que poder
     * marcar a todos de una vez, y una hoja partida en paginas se guardaria a
     * medias sin que nadie lo note.
     */
    public const INSCRITOS_POR_PAGINA = 50;

    public function ver(Request $request, Actividad $actividad): View
    {
        /** @var Perfil $perfil */
        $perfil = $request->attributes->get('perfil');

        // Esconder una actividad de la lista no cierra su URL.
        abort_unless(Permisos::puedeVerActividad($perfil, $actividad), 404);

        return view('panel.actividad', [
            'actividad' => $actividad,
            // `withCount` y no recorrer la relacion: la tabla pinta una fila
            // por sesion, y preguntar la asistencia dentro del bucle costaria
            // una consulta por fila.
            'sesiones' => $actividad->sesiones()->with('iniciadaPor')->withCount('asistencias')->get(),
            // `with('perfil')` y no dejarlo a la plantilla: la tabla marca
            // «Estudiante de la institucion» fila a fila, y preguntarlo dentro
            // del bucle era una consulta por inscrito.
            //
            // Desempate por id: dos personas con el mismo nombre --que aqui es
            // corriente, porque se apuntan por un enlace y nadie normaliza--
            // quedarian en un orden que el motor no esta obligado a repetir, y
            // al paginar eso reparte filas repetidas o perdidas.
            'inscritos' => $actividad->inscritos()
                ->with('perfil')
                ->orderBy('nombre_completo')
                ->orderBy('id')
                ->paginate(self::INSCRITOS_POR_PAGINA),
            // El total va aparte: `$inscritos->count()` sobre un paginador son
            // los de la pagina, y de esta cifra cuelga si el enlace admite mas
            // gente. Con la de la pagina, una actividad de 200 inscritos se
            // leeria como que tiene 50 y el enlace seguiria abierto.
            'apuntados' => $actividad->inscritos()->count(),
            // Quien mira puede no ser quien dirige: direccion ve esta pantalla
            // en solo lectura. La plantilla necesita saberlo para no pintar un
            // boton que al pulsarlo rebota.
            'dirige' => Permisos::dirigeLaActividad($perfil, $actividad),
        ]);
    }

    /**
     * Oprime "Iniciar" en una sesion que ya existe: la de un curso o un taller.
     *
     * Lo que queda guardado es la hora REAL en que se oprimio, no la fecha
     * prevista. Son dos datos distintos y por eso hay dos columnas: la fecha
     * dice cuando tocaba y `iniciada_en`, cuando paso.
     */
    public function iniciar(Request $request, SesionActividad $sesion): RedirectResponse
    {
        /** @var Perfil $perfil */
        $perfil = $request->attributes->get('perfil');
        $actividad = $sesion->actividad;

        abort_unless(Permisos::puedeVerActividad($perfil, $actividad), 404);

        if (! Permisos::dirigeLaActividad($perfil, $actividad)) {
            return $this->volver($actividad, 'Solo quien dirige la actividad puede iniciar sus sesiones.');
        }

        // Ya iniciada: no se dice nada y no se toca la hora. Volver a oprimir
        // por si acaso es lo que hace cualquiera, y reescribir la hora borraria
        // la de verdad.
        if ($sesion->yaEmpezo()) {
            return $this->volver($actividad, '');
        }

        $sesion->iniciada_en = now();
        $sesion->iniciada_por_id = $perfil->id;
        $sesion->save();

        return $this->volver(
            $actividad,
            "Empezó {$actividad->etiquetaSesionConArticulo()}. Ya puedes pasar lista.",
            exito: true
        );
    }

    /**
     * El boton de un grupo de proyeccion, que no tiene fechas puestas.
     *
     * Aqui la sesion NACE al oprimir, como `Clase` del lado de las promotorias.
     * Se busca la de hoy antes de crearla: dos toques seguidos —o dos personas
     * mirando la misma pantalla— tienen que dar un ensayo, no dos. Ademas el
     * unico de la base solo admite uno por dia, asi que crear a ciegas fallaria
     * con un error del motor.
     */
    public function iniciarHoy(Request $request, Actividad $actividad): RedirectResponse
    {
        /** @var Perfil $perfil */
        $perfil = $request->attributes->get('perfil');

        abort_unless(Permisos::puedeVerActividad($perfil, $actividad), 404);

        if (! Permisos::dirigeLaActividad($perfil, $actividad)) {
            return $this->volver($actividad, 'Solo quien dirige la actividad puede iniciar sus sesiones.');
        }

        $hoy = Carbon::today()->toDateString();
        $sesion = $actividad->sesiones()->firstOrCreate(['fecha' => $hoy]);

        if ($sesion->yaEmpezo()) {
            return $this->volver($actividad, '');
        }

        $sesion->iniciada_en = now();
        $sesion->iniciada_por_id = $perfil->id;
        $sesion->save();

        return $this->volver(
            $actividad,
            "Empezó {$actividad->etiquetaSesionConArticulo()} de hoy. Ya puedes pasar lista.",
            exito: true
        );
    }

    // -----------------------------------------------------------------------
    // Pasar lista
    // -----------------------------------------------------------------------

    public function lista(Request $request, SesionActividad $sesion): View|RedirectResponse
    {
        /** @var Perfil $perfil */
        $perfil = $request->attributes->get('perfil');
        $actividad = $sesion->actividad;

        abort_unless(Permisos::puedeVerActividad($perfil, $actividad), 404);

        // No hay lista que pasar de algo que no ha empezado. El boton de la
        // pantalla anterior ya lo tiene en cuenta; esto cierra la URL.
        if (! $sesion->yaEmpezo()) {
            return $this->volver(
                $actividad,
                'Esa sesión todavía no ha empezado. Inicia primero y luego pasa lista.'
            );
        }

        $marcado = $sesion->asistencias()->pluck('estado', 'inscrito_id')->all();

        return view('panel.actividad-lista', [
            'actividad' => $actividad,
            'sesion' => $sesion,
            'estados' => AsistenciaActividad::ESTADOS,
            'inscritos' => $actividad->inscritos()
                ->orderBy('nombre_completo')
                ->get()
                ->map(fn (InscritoActividad $i) => [
                    'inscrito' => $i,
                    'estado' => $marcado[$i->id] ?? null,
                ]),
            'dirige' => Permisos::dirigeLaActividad($perfil, $actividad),
        ]);
    }

    public function guardarLista(Request $request, SesionActividad $sesion): RedirectResponse
    {
        /** @var Perfil $perfil */
        $perfil = $request->attributes->get('perfil');
        $actividad = $sesion->actividad;

        abort_unless(Permisos::puedeVerActividad($perfil, $actividad), 404);

        // Se comprueba aqui y no solo escondiendo los controles: la peticion
        // llega igual si alguien la envia a mano.
        if (! Permisos::dirigeLaActividad($perfil, $actividad)) {
            return $this->volverALista($sesion, 'Solo quien dirige la actividad puede pasar lista.');
        }

        if (! $sesion->yaEmpezo()) {
            return $this->volver($actividad, 'Esa sesión todavía no ha empezado.');
        }

        $inscritos = $actividad->inscritos()->pluck('id');

        $marcados = PaseDeLista::guardar(
            request: $request,
            asistencias: AsistenciaActividad::class,
            sesion: ['sesion_id' => $sesion->id],
            quien: 'inscrito_id',
            ids: $inscritos,
        );

        $sinMarcar = $inscritos->count() - $marcados;

        return $this->volverALista(
            $sesion,
            $sinMarcar
                ? "Lista guardada. Quedaron {$sinMarcar} sin marcar: puedes volver y completarlos."
                : 'Lista guardada.',
            exito: true
        );
    }

    /**
     * Anade a quien llego sin haberse inscrito por el enlace.
     *
     * Solo el nombre: nadie le va a pedir el documento con la clase empezando,
     * y exigirselo seria dejarlo fuera de la lista por un tramite. Queda como
     * inscrito de la actividad —no solo de esta sesion— porque eso es lo que
     * ha pasado: se sumo al curso.
     *
     * NO respeta el cupo, a proposito. El cupo gobierna el ENLACE, que es el
     * que hay que cerrar cuando ya no caben mas; a quien esta de pie en el
     * salon no lo echa un numero.
     */
    public function anadirEnSesion(Request $request, SesionActividad $sesion): RedirectResponse
    {
        /** @var Perfil $perfil */
        $perfil = $request->attributes->get('perfil');
        $actividad = $sesion->actividad;

        abort_unless(Permisos::puedeVerActividad($perfil, $actividad), 404);

        if (! Permisos::dirigeLaActividad($perfil, $actividad)) {
            return $this->volverALista($sesion, 'Solo quien dirige la actividad puede pasar lista.');
        }

        $datos = $request->validate(
            ['nombre_completo' => ['required', 'string', 'max:90']],
            [],
            ['nombre_completo' => 'nombre']
        );

        // Un nombre que ya esta en la lista casi siempre es la misma persona
        // apuntada dos veces: el boton se pulsa con la clase empezando y no hay
        // un documento con el que distinguirlas. NO se bloquea —dos hermanos
        // pueden llamarse casi igual, y quien esta delante sabe mejor que el
        // sistema quien hay en el salon—, pero se dice, que es lo que evita la
        // fila duplicada sin quitarle la decision a nadie.
        $repetido = $actividad->inscritos()
            ->where('nombre_completo', $datos['nombre_completo'])
            ->exists();

        if ($repetido) {
            return $this->volverALista(
                $sesion,
                "Ya hay alguien con el nombre «{$datos['nombre_completo']}» en la lista. "
                .'Si son dos personas distintas, escribe el nombre completo de cada una.'
            );
        }

        DB::transaction(function () use ($actividad, $sesion, $datos) {
            $inscrito = $actividad->inscritos()->create([
                'nombre_completo' => $datos['nombre_completo'],
                'origen' => InscritoActividad::EN_SESION,
            ]);

            // Se marca como asistio de una vez: se le anade PORQUE esta aqui, y
            // dejarlo sin marcar obligaria a buscarlo en la lista para decir lo
            // que ya se sabe.
            AsistenciaActividad::create([
                'sesion_id' => $sesion->id,
                'inscrito_id' => $inscrito->id,
                'estado' => AsistenciaActividad::ASISTIO,
            ]);
        });

        return $this->volverALista(
            $sesion,
            "{$datos['nombre_completo']} queda en la lista y marcado como asistió.",
            exito: true
        );
    }

    private function volverALista(SesionActividad $sesion, string $mensaje, bool $exito = false): RedirectResponse
    {
        return redirect()
            ->route('panel-actividad-lista', $sesion)
            ->with($exito ? 'success' : 'error', $mensaje);
    }

    private function volver(Actividad $actividad, string $mensaje, bool $exito = false): RedirectResponse
    {
        $destino = redirect()->route('panel-actividad', $actividad);

        return $mensaje === ''
            ? $destino
            : $destino->with($exito ? 'success' : 'error', $mensaje);
    }
}
