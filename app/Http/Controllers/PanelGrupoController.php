<?php

namespace App\Http\Controllers;

use App\Models\Grupo;
use App\Models\Perfil;
use App\Models\Promotoria;
use App\Support\ErrorDeBaseDeDatos;
use App\Support\Permisos;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Alta, edicion y baja de los grupos de una promotoria.
 *
 * Un grupo es un horario concreto. Lo crea quien dicta segun su disponibilidad,
 * y solo despues reparte ahi a los que ya se matricularon: el estudiante nunca
 * elige horario.
 */
class PanelGrupoController extends Controller
{
    public function crear(Request $request, Promotoria $promotoria): View|RedirectResponse
    {
        /** @var Perfil $perfil */
        $perfil = $request->attributes->get('perfil');

        if (! Permisos::puedeGestionarPromotoria($perfil, $promotoria)) {
            return $this->alPanel('No tienes acceso a esta promotoría.');
        }

        return view('panel.grupo-form', [
            'titulo' => "Nuevo grupo — {$promotoria->nombre}",
            'promotoria' => $promotoria,
            'grupo' => new Grupo(),
            'accion' => route('panel-grupo-nuevo', $promotoria),
        ]);
    }

    public function guardar(Request $request, Promotoria $promotoria): RedirectResponse
    {
        /** @var Perfil $perfil */
        $perfil = $request->attributes->get('perfil');

        if (! Permisos::puedeGestionarPromotoria($perfil, $promotoria)) {
            return $this->alPanel('No tienes acceso a esta promotoría.');
        }

        $datos = $this->validar($request, $promotoria);

        $grupo = new Grupo($datos);
        $grupo->promotoria_id = $promotoria->id;
        $grupo->save();

        return $this->alPanel('Grupo creado.', exito: true);
    }

    public function editar(Request $request, Grupo $grupo): View|RedirectResponse
    {
        /** @var Perfil $perfil */
        $perfil = $request->attributes->get('perfil');

        if (! Permisos::puedeGestionarPromotoria($perfil, $grupo->promotoria)) {
            return $this->alPanel('No tienes acceso a esta promotoría.');
        }

        return view('panel.grupo-form', [
            'titulo' => "Editar grupo — {$grupo}",
            'promotoria' => $grupo->promotoria,
            'grupo' => $grupo,
            'accion' => route('panel-grupo-editar', $grupo),
        ]);
    }

    public function actualizar(Request $request, Grupo $grupo): RedirectResponse
    {
        /** @var Perfil $perfil */
        $perfil = $request->attributes->get('perfil');

        if (! Permisos::puedeGestionarPromotoria($perfil, $grupo->promotoria)) {
            return $this->alPanel('No tienes acceso a esta promotoría.');
        }

        $grupo->fill($this->validar($request, $grupo->promotoria, $grupo));
        $grupo->save();

        return $this->alPanel('Grupo actualizado.', exito: true);
    }

    /**
     * Elimina el grupo, si es que no queda nadie apuntado a el.
     *
     * La matricula apunta al grupo con RESTRICT, no con CASCADE: borrar el
     * horario no puede llevarse por delante la inscripcion de nadie. Quien
     * quiera deshacerlo tiene que sacar antes a los estudiantes, que es una
     * decision que se toma persona a persona.
     */
    public function eliminar(Request $request, Grupo $grupo): RedirectResponse
    {
        /** @var Perfil $perfil */
        $perfil = $request->attributes->get('perfil');

        if (! Permisos::puedeGestionarPromotoria($perfil, $grupo->promotoria)) {
            return $this->alPanel('No tienes acceso a esta promotoría.');
        }

        try {
            $grupo->delete();
        } catch (QueryException $e) {
            if (! ErrorDeBaseDeDatos::esFilaEnUso($e)) {
                throw $e;
            }

            return $this->alPanel(
                'No se puede eliminar: hay estudiantes con matrícula asignada a este grupo.'
            );
        }

        return $this->alPanel('Grupo eliminado.', exito: true);
    }

    /**
     * @return array<string, mixed>
     */
    private function validar(Request $request, Promotoria $promotoria, ?Grupo $grupo = null): array
    {
        return $request->validate([
            'nivel' => [
                'required',
                Rule::in(array_keys(Grupo::NIVELES)),
                // Un nivel por promotoria: no hay dos "Básico" de Violín. Se
                // comprueba aqui ademas de en el indice unico para que el
                // mensaje llegue al campo y no como un error del motor.
                Rule::unique('grupos', 'nivel')
                    ->where('promotoria_id', $promotoria->id)
                    ->ignore($grupo?->id),
            ],
            'horario' => ['required', 'string', 'max:60'],
            'salon' => ['required', 'string', 'max:40'],
            'cupo_maximo' => ['required', 'integer', 'min:0'],
        ], [
            'nivel.unique' => "{$promotoria->nombre} ya tiene un grupo de ese nivel. "
                . 'Edita el que existe o elige otro nivel.',
        ], [
            'nivel' => 'nivel',
            'horario' => 'horario',
        ]);
    }

    private function alPanel(string $mensaje, bool $exito = false): RedirectResponse
    {
        $respuesta = redirect()->route('panel');

        if ($mensaje !== '') {
            $respuesta->with($exito ? 'success' : 'error', $mensaje);
        }

        return $respuesta;
    }
}
