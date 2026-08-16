<?php

namespace App\Http\Controllers\Gestion;

use App\Http\Controllers\Controller;
use App\Models\Matricula;
use Illuminate\Http\RedirectResponse;
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

    private function volver(string $mensaje, bool $exito = false): RedirectResponse
    {
        return redirect()->route('gestion-cancelaciones')->with($exito ? 'success' : 'error', $mensaje);
    }
}
