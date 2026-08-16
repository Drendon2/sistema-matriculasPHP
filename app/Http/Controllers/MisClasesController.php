<?php

namespace App\Http\Controllers;

use App\Models\Clase;
use App\Models\ConfirmacionClase;
use App\Models\Perfil;
use App\Models\Periodo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * Las clases de sus grupos, para que el estudiante confirme que se dieron.
 *
 * Es la otra mitad del boton del profesor: quien registra la clase es parte
 * interesada, asi que hasta que suficientes estudiantes den fe, la clase queda
 * registrada pero sin verificar.
 */
class MisClasesController extends Controller
{
    public function index(Request $request): View
    {
        /** @var Perfil $perfil */
        $perfil = $request->attributes->get('perfil');
        $periodo = Periodo::enCurso();
        $filas = Clase::porConfirmar($perfil, $periodo);

        return view('estudiante.mis-clases', [
            'periodo' => $periodo,
            'filas' => $filas,
            'porConfirmar' => count(array_filter(
                $filas,
                fn (array $f) => $f['abierta'] && ! $f['confirmada_por_mi']
            )),
            'horasPlazo' => Clase::VENTANA_CONFIRMACION_HORAS,
        ]);
    }

    public function confirmar(Request $request, Clase $clase): RedirectResponse
    {
        /** @var Perfil $perfil */
        $perfil = $request->attributes->get('perfil');
        $fila = $this->filaDeClase($perfil, $clase->id);

        if ($fila === null) {
            return $this->volver(
                'Esa clase no es de ninguno de tus grupos, o es anterior a tu matrícula, '
                . 'así que no la puedes confirmar.'
            );
        }

        // El plazo se comprueba aqui y no solo escondiendo el boton: una
        // peticion enviada desde una pestana que quedo abierta antes de que
        // venciera llegaria igual, y a destiempo.
        if (! $fila['abierta']) {
            return $this->volver(
                'El plazo para confirmar esa clase ya venció: solo se puede hasta '
                . Clase::VENTANA_CONFIRMACION_HORAS . ' horas después, y esa terminó el '
                . $this->textoPlazo($fila['limite']) . '.'
            );
        }

        // firstOrCreate y no create: dos pulsaciones seguidas del mismo boton no
        // pueden acabar en un error de integridad contra el indice unico.
        $confirmacion = ConfirmacionClase::firstOrCreate([
            'clase_id' => $fila['clase']->id,
            'matricula_id' => $fila['matricula']->id,
        ]);

        if (! $confirmacion->wasRecentlyCreated) {
            return $this->volver('');
        }

        return $this->volver(
            "Confirmaste la clase de {$fila['clase']->grupo->promotoria->nombre} del "
            . $fila['clase']->fecha_hora->format('d/m/Y') . '.',
            exito: true
        );
    }

    /**
     * Deshace la confirmacion propia: se equivoco de renglon.
     *
     * Rige el mismo plazo que para confirmar. Si retirar siguiera abierto
     * despues, una clase ya verificada podria dejar de estarlo semanas mas
     * tarde, cuando el registro ya se dio por cerrado.
     */
    public function retirar(Request $request, Clase $clase): RedirectResponse
    {
        /** @var Perfil $perfil */
        $perfil = $request->attributes->get('perfil');
        $fila = $this->filaDeClase($perfil, $clase->id);

        if ($fila === null) {
            return $this->volver('');
        }

        if (! $fila['abierta']) {
            return $this->volver(
                'Ya no puedes cambiar esa clase: el plazo terminó el '
                . $this->textoPlazo($fila['limite']) . '.'
            );
        }

        $borradas = ConfirmacionClase::where('clase_id', $fila['clase']->id)
            ->where('matricula_id', $fila['matricula']->id)
            ->delete();

        return $borradas
            ? $this->volver('Quitaste tu confirmación de esa clase.', exito: true)
            : $this->volver('');
    }

    /**
     * La clase dentro de lo que este estudiante puede confirmar, o null.
     *
     * Pasa por `Clase::porConfirmar` a proposito, en vez de comprobar la
     * matricula por su cuenta: asi el boton y el permiso salen de la MISMA regla
     * —solo los grupos donde esta inscrito, y solo las clases posteriores a su
     * matricula— y no pueden separarse el dia que la regla cambie.
     *
     * @return array<string, mixed>|null
     */
    private function filaDeClase(Perfil $perfil, int $claseId): ?array
    {
        foreach (Clase::porConfirmar($perfil, Periodo::enCurso()) as $fila) {
            if ($fila['clase']->id === $claseId) {
                return $fila;
            }
        }

        return null;
    }

    /** El limite del plazo, para un mensaje ("14/08/2026 a las 21:15"). */
    private function textoPlazo(Carbon $limite): string
    {
        return $limite->format('d/m/Y \a \l\a\s H:i');
    }

    private function volver(string $mensaje, bool $exito = false): RedirectResponse
    {
        $respuesta = redirect()->route('mis-clases');

        if ($mensaje !== '') {
            $respuesta->with($exito ? 'success' : 'error', $mensaje);
        }

        return $respuesta;
    }
}
