<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Acudiente;
use App\Models\DatosEstudiante;
use App\Models\Matricula;
use App\Models\Perfil;
use App\Models\Periodo;
use App\Models\Promotoria;
use App\Models\User;
use App\Support\ErrorDeBaseDeDatos;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Autorregistro de estudiante: crea la cuenta Y la inscribe a una o varias
 * promotorias de una vez.
 *
 * Las matriculas nacen 'pendiente': quien dicta la promotoria debe confirmarlas
 * antes de asignar grupo.
 *
 * NO pide foto de perfil ni copia del documento. Por seguridad, los archivos no
 * se suben desde un formulario publico sin autenticar; el estudiante los sube
 * despues, ya con sesion, en "Mi perfil". Eso NO bloquea que le confirmen la
 * matricula mientras tanto.
 */
class InscripcionController extends Controller
{
    public function mostrar(): View
    {
        $periodo = Periodo::enCurso();

        return view('auth.inscripcion', [
            'periodo' => $periodo,
            'matriculasAbiertas' => $periodo !== null && $periodo->matriculas_abiertas,
            'limite' => Matricula::limitePromotorias(),
            'catalogo' => $this->catalogo(),
        ]);
    }

    public function guardar(Request $request): RedirectResponse
    {
        $periodo = Periodo::enCurso();

        // La inscripcion publica solo existe mientras las matriculas esten
        // abiertas. Se comprueba en el POST y no solo al pintar el formulario:
        // esconder el formulario no cierra la URL.
        if ($periodo === null || ! $periodo->matriculas_abiertas) {
            return redirect()->route('inscripcion')->with(
                'error',
                'Las matrículas están cerradas en este momento.'
            );
        }

        $limite = Matricula::limitePromotorias();
        $datos = $this->validar($request, $limite);
        $elegidas = $this->promotoriasElegidas($request, $limite);

        try {
            DB::transaction(function () use ($datos, $elegidas, $periodo) {
                $user = User::create([
                    'username' => $datos['username'],
                    'password' => $datos['password'],
                    'activo' => true,
                ]);

                $perfil = Perfil::create([
                    'user_id' => $user->id,
                    'rol' => 'estudiante',
                    'nombre_completo' => $datos['nombre_completo'],
                    'fecha_nacimiento' => $datos['fecha_nacimiento'],
                    'telefono' => $datos['telefono'],
                ]);

                $acudiente = null;

                if (! empty($datos['acudiente_nombre'])) {
                    $acudiente = Acudiente::create([
                        'nombre' => $datos['acudiente_nombre'],
                        'telefono' => $datos['acudiente_telefono'] ?? '',
                    ]);
                }

                $datosEstudiante = new DatosEstudiante([
                    'perfil_id' => $perfil->id,
                    'documento_identidad' => $datos['documento_identidad'],
                    'acudiente_id' => $acudiente?->id,
                ]);
                $datosEstudiante->setRelation('perfil', $perfil);
                $datosEstudiante->validar();
                $datosEstudiante->save();

                foreach ($elegidas as $promotoria) {
                    $matricula = new Matricula([
                        'estudiante_id' => $perfil->id,
                        'promotoria_id' => $promotoria->id,
                        'periodo_id' => $periodo->id,
                        'estado' => Matricula::PENDIENTE,
                    ]);
                    $matricula->validar();
                    $matricula->save();
                }
            });
        } catch (ValidationException $e) {
            return back()->withInput()->withErrors($e->validator->errors());
        } catch (QueryException $e) {
            // La transaccion ya se deshizo entera: no queda ni la cuenta.
            return back()->withInput()->with('error', $this->mensajeDeConflicto($e));
        }

        return redirect()->route('login')->with('success', $this->mensajeDeExito($elegidas));
    }

    /**
     * @return array<string, mixed>
     */
    private function validar(Request $request, int $limite): array
    {
        $reglas = [
            'username' => ['required', 'string', 'max:150', Rule::unique('users', 'username')],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
            'nombre_completo' => ['required', 'string', 'max:90'],
            'fecha_nacimiento' => ['required', 'date', 'before:today'],
            'telefono' => ['required', 'string', 'max:15'],
            'documento_identidad' => [
                'required', 'string', 'max:15',
                Rule::unique('datos_estudiante', 'documento_identidad'),
            ],
            'acudiente_nombre' => ['nullable', 'string', 'max:90'],
            'acudiente_telefono' => ['nullable', 'string', 'max:15'],
            'promotoria' => ['required', Rule::exists('promotorias', 'id')],
        ];

        // Tantos cupos como permita la configuracion: subir el limite anade
        // selectores sin tocar el codigo. Solo el primero es obligatorio.
        for ($n = 2; $n <= $limite; $n++) {
            $reglas["promotoria_{$n}"] = ['nullable', Rule::exists('promotorias', 'id')];
        }

        $validador = validator($request->all(), $reglas, [
            'username.unique' => 'Ya existe una cuenta con ese nombre de usuario.',
            'password.confirmed' => 'Las contraseñas no coinciden.',
            'documento_identidad.unique' => 'Ya existe un estudiante registrado con ese documento de identidad.',
            'promotoria.required' => 'Elige al menos una promotoría.',
        ], [
            'username' => 'usuario',
            'nombre_completo' => 'nombre completo',
            'fecha_nacimiento' => 'fecha de nacimiento',
            'documento_identidad' => 'documento de identidad',
        ]);

        $validador->after(function ($validador) use ($request, $limite) {
            $this->comprobarAcudienteDeMenor($validador, $request);
            $this->comprobarPromotoriasSinRepetir($validador, $request, $limite);
        });

        return $validador->validate();
    }

    /**
     * Un menor de edad necesita acudiente ya en la inscripcion.
     *
     * Se comprueba aqui ademas de en `DatosEstudiante::validar()` para poder
     * senalar el CAMPO concreto que hay que rellenar, que es lo que el
     * formulario necesita para marcarlo en rojo.
     */
    private function comprobarAcudienteDeMenor($validador, Request $request): void
    {
        $nacimiento = $request->input('fecha_nacimiento');

        if (! $nacimiento) {
            return;
        }

        try {
            $fecha = Carbon::parse($nacimiento);
        } catch (\Throwable) {
            return;
        }

        if (Perfil::edadDe($fecha) >= 18) {
            return;
        }

        if (! $request->filled('acudiente_nombre')) {
            $validador->errors()->add(
                'acudiente_nombre',
                'Eres menor de edad: necesitas registrar el nombre de tu acudiente.'
            );
        }

        // El telefono es tan obligatorio como el nombre, y por una razon
        // concreta: el acudiente de un menor no esta ahi para figurar en una
        // ficha, sino para que la institucion pueda llamarlo — al resolver una
        // cancelacion, al hacer seguimiento de una mala experiencia, o si pasa
        // algo en clase. Un acudiente sin telefono no sirve para ninguna de las
        // tres.
        if (! $request->filled('acudiente_telefono')) {
            $validador->errors()->add(
                'acudiente_telefono',
                'Falta el teléfono de tu acudiente: es el número al que llamaría la institución.'
            );
        }
    }

    /**
     * Ninguna promotoria puede repetirse entre cupos.
     *
     * El error se marca en el cupo REPETIDO, que es el que la persona tiene que
     * corregir, y no en el primero que la eligio.
     */
    private function comprobarPromotoriasSinRepetir($validador, Request $request, int $limite): void
    {
        $vistas = [];

        foreach ($this->nombresCampos($limite) as $campo) {
            $valor = $request->input($campo);

            if (! $valor) {
                continue;
            }

            if (in_array($valor, $vistas, true)) {
                $promotoria = Promotoria::with('area')->find($valor);
                $validador->errors()->add(
                    $campo,
                    "Ya elegiste {$promotoria} en otro cupo. Escoge una distinta o deja este vacío."
                );
            }

            $vistas[] = $valor;
        }
    }

    /**
     * Las promotorias escogidas, en orden de cupo y sin repetir.
     *
     * @return list<Promotoria>
     */
    private function promotoriasElegidas(Request $request, int $limite): array
    {
        $ids = [];

        foreach ($this->nombresCampos($limite) as $campo) {
            $valor = $request->input($campo);

            if ($valor && ! in_array($valor, $ids, true)) {
                $ids[] = $valor;
            }
        }

        $promotorias = Promotoria::with('area')->findMany($ids)->keyBy('id');

        // Se respeta el orden de los cupos, no el que devuelva la base de datos:
        // la primera elegida es la principal y es la que nombra el mensaje.
        return array_values(array_filter(array_map(
            fn ($id) => $promotorias->get($id),
            $ids
        )));
    }

    /** @return list<string> */
    private function nombresCampos(int $limite): array
    {
        $campos = ['promotoria'];

        for ($n = 2; $n <= $limite; $n++) {
            $campos[] = "promotoria_{$n}";
        }

        return $campos;
    }

    private function catalogo()
    {
        return Promotoria::with('area')
            ->join('areas', 'areas.id', '=', 'promotorias.area_id')
            ->orderBy('areas.nombre')
            ->orderBy('promotorias.nombre')
            ->select('promotorias.*')
            ->get();
    }

    private function mensajeDeConflicto(QueryException $e): string
    {
        if (ErrorDeBaseDeDatos::esCupoAgotado($e)) {
            return 'Una de las promotorías que elegiste se llenó mientras enviabas el '
                . 'formulario. No se creó la cuenta: vuelve a intentarlo eligiendo otra.';
        }

        return 'Ya existe una cuenta con ese usuario, o un estudiante con ese documento de identidad.';
    }

    /** @param list<Promotoria> $elegidas */
    private function mensajeDeExito(array $elegidas): string
    {
        $nombres = implode(', ', array_map(fn (Promotoria $p) => (string) $p, $elegidas));
        $varias = count($elegidas) > 1;

        return 'Tu cuenta quedó creada y '
            . ($varias ? 'tus inscripciones a' : 'tu inscripción a')
            . " {$nombres} "
            . ($varias ? 'están' : 'está')
            . ' pendiente' . ($varias ? 's' : '')
            . ' de confirmación del profesor. '
            . 'Inicia sesión y ve a "Mi perfil" para subir tu foto y tu documento.';
    }
}
