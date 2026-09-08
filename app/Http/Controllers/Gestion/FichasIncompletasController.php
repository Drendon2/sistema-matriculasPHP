<?php

namespace App\Http\Controllers\Gestion;

use App\Http\Controllers\Controller;
use App\Models\Promotoria;
use App\Support\FichasIncompletas;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\View\View;

/**
 * LA TERCERA BANDEJA DE ALERTAS: a quien le falta algo por completar.
 *
 * Pantalla propia y no una seccion mas de Alertas, y esa es una decision que
 * salio de MEDIR y no de gusto: en produccion son ~810 personas de 839. Metidas
 * en la bandeja la convertirian en un muro, que es exactamente lo que ya paso
 * con las 596 clases no dictadas. Desde Alertas se entra por un boton que dice
 * cuantas son; el porque de cada motivo esta en `Support\FichasIncompletas`.
 *
 * Dos filtros, y los dos existen por un uso concreto:
 *
 * - Por MOTIVO, porque sin el quien busca al unico menor sin acudiente tendria
 *   que recorrer 804 consentimientos sin subir.
 * - Por PROMOTORIA, que lo pidio el usuario para poder sacar la lista de una y
 *   pedirle a SU profesor que persiga esos datos. Por eso la pantalla ensena
 *   ademas quien la dicta: la lista sin el nombre de a quien mandarsela no
 *   sirve para lo que se pidio.
 *
 * @phpstan-import-type FichaIncompleta from FichasIncompletas
 */
class FichasIncompletasController extends Controller
{
    /**
     * Cuantas fichas por pagina.
     *
     * Cincuenta como en el resto del sistema. Aqui pesa mas que en otras
     * pantallas: sin paginar esto son ochocientas filas en una respuesta, en un
     * hosting compartido y casi siempre en un telefono.
     */
    public const POR_PAGINA = 50;

    public function index(Request $request): View
    {
        $todas = FichasIncompletas::todas();
        $porMotivo = FichasIncompletas::porMotivo($todas);

        $motivo = (string) $request->query('motivo', '');
        $promotoria = $request->query('promotoria');
        $promotoria = is_numeric($promotoria) ? (int) $promotoria : null;

        $filtradas = $this->filtrar($todas, $motivo, $promotoria);

        return view('gestion.fichas-incompletas', [
            'fichas' => $this->paginar($filtradas, $request),
            'total' => count($todas),
            'filtradas' => count($filtradas),
            'porMotivo' => $porMotivo,
            'motivos' => FichasIncompletas::MOTIVOS,
            // Un motivo inventado en la URL no filtra nada, en vez de dejar la
            // lista vacia: una pantalla en blanco se lee como «no falta nada»,
            // que es justo lo contrario de lo que pasa.
            'motivo' => array_key_exists($motivo, FichasIncompletas::MOTIVOS) ? $motivo : '',
            'promotoria' => $promotoria,
            // Con su profesor, que es a quien va dirigida la lista filtrada.
            'promotorias' => Promotoria::with(['area', 'profesor'])
                ->join('areas', 'areas.id', '=', 'promotorias.area_id')
                ->orderBy('areas.nombre')
                ->orderBy('promotorias.nombre')
                ->select('promotorias.*')
                ->get(),
            'hayFiltros' => $motivo !== '' || $promotoria !== null,
        ]);
    }

    /**
     * @param  list<FichaIncompleta>  $todas
     * @return list<FichaIncompleta>
     */
    private function filtrar(array $todas, string $motivo, ?int $promotoria): array
    {
        if (array_key_exists($motivo, FichasIncompletas::MOTIVOS)) {
            $todas = array_filter($todas, fn (array $f) => in_array($motivo, $f['motivos'], true));
        }

        if ($promotoria !== null) {
            $todas = array_filter($todas, function (array $f) use ($promotoria) {
                foreach ($f['promotorias'] as $vinculo) {
                    if ($vinculo['id'] === $promotoria) {
                        return true;
                    }
                }

                return false;
            });
        }

        return array_values($todas);
    }

    /**
     * Pagina una lista ya calculada.
     *
     * A mano y no con `->paginate()` porque esto no sale de una consulta: los
     * ocho motivos se cruzan en memoria, asi que no hay `Builder` al que
     * pedirselo. `withQueryString()` conserva los dos filtros en los enlaces —
     * sin el, pasar de pagina los limpia y la pagina 2 es la de TODO el mundo,
     * que es el mismo fallo que ya se corrigio en el listado de usuarios.
     *
     * El tipo de vuelta se deja SIN genericos a proposito. `LengthAwarePaginator`
     * es invariante en su tipo de valor, asi que declararlo con la forma exacta
     * de una ficha lo rechaza aunque el contenido sea justo esa forma —PHPStan
     * imprime los dos lados identicos y sigue diciendo que no cuadran—. Quien
     * necesite saber que hay dentro lo tiene escrito en `filtrar()`, que si
     * conserva la forma.
     *
     * @param  list<FichaIncompleta>  $filas
     * @return LengthAwarePaginator<int, mixed>
     */
    private function paginar(array $filas, Request $request): LengthAwarePaginator
    {
        $pagina = LengthAwarePaginator::resolveCurrentPage();

        /** @var LengthAwarePaginator<int, mixed> $paginador */
        $paginador = (new LengthAwarePaginator(
            collect(array_slice($filas, ($pagina - 1) * self::POR_PAGINA, self::POR_PAGINA)),
            count($filas),
            self::POR_PAGINA,
            $pagina,
            ['path' => $request->url()],
        ))->withQueryString();

        return $paginador;
    }
}
