<?php

namespace App\Http\Controllers;

use App\Models\ConfiguracionInstitucion;
use App\Models\EncuestaSatisfaccion;
use App\Models\Matricula;
use App\Models\Perfil;
use App\Models\Periodo;
use App\Models\Promotoria;
use App\Support\ErrorDeBaseDeDatos;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Renovacion para estudiantes ANTIGUOS: encuesta de satisfaccion y un boton.
 *
 * Quien ya curso un periodo no vuelve a crear cuenta ni a llenar la encuesta
 * demografica; solo evalua el periodo que termino y confirma en que promotorias
 * sigue. Las matriculas nacen 'pendiente', igual que cualquier otra.
 */
class RenovarController extends Controller
{
    public function mostrar(Request $request): View|RedirectResponse
    {
        /** @var Perfil $perfil */
        $perfil = $request->attributes->get('perfil');

        $contexto = $this->contexto($perfil);

        if ($contexto instanceof RedirectResponse) {
            return $contexto;
        }

        return view('estudiante.renovar', $contexto);
    }

    public function guardar(Request $request): RedirectResponse
    {
        /** @var Perfil $perfil */
        $perfil = $request->attributes->get('perfil');

        $contexto = $this->contexto($perfil);

        if ($contexto instanceof RedirectResponse) {
            return $contexto;
        }

        $elegidas = array_map('intval', (array) $request->input('promotoria', []));

        $seleccionadas = array_values(array_filter(
            $contexto['renovables'],
            fn (Matricula $m) => in_array($m->promotoria_id, $elegidas, true)
        ));

        // Promotorias nuevas: dos campos opcionales, sin repetir entre si.
        $idsNuevas = [];

        foreach (['promotoria_nueva', 'promotoria_nueva_2'] as $campo) {
            $valor = trim((string) $request->input($campo, ''));

            if ($valor !== '' && ! in_array($valor, $idsNuevas, true)) {
                $idsNuevas[] = $valor;
            }
        }

        $nuevas = Promotoria::whereIn('id', $idsNuevas)
            ->whereNotIn('id', $contexto['yaSuyas'])
            ->get();

        $total = count($seleccionadas) + $nuevas->count();
        $errores = [];

        if ($total === 0) {
            $errores[] = 'No elegiste nada: marca al menos una promotoría para renovar o escoge una nueva.';
        }

        if ($total > $contexto['cuposLibres']) {
            $errores[] = "Solo te quedan {$contexto['cuposLibres']} cupo(s) libre(s) en este periodo "
                ."y elegiste {$total}.";
        }

        if (count($idsNuevas) !== $nuevas->count()) {
            $errores[] = 'Una de las promotorías nuevas que elegiste ya la estás cursando.';
        }

        $encuestas = [];

        if ($contexto['porValorar']->isNotEmpty()) {
            try {
                $encuestas = $this->validarEncuestas($request, $contexto['porValorar']);
            } catch (ValidationException $e) {
                $errores[] = 'Revisa las respuestas de la encuesta: hay que contestarla '
                    .'para cada promotoría que cursaste.';
            }
        }

        if ($errores !== []) {
            return back()->withInput()->with('error', implode(' ', $errores));
        }

        try {
            DB::transaction(function () use ($perfil, $contexto, $encuestas, $seleccionadas, $nuevas) {
                foreach ($encuestas as $respuestas) {
                    EncuestaSatisfaccion::create([
                        'perfil_id' => $perfil->id,
                        // Se evalua el periodo que TERMINO, no aquel al que se
                        // renueva: sobre el que empieza todavia no hay nada que
                        // opinar.
                        'periodo_id' => $contexto['periodoAnterior']->id,
                        ...$respuestas,
                    ]);
                }

                $promotorias = [
                    ...array_map(fn (Matricula $m) => $m->promotoria, $seleccionadas),
                    ...$nuevas->all(),
                ];

                foreach ($promotorias as $promotoria) {
                    $matricula = new Matricula([
                        'estudiante_id' => $perfil->id,
                        'promotoria_id' => $promotoria->id,
                        'periodo_id' => $contexto['periodo']->id,
                        'estado' => Matricula::PENDIENTE,
                    ]);

                    $matricula->validar();
                    $matricula->save();
                }
            });
        } catch (ValidationException $e) {
            return back()->withInput()->with('error', implode(' ', Arr::flatten($e->errors())));
        } catch (QueryException $e) {
            return back()->withInput()->with(
                'error',
                ErrorDeBaseDeDatos::esCupoAgotado($e)
                    ? 'Una de las promotorías se llenó mientras enviabas la renovación. '
                        .'No se registró: vuelve a intentarlo.'
                    : 'No se pudo completar la renovación. Revisa tus matrículas y vuelve a intentarlo.'
            );
        }

        $partes = [];

        if ($seleccionadas !== []) {
            $partes[] = 'renovaste '.implode(', ', array_map(
                fn (Matricula $m) => (string) $m->promotoria,
                $seleccionadas
            ));
        }

        if ($nuevas->isNotEmpty()) {
            $partes[] = 'entraste como nuevo a '.$nuevas
                ->map(fn (Promotoria $p) => (string) $p)
                ->implode(', ');
        }

        return redirect()->route('mis-matriculas')->with(
            'success',
            'Listo: '.implode(' y ', $partes).'. Queda pendiente de confirmación del profesor.'
        );
    }

    /**
     * Todo lo que las dos mitades necesitan, o la redireccion que corresponde si
     * esta pantalla no aplica.
     *
     * Se resuelve una sola vez y en un solo sitio para que el GET y el POST no
     * puedan discrepar sobre que es renovable ni sobre cuantos cupos quedan.
     *
     * @return array<string, mixed>|RedirectResponse
     */
    private function contexto(Perfil $perfil): array|RedirectResponse
    {
        $periodo = Periodo::enCurso();
        [$periodoAnterior, $renovables] = Matricula::renovables($perfil, $periodo);

        if ($periodo === null) {
            return redirect()->route('promotorias-disponibles')->with(
                'error',
                'No hay un periodo de matrícula activo en este momento.'
            );
        }

        if (! $periodo->matriculas_abiertas) {
            $institucion = ConfiguracionInstitucion::actual()->nombre_institucion;

            return redirect()->route('promotorias-disponibles')->with(
                'error',
                "Las matrículas de {$periodo} están cerradas. Espera a que {$institucion} las abra de nuevo."
            );
        }

        if ($renovables === []) {
            return redirect()->route('promotorias-disponibles')->with(
                'error',
                'No tienes matrículas por renovar: o eres estudiante nuevo, o ya renovaste '
                .'todo lo que cursaste el periodo anterior.'
            );
        }

        $limite = Matricula::limitePromotorias();
        $usados = Matricula::promotoriasOcupadas($perfil->id, $periodo->id);

        // Un antiguo puede dejar una promotoria (o las dos) y entrar a otras:
        // para esas es un estudiante NUEVO, aunque no repita cuenta ni encuesta
        // demografica. Aqui se le ofrece todo lo que no esta ya cursando.
        $yaSuyas = array_unique([
            ...array_map(fn (Matricula $m) => $m->promotoria_id, $renovables),
            ...Matricula::where('estudiante_id', $perfil->id)
                ->where('periodo_id', $periodo->id)
                ->where('estado', '!=', Matricula::RETIRADA)
                ->pluck('promotoria_id')
                ->all(),
        ]);

        $disponibles = [];

        $catalogo = Promotoria::with(['area', 'cupos'])
            ->join('areas', 'areas.id', '=', 'promotorias.area_id')
            ->orderBy('areas.nombre')
            ->orderBy('promotorias.nombre')
            ->select('promotorias.*')
            ->get();

        foreach ($catalogo as $promotoria) {
            if (in_array($promotoria->id, $yaSuyas, true)) {
                continue;
            }

            $libres = $promotoria->cuposDisponibles($periodo);

            $disponibles[] = [
                'promotoria' => $promotoria,
                'llena' => $libres !== null && $libres <= 0,
            ];
        }

        return [
            'periodo' => $periodo,
            'periodoAnterior' => $periodoAnterior,
            'renovables' => $renovables,
            'disponibles' => $disponibles,
            'yaSuyas' => $yaSuyas,
            // Una encuesta por PROMOTORIA cursada, no una por periodo: la
            // pregunta del acompanamiento del profesor no significa nada si se
            // contesta una sola vez para dos disciplinas distintas.
            'porValorar' => EncuestaSatisfaccion::pendientesDe($perfil, $periodoAnterior),
            // Nunca negativo: si el administrador bajo el limite, quien ya
            // estaba por encima se queda sin cupos libres, no con un numero en
            // rojo.
            'cuposLibres' => max(0, $limite - $usados),
            'cuposLimite' => $limite,
        ];
    }

    /**
     * Las cinco preguntas que acompanan al boton de renovar.
     *
     * @return array<string, mixed>
     */
    /**
     * Las cinco preguntas, una tanda por promotoria que haya que valorar.
     *
     * Los campos van sufijados con el id de la matricula porque en la misma
     * pagina puede haber dos tandas y los nombres chocarian. Se valida todo
     * junto: si falta media encuesta, no se guarda ninguna.
     *
     * @param  Collection<int, Matricula>  $porValorar
     * @return array<int, array<string, mixed>> matricula_id => respuestas
     */
    private function validarEncuestas(Request $request, $porValorar): array
    {
        $reglas = [];

        foreach ($porValorar as $matricula) {
            $reglas["satisfaccion_general_{$matricula->id}"] = ['required', 'integer', 'between:1,5'];
            $reglas["calificacion_profesor_{$matricula->id}"] = ['required', 'integer', 'between:1,5'];
            $reglas["horario_funciono_{$matricula->id}"] = ['required', 'boolean'];
            $reglas["recomendaria_{$matricula->id}"] = ['required', 'boolean'];
            $reglas["comentario_{$matricula->id}"] = ['nullable', 'string'];
        }

        $datos = $request->validate($reglas);
        $respuestas = [];

        foreach ($porValorar as $matricula) {
            $respuestas[$matricula->id] = [
                'promotoria_id' => $matricula->promotoria_id,
                'satisfaccion_general' => $datos["satisfaccion_general_{$matricula->id}"],
                'calificacion_profesor' => $datos["calificacion_profesor_{$matricula->id}"],
                'horario_funciono' => $datos["horario_funciono_{$matricula->id}"],
                'recomendaria' => $datos["recomendaria_{$matricula->id}"],
                'comentario' => $datos["comentario_{$matricula->id}"] ?? '',
            ];
        }

        return $respuestas;
    }
}
