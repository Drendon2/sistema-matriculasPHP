<?php

namespace App\Http\Controllers\Gestion;

use App\Http\Controllers\Controller;
use App\Models\Matricula;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Cancelaciones pedidas por estudiantes y todavia sin resolver.
 *
 * Solo direccion: quien dicta las ve marcadas en su panel, pero no decide.
 * Aprobar retira la matricula de verdad y libera el cupo; rechazar la devuelve a
 * activa, y eso ultimo solo cabe con menores de edad (ver
 * `Matricula::$cancelacion_es_rechazable`).
 */
class CancelacionesController extends Controller
{
    public function index(): View
    {
        return view('gestion.cancelaciones', [
            'pendientes' => Matricula::query()
                ->where('estado', Matricula::CANCELACION_SOLICITADA)
                ->with([
                    'estudiante.datosEstudiante.acudiente',
                    'promotoria.area',
                    'periodo',
                    'grupo',
                ])
                ->join('periodos', 'periodos.id', '=', 'matriculas.periodo_id')
                ->join('promotorias', 'promotorias.id', '=', 'matriculas.promotoria_id')
                ->orderBy('periodos.fecha_inicio')
                ->orderBy('promotorias.nombre')
                ->select('matriculas.*')
                ->get(),
        ]);
    }

    public function resolver(Matricula $matricula, string $decision): RedirectResponse
    {
        abort_unless($matricula->estado === Matricula::CANCELACION_SOLICITADA, 404);
        abort_unless(in_array($decision, ['aprobar', 'rechazar'], true), 404);

        $nombre = $matricula->estudiante->nombre_completo;

        if ($decision === 'rechazar') {
            // La comprobacion va aqui y no solo en la plantilla: ocultar el
            // boton no impide que alguien envie el formulario a mano.
            if (! $matricula->cancelacion_es_rechazable) {
                return $this->volver(
                    "{$nombre} es mayor de edad, así que su decisión de salir no se rechaza: "
                    . 'esta cancelación solo se puede aprobar.'
                );
            }

            $matricula->estado = Matricula::ACTIVA;
            $matricula->save();

            return $this->volver(
                "Cancelación rechazada: {$nombre} sigue matriculado en {$matricula->promotoria}.",
                exito: true
            );
        }

        $matricula->estado = Matricula::RETIRADA;
        $matricula->grupo_id = null;
        $matricula->save();

        return $this->volver(
            "{$nombre} quedó retirado de {$matricula->promotoria}. Su cupo vuelve a estar libre.",
            exito: true
        );
    }

    /**
     * Resuelve varias cancelaciones de una vez.
     *
     * Al cerrar un periodo la cola se llena, y casi todas se resuelven igual.
     *
     * NO es todo o nada: cada cancelacion es independiente y la unica que puede
     * fallar lo hace por un motivo que se nombra —es de un mayor de edad y a un
     * mayor no se le rechaza la salida—. Se resuelven las que se puede y se dice
     * quien quedo fuera.
     *
     * Lo que NO cambia respecto de resolver de a una: rechazar sigue siendo solo
     * para menores. La pausa existe para hablar con el acudiente antes de que un
     * nino se salga por su cuenta; irse siendo mayor es decision propia y el
     * sistema no se la discute. Comprobarlo aqui tambien es obligatorio, porque
     * esconder el boton no impide componer el envio a mano.
     */
    public function resolverLote(Request $request): RedirectResponse
    {
        $decision = $request->input('decision');

        abort_unless(in_array($decision, ['aprobar', 'rechazar'], true), 404);

        $matriculas = Matricula::query()
            ->whereIn('id', (array) $request->input('matricula_ids', []))
            ->where('estado', Matricula::CANCELACION_SOLICITADA)
            ->with(['estudiante.datosEstudiante', 'promotoria'])
            ->get();

        if ($matriculas->isEmpty()) {
            return $this->volver('No marcaste ninguna cancelación.');
        }

        $resueltas = 0;
        $mayores = [];

        DB::transaction(function () use ($matriculas, $decision, &$resueltas, &$mayores) {
            foreach ($matriculas as $matricula) {
                if ($decision === 'rechazar' && ! $matricula->cancelacion_es_rechazable) {
                    $mayores[] = $matricula->estudiante->nombre_completo;

                    continue;
                }

                if ($decision === 'aprobar') {
                    $matricula->estado = Matricula::RETIRADA;
                    $matricula->grupo_id = null;
                } else {
                    $matricula->estado = Matricula::ACTIVA;
                }

                $matricula->save();
                $resueltas++;
            }
        });

        if ($decision === 'aprobar') {
            return $this->volver(
                $resueltas === 1
                    ? '1 retiro aprobado. Su cupo vuelve a estar libre.'
                    : "{$resueltas} retiros aprobados. Sus cupos vuelven a estar libres.",
                exito: true
            );
        }

        $hechas = $resueltas === 1
            ? '1 cancelación rechazada: sigue matriculado.'
            : "{$resueltas} cancelaciones rechazadas: siguen matriculados.";

        if ($mayores === []) {
            return $this->volver($hechas, exito: true);
        }

        $quienes = implode(', ', $mayores);
        $cola = count($mayores) === 1
            ? "{$quienes} es mayor de edad, así que su salida no se rechaza: solo se puede aprobar."
            : "{$quienes} son mayores de edad, así que su salida no se rechaza: solo se puede aprobar.";

        return $this->volver(
            $resueltas === 0 ? "No se rechazó ninguna. {$cola}" : "{$hechas} {$cola}"
        );
    }

    private function volver(string $mensaje, bool $exito = false): RedirectResponse
    {
        return redirect()->route('gestion-cancelaciones')->with($exito ? 'success' : 'error', $mensaje);
    }
}
