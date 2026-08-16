<?php

namespace App\Http\Controllers\Gestion;

use App\Http\Controllers\Controller;
use App\Models\Acudiente;
use App\Models\Area;
use App\Models\DatosEstudiante;
use App\Models\Grupo;
use App\Models\Matricula;
use App\Models\Perfil;
use App\Models\Periodo;
use App\Models\Promotoria;
use App\Models\User;
use App\Support\Imagen;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Alta, edicion y baja logica de usuarios. Un usuario son varias piezas a la
 * vez: la cuenta, el perfil y —si es estudiante— sus datos y su acudiente.
 */
class UsuarioController extends Controller
{
    /**
     * Valor del filtro de rol que pide justamente a los que NO tienen rol.
     *
     * Hace falta un centinela porque el "sin rol" real es la cadena vacia, y esa
     * ya significa "no filtres por rol" en un formulario GET.
     */
    public const ROL_PENDIENTE = '__sin__';

    /**
     * Listado de usuarios, filtrable por rol y por donde esta la persona.
     *
     * Los tres filtros de catalogo (departamento, promotoria, grupo) no se
     * resuelven igual para todos los roles, porque la gente se relaciona con una
     * promotoria de dos maneras distintas: el estudiante porque esta matriculado
     * en ella, el profesor porque la dicta. Filtrar por «Violín» devuelve las dos
     * cosas —los matriculados y su profesor—, que es lo que uno espera al pedir
     * «la gente de Violín». El filtro de grupo es la excepcion: un grupo solo
     * tiene estudiantes, asi que ahi el profesor no aparece.
     *
     * Direccion no cuelga de ninguna promotoria, asi que cualquier filtro de
     * catalogo la deja fuera. Es correcto, no un fallo: si alguien cruza
     * rol=administrador con una promotoria, la lista sale vacia porque esa
     * combinacion no existe.
     */
    public function index(Request $request): View
    {
        $seleccion = $this->seleccion($request);

        // Los pendientes de rol (rol = '') quedan primero para que no se pierdan
        // de vista.
        $consulta = Perfil::query()
            ->with(['user', 'promotoriasDictadas.area'])
            ->orderBy('rol')
            ->orderBy('nombre_completo');

        if ($seleccion['rol'] === self::ROL_PENDIENTE) {
            $consulta->where('rol', '');
        } elseif ($seleccion['rol'] !== '') {
            $consulta->where('rol', $seleccion['rol']);
        }

        if ($seleccion['area'] || $seleccion['promotoria'] || $seleccion['grupo']) {
            $consulta->where(function ($q) use ($seleccion) {
                $q->whereIn('id', $this->idsPorMatricula($seleccion));

                // Rama de profesores: solo cuando no se filtro por grupo. Va por
                // Promotoria y no por Matricula, porque el vinculo del profesor
                // con su promotoria no depende de que alguien se haya
                // matriculado ni de que periodo se este mirando.
                if ($seleccion['grupo'] === null) {
                    $q->orWhereIn('id', $this->idsPorPromotoria($seleccion));
                }
            });
        }

        $perfiles = $consulta->get();

        // La columna "Promotorías" resuelve los dos vinculos de una vez. Se trae
        // aparte y no fila por fila porque, con doscientos usuarios, preguntarlo
        // en la plantilla son cuatrocientas consultas.
        $vinculadas = Matricula::query()
            ->whereIn('estudiante_id', $perfiles->pluck('id'))
            ->where('estado', '!=', Matricula::RETIRADA)
            ->when($seleccion['periodo'], fn ($q) => $q->where('periodo_id', $seleccion['periodo']->id))
            ->with('promotoria.area')
            ->get()
            ->groupBy('estudiante_id');

        return view('gestion.usuarios', [
            'perfiles' => $perfiles,
            'matriculasPorPerfil' => $vinculadas,
            'seleccion' => $seleccion,
            'roles' => Perfil::ROLES,
            'rolPendiente' => self::ROL_PENDIENTE,
            'areas' => Area::orderBy('nombre')->get(),
            // Agrupadas por area para poder pintarlas en <optgroup>: la
            // jerarquia del catalogo se ve en el desplegable sin recargar la
            // pagina al elegir el departamento.
            'promotorias' => Promotoria::with('area')
                ->join('areas', 'areas.id', '=', 'promotorias.area_id')
                ->orderBy('areas.nombre')
                ->orderBy('promotorias.nombre')
                ->select('promotorias.*')
                ->get(),
            'grupos' => Grupo::with('promotoria')
                ->join('promotorias', 'promotorias.id', '=', 'grupos.promotoria_id')
                ->orderBy('promotorias.nombre')
                ->orderBy('grupos.nivel')
                ->select('grupos.*')
                ->get(),
            'periodos' => Periodo::orderByDesc('activo')->orderByDesc('fecha_inicio')->get(),
            'hayFiltros' => $seleccion['rol'] !== ''
                || $seleccion['area'] || $seleccion['promotoria'] || $seleccion['grupo'],
        ]);
    }

    public function crear(): View
    {
        return view('gestion.usuario-form', [
            'titulo' => 'Nuevo usuario',
            'esCreacion' => true,
            'perfil' => new Perfil(),
            'accion' => route('usuario-nuevo'),
            'datos' => null,
            'acudiente' => null,
        ]);
    }

    public function guardar(Request $request): RedirectResponse
    {
        $datos = $this->validar($request, null);

        try {
            DB::transaction(function () use ($request, $datos) {
                $user = User::create([
                    'username' => $datos['username'],
                    'password' => $datos['password'],
                    'activo' => true,
                ]);

                $perfil = Perfil::create([
                    'user_id' => $user->id,
                    'rol' => $datos['rol'],
                    'nombre_completo' => $datos['nombre_completo'],
                    'fecha_nacimiento' => $datos['fecha_nacimiento'],
                    'telefono' => $datos['telefono'],
                    'foto_perfil' => $this->guardarFoto($request),
                ]);

                if ($datos['rol'] === 'estudiante') {
                    $this->guardarDatosEstudiante($perfil, $datos, null, null);
                }
            });
        } catch (ValidationException $e) {
            return back()->withInput()->withErrors($e->errors());
        }

        return redirect()->route('usuario-lista')->with('success', 'Usuario creado.');
    }

    public function editar(Perfil $usuario): View
    {
        $datos = $usuario->datosEstudiante;

        return view('gestion.usuario-form', [
            'titulo' => "Editar a {$usuario->nombre_completo}",
            'esCreacion' => false,
            'perfil' => $usuario,
            'accion' => route('usuario-editar', $usuario),
            'datos' => $datos,
            'acudiente' => $datos?->acudiente,
        ]);
    }

    public function actualizar(Request $request, Perfil $usuario): RedirectResponse
    {
        $datos = $this->validar($request, $usuario);
        $datosEstudiante = $usuario->datosEstudiante;
        $acudiente = $datosEstudiante?->acudiente;

        try {
            DB::transaction(function () use ($request, $usuario, $datos, $datosEstudiante, $acudiente) {
                $user = $usuario->user;
                $user->username = $datos['username'];

                // Vacia significa "no la cambies": es lo que permite editar el
                // telefono de alguien sin tener que inventarle una contrasena.
                if (! empty($datos['password'])) {
                    $user->password = $datos['password'];
                }

                $user->save();

                $usuario->rol = $datos['rol'];
                $usuario->nombre_completo = $datos['nombre_completo'];
                $usuario->fecha_nacimiento = $datos['fecha_nacimiento'];
                $usuario->telefono = $datos['telefono'];

                $foto = $this->guardarFoto($request);

                if ($foto !== '') {
                    if ($usuario->foto_perfil !== '') {
                        Storage::disk('local')->delete($usuario->foto_perfil);
                    }

                    $usuario->foto_perfil = $foto;
                }

                $usuario->save();

                if ($datos['rol'] === 'estudiante') {
                    $this->guardarDatosEstudiante($usuario, $datos, $datosEstudiante, $acudiente);
                }
            });
        } catch (ValidationException $e) {
            return back()->withInput()->withErrors($e->errors());
        }

        return redirect()->route('usuario-lista')->with('success', 'Usuario actualizado.');
    }

    /**
     * Da de baja una cuenta sin borrarla.
     *
     * Borrar el usuario se llevaria por delante su perfil y con el todo su
     * historial de matriculas; desactivarlo le cierra la puerta y conserva el
     * registro de que estuvo.
     */
    public function alternarActivo(Request $request, Perfil $usuario): RedirectResponse
    {
        if ($usuario->user_id === $request->user()->id) {
            return redirect()->route('usuario-lista')
                ->with('error', 'No puedes desactivar tu propia cuenta.');
        }

        $user = $usuario->user;
        $user->activo = ! $user->activo;
        $user->save();

        return redirect()->route('usuario-lista')->with(
            'success',
            $user->activo ? 'Cuenta activada.' : 'Cuenta desactivada.'
        );
    }

    // -----------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function validar(Request $request, ?Perfil $perfil): array
    {
        $esEstudiante = $request->input('rol') === 'estudiante';

        return $request->validate([
            'username' => [
                'required', 'string', 'max:150',
                Rule::unique('users', 'username')->ignore($perfil?->user_id),
            ],
            // Al crear es obligatoria; al editar, en blanco quiere decir
            // "dejala como esta".
            'password' => [$perfil === null ? 'required' : 'nullable', 'string'],
            'rol' => ['required', Rule::in(array_keys(Perfil::ROLES))],
            'nombre_completo' => ['required', 'string', 'max:90'],
            'fecha_nacimiento' => ['required', 'date', 'before:today'],
            'telefono' => ['required', 'string', 'max:15'],
            'foto_perfil' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp,gif', 'max:8192'],
            'documento_identidad' => [
                $esEstudiante ? 'required' : 'nullable', 'string', 'max:15',
                Rule::unique('datos_estudiante', 'documento_identidad')
                    ->ignore($perfil?->datosEstudiante?->id),
            ],
            'acudiente_nombre' => ['nullable', 'string', 'max:90'],
            'acudiente_telefono' => ['nullable', 'string', 'max:15'],
        ], [
            'username.unique' => 'Ya existe una cuenta con ese nombre de usuario.',
            'documento_identidad.unique' => 'Ya hay un estudiante registrado con ese documento.',
        ]);
    }

    private function guardarFoto(Request $request): string
    {
        if (! $request->hasFile('foto_perfil')) {
            return '';
        }

        $ruta = 'fotos_perfil/'.uniqid().'.webp';
        Storage::disk('local')->put($ruta, Imagen::aWebp($request->file('foto_perfil')));

        return $ruta;
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    private function guardarDatosEstudiante(
        Perfil $perfil,
        array $datos,
        ?DatosEstudiante $existentes,
        ?Acudiente $acudiente,
    ): void {
        if (! empty($datos['acudiente_nombre'])) {
            $acudiente ??= new Acudiente();
            $acudiente->nombre = $datos['acudiente_nombre'];
            $acudiente->telefono = $datos['acudiente_telefono'] ?? '';
            $acudiente->save();
        }

        $datosEstudiante = $existentes ?? new DatosEstudiante(['perfil_id' => $perfil->id]);
        $datosEstudiante->perfil_id = $perfil->id;
        $datosEstudiante->documento_identidad = $datos['documento_identidad'];
        $datosEstudiante->acudiente_id = $acudiente?->id;

        // La regla del acudiente para menores vive en el modelo y no en las
        // reglas del formulario: la minoria de edad se deduce de otra tabla.
        $datosEstudiante->setRelation('perfil', $perfil);
        $datosEstudiante->validar();
        $datosEstudiante->save();
    }

    /**
     * @return array<string, mixed>
     */
    private function seleccion(Request $request): array
    {
        return [
            'rol' => (string) $request->query('rol', ''),
            'area' => $request->query('area') ? Area::find($request->query('area')) : null,
            'promotoria' => $request->query('promotoria') ? Promotoria::find($request->query('promotoria')) : null,
            'grupo' => $request->query('grupo') ? Grupo::find($request->query('grupo')) : null,
            'periodo' => $this->periodo($request),
        ];
    }

    /**
     * El periodo sobre el que se filtra: el pedido, o el que este en curso.
     *
     * Siempre devuelve uno mientras exista alguno, y esa garantia importa: si
     * devolviera null, la consulta dejaria de acotar por periodo y barreria el
     * historico entero mientras el desplegable ensena un periodo concreto
     * seleccionado.
     */
    private function periodo(Request $request): ?Periodo
    {
        $pedido = $request->query('periodo');

        if ($pedido) {
            $elegido = Periodo::find($pedido);

            if ($elegido !== null) {
                return $elegido;
            }
        }

        return Periodo::enCurso() ?? Periodo::orderByDesc('fecha_inicio')->first();
    }

    /**
     * Rama de estudiantes del filtro de catalogo.
     *
     * Se resuelve sobre Matricula y no encadenando condiciones sobre Perfil
     * porque hay que excluir las retiradas DENTRO de la misma matricula: por
     * separado, quien tenga una retirada quedaria fuera aunque siga matriculado.
     *
     * @param  array<string, mixed>  $seleccion
     */
    private function idsPorMatricula(array $seleccion)
    {
        return Matricula::query()
            ->where('estado', '!=', Matricula::RETIRADA)
            ->when($seleccion['periodo'], fn ($q, $p) => $q->where('periodo_id', $p->id))
            ->when($seleccion['grupo'], fn ($q, $g) => $q->where('grupo_id', $g->id))
            ->when($seleccion['promotoria'], fn ($q, $p) => $q->where('promotoria_id', $p->id))
            ->when($seleccion['area'], fn ($q, $a) => $q->whereHas(
                'promotoria',
                fn ($sub) => $sub->where('area_id', $a->id)
            ))
            ->select('estudiante_id');
    }

    /** @param  array<string, mixed>  $seleccion */
    private function idsPorPromotoria(array $seleccion)
    {
        return Promotoria::query()
            ->whereNotNull('profesor_id')
            ->when($seleccion['promotoria'], fn ($q, $p) => $q->where('id', $p->id))
            ->when($seleccion['area'], fn ($q, $a) => $q->where('area_id', $a->id))
            ->select('profesor_id');
    }
}
