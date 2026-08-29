<?php

namespace App\Http\Controllers;

use App\Models\DocumentoRequerido;
use App\Models\Matricula;
use App\Models\Perfil;
use App\Models\Periodo;
use App\Models\Promotoria;
use App\Support\Permisos;
use App\Support\ResumenAsistencia;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
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
     *
     * La edad tiene ademas un limite propio, y no viaja con el resto del
     * contacto: la del PERSONAL no se muestra, ni siquiera al administrador.
     * El porque esta en la matriz de `Perfil`. Por eso la esconde la plantilla y
     * no `$veContacto`: son dos puertas distintas y juntarlas en una bandera
     * dejaria sin telefono a quien solo hay que dejar sin edad.
     */
    public function usuario(Request $request, Perfil $usuario): View|RedirectResponse
    {
        /** @var Perfil $perfil */
        $perfil = $request->attributes->get('perfil');

        if (! Permisos::puedeVerFicha($perfil, $usuario)) {
            return redirect()->route('panel')->with(
                'error',
                "No tienes acceso a la ficha de {$usuario->nombre_completo}: "
                .'un profesor solo puede consultar la de sus estudiantes.'
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
    public function historial(Request $request, Perfil $usuario): View
    {
        abort_unless($usuario->rol === 'estudiante', 404);

        /** @var Perfil $perfil */
        $perfil = $request->attributes->get('perfil');

        $puedeCorregir = in_array($perfil->rol, ['director', 'administrador'], true);
        $enCurso = Periodo::enCurso();

        return view('panel.historial-estudiante', [
            'estudiante' => $usuario,
            'historial' => Matricula::historialPorPeriodo($usuario),
            'resumen' => Matricula::resumenTrayectoria($usuario),
            'puedeCorregir' => $puedeCorregir,
            'periodoEnCursoId' => $enCurso?->id,
            // Solo hace falta la lista si va a pintarse el desplegable. Agrupada
            // por area para el <optgroup>, como el resto de los selectores de
            // promotoria del proyecto.
            'promotoriasParaCorregir' => $puedeCorregir
                ? Promotoria::with('area')
                    ->join('areas', 'areas.id', '=', 'promotorias.area_id')
                    ->orderBy('areas.nombre')
                    ->orderBy('promotorias.nombre')
                    ->select('promotorias.*')
                    ->get()
                : collect(),
        ]);
    }

    /**
     * Corrige la promotoria de una matricula: el estudiante se inscribio en la
     * que no era.
     *
     * MUEVE la fila en vez de retirar una y crear otra, y eso es deliberado (se
     * decidio el 28/08/2026). No fue una salida, fue un dato mal puesto: la
     * matricula conserva su fecha de inscripcion y el estudiante figura como si
     * siempre hubiera estado en la correcta. Retirar y crear le dejaria una
     * `retirada` en la equivocada, que en pantalla se lee como que se salio.
     *
     * Vuelve a `pendiente` aunque estuviera activa, por la misma razon por la
     * que solo quien dicta pasa lista: confirmar es un acto de quien tiene a esa
     * persona en su salon, y el profesor de la promotoria nueva no la ha visto
     * nunca. Le aparece en su Panel por el circuito normal.
     *
     * Solo el periodo EN CURSO. Mover una matricula de un periodo cerrado
     * cambiaria certificados ya emitidos y la antiguedad que cuenta
     * `InformeController`, y eso no es corregir un error de captura.
     */
    public function corregirPromotoria(Request $request, Matricula $matricula): RedirectResponse
    {
        /** @var Perfil $perfil */
        $perfil = $request->attributes->get('perfil');

        $volver = redirect()->route('historial-estudiante', $matricula->estudiante_id);

        if (! in_array($perfil->rol, ['director', 'administrador'], true)) {
            return $volver->with('error', 'No tienes acceso a esta corrección.');
        }

        if ($matricula->periodo_id !== Periodo::enCurso()?->id) {
            return $volver->with('error', 'Solo se corrige una matrícula del periodo en curso.');
        }

        if ($matricula->estado === Matricula::RETIRADA) {
            return $volver->with('error', 'Una matrícula retirada no se corrige: el estudiante ya no está en ella.');
        }

        // El id llega de un formulario: que exista se exige en la consulta, no
        // se da por hecho porque se haya pintado en el desplegable.
        $destino = Promotoria::find($request->input('promotoria_id'));

        if ($destino === null) {
            return $volver->with('error', 'Esa promotoría no existe.');
        }

        if ($destino->id === $matricula->promotoria_id) {
            return $volver->with('error', 'La matrícula ya está en esa promotoría.');
        }

        // Anotada porque `Matricula` no lleva `@property` y PHPStan no adivina
        // el tipo detras de la relacion. No es un cast para callarlo: el nombre
        // se lee ANTES de mover, que es cuando todavia es el de la promotoria
        // equivocada, y es lo que se le cuenta a quien corrige.
        /** @var Promotoria $anterior */
        $anterior = $matricula->promotoria;
        $origen = $anterior->nombre;

        $matricula->promotoria_id = $destino->id;
        // El grupo cuelga de la promotoria vieja: dejarlo puesto meteria al
        // estudiante en un horario de otra promotoria. Se pierde siempre, y por
        // eso la pantalla lo avisa antes de preguntar.
        $matricula->grupo_id = null;
        $matricula->estado = Matricula::PENDIENTE;

        try {
            $matricula->validar();
            $matricula->save();
        } catch (ValidationException $e) {
            return $volver->with('error', implode(' ', Arr::flatten($e->errors())));
        } catch (QueryException $e) {
            return $volver->with('error', $this->porQueNoSePudoCorregir($e, $destino));
        }

        return $volver->with('success', "La matrícula pasó de {$origen} a {$destino->nombre}, "
            .'y queda pendiente de que la confirme quien la dicta.');
    }

    /**
     * Traduce el rechazo de la base a algo que se pueda leer en pantalla.
     *
     * Las dos garantias que pueden saltar aqui viven en el motor y no en la
     * aplicacion, asi que no hay forma de anticiparlas sin una carrera: el
     * trigger de cupo —que tambien corre en UPDATE— y el unico
     * `(estudiante, promotoria, periodo)`, que cuenta TAMBIEN las retiradas. Ese
     * segundo caso es el que sorprende: un estudiante que entro y se salio de la
     * promotoria destino este mismo periodo no puede volver a ella moviendo otra
     * matricula, y el mensaje crudo de MariaDB no lo explicaria.
     */
    private function porQueNoSePudoCorregir(QueryException $e, Promotoria $destino): string
    {
        $mensaje = $e->getMessage();

        if (str_contains($mensaje, 'unica_matricula_por_periodo')) {
            return "El estudiante ya tiene una matrícula en {$destino->nombre} este periodo, "
                .'aunque esté retirada. Reactívala desde ahí en vez de mover esta.';
        }

        if (str_contains($mensaje, '45000') || str_contains($mensaje, 'cupo')) {
            return "{$destino->nombre} no tiene cupo libre en este periodo.";
        }

        throw $e;
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
