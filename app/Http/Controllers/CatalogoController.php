<?php

namespace App\Http\Controllers;

use App\Models\Clase;
use App\Models\ConfiguracionInstitucion;
use App\Models\Matricula;
use App\Models\Perfil;
use App\Models\Periodo;
use App\Models\Promotoria;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Catalogo de promotorias del estudiante: donde se puede matricular y en que
 * esta ya.
 *
 * El estudiante NO elige grupo/horario aqui: eso lo reparte despues quien dicta
 * entre los ya matriculados.
 */
class CatalogoController extends Controller
{
    public function __invoke(Request $request): View|RedirectResponse
    {
        $configuracion = ConfiguracionInstitucion::actual();

        // La institucion puede apagar esta pantalla (hay entidades que inscriben
        // en ventanilla y matriculan desde Gestion). El corte va AQUI y no solo
        // en el enlace del menu: esconder el enlace no cierra la URL, y quien la
        // tenga guardada seguiria matriculandose solo.
        if (! $configuracion->promotorias_visibles_para_estudiantes) {
            return redirect()->route('mis-matriculas')->with(
                'error',
                'La inscripción por tu cuenta no está habilitada. Acércate a la '
                . 'institución para que te matriculen.'
            );
        }

        /** @var Perfil $perfil */
        $perfil = $request->attributes->get('perfil');
        $periodo = Periodo::enCurso();
        $abiertas = $periodo !== null && $periodo->matriculas_abiertas;

        [$periodoAnterior, $renovables] = Matricula::renovables($perfil, $periodo);

        $promotorias = [];
        $cuposUsados = 0;
        $limite = Matricula::limitePromotorias();

        if ($periodo !== null) {
            $misMatriculas = Matricula::query()
                ->where('estudiante_id', $perfil->id)
                ->where('periodo_id', $periodo->id)
                ->where('estado', '!=', Matricula::RETIRADA)
                ->get()
                ->keyBy('promotoria_id');

            $cuposUsados = $misMatriculas->count();
            $sinCupo = $cuposUsados >= $limite;

            $catalogo = Promotoria::with(['area', 'profesor', 'cupos'])->get();

            // Los ocupados de TODAS las promotorias en una sola consulta, en vez
            // de un COUNT por fila.
            //
            // `ocupadosEn()` siempre consulta —a diferencia de `cupoEn()`, que a
            // su lado si aprovecha la relacion que trae el `with('cupos')`—, asi
            // que el bucle costaba tantas consultas como promotorias haya. Y es
            // la pantalla a la que cae todo estudiante al iniciar sesion, en los
            // dias de matricula, que es justo cuando mas gente entra a la vez.
            $ocupadosPorPromotoria = Promotoria::ocupadosEnLote($periodo, $catalogo);

            foreach ($catalogo as $promotoria) {
                $matricula = $misMatriculas->get($promotoria->id);
                $maximo = $promotoria->cupoEn($periodo);
                // Sin fila en el mapa significa ninguna matricula, no un fallo:
                // el GROUP BY solo devuelve las promotorias que tienen alguna.
                $ocupados = (int) ($ocupadosPorPromotoria[$promotoria->id] ?? 0);

                $promotorias[] = [
                    'promotoria' => $promotoria,
                    'matricula' => $matricula,
                    // Sin cupo propio libre, entrar a una promotoria nueva queda
                    // bloqueado — pero las que ya tiene se siguen viendo.
                    'bloqueada' => $matricula === null && $sinCupo,
                    'cupo' => $maximo,
                    'ocupados' => $ocupados,
                    // Llena solo aplica si la promotoria tiene tope definido.
                    'llena' => $matricula === null && $maximo !== null && $ocupados >= $maximo,
                ];
            }
        }

        // Cuantas clases suyas estan esperando que el las confirme, y todavia a
        // tiempo. Va aqui, en la pantalla a la que cae al iniciar sesion, porque
        // una verificacion que solo vive detras de un enlace del menu no la hace
        // nadie — y con 48 horas de plazo, enterarse tarde es quedarse sin poder
        // hacerla.
        $pendientesDeConfirmar = count(array_filter(
            Clase::porConfirmar($perfil, $periodo),
            fn (array $fila) => $fila['abierta'] && ! $fila['confirmada_por_mi']
        ));

        return view('estudiante.promotorias-disponibles', [
            'periodo' => $periodo,
            'promotorias' => $promotorias,
            'clasesPorConfirmar' => $pendientesDeConfirmar,
            'cuposUsados' => $cuposUsados,
            'cuposLimite' => $limite,
            'matriculasAbiertas' => $abiertas,
            'renovables' => $renovables,
            'periodoAnterior' => $periodoAnterior,
        ]);
    }
}
