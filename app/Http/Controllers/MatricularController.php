<?php

namespace App\Http\Controllers;

use App\Models\ConfiguracionInstitucion;
use App\Models\Matricula;
use App\Models\Perfil;
use App\Models\Periodo;
use App\Models\Promotoria;
use App\Support\ErrorDeBaseDeDatos;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * El boton "Matricularme" del catalogo.
 */
class MatricularController extends Controller
{
    public function __invoke(Request $request, Promotoria $promotoria): RedirectResponse
    {
        /** @var Perfil $perfil */
        $perfil = $request->attributes->get('perfil');
        $periodo = Periodo::enCurso();

        if ($periodo === null) {
            return $this->volver('No hay un periodo de matrícula activo en este momento.');
        }

        if (! $periodo->matriculas_abiertas) {
            $institucion = ConfiguracionInstitucion::actual()->nombre_institucion;

            return $this->volver(
                "Las matrículas de {$periodo} están cerradas. Espera a que {$institucion} las abra de nuevo."
            );
        }

        $datosEstudiante = $perfil->datosEstudiante;

        if ($datosEstudiante === null) {
            return $this->volver(
                'Tu registro como estudiante no está completo (falta documento de identidad). '
                . 'Contacta al administrador.'
            );
        }

        if ($perfil->es_menor && $datosEstudiante->acudiente_id === null) {
            return $this->volver(
                'Eres menor de edad y no tienes un acudiente registrado. '
                . 'Pide al administrador que registre tu acudiente antes de matricularte.'
            );
        }

        // Si ya se retiro de esta promotoria en este periodo se REACTIVA su
        // matricula en vez de crear otra: `unica_matricula_por_periodo` no
        // admite una segunda fila para el mismo (estudiante, promotoria,
        // periodo), y sin esto el boton "Matricularme" de esa fila no llevaria a
        // ninguna parte. La fecha original se conserva; el estado vuelve a
        // pendiente y hay que confirmarla de nuevo.
        $matricula = Matricula::query()
            ->where('estudiante_id', $perfil->id)
            ->where('promotoria_id', $promotoria->id)
            ->where('periodo_id', $periodo->id)
            ->where('estado', Matricula::RETIRADA)
            ->first();

        $reactivada = $matricula !== null;

        if ($reactivada) {
            $matricula->estado = Matricula::PENDIENTE;
            $matricula->grupo_id = null;
        } else {
            $matricula = new Matricula([
                'estudiante_id' => $perfil->id,
                'promotoria_id' => $promotoria->id,
                'periodo_id' => $periodo->id,
            ]);
        }

        try {
            DB::transaction(function () use ($matricula) {
                $matricula->validar();
                $matricula->save();
            });
        } catch (ValidationException $e) {
            return $this->volver(implode(' ', $e->validator->errors()->all()));
        } catch (QueryException $e) {
            return $this->volver($this->mensajeDeConflicto($e, $promotoria, $periodo));
        }

        return $this->volver(
            $reactivada
                ? "Volviste a inscribirte en {$promotoria}. Tu matrícula quedó otra vez "
                    . 'pendiente de confirmación del profesor.'
                : "Tu inscripción a {$promotoria} quedó pendiente de confirmación del profesor.",
            exito: true
        );
    }

    /**
     * Traduce el rechazo de la base de datos al mensaje que corresponde.
     *
     * Aqui solo se llega en una CARRERA real: la validacion del modelo ya
     * comprobo todo esto, asi que si el motor rechaza la escritura es porque
     * entre la comprobacion y el guardado entro otra peticion.
     */
    private function mensajeDeConflicto(QueryException $e, Promotoria $promotoria, Periodo $periodo): string
    {
        if (ErrorDeBaseDeDatos::esCupoAgotado($e)) {
            return "{$promotoria} se llenó mientras enviabas la solicitud: alguien tomó el "
                . "último cupo de {$periodo}. No quedó registrada.";
        }

        // Matricula repetida en la misma promotoria, o el indice que limita las
        // promotorias por periodo si dos peticiones llegaron a la vez.
        $limite = Matricula::limitePromotorias();

        return 'No se pudo registrar la matrícula: o ya tienes una en esa promotoría este '
            . "periodo, o ya ocupas las {$limite} promotorías permitidas. "
            . 'Revisa tus matrículas y vuelve a intentarlo.';
    }

    private function volver(string $mensaje, bool $exito = false): RedirectResponse
    {
        return redirect()
            ->route('promotorias-disponibles')
            ->with($exito ? 'success' : 'error', $mensaje);
    }
}
