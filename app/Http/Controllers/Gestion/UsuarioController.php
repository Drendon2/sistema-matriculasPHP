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
use App\Rules\ImagenProcesable;
use App\Support\Dependencias;
use App\Support\Imagen;
use App\Support\Permisos;
use App\Support\Regreso;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
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
     * Cuantos usuarios por pagina.
     *
     * Sin paginar, esta pantalla hidrataba los 308 perfiles con sus relaciones
     * y TODAS sus matriculas en una sola respuesta. Hoy cabe; a cinco anos con
     * 300 estudiantes por curso son ~1.500 perfiles y ~4.000 matriculas de
     * golpe, en un hosting compartido con la memoria contada.
     */
    public const POR_PAGINA = 50;

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
            // Solo para el administrador, que es el unico que ve «Eliminar»: son
            // tres subconsultas correlacionadas mas y no hay por que cobrarselas
            // a un director que nunca va a usar el dato. Van DENTRO de la
            // consulta paginada, asi que siguen siendo una sola ida a la base
            // por pagina y no crecen con las filas.
            ->when(
                $request->attributes->get('perfil')?->rol === 'administrador',
                fn ($q) => $q->withCount(Dependencias::nombresDeBloqueos(Perfil::class))
            )
            ->orderBy('rol')
            ->orderBy('nombre_completo')
            // Desempate por id, que es lo unico unico de la fila. Sin el, dos
            // homonimos del mismo rol quedan en un orden que el motor no esta
            // OBLIGADO a repetir entre consultas: al paginar, eso significa que
            // uno puede salir al final de una pagina y otra vez al principio de
            // la siguiente, o no salir en ninguna. Sin paginar no se notaba
            // porque la lista venia entera de una sola consulta.
            //
            // Ojo con el estado de esto: NO hay prueba, y no por descuido. Se
            // escribio una con sesenta homonimos y pasaba igual quitando esta
            // linea, porque MariaDB devuelve aqui un orden estable aunque no
            // este obligada. Se quito la prueba y se deja el desempate: es
            // gratis y cubre el dia que cambie el plan de ejecucion, que es
            // justo el dia en que nadie estaria mirando.
            ->orderBy('id');

        // Buscador por texto: nombre o usuario, que son las dos columnas por las
        // que alguien identifica a una persona en esta lista. NO busca por
        // documento ni por telefono a proposito — son datos de visibilidad
        // restringida (ver PRODUCT.md), y un buscador que los aceptara dejaria
        // que un director confirmara el documento de alguien probando cifras.
        //
        // El cotejo de la base es utf8mb4_unicode_ci, o sea que ignora
        // mayusculas Y tildes: «gomez» encuentra «Gómez» sin normalizar nada
        // aqui. Si alguna vez se cambia el cotejo de estas dos columnas, esta
        // busqueda deja de encontrar los nombres con tilde en silencio.
        if ($seleccion['buscar'] !== '') {
            $termino = '%'.$this->escaparLike($seleccion['buscar']).'%';

            $consulta->where(function ($q) use ($termino) {
                $q->where('nombre_completo', 'like', $termino)
                    ->orWhereHas('user', fn ($u) => $u->where('username', 'like', $termino));
            });
        }

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

        // `withQueryString()` conserva los filtros en los enlaces: sin el,
        // pasar de pagina limpiaba rol, departamento, promotoria y periodo, y
        // la pagina 2 era la de TODOS los usuarios.
        $perfiles = $consulta->paginate(self::POR_PAGINA)->withQueryString();

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
            // `sesiones` NO sobra: el desplegable pinta `rotulo_breve`, que se
            // compone con el HORARIO, y el horario se lee de las sesiones. Sin
            // esto era una consulta por grupo — 26 de las 45 de esta pantalla en
            // la base de desarrollo, y creciendo con cada grupo que se cree.
            'grupos' => Grupo::with(['promotoria', 'sesiones'])
                ->join('promotorias', 'promotorias.id', '=', 'grupos.promotoria_id')
                ->orderBy('promotorias.nombre')
                ->orderBy('grupos.nivel')
                ->select('grupos.*')
                ->get(),
            'periodos' => Periodo::orderByDesc('activo')->orderByDesc('fecha_inicio')->get(),
            'hayFiltros' => $seleccion['buscar'] !== '' || $seleccion['rol'] !== ''
                || $seleccion['area'] || $seleccion['promotoria'] || $seleccion['grupo'],
        ]);
    }

    public function crear(Request $request): View
    {
        return view('gestion.usuario-form', [
            'titulo' => 'Nuevo usuario',
            'esCreacion' => true,
            'perfil' => new Perfil,
            'accion' => route('usuario-nuevo'),
            'datos' => null,
            'acudiente' => null,
            'roles' => $this->rolesQuePuedeRepartir($request),
            // Se abre en el modal del listado, como los catalogos. El porque
            // esta en la plantilla: es el formulario que mas se abre.
            'modal' => true,
            'volver' => Regreso::consulta($request, route('usuario-lista')),
        ]);
    }

    public function guardar(Request $request): RedirectResponse
    {
        $this->exigirRolRepartible($request);

        $datos = $this->validar($request, null);

        try {
            DB::transaction(function () use ($request, $datos) {
                $user = User::create([
                    'username' => $datos['username'],
                    'password' => $datos['password'],
                    // Vacio se guarda como null: «sin correo» es la ausencia del
                    // dato, y dejar '' obligaria a comprobar las dos cosas en
                    // cada sitio que lo lea.
                    'email' => ($datos['correo'] ?? null) ?: null,
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

        return redirect(Regreso::url(route('usuario-lista'), $request->input('volver')))
            ->with('success', 'Usuario creado.');
    }

    public function editar(Request $request, Perfil $usuario): View
    {
        $this->exigirAccesoA($request, $usuario);

        $datos = $usuario->datosEstudiante;

        return view('gestion.usuario-form', [
            'titulo' => "Editar a {$usuario->nombre_completo}",
            'esCreacion' => false,
            'perfil' => $usuario,
            'accion' => route('usuario-editar', $usuario),
            'datos' => $datos,
            'acudiente' => $datos?->acudiente,
            'roles' => $this->rolesQuePuedeRepartir($request),
            'modal' => true,
            // Los filtros y la pagina de la lista de la que se viene, para
            // devolverlos puestos: sin esto, editar a alguien de la pagina 3
            // aterrizaba en la 1.
            'volver' => Regreso::consulta($request, route('usuario-lista')),
        ]);
    }

    public function actualizar(Request $request, Perfil $usuario): RedirectResponse
    {
        // Las dos puertas, y hacen falta las dos: una impide ascender a nadie a
        // administrador, la otra impide tocar al que ya lo es.
        $this->exigirAccesoA($request, $usuario);
        $this->exigirRolRepartible($request);

        $datos = $this->validar($request, $usuario);
        $datosEstudiante = $usuario->datosEstudiante;
        $acudiente = $datosEstudiante?->acudiente;

        try {
            DB::transaction(function () use ($request, $usuario, $datos, $datosEstudiante, $acudiente) {
                $user = $usuario->user;
                $user->username = $datos['username'];
                $user->email = ($datos['correo'] ?? null) ?: null;

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

        return redirect(Regreso::url(route('usuario-lista'), $request->input('volver')))
            ->with('success', 'Usuario actualizado.');
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
        // Desactivar tambien es tocar la cuenta. Sin esto, un director que no
        // puede suplantar al administrador si podria dejarlo fuera, y con el a
        // las tres pantallas que solo el abre.
        $this->exigirAccesoA($request, $usuario);

        // Las dos salidas vuelven con `back()` y no a `route('usuario-lista')`.
        // Sin paginacion las dos cosas eran la misma URL y daba igual; con
        // paginacion, desactivar a alguien de la pagina 6 devolvia a la 1 y
        // ademas sin el filtro puesto, asi que para desactivar a tres personas
        // de la misma promotoria habia que volver a filtrar y a bajar tres
        // veces. `back()` recupera la URL de la lista con su `?rol=` y su
        // `?page=`, que durante estos POST por `fetch` sigue siendo la ultima
        // GET de la sesion.
        //
        // Aqui no hace falta ajustar la pagina cuando se queda vacia, como si
        // en Cancelaciones: desactivar una cuenta le cambia el estado y la deja
        // en la lista, no la saca.
        if ($usuario->user_id === $request->user()->id) {
            return back()->with('error', 'No puedes desactivar tu propia cuenta.');
        }

        $user = $usuario->user;
        $user->activo = ! $user->activo;
        $user->save();

        return back()->with(
            'success',
            $user->activo ? 'Cuenta activada.' : 'Cuenta desactivada.'
        );
    }

    /**
     * La pantalla que pregunta antes de borrar una cuenta, y que casi siempre
     * se niega.
     *
     * Sigue el molde de `RecursoController::confirmarBorrado` —decir la verdad
     * ANTES de preguntar, en vez de preguntar «¿seguro?» para negarse
     * despues—, con una diferencia que justifica la pantalla propia: aqui,
     * ademas de confirmar, hay que teclear la contrasena de quien borra.
     *
     * No es un adorno de seguridad. Borrar una cuenta es la unica accion del
     * sistema que quita datos de verdad, y una sesion abierta en un celular
     * prestado o sin bloquear basta para llegar hasta aqui. La contrasena
     * comprueba que quien pulsa es la persona, no el aparato.
     */
    public function confirmarBorrado(Request $request, Perfil $usuario): View
    {
        // Borrar es la CUARTA forma de tocar una cuenta, y pasa por la misma
        // puerta que las otras tres. Hoy no cambia nada —a esta ruta solo llega
        // un administrador, y un administrador puede con cualquiera—, pero el
        // comentario de `Permisos::puedeEditarUsuario` avisa de que dejar una
        // fuera es dejarla entera fuera, y esta era la que faltaba.
        $this->exigirAccesoA($request, $usuario);

        // Una sola vez: `de()` son seis COUNT, y pedirlos aqui y otra vez dentro
        // de `impedimento()` eran doce para pintar una pagina.
        $dependencias = Dependencias::de($usuario);

        return view('gestion.confirma-borrado-usuario', [
            'usuario' => $usuario,
            'accion' => route('usuario-eliminar', $usuario),
            'impedimento' => $this->impedimento($request, $usuario, $dependencias),
            'arrastre' => $dependencias['arrastre'],
            // El filtro y la pagina en que estaba quien mira. Sin esto, borrar a
            // uno de los «Pendiente de rol» devolvia a la lista entera por el
            // principio, y para borrar a cinco habia que filtrar cinco veces.
            'volver' => Regreso::consulta($request, route('usuario-lista')),
        ]);
    }

    public function eliminar(Request $request, Perfil $usuario): RedirectResponse
    {
        $this->exigirAccesoA($request, $usuario);

        // Se vuelve a preguntar lo mismo que al pintar la pantalla, y no por
        // duplicar: entre que se pinto y se pulso pueden haber pasado minutos, y
        // en ese rato alguien pudo matricular a esta persona o ponerla al frente
        // de una promotoria. La pantalla informa; esta linea es la que decide.
        $impedimento = $this->impedimento($request, $usuario);

        $destino = Regreso::url(route('usuario-lista'), $request->input('volver'));

        if ($impedimento !== null) {
            return redirect($destino)->with('error', $impedimento);
        }

        // La contrasena se valida como un campo del formulario y no con un
        // `abort()`: escribirla mal es un error corriente de dedo, no un intento
        // de saltarse nada, y tiene que poder corregirse sin perder la pagina.
        if (! Hash::check((string) $request->input('password'), $request->user()->password)) {
            return back()->withErrors([
                'password' => 'Esa no es tu contraseña. No se eliminó nada.',
            ]);
        }

        $nombre = $usuario->nombre_completo;

        // Se borra la CUENTA, no el perfil: `perfiles.user_id` es CASCADE, asi
        // que el perfil se va con ella. Al reves quedaria una cuenta capaz de
        // iniciar sesion sin perfil, que es el unico estado que el sistema no
        // sabe atender —la redireccion posterior al login no tiene a donde
        // mandarla—.
        $usuario->user->delete();

        return redirect($destino)->with('success', "Se eliminó la cuenta de {$nombre}.");
    }

    /**
     * Por que NO se puede borrar esta cuenta, o null si se puede.
     *
     * Son DOS razones, y hubo una tercera. Se escribio un «es el ultimo
     * administrador que queda» que resulto ser INALCANZABLE, y no se vio
     * leyendolo sino al comprobar que su prueba seguia verde con la guarda
     * quitada: a esta ruta solo llega un administrador, asi que si queda uno
     * solo, ese uno es QUIEN PULSA, y la comprobacion de la cuenta propia ya lo
     * paro una linea antes. Queda dicho para que nadie la reponga creyendo que
     * falta.
     *
     * De ahi que la garantia de no quedarse sin administradores la sostengan
     * dos cosas y ninguna se llame asi: la puerta de la ruta y la cuenta
     * propia. El dia que el borrado se le de a alguien mas que al
     * administrador, esa garantia se cae y hay que reponerla aqui de verdad.
     */
    /**
     * @param  array{bloqueos: string, arrastre: string}|null  $dependencias
     *                                                                        ya calculadas, si quien llama las tenia
     */
    private function impedimento(Request $request, Perfil $usuario, ?array $dependencias = null): ?string
    {
        if ($usuario->user_id === $request->user()->id) {
            return 'No puedes eliminar tu propia cuenta.';
        }

        $bloqueos = ($dependencias ?? Dependencias::de($usuario))['bloqueos'];

        if ($bloqueos !== '') {
            return "No se puede eliminar a {$usuario->nombre_completo}: todavía tiene {$bloqueos}. "
                .'Desactiva la cuenta en vez de eliminarla.';
        }

        return null;
    }

    // -----------------------------------------------------------------------
    // Quien puede repartir que rol, y sobre quien
    // -----------------------------------------------------------------------

    /** @return list<string> */
    private function rolesQuePuedeRepartir(Request $request): array
    {
        /** @var Perfil $perfil */
        $perfil = $request->attributes->get('perfil');

        return Permisos::rolesAsignablesPor($perfil);
    }

    /**
     * Corta si se intenta repartir un rol que esta persona no reparte.
     *
     * Un 403 y no un error de campo: el formulario no ofrece ese rol siquiera
     * —el desplegable solo pinta los asignables—, asi que llegar aqui con
     * `rol=administrador` significa que la peticion se compuso a mano. Eso no es
     * un dato mal escrito que haya que corregir en pantalla, es alguien
     * intentando pasar por encima de la regla.
     */
    private function exigirRolRepartible(Request $request): void
    {
        abort_unless(
            in_array($request->input('rol'), $this->rolesQuePuedeRepartir($request), true),
            403,
            'No puedes asignar ese rol.'
        );
    }

    /** Corta si la cuenta de destino esta por encima de quien la quiere tocar. */
    private function exigirAccesoA(Request $request, Perfil $usuario): void
    {
        /** @var Perfil $perfil */
        $perfil = $request->attributes->get('perfil');

        abort_unless(
            Permisos::puedeEditarUsuario($perfil, $usuario),
            403,
            'La cuenta de un administrador solo la edita otro administrador.'
        );
    }

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
            //
            // El minimo convive con eso sin pelearse: `ConvertEmptyStringsToNull`
            // deja el campo vacio en null, y `nullable` salta las reglas que
            // vienen detras. Asi que editar el telefono de alguien sigue sin
            // pedir contrasena, pero en cuanto se escribe una tiene que cumplir
            // — que es justo el caso que importa, porque una contrasena puesta
            // desde Gestion se la lleva la persona tal cual.
            'password' => [
                $perfil === null ? 'required' : 'nullable',
                'string',
                Password::defaults(),
            ],
            // Acotado a lo que ESTA persona reparte, no a la lista entera. La
            // puerta de verdad es `exigirRolRepartible`, que ya corto antes de
            // llegar aqui; esto es la segunda vuelta de llave, para que la regla
            // siga puesta si algun dia alguien anade un camino nuevo a este
            // formulario y se olvida de la primera.
            'rol' => ['required', Rule::in($this->rolesQuePuedeRepartir($request))],
            'nombre_completo' => ['required', 'string', 'max:90'],
            'fecha_nacimiento' => ['required', 'date', 'before:today'],
            'telefono' => ['required', 'string', 'max:15'],
            // Opcional y NO unico, igual que en el esquema: buena parte de los
            // matriculados son menores sin correo propio, y dos hermanos
            // comparten el de su acudiente. Un indice unico convertiria ese caso
            // corriente en un error que la familia no sabria resolver.
            'correo' => ['nullable', 'email', 'max:255'],
            'foto_perfil' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp,gif', 'max:8192', new ImagenProcesable],
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
            $acudiente ??= new Acudiente;
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
     * Neutraliza los comodines de LIKE en lo que teclee el usuario.
     *
     * Sin esto, `%` devuelve la lista entera y `_` casa con cualquier letra: el
     * buscador contestaria cosas que nadie pidio, y ademas seria una forma de
     * tantear nombres que no se pueden ver. La consulta ya va con parametros
     * ligados, asi que esto no es contra inyeccion sino contra el comodin.
     */
    private function escaparLike(string $valor): string
    {
        return addcslashes($valor, '%_\\');
    }

    /**
     * @return array<string, mixed>
     */
    private function seleccion(Request $request): array
    {
        return [
            // Un espacio suelto no puede contar como filtro puesto: dejaria
            // "Limpiar" a la vista sin que nada este filtrado. Por HTTP esto ya
            // lo garantiza el middleware `TrimStrings` de Laravel y este `trim`
            // no llega a hacer nada; se queda por si alguna vez se exceptua esa
            // clave del middleware o se llama a este metodo desde otro sitio.
            'buscar' => trim((string) $request->query('buscar', '')),
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
