<?php

namespace App\Http\Controllers;

use App\Models\DocumentoRequerido;
use App\Models\Matricula;
use App\Models\Perfil;
use App\Models\Promotoria;
use App\Support\Permisos;
use App\Support\ResumenAsistencia;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Las fichas de una persona: el destino de su nombre en cualquier listado.
 *
 * Son tres pantallas con tres alcances distintos, y la diferencia no es
 * decorativa: la matriz de visibilidad del proyecto separa identidad, contacto y
 * datos sensibles, y cada una de estas vistas cubre exactamente un escalon.
 */
class FichaController extends Controller
{
    /**
     * Ficha de una persona, sea cual sea su rol.
     *
     * Reune identidad y contacto, resume lo que corresponde segun el rol
     * —promotorias que dicta un profesor; trayectoria de un estudiante— y enlaza
     * a la trayectoria completa y, para el administrador, a la ficha con
     * encuesta y documento.
     *
     * Quien ENTRA lo decide `Permisos::puedeVerFicha`. QUE se muestra sigue la
     * matriz de visibilidad: edad, telefono y acudiente de un estudiante son
     * para direccion, y para el profesor SOLO si ese estudiante cursa alguna de
     * sus promotorias. Que un profesor pueda abrir la ficha no le da acceso a
     * los datos de contacto de cualquiera.
     */
    public function usuario(Request $request, Perfil $usuario): View|RedirectResponse
    {
        /** @var Perfil $perfil */
        $perfil = $request->attributes->get('perfil');

        if (! Permisos::puedeVerFicha($perfil, $usuario)) {
            return redirect()->route('panel')->with(
                'error',
                "No tienes acceso a la ficha de {$usuario->nombre_completo}: "
                . 'un profesor solo puede consultar la de sus estudiantes.'
            );
        }

        $esEstudiante = $usuario->rol === 'estudiante';

        // El contacto es el dato acotado, no la ficha entera.
        $veContacto = in_array($perfil->rol, ['administrador', 'director'], true)
            || ($perfil->rol === 'profesor' && $esEstudiante && $this->loTieneEnClase($perfil, $usuario));

        $datos = $esEstudiante ? $usuario->datosEstudiante : null;

        // Solo los obligatorios: un papel opcional que falta no deja la
        // matricula incompleta. La etiqueta la ve todo el personal —es una
        // gestion pendiente, no un dato sensible—, pero los ARCHIVOS siguen
        // siendo del administrador.
        $papelesPendientes = $datos
            ? array_values(array_filter(
                $datos->documentosFaltantes(),
                fn (DocumentoRequerido $d) => $d->obligatorio
            ))
            : [];

        // El periodo del panel de asistencia lo eligen las flechas. Va como
        // parametro de consulta y no en el camino porque lo que se mueve es una
        // seccion: el contacto, la trayectoria y los papeles de esta ficha son
        // los mismos en cualquier periodo.
        $navegacion = ResumenAsistencia::navegacionDePeriodos(
            $usuario,
            $request->query('periodo'),
            $esEstudiante
        );
        $periodo = $navegacion['periodo'];

        // El panel de asistencia sigue la misma matriz que el resto de la ficha:
        // un profesor ve lo de SUS promotorias y no la asistencia del estudiante
        // en otras disciplinas. Direccion lo ve completo.
        if ($esEstudiante) {
            $acotarA = $perfil->rol === 'profesor'
                ? Promotoria::where('profesor_id', $perfil->id)->pluck('id')->all()
                : null;

            $asistencia = ResumenAsistencia::deEstudiante($usuario, $periodo, $acotarA);
        } else {
            $asistencia = ResumenAsistencia::deProfesor($usuario, $periodo);
        }

        return view('panel.detalle-usuario', [
            'objetivo' => $usuario,
            'esEstudiante' => $esEstudiante,
            'veContacto' => $veContacto,
            'papelesPendientes' => $papelesPendientes,
            'asistencia' => $asistencia,
            'periodo' => $periodo,
            'periodoAtras' => $navegacion['atras']
                ? route('detalle-usuario', [$usuario, 'periodo' => $navegacion['atras']->id])
                : null,
            'periodoAdelante' => $navegacion['adelante']
                ? route('detalle-usuario', [$usuario, 'periodo' => $navegacion['adelante']->id])
                : null,
            'periodoEsElEnCurso' => $periodo !== null && $periodo->activo,
            'acudiente' => $datos && $veContacto ? $datos->acudiente : null,
            'resumen' => $esEstudiante ? Matricula::resumenTrayectoria($usuario) : null,
            // Las promotorias salen del VINCULO y no del rol: un director que
            // ademas dicta tiene que ver las suyas en su ficha. Para quien no
            // dicta nada la lista queda vacia sola, sin preguntarle el rol.
            'promotorias' => $esEstudiante
                ? collect()
                : Promotoria::where('profesor_id', $usuario->id)
                    ->with(['area', 'grupos'])
                    ->join('areas', 'areas.id', '=', 'promotorias.area_id')
                    ->orderBy('areas.nombre')
                    ->orderBy('promotorias.nombre')
                    ->select('promotorias.*')
                    ->get(),
            'puedeGestionarUsuarios' => in_array($perfil->rol, ['director', 'administrador'], true),
        ]);
    }

    /**
     * Trayectoria de un estudiante: en que promotorias ha estado y en cuales
     * sigue.
     *
     * La ve el personal completo, y muestra el historial ENTERO: todas las
     * promotorias del estudiante, no solo las de quien consulta.
     *
     * Eso ultimo es una excepcion deliberada al criterio acotado que sigue el
     * resto del sistema, y se decidio asi porque el dato es justamente el que
     * hace falta para ubicar a alguien en un nivel: saber que lleva tres
     * periodos en Danza le sirve al profesor de Teatro que lo recibe por primera
     * vez. No abre nada mas: la encuesta demografica y la copia del documento
     * siguen siendo solo del administrador.
     */
    public function historial(Perfil $usuario): View
    {
        abort_unless($usuario->rol === 'estudiante', 404);

        return view('panel.historial-estudiante', [
            'estudiante' => $usuario,
            'historial' => Matricula::historialPorPeriodo($usuario),
            'resumen' => Matricula::resumenTrayectoria($usuario),
        ]);
    }

    /**
     * Ficha completa de un estudiante: encuesta demografica y documento.
     *
     * Solo el administrador. La trayectoria por promotorias no se repite aqui:
     * vive en `historial`, que si ve todo el personal.
     */
    public function estudiante(Perfil $usuario): View
    {
        abort_unless($usuario->rol === 'estudiante', 404);

        return view('panel.detalle-estudiante', [
            'estudiante' => $usuario,
            'datos' => $usuario->datosEstudiante,
            'encuesta' => $usuario->encuesta,
        ]);
    }

    /** ¿El estudiante cursa alguna promotoria de este profesor, sin retirar? */
    private function loTieneEnClase(Perfil $profesor, Perfil $estudiante): bool
    {
        return Matricula::query()
            ->where('estudiante_id', $estudiante->id)
            ->where('estado', '!=', Matricula::RETIRADA)
            ->whereHas('promotoria', fn ($q) => $q->where('profesor_id', $profesor->id))
            ->exists();
    }
}
