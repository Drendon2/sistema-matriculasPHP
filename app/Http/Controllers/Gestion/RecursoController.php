<?php

namespace App\Http\Controllers\Gestion;

use App\Http\Controllers\Controller;
use App\Support\Dependencias;
use App\Support\ErrorDeBaseDeDatos;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * El esqueleto compartido de los cuatro catalogos de Gestion: departamentos,
 * periodos, promotorias y grupos.
 *
 * Es el equivalente de las vistas genericas de Django (`ListView`,
 * `CreateView`, `UpdateView`, `DeleteView`) con su plantilla comun. Las cuatro
 * pantallas hacen exactamente lo mismo y solo cambian en el modelo, los campos y
 * a donde vuelven; escribirlas cuatro veces era garantizar que se separaran.
 *
 * Cada subclase declara su configuracion y, si le hace falta, redefine
 * `urlExito()` — las promotorias y los grupos vuelven a su padre en la jerarquia
 * y no al listado plano.
 */
abstract class RecursoController extends Controller
{
    /** @return class-string<Model> */
    abstract protected function modelo(): string;

    /**
     * Textos y rutas de esta pantalla.
     *
     * @return array{
     *     titulo: string,
     *     titulo_nuevo: string,
     *     titulo_editar: string,
     *     ruta_lista: string,
     *     ruta_nuevo: string,
     *     ruta_editar: string,
     *     ruta_eliminar: string,
     *     creado: string,
     *     actualizado: string,
     * }
     */
    abstract protected function textos(): array;

    /** @return array<string, mixed> lo que la vista de listado necesita ademas */
    abstract protected function listado(Request $request): array;

    /** @return array<string, array<int, mixed>> reglas de validacion */
    abstract protected function reglas(Request $request, ?Model $objeto): array;

    /**
     * Los campos del formulario, tal como hay que pintarlos.
     *
     * Se declaran aqui y no en la plantilla —que es una sola para los cuatro
     * catalogos— por la misma razon que en Django los declaraba el ModelForm:
     * asi la pantalla no tiene que preguntar de que modelo se trata para saber
     * que dibujar.
     *
     * Cada entrada es `campo => [etiqueta, tipo, ...]`, donde `tipo` es uno de
     * text, date, number o select. Un select trae ademas `opciones` y, si admite
     * quedarse en blanco, `vacio`.
     *
     * @return array<string, array<string, mixed>>
     */
    abstract protected function campos(Request $request, ?Model $objeto): array;

    protected function urlExito(Model $objeto): string
    {
        return route($this->textos()['ruta_lista']);
    }

    /**
     * Resuelve el registro de la URL.
     *
     * Se hace a mano y no con el enlace implicito de Laravel porque estos cuatro
     * catalogos comparten los mismos metodos: el tipo del parametro tendria que
     * ser `Model`, que es abstracto, y de ahi Laravel no puede deducir que tabla
     * consultar. La alternativa era repetir los cuatro metodos en cada subclase
     * solo para cambiar una firma.
     */
    private function buscar(string $id): Model
    {
        return $this->modelo()::findOrFail($id);
    }

    // -----------------------------------------------------------------------

    public function index(Request $request): View
    {
        return view('gestion.lista', [
            ...$this->textos(),
            ...$this->listado($request),
        ]);
    }

    public function crear(Request $request): View
    {
        $textos = $this->textos();

        return view('gestion.form', [
            'titulo' => $textos['titulo_nuevo'],
            'ruta_lista' => $textos['ruta_lista'],
            'accion' => route($textos['ruta_nuevo']),
            'objeto' => new ($this->modelo()),
            'campos' => $this->campos($request, null),
        ]);
    }

    public function guardar(Request $request): RedirectResponse
    {
        $modelo = $this->modelo();
        $datos = $request->validate($this->reglas($request, null));
        // Todo lo que pueda rechazar el formulario se comprueba ANTES de
        // escribir: si esto lanza, no queda un registro a medias en la base.
        $extra = $this->validarExtra($request, null);

        $objeto = new $modelo($datos);
        $objeto->save();
        $this->despuesDeGuardar($objeto, $extra);

        return redirect($this->urlExito($objeto))->with('success', $this->textos()['creado']);
    }

    public function editar(Request $request, string $id): View
    {
        $objeto = $this->buscar($id);

        $textos = $this->textos();

        return view('gestion.form', [
            'titulo' => $textos['titulo_editar'],
            'ruta_lista' => $textos['ruta_lista'],
            'accion' => route($textos['ruta_editar'], $objeto),
            'objeto' => $objeto,
            'campos' => $this->campos($request, $objeto),
        ]);
    }

    public function actualizar(Request $request, string $id): RedirectResponse
    {
        $objeto = $this->buscar($id);
        $datos = $request->validate($this->reglas($request, $objeto));
        $extra = $this->validarExtra($request, $objeto);

        $objeto->fill($datos);
        $objeto->save();
        $this->despuesDeGuardar($objeto, $extra);

        return redirect($this->urlExito($objeto))->with('success', $this->textos()['actualizado']);
    }

    /**
     * Comprueba lo que no cabe en `reglas()` y devuelve lo que haga falta para
     * guardarlo despues.
     *
     * Corre ANTES de escribir nada y puede lanzar `ValidationException`: es lo
     * que permite que un horario mal puesto rebote el formulario sin dejar
     * creado un grupo sin horas.
     */
    protected function validarExtra(Request $request, ?Model $objeto): mixed
    {
        return null;
    }

    /**
     * Guarda lo que va ADEMAS de las columnas del propio registro.
     *
     * Vacio para tres de los cuatro catalogos, que son columnas y nada mas. El
     * grupo lo usa para su horario, que vive en filas aparte. Corre con el
     * objeto ya guardado, que es cuando tiene id al que colgar esas filas, y
     * recibe lo que `validarExtra()` ya dio por bueno.
     */
    protected function despuesDeGuardar(Model $objeto, mixed $extra): void
    {
        //
    }

    /**
     * La pantalla de confirmacion, que dice la verdad antes de preguntar.
     *
     * Si algo bloquea el borrado no se ofrece boton: preguntar «¿seguro?» para
     * negarse despues es hacer perder el viaje, y la respuesta ya se sabe.
     */
    public function confirmarBorrado(string $id): View
    {
        $objeto = $this->buscar($id);

        return view('gestion.confirma-borrado', [
            'objeto' => $objeto,
            'ruta_lista' => $this->textos()['ruta_lista'],
            'accion' => route($this->textos()['ruta_eliminar'], $objeto),
            ...Dependencias::de($objeto),
        ]);
    }

    public function eliminar(string $id): RedirectResponse
    {
        $objeto = $this->buscar($id);

        // El destino se calcula ANTES de borrar: despues, `$objeto->area_id` y
        // compania siguen en memoria pero la fila ya no esta, y las subclases que
        // vuelven al padre necesitan ese dato intacto.
        $destino = $this->urlExito($objeto);

        try {
            $objeto->delete();
        } catch (QueryException $e) {
            if (! ErrorDeBaseDeDatos::esFilaEnUso($e)) {
                throw $e;
            }

            // La pantalla ya avisa antes de preguntar, asi que aqui no deberia
            // llegar nadie. Se queda igual porque el boton no es la unica forma
            // de enviar este POST, y porque entre que se pinta la pagina y se
            // pulsa puede entrar una matricula nueva.
            return redirect($destino)->with('error', Dependencias::avisoDeProtegido($objeto));
        }

        return redirect($destino)->with('success', 'Eliminado.');
    }
}
