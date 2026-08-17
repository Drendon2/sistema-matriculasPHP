<?php

namespace App\Http\Controllers;

use App\Models\DocumentoEstudiante;
use App\Models\DocumentoRequerido;
use App\Models\EncuestaDemografica;
use App\Models\Grupo;
use App\Models\Matricula;
use App\Models\Perfil;
use App\Models\Periodo;
use App\Models\Promotoria;
use App\Support\Imagen;
use App\Support\ResumenAsistencia;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Lo que cada quien completa aqui, ya con sesion, es lo que los formularios
 * publicos de autorregistro NO piden por seguridad: foto de perfil, copia del
 * documento de identidad (solo estudiantes) y la encuesta demografica
 * (obligatoria para todos).
 *
 * Son formularios independientes en una sola pagina, distinguidos por el campo
 * oculto `accion`. Cada uno guarda por su cuenta: los papeles se consiguen de a
 * uno y en dias distintos, y un unico boton de "guardar todo" obligaria a
 * tenerlos todos a la mano el mismo dia.
 */
class MiPerfilController extends Controller
{
    /** Lo que se admite como foto o como papel. */
    private const IMAGENES = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

    private const ARCHIVOS = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'pdf'];

    public function mostrar(Request $request): View|RedirectResponse
    {
        $perfil = $request->user()->perfil;

        if ($perfil === null) {
            return redirect()->route('login')->with(
                'error',
                'Tu cuenta no tiene un perfil asociado. Contacta al administrador.'
            );
        }

        $datos = $perfil->datosEstudiante;
        $encuesta = $perfil->encuesta;

        // El panel de asistencia camina por los periodos donde esta persona
        // tiene algo. El resto de la pagina —foto, papeles, encuesta— no cambia
        // con el periodo, y por eso va como parametro de consulta y no en el
        // camino: lo que se mueve es una seccion, no la pantalla.
        $esEstudiante = $perfil->rol === 'estudiante';
        $navegacion = ResumenAsistencia::navegacionDePeriodos(
            $perfil,
            $request->query('periodo'),
            $esEstudiante
        );
        $periodo = $navegacion['periodo'];

        return view('perfil.mi-perfil', [
            'perfil' => $perfil,
            'datos' => $datos,
            'encuesta' => $encuesta,
            // Una ranura por papel pedido, con lo que ya subio si es que subio
            // algo.
            'papeles' => $datos === null ? [] : DocumentoRequerido::activos()->ordenados()->get()
                ->map(fn (DocumentoRequerido $requerido) => [
                    'requerido' => $requerido,
                    'entrega' => $datos->documentos
                        ->first(fn (DocumentoEstudiante $d) => $d->requerido_id === $requerido->id
                            && $d->archivo !== ''),
                ])
                ->all(),
            // Vacia cuando no hay nada que pedir, asi que la plantilla la usa
            // tambien como "¿esta pendiente?". Sin encuesta empezada no se
            // listan preguntas sueltas: ahi lo que falta es la encuesta entera.
            'faltanPreguntas' => $encuesta?->preguntas_faltantes ?? [],
            'estadisticas' => $this->estadisticas($perfil),
            'periodo' => $periodo,
            // Las flechas del panel de asistencia. Conservan el resto de la URL
            // vacio a proposito: esta pantalla no tiene otros parametros.
            'periodoAtras' => $navegacion['atras']
                ? route('mi-perfil', ['periodo' => $navegacion['atras']->id])
                : null,
            'periodoAdelante' => $navegacion['adelante']
                ? route('mi-perfil', ['periodo' => $navegacion['adelante']->id])
                : null,
            'periodoEsElEnCurso' => $periodo !== null && $periodo->activo,
            // El mismo panel de asistencia que ve el personal en la ficha de una
            // persona, aqui puesto para que cada quien vea el SUYO: un estudiante
            // sus clases y sus faltas, quien dicta las que dio y cuantas le
            // verificaron. Es informacion sobre uno mismo y no habia ninguna
            // razon para que hubiera que pedirsela a otro.
            //
            // Sin acotar por promotoria: aqui no hay nada que esconderle a nadie
            // de lo suyo. El recorte de «solo mis promotorias» existe en la ficha
            // porque ahi mira un tercero.
            'asistencia' => $esEstudiante
                ? ResumenAsistencia::deEstudiante($perfil, $periodo)
                : ResumenAsistencia::deProfesor($perfil, $periodo),
        ]);
    }

    public function guardar(Request $request): RedirectResponse
    {
        $perfil = $request->user()->perfil;

        abort_if($perfil === null, 404);

        return match ($request->input('accion')) {
            'foto' => $this->guardarFoto($request, $perfil),
            'contacto' => $this->guardarContacto($request, $perfil),
            'correo' => $this->guardarCorreo($request, $perfil),
            'documento' => $this->guardarDocumento($request, $perfil),
            'papel' => $this->guardarPapel($request, $perfil),
            'encuesta' => $this->guardarEncuesta($request, $perfil),
            default => redirect()->route('mi-perfil'),
        };
    }

    private function guardarFoto(Request $request, Perfil $perfil): RedirectResponse
    {
        $request->validate([
            'foto_perfil' => ['required', 'image', 'mimes:'.implode(',', self::IMAGENES), 'max:8192'],
        ], [], ['foto_perfil' => 'foto de perfil']);

        // Se convierte a WebP, se endereza y se acota antes de tocar el disco
        // (ver `Imagen`). Es una diferencia deliberada con el original, que
        // guardaba el archivo tal como llegaba del celular.
        $contenido = Imagen::aWebp($request->file('foto_perfil'));
        $ruta = "fotos_perfil/{$perfil->id}-".uniqid().'.webp';

        Storage::disk('local')->put($ruta, $contenido);
        $this->borrarAnterior($perfil->foto_perfil);

        $perfil->foto_perfil = $ruta;
        $perfil->save();

        return redirect()->route('mi-perfil')->with('success', 'Tu foto de perfil quedó guardada.');
    }

    private function guardarContacto(Request $request, Perfil $perfil): RedirectResponse
    {
        $datos = $request->validate([
            'telefono' => ['required', 'string', 'max:15'],
        ]);

        $perfil->telefono = $datos['telefono'];
        $perfil->save();

        return redirect()->route('mi-perfil')->with('success', 'Tu teléfono quedó actualizado.');
    }

    /**
     * El correo, que es opcional y vive en la CUENTA y no en el perfil.
     *
     * La asimetria con el telefono no es un descuido: el telefono es un dato de
     * la persona y el correo es de la credencial —esta en `users` desde el
     * principio, reservado para poder recuperar una clave algun dia—, y hasta
     * hoy nada lo escribia.
     *
     * Opcional, y esa es la decision de fondo: buena parte de quien se inscribe
     * aqui son menores que no tienen correo propio. Exigirlo obligaria a
     * inventarse uno por cada uno, que es justamente lo que la tabla evita
     * autenticando por `username`.
     *
     * Y NO se exige unico, igual que en el esquema. Dos hermanos matriculados
     * comparten el correo de su acudiente, y ese caso es corriente en una casa
     * de la cultura; un indice unico lo convertiria en un error que la familia
     * no sabria como resolver. La contrapartida es que el correo no sirve por si
     * solo para identificar una cuenta: el dia que haya recuperacion de clave
     * tendra que pedir tambien el usuario.
     */
    private function guardarCorreo(Request $request, Perfil $perfil): RedirectResponse
    {
        $datos = $request->validate([
            'correo' => ['nullable', 'email', 'max:255'],
        ], [], ['correo' => 'correo electrónico']);

        $user = $perfil->user;
        // Vacio se guarda como null y no como cadena: «sin correo» es la
        // ausencia del dato, y dejar '' obligaria a comprobar las dos cosas en
        // cada sitio que lo lea.
        $user->email = $datos['correo'] ?: null;
        $user->save();

        return redirect()->route('mi-perfil')->with(
            'success',
            $user->email === null ? 'Tu correo quedó vacío.' : 'Tu correo quedó actualizado.'
        );
    }

    private function guardarDocumento(Request $request, Perfil $perfil): RedirectResponse
    {
        $datos = $perfil->datosEstudiante;

        abort_if($datos === null, 404);

        $request->validate([
            'copia_documento' => ['required', 'file', 'mimes:'.implode(',', self::ARCHIVOS), 'max:8192'],
        ], [], ['copia_documento' => 'copia del documento']);

        // La copia del documento se guarda TAL CUAL llega, sin pasar por
        // `Imagen`: puede ser un PDF, y aunque sea una foto es evidencia de un
        // tramite. Reescribirla la convierte en otra cosa.
        $ruta = $request->file('copia_documento')->store('documentos', 'local');
        $this->borrarAnterior($datos->copia_documento);

        $datos->copia_documento = $ruta;
        $datos->save();

        return redirect()->route('mi-perfil')->with('success', 'Tu documento quedó guardado.');
    }

    private function guardarPapel(Request $request, Perfil $perfil): RedirectResponse
    {
        $datos = $perfil->datosEstudiante;

        abort_if($datos === null, 404);

        $request->validate([
            'documento_id' => ['required', Rule::exists('documentos_requeridos', 'id')->where('activo', true)],
            'archivo' => ['required', 'file', 'mimes:'.implode(',', self::ARCHIVOS), 'max:8192'],
        ], [
            'archivo.required' => 'Elige un archivo antes de subirlo.',
        ], ['archivo' => 'archivo']);

        $requerido = DocumentoRequerido::findOrFail($request->input('documento_id'));

        $entrega = DocumentoEstudiante::firstOrNew([
            'datos_estudiante_id' => $datos->id,
            'requerido_id' => $requerido->id,
        ]);

        $this->borrarAnterior($entrega->archivo);
        $entrega->archivo = $request->file('archivo')->store('documentos', 'local');
        $entrega->save();

        return redirect()->route('mi-perfil')->with('success', "«{$requerido->nombre}» quedó guardado.");
    }

    private function guardarEncuesta(Request $request, Perfil $perfil): RedirectResponse
    {
        $reglas = [
            'genero' => ['required', Rule::in(array_keys(EncuestaDemografica::GENEROS))],
            'barrio' => ['required', 'string', 'max:60'],
            'estrato' => ['required', Rule::in(array_keys(EncuestaDemografica::ESTRATOS))],
            'nivel_educativo' => ['required', Rule::in(array_keys(EncuestaDemografica::NIVELES_EDUCATIVOS))],
            'ocupacion' => ['required', Rule::in(array_keys(EncuestaDemografica::OCUPACIONES))],
            'zona' => ['nullable', Rule::in(array_keys(EncuestaDemografica::ZONAS))],
            'afiliacion_salud' => ['nullable', Rule::in(array_keys(EncuestaDemografica::AFILIACIONES_SALUD))],
            'grupo_etnico' => ['nullable', Rule::in(array_keys(EncuestaDemografica::GRUPOS_ETNICOS))],
            'discapacidad' => ['nullable', Rule::in(array_keys(EncuestaDemografica::DISCAPACIDADES))],
            'victima_conflicto_armado' => ['nullable', Rule::in(array_keys(EncuestaDemografica::VICTIMAS_CONFLICTO))],
        ];

        // A un menor de edad ni siquiera se le pinta la casilla: la autorizacion
        // de tratamiento de datos la da su acudiente, y admitirla aqui seria
        // recoger un consentimiento que la ley no reconoce.
        if (! $perfil->es_menor) {
            $reglas['autoriza_tratamiento_datos'] = ['nullable', 'boolean'];
        }

        $datos = $request->validate($reglas);

        $encuesta = $perfil->encuesta ?? new EncuestaDemografica(['perfil_id' => $perfil->id]);
        $encuesta->fill(array_map(fn ($v) => $v ?? '', $datos));

        if (! $perfil->es_menor) {
            $autoriza = $request->boolean('autoriza_tratamiento_datos');
            $encuesta->autoriza_tratamiento_datos = $autoriza;

            // La fecha marca CUANDO se dio el consentimiento y no se refresca en
            // cada guardado: si se pisara, la constancia diria la fecha de la
            // ultima vez que alguien toco el formulario.
            if ($autoriza && $encuesta->fecha_autorizacion === null) {
                $encuesta->fecha_autorizacion = now();
            } elseif (! $autoriza) {
                $encuesta->fecha_autorizacion = null;
            }
        }

        $encuesta->perfil_id = $perfil->id;
        $encuesta->save();

        return redirect()->route('mi-perfil')->with('success', 'Tu encuesta quedó guardada.');
    }

    /**
     * Borra el archivo que se reemplaza.
     *
     * El original dejaba el anterior en disco —Django tampoco lo borra solo— y
     * en hosting compartido eso se acumula: cada foto nueva dejaba la vieja
     * ocupando sitio para siempre.
     */
    private function borrarAnterior(?string $ruta): void
    {
        if ($ruta !== null && $ruta !== '') {
            Storage::disk('local')->delete($ruta);
        }
    }

    /**
     * Las cifras de la tarjeta, segun el rol.
     *
     * @return list<array{numero: int, etiqueta: string}>
     */
    private function estadisticas(Perfil $perfil): array
    {
        if ($perfil->rol === 'estudiante') {
            $activas = Matricula::where('estudiante_id', $perfil->id)
                ->where('estado', Matricula::ACTIVA)
                ->get(['promotoria_id', 'periodo_id']);

            // "Companero" = misma promotoria Y periodo, con matricula activa; la
            // misma regla que «Mis companeros».
            $companeros = collect();

            foreach ($activas as $matricula) {
                $companeros = $companeros->merge(
                    Matricula::where('promotoria_id', $matricula->promotoria_id)
                        ->where('periodo_id', $matricula->periodo_id)
                        ->where('estado', Matricula::ACTIVA)
                        ->where('estudiante_id', '!=', $perfil->id)
                        ->pluck('estudiante_id')
                );
            }

            return [
                ['numero' => $activas->count(), 'etiqueta' => 'Matrículas activas'],
                ['numero' => $companeros->unique()->count(), 'etiqueta' => 'Compañeros'],
            ];
        }

        if ($perfil->rol === 'profesor') {
            return [
                [
                    'numero' => Promotoria::where('profesor_id', $perfil->id)->count(),
                    'etiqueta' => 'Promotorías a cargo',
                ],
                [
                    'numero' => Grupo::whereHas('promotoria', fn ($q) => $q->where('profesor_id', $perfil->id))->count(),
                    'etiqueta' => 'Grupos',
                ],
            ];
        }

        if (in_array($perfil->rol, ['director', 'administrador'], true)) {
            $cifras = [
                ['numero' => Promotoria::count(), 'etiqueta' => 'Promotorías'],
                ['numero' => Perfil::count(), 'etiqueta' => 'Usuarios'],
            ];

            // Un director puede ademas dictar. Cuando lo hace, sus dos cifras de
            // direccion no cuentan lo suyo por ninguna parte: se le suma la que
            // si, y solo entonces — a quien no dicta no se le ensena un cero que
            // no significa nada.
            $aCargo = Promotoria::where('profesor_id', $perfil->id)->count();

            if ($aCargo) {
                $cifras[] = ['numero' => $aCargo, 'etiqueta' => 'Promotorías a cargo'];
            }

            return $cifras;
        }

        return [];
    }
}
