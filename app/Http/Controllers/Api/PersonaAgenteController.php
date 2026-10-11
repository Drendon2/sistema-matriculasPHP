<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Asistencia;
use App\Models\Matricula;
use App\Models\Perfil;
use App\Models\Periodo;
use App\Models\SesionGrupo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Lo que el agente de voz dice de UNA persona, despues de verificarla.
 *
 * SOLO EN LA DEMO (decision del usuario, 10/10/2026): con
 * `agente.datos_personales` apagado, las tres rutas contestan 404.
 *
 * Lo que NUNCA sale de aqui: el documento, la copia del documento, la encuesta
 * demografica y cualquier dato de otra persona. La verificacion devuelve una
 * sesion y nada mas — ni siquiera el nombre, que confirmaria a quien llama de
 * quien es ese documento.
 */
class PersonaAgenteController extends Controller
{
    /** Intentos fallidos por documento antes de bloquearlo. */
    public const INTENTOS = 3;

    /** Cuanto dura el bloqueo, en segundos. */
    public const BLOQUEO = 900;

    /**
     * Documento + fecha de nacimiento.
     *
     * LA MISMA RESPUESTA para «ese documento no existe» y «la fecha no
     * cuadra»: distinguirlas convierte el telefono en una forma de averiguar
     * quien esta inscrito. Y el contador cuelga del DOCUMENTO, no del
     * llamante: el agente es un solo cliente, y lo que se protege es la
     * persona a la que alguien intenta suplantar.
     */
    public function verificar(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'documento' => ['required', 'string', 'max:30'],
            'fecha_nacimiento' => ['required', 'date_format:Y-m-d'],
        ]);

        $documento = preg_replace('/\D/', '', $datos['documento']);
        $clave = 'agente-verificacion:'.hash('sha256', $documento);

        if (RateLimiter::tooManyAttempts($clave, self::INTENTOS)) {
            return response()->json(['verificado' => false, 'bloqueado' => true], 429);
        }

        // Las dos cosas en la misma consulta: o cuadran juntas o no hay perfil.
        $perfil = Perfil::whereHas('datosEstudiante', fn ($q) => $q->where('documento_identidad', $documento))
            ->whereDate('fecha_nacimiento', $datos['fecha_nacimiento'])
            ->whereNull('suprimido_en')
            ->first();

        if ($perfil === null || ! $perfil->user->activo) {
            RateLimiter::hit($clave, self::BLOQUEO);

            return response()->json([
                'verificado' => false,
                'bloqueado' => RateLimiter::tooManyAttempts($clave, self::INTENTOS),
            ], 422);
        }

        RateLimiter::clear($clave);

        $sesion = Str::random(48);
        Cache::put($this->claveSesion($sesion), $perfil->id, now()->addMinutes((int) config('agente.sesion_minutos')));

        return response()->json([
            'verificado' => true,
            'sesion' => $sesion,
            'expira_en_minutos' => (int) config('agente.sesion_minutos'),
        ]);
    }

    /**
     * Sus matriculas del periodo en curso y si le cabe otra.
     *
     * `puede_inscribir_otra` lo calcula el servidor con la misma cuenta que
     * aplica la inscripcion (`Matricula::promotoriasOcupadas`), en vez de
     * dejar que el modelo cuente. Una cancelacion en tramite OCUPA su sitio
     * hasta que la direccion la resuelva, y por eso cuenta.
     */
    public function matriculas(Request $request): JsonResponse
    {
        $perfil = $this->perfilDeLaSesion($request);
        $periodo = Periodo::enCurso();

        if ($periodo === null) {
            return response()->json(['periodo' => null, 'matriculas' => []]);
        }

        $limite = Matricula::limitePromotorias();
        $ocupadas = Matricula::promotoriasOcupadas($perfil->id, $periodo->id);

        $matriculas = Matricula::with(['promotoria', 'periodo', 'grupos.sesiones'])
            ->where('estudiante_id', $perfil->id)
            ->where('periodo_id', $periodo->id)
            ->get()
            ->map(fn (Matricula $m) => [
                'promotoria_id' => $m->promotoria_id,
                'promotoria' => $m->promotoria->nombre,
                'estado' => $m->estado_visible,
                'estado_texto' => $m->estado_visible_display,
                'grupos' => $m->grupos->map(fn ($g) => [
                    'nombre' => $g->nombre,
                    'salon' => $g->salon,
                    'horario' => $g->sesiones->map(fn (SesionGrupo $s) => $s->paraLaApi())->values(),
                ])->values(),
            ]);

        return response()->json([
            'periodo' => $periodo->nombre,
            'limite_programas' => $limite,
            'programas_ocupados' => $ocupadas,
            'puede_inscribir_otra' => $ocupadas < $limite,
            'matriculas' => $matriculas,
        ]);
    }

    /**
     * Cuantas clases lleva en cada promotoria del periodo en curso.
     *
     * La racha son los «Asistió» seguidos contando desde la clase mas
     * reciente: una excusa la corta, igual que en las alertas.
     */
    public function asistencia(Request $request): JsonResponse
    {
        $perfil = $this->perfilDeLaSesion($request);
        $periodo = Periodo::enCurso();

        $request->validate(['promotoria_id' => ['nullable', 'integer']]);

        $matriculas = $periodo === null ? collect() : Matricula::with('promotoria')
            ->where('estudiante_id', $perfil->id)
            ->where('periodo_id', $periodo->id)
            ->when($request->filled('promotoria_id'), fn ($q) => $q->where('promotoria_id', (int) $request->query('promotoria_id')))
            ->get();

        $resumen = $matriculas->map(function (Matricula $m) {
            $estados = Asistencia::query()
                ->join('clases', 'clases.id', '=', 'asistencias.clase_id')
                ->where('asistencias.matricula_id', $m->id)
                ->orderByDesc('clases.fecha_hora')
                ->orderByDesc('clases.id')
                ->pluck('asistencias.estado');

            $racha = 0;
            foreach ($estados as $estado) {
                if ($estado !== Asistencia::ASISTIO) {
                    break;
                }
                $racha++;
            }

            return [
                'promotoria_id' => $m->promotoria_id,
                'promotoria' => $m->promotoria->nombre,
                'asistio' => $estados->filter(fn ($e) => $e === Asistencia::ASISTIO)->count(),
                'falto' => $estados->filter(fn ($e) => $e === Asistencia::FALTO)->count(),
                'falto_con_excusa' => $estados->filter(fn ($e) => $e === Asistencia::EXCUSA)->count(),
                'clases_con_lista' => $estados->count(),
                'racha_asistencias' => $racha,
            ];
        })->values();

        return response()->json([
            'periodo' => $periodo?->nombre,
            'asistencia' => $resumen,
        ]);
    }

    private function perfilDeLaSesion(Request $request): Perfil
    {
        $sesion = (string) $request->header('X-Sesion-Agente');
        $perfilId = $sesion === '' ? null : Cache::get($this->claveSesion($sesion));

        abort_if($perfilId === null, 401, 'sesion_invalida');

        /** @var Perfil */
        return Perfil::findOrFail($perfilId);
    }

    private function claveSesion(string $sesion): string
    {
        return 'agente-sesion:'.hash('sha256', $sesion);
    }
}
