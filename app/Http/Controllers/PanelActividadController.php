<?php

namespace App\Http\Controllers;

use App\Models\Actividad;
use App\Models\Perfil;
use App\Models\SesionActividad;
use App\Support\Permisos;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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

    public function ver(Request $request, Actividad $actividad): View
    {
        /** @var Perfil $perfil */
        $perfil = $request->attributes->get('perfil');

        // Esconder una actividad de la lista no cierra su URL.
        abort_unless(Permisos::puedeVerActividad($perfil, $actividad), 404);

        return view('panel.actividad', [
            'actividad' => $actividad,
            'sesiones' => $actividad->sesiones()->with('iniciadaPor')->get(),
            'inscritos' => $actividad->inscritos()->orderBy('nombre_completo')->get(),
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

    private function volver(Actividad $actividad, string $mensaje, bool $exito = false): RedirectResponse
    {
        $destino = redirect()->route('panel-actividad', $actividad);

        return $mensaje === ''
            ? $destino
            : $destino->with($exito ? 'success' : 'error', $mensaje);
    }
}
