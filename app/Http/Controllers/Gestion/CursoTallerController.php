<?php

namespace App\Http\Controllers\Gestion;

use App\Models\Actividad;
use App\Models\SesionActividad;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Cursos y talleres: lo que tiene fechas.
 *
 * Van juntos en una pantalla porque son la misma cosa contada en dias, y de ahi
 * sale la decision que gobierna este archivo: el tipo NO se pregunta. Se
 * pregunta CUANTAS CLASES, y una sola clase es un taller. Preguntar las dos
 * cosas dejaba crear un taller de cuatro dias y un curso de uno, y entonces el
 * nombre del tipo no querria decir nada.
 *
 * Crear es de dos pasos: primero el nombre, las clases, el responsable y el
 * cupo; despues las fechas, una por clase. No caben en un solo formulario
 * porque cuantos campos de fecha hay que pintar depende de lo que se conteste
 * arriba, y este proyecto no monta JavaScript para eso.
 */
class CursoTallerController extends ActividadController
{
    /**
     * El techo de clases de un curso.
     *
     * No es una regla de negocio, es el limite de lo que cabe en una rejilla que
     * se llena a mano: pasadas unas decenas de casillas, escribirlas una a una
     * deja de ser el camino y hace falta otra pantalla. Alto de sobra para un
     * curso de un semestre.
     */
    public const CLASES_MAXIMAS = 60;

    /** Casillas de fecha vacias que se ofrecen para crecer, al volver a entrar. */
    private const FILAS_DE_SOBRA = 3;

    protected function tipos(): array
    {
        return Actividad::TIPOS_CON_FECHAS;
    }

    protected function textos(): array
    {
        return [
            'titulo' => 'Cursos y talleres',
            'titulo_nuevo' => 'Nuevo curso o taller',
            'titulo_editar' => 'Editar curso o taller',
            'ruta_lista' => 'actividad-curso-lista',
            'ruta_nuevo' => 'actividad-curso-nueva',
            'ruta_editar' => 'actividad-curso-editar',
            'ruta_eliminar' => 'actividad-curso-eliminar',
            'creado' => 'Creado. Ahora pon las fechas.',
            'actualizado' => 'Curso actualizado.',
        ];
    }

    protected function campos(Request $request, ?Model $objeto): array
    {
        $campos = parent::campos($request, $objeto);

        // Solo al CREAR. Al editar, el numero de clases se cambia poniendo o
        // quitando fechas, que es donde se ve lo que se esta cambiando: un
        // campo aqui que dijera "3" tendria que decidir en silencio cual de las
        // cuatro fechas se tira.
        if ($objeto !== null) {
            return $campos;
        }

        return [
            'nombre' => $campos['nombre'],
            'clases' => [
                'etiqueta' => '¿Cuántas clases?',
                'tipo' => 'number',
                'min' => 1,
                'valor' => 1,
                'ayuda' => 'Una sola clase es un taller. Dos o más, un curso.',
            ],
            ...$campos,
        ];
    }

    protected function reglas(Request $request, ?Model $objeto): array
    {
        $reglas = parent::reglas($request, $objeto);

        if ($objeto !== null) {
            return $reglas;
        }

        return [
            'clases' => ['required', 'integer', 'min:1', 'max:'.self::CLASES_MAXIMAS],
            ...$reglas,
        ];
    }

    /**
     * El tipo sale del numero de clases, no de un desplegable.
     *
     * `clases` no es columna de `actividades` y no esta en su `$fillable`, asi
     * que llega hasta aqui por el request y se queda fuera de la insercion.
     */
    protected function atributosFijos(Request $request): array
    {
        return [
            ...parent::atributosFijos($request),
            'tipo' => Actividad::tipoSegunClases((int) $request->input('clases', 1)),
        ];
    }

    /**
     * Recien creado se va a poner las fechas, no al listado.
     *
     * Se lleva el numero pedido en la URL para saber cuantas casillas pintar:
     * todavia no hay ninguna sesion de la que deducirlo.
     */
    protected function urlTrasCrear(Model $objeto, Request $request): string
    {
        return route('actividad-curso-fechas', [
            $objeto,
            'clases' => (int) $request->input('clases', 1),
        ]);
    }

    // -----------------------------------------------------------------------
    // Las fechas
    // -----------------------------------------------------------------------

    public function fechas(Request $request, string $id): View
    {
        /** @var Actividad $actividad */
        $actividad = $this->buscar($id);
        $sesiones = $actividad->sesiones()->get();

        // Recien creado llega el numero pedido y se pintan exactamente esas.
        // Al volver a entrar se pintan las que hay mas unas pocas vacias, que
        // es como se le anaden dias sin un boton que las invente.
        $pedidas = (int) $request->query('clases', 0);
        $filas = $pedidas > 0
            ? min($pedidas, self::CLASES_MAXIMAS)
            : $sesiones->count() + self::FILAS_DE_SOBRA;

        return view('gestion.actividad-fechas', [
            'actividad' => $actividad,
            'fechas' => $this->casillas($sesiones->pluck('fecha')->all(), $filas),
        ]);
    }

    public function guardarFechas(Request $request, string $id): RedirectResponse
    {
        /** @var Actividad $actividad */
        $actividad = $this->buscar($id);

        $request->validate([
            'fechas' => ['array'],
            'fechas.*' => ['nullable', 'date'],
        ]);

        $puestas = collect($request->input('fechas', []))
            ->map(fn ($f) => trim((string) $f))
            ->filter()
            ->map(fn (string $f) => Carbon::parse($f)->toDateString())
            ->values();

        if ($puestas->duplicates()->isNotEmpty()) {
            // Lo atajaria el unico de la base, pero con un error del motor. Y en
            // una rejilla de doce casillas repetir una fecha es facil.
            return back()->with('error', 'Hay una fecha repetida. Cada clase va en un día distinto.');
        }

        if ($puestas->isEmpty()) {
            return back()->with('error', 'Pon al menos una fecha.');
        }

        $sesiones = $actividad->sesiones()->get();
        $sobran = $sesiones->reject(fn (SesionActividad $s) => $puestas->contains($s->fecha->toDateString()));

        // Una clase que ya se dio no se borra quitandole la fecha: lo que
        // ocurrio, ocurrio, y con ella se iria su lista de asistencia.
        $yaDadas = $sobran->filter(fn (SesionActividad $s) => $s->yaEmpezo());

        if ($yaDadas->isNotEmpty()) {
            $cuales = $yaDadas->map(fn (SesionActividad $s) => $s->fecha->format('d/m/Y'))->implode(', ');

            return back()->with('error', "No se puede quitar {$cuales}: esa sesión ya se inició.");
        }

        DB::transaction(function () use ($actividad, $sesiones, $sobran, $puestas) {
            SesionActividad::whereIn('id', $sobran->pluck('id'))->delete();

            // Las que ya estaban se dejan intactas: conservan cuando se
            // iniciaron y a quien se le paso lista.
            $yaEstaban = $sesiones->map(fn (SesionActividad $s) => $s->fecha->toDateString())->all();

            foreach ($puestas->diff($yaEstaban) as $fecha) {
                $actividad->sesiones()->create(['fecha' => $fecha]);
            }

            // El tipo vuelve a deducirse: un curso al que le quitan dias hasta
            // dejarlo en uno es un taller, y al reves.
            $actividad->tipo = Actividad::tipoSegunClases($puestas->count());
            $actividad->save();
        });

        return redirect()
            ->route('actividad-curso-lista')
            ->with('success', 'Fechas guardadas.');
    }

    /**
     * Las casillas de la rejilla: las que ya tienen fecha primero, y el resto
     * vacias.
     *
     * @param  list<Carbon>  $fechas
     * @return list<string>
     */
    private function casillas(array $fechas, int $filas): array
    {
        $puestas = array_map(fn (Carbon $f) => $f->toDateString(), $fechas);

        return array_pad($puestas, max($filas, count($puestas)), '');
    }
}
