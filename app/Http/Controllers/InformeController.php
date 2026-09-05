<?php

namespace App\Http\Controllers;

use App\Models\Area;
use App\Models\EncuestaDemografica;
use App\Models\Grupo;
use App\Models\Matricula;
use App\Models\Perfil;
use App\Models\Periodo;
use App\Models\Promotoria;
use App\Support\Csv;
use App\Support\Permisos;
use Generator;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Los informes descargables.
 *
 * Son dos y tienen alcances MUY distintos, asi que van con puertas distintas:
 *
 * - El de estudiantes por grupo es operativo: la lista que se lleva quien dicta
 *   para pasar asistencia en papel o para llamar a una familia. Lo baja el
 *   personal, y un profesor solo el de las promotorias que dicta.
 * - El de la institucion es el completo, con la encuesta demografica NOMINAL.
 *   Solo el administrador. Ver el aviso largo en `institucion()`.
 */
class InformeController extends Controller
{
    /**
     * Cuantas filas se traen por tanda.
     *
     * Es constante y no un numero suelto porque hay una prueba que necesita
     * superarla: el fallo que se arreglo aqui —tandas que se solapan y repiten
     * filas— solo aparece cuando el informe pasa de una tanda, y con cuatro
     * filas de prueba no se veia.
     */
    private const POR_TANDA = 100;

    /**
     * Estudiantes por promotoria y grupo, con su contacto.
     *
     * Sigue la misma matriz de visibilidad que la ficha: edad, telefono y
     * acudiente son para direccion, y para el profesor SOLO de sus promotorias.
     * Por eso el filtro no es un adorno del listado sino la propia puerta — un
     * profesor que pida el informe entero recibe unicamente lo suyo.
     *
     * Se acota al periodo en curso: la lista que se pide aqui es la de quien
     * esta yendo a clase ahora, no el historico. El historico de una persona
     * vive en su ficha.
     *
     * Se puede pedir entero, de una promotoria o de UN GRUPO. Lo de un grupo es
     * lo que de verdad se lleva impreso al salon: la lista de a quien le toca
     * ese horario. Con el informe entero encima, quien dicta tendria que buscar
     * sus veinte filas entre trescientas.
     */
    public function estudiantes(Request $request): StreamedResponse
    {
        /** @var Perfil $perfil */
        $perfil = $request->attributes->get('perfil');
        $periodo = Periodo::enCurso();
        $grupo = $this->grupoPedido($request, $perfil);
        $promotoria = $this->promotoriaPedida($request, $perfil, $grupo);

        $consulta = Matricula::query()
            ->whereIn('estado', Matricula::ESTADOS_INSCRITO)
            ->when($periodo, fn ($q) => $q->where('periodo_id', $periodo->id))
            // Sin periodo en curso no hay lista que dar: se devuelve vacio en
            // vez de barrer el historico entero.
            ->when($periodo === null, fn ($q) => $q->whereRaw('1 = 0'))
            ->when(
                $perfil->rol === 'profesor',
                fn ($q) => $q->whereIn(
                    'promotoria_id',
                    Promotoria::where('profesor_id', $perfil->id)->select('id')
                )
            )
            ->when($promotoria, fn ($q) => $q->where('promotoria_id', $promotoria->id))
            ->when($grupo, fn ($q) => $q->where('grupo_id', $grupo->id))
            ->with([
                'estudiante.datosEstudiante.acudiente',
                'promotoria.area',
                // Con las sesiones: el horario se deriva de ellas, y sin
                // traerlas aqui el informe pregunta una vez por fila. Este
                // informe se recorre con `lazy()` y puede ser de cientos.
                'grupo.sesiones',
            ])
            ->join('promotorias', 'promotorias.id', '=', 'matriculas.promotoria_id')
            ->join('areas', 'areas.id', '=', 'promotorias.area_id')
            ->join('perfiles', 'perfiles.id', '=', 'matriculas.estudiante_id')
            ->orderBy('areas.nombre')
            ->orderBy('promotorias.nombre')
            ->orderBy('perfiles.nombre_completo')
            ->select('matriculas.*');

        return Csv::descargar($this->nombreDelArchivo($promotoria, $grupo), [
            'Departamento',
            'Promotoría',
            'Grupo',
            'Nivel',
            'Horario',
            'Salón',
            'Estudiante',
            'Edad',
            'Teléfono',
            'Acudiente',
            'Teléfono del acudiente',
            'Estado',
        ], $this->filasDeEstudiantes($consulta));
    }

    /**
     * El grupo que se pide, si se pide, y solo si esta persona puede verlo.
     *
     * Un 404 y no una lista vacia: pedir el grupo de una promotoria ajena tiene
     * que decir que no, no devolver un archivo con la cabecera y ninguna fila.
     * Lo segundo se lee como «ese grupo esta vacio», que es una respuesta falsa
     * a una pregunta que no correspondia hacer.
     *
     * La regla es la misma de siempre: direccion cualquiera, quien dicta solo
     * las suyas.
     */
    private function grupoPedido(Request $request, Perfil $perfil): ?Grupo
    {
        $id = $request->query('grupo');

        if (! $id) {
            return null;
        }

        $grupo = Grupo::with('promotoria')->find($id);

        abort_if($grupo === null, 404);
        abort_unless(Permisos::puedeGestionarPromotoria($perfil, $grupo->promotoria), 404);

        return $grupo;
    }

    /**
     * La promotoria que se pide, con la misma puerta.
     *
     * Si ya vino un grupo, manda el suyo: pedir a la vez el grupo A y la
     * promotoria B es una peticion incoherente, y quedarse con la promotoria del
     * grupo es la unica lectura que no devuelve algo vacio sin explicacion.
     */
    private function promotoriaPedida(Request $request, Perfil $perfil, ?Grupo $grupo): ?Promotoria
    {
        if ($grupo !== null) {
            return $grupo->promotoria;
        }

        $id = $request->query('promotoria');

        if (! $id) {
            return null;
        }

        $promotoria = Promotoria::find($id);

        abort_if($promotoria === null, 404);
        abort_unless(Permisos::puedeGestionarPromotoria($perfil, $promotoria), 404);

        return $promotoria;
    }

    /**
     * Como se llama el archivo.
     *
     * Lleva el nombre de la promotoria y del grupo porque quien dicta se baja
     * tres listas seguidas, una por horario, y con el mismo nombre las tres el
     * navegador las guarda como «(1)» y «(2)»: para saber cual es cual hay que
     * abrirlas. Con el nombre dentro se distinguen en la carpeta de descargas.
     */
    private function nombreDelArchivo(?Promotoria $promotoria, ?Grupo $grupo): string
    {
        // Sin acotar sigue siendo «por grupo», que es como esta organizado.
        if ($promotoria === null) {
            return 'estudiantes-por-grupo';
        }

        $partes = ['estudiantes', $promotoria->nombre];

        if ($grupo !== null) {
            $partes[] = $grupo->nombre;
        }

        return Str::slug(implode(' ', $partes)) ?: 'estudiantes-por-grupo';
    }

    /**
     * @return Generator<int, list<string>>
     */
    private function filasDeEstudiantes($consulta): Generator
    {
        // Por tandas y no de una: el informe puede ser de cientos de filas con
        // cuatro relaciones cargadas cada una, y en hosting compartido traerlas
        // todas a memoria a la vez es justo lo que no conviene.
        //
        // `lazy` y NO `lazyById`. Este informe va ordenado por departamento,
        // promotoria y nombre, y `lazyById` pagina preguntando por el id mayor
        // que el ultimo devuelto: con otro orden, «el ultimo» es un id
        // cualquiera, las tandas se solapan y las filas se repiten. Costo 4961
        // filas para 302 matriculas, y no se veia en las pruebas porque con
        // pocos datos todo cabe en la primera tanda.
        foreach ($consulta->lazy(self::POR_TANDA) as $matricula) {
            $datos = $matricula->estudiante->datosEstudiante;
            $acudiente = $datos?->acudiente;

            yield array_map(Csv::celda(...), [
                $matricula->promotoria->area->nombre,
                $matricula->promotoria->nombre,
                $matricula->grupo?->nombre ?? 'Sin grupo',
                $matricula->grupo?->nivel_display,
                $matricula->grupo?->horario,
                $matricula->grupo?->salon,
                $matricula->estudiante->nombre_completo,
                $matricula->estudiante->edad,
                $matricula->estudiante->telefono,
                $acudiente?->nombre,
                $acudiente?->telefono,
                Matricula::ESTADOS[$matricula->estado] ?? $matricula->estado,
            ]);
        }
    }

    /**
     * El informe completo de la institucion. SOLO ADMINISTRADOR.
     *
     * Lleva la encuesta demografica con NOMBRE Y APELLIDO, y eso invierte a
     * proposito lo que el sistema garantizaba hasta ahora: la encuesta se recogia
     * agregada y anonima, y `EstadisticasController` explica por que —una
     * encuesta con nombre en un tablero se convierte en un marcador, y la vez
     * siguiente la gente contesta pensando en quien va a leerla.
     *
     * Se hace porque la institucion lo necesita para reportar a quien la
     * financia, y se acota todo lo que se puede: la descarga es del
     * administrador y de nadie mas —la misma puerta que la copia del documento
     * de identidad—, y la pantalla avisa de lo que contiene antes de entregarlo.
     * Lo que el sistema NO puede controlar es que despues el archivo se reenvie,
     * y por eso la decision tenia que ser explicita y de quien manda, no de quien
     * programa.
     *
     * Una fila por persona y promotoria: quien cursa dos sale en dos filas, con
     * sus datos repetidos. Es la forma correcta para una hoja de calculo —permite
     * tablas dinamicas— y la unica que puede llevar el nivel y el tiempo, que son
     * datos DE la promotoria y no de la persona. Quien no tiene ninguna sale en
     * una sola fila con esas columnas vacias.
     */
    public function institucion(): StreamedResponse
    {
        return Csv::descargar('institucion-completo', [
            'Rol',
            'Nombre completo',
            'Usuario',
            'Edad',
            'Teléfono',
            'Correo',
            'Departamento',
            'Promotoría',
            'Grupo',
            'Nivel',
            'Estado',
            'Periodos en la promotoría',
            'Desde',
            'Género',
            'Barrio',
            'Estrato',
            'Nivel educativo',
            'Ocupación',
            'Zona',
            'Afiliación en salud',
            'Grupo étnico',
            'Discapacidad',
            'Víctima del conflicto',
            'Autoriza tratamiento de datos',
            'Fecha de autorización',
        ], $this->filasDeInstitucion());
    }

    /**
     * @return Generator<int, list<string>>
     */
    private function filasDeInstitucion(): Generator
    {
        $trayectorias = $this->trayectorias();
        $periodo = Periodo::enCurso();

        $consulta = Perfil::query()
            ->with([
                'user',
                'encuesta',
                // Solo las del periodo EN CURSO. Una matricula de un periodo que
                // ya termino sigue guardada como 'activa' —el estado no cambia,
                // lo que cambia es el calendario—, asi que sin este filtro quien
                // lleva tres semestres en Violin salia en tres filas identicas.
                // El pasado no se pierde: lo cuentan «Periodos en la promotoría»
                // y «Desde», que es justo para lo que estan.
                'matriculas' => fn ($q) => $q
                    ->whereIn('estado', Matricula::ESTADOS_INSCRITO)
                    ->when($periodo, fn ($sub) => $sub->where('periodo_id', $periodo->id))
                    ->when($periodo === null, fn ($sub) => $sub->whereRaw('1 = 0')),
                'matriculas.promotoria.area',
                'matriculas.grupo.sesiones',
                'matriculas.periodo',
            ])
            ->orderBy('rol')
            ->orderBy('nombre_completo');

        // `lazy` y no `lazyById`, por lo mismo que arriba: va ordenado por rol y
        // nombre, y paginar por id con otro orden repite filas.
        foreach ($consulta->lazy(self::POR_TANDA) as $perfil) {
            $comunes = $this->columnasDePersona($perfil);
            $encuesta = $this->columnasDeEncuesta($perfil->encuesta);

            if ($perfil->matriculas->isEmpty()) {
                yield array_map(Csv::celda(...), [
                    ...$comunes,
                    ...$this->columnasDeMatricula(null, $trayectorias),
                    ...$encuesta,
                ]);

                continue;
            }

            foreach ($perfil->matriculas as $matricula) {
                // Anotada porque la relacion no lleva tipo y PHPStan la ve como
                // un `Model` cualquiera. Antes esto se tapaba con cuatro
                // patrones en la linea base —uno por propiedad tocada—; al
                // pasar las columnas a su propio metodo hace falta el tipo de
                // verdad, y los cuatro patrones se van.
                /** @var Matricula $matricula */
                yield array_map(Csv::celda(...), [
                    ...$comunes,
                    ...$this->columnasDeMatricula($matricula, $trayectorias),
                    ...$encuesta,
                ]);
            }
        }
    }

    /**
     * Rol, identidad y contacto de una persona, sea cual sea su rol.
     *
     * La edad sale VACIA para el personal, y esa es la unica columna que
     * distingue una fila de otra. Es un dato del estudiante --de ahi salen la
     * minoria de edad, el acudiente obligatorio y el nivel del grupo-- y en un
     * profesor no lo usa nadie. Aqui pesa mas que en la ficha: esto es un
     * archivo que sale del sistema y ya no vuelve.
     *
     * La columna no se quita de la cabecera porque para el estudiante sigue
     * haciendo falta, y una hoja con las columnas cambiando segun la fila no la
     * abre ningun programa.
     *
     * @return list<string|int|null>
     */
    /**
     * Las siete columnas que describen UNA matricula, o siete vacias si no hay.
     *
     * EL FALLO QUE ESTO CIERRA, del 04/09/2026: las dos ramas escribian su
     * propia lista, y la de «sin matricula» tenia SEIS huecos donde la otra
     * ponia siete columnas. Con eso, todo lo que va de «Departamento» en
     * adelante se corria una posicion a la izquierda y la encuesta caia bajo
     * cabeceras que no eran las suyas.
     *
     * No era un caso raro: le pasaba a TODO EL PERSONAL —un profesor no tiene
     * matriculas nunca— y a cualquier estudiante sin matricula activa en el
     * periodo en curso. O sea que el informe salia torcido siempre, y la parte
     * torcida era justo la que se reporta a quien financia.
     *
     * Se arregla con una sola lista y no contando huecos a mano: mientras las
     * dos ramas pasen por aqui no pueden descuadrarse entre si. Que ademas
     * cuadre con la CABECERA lo vigila `InformeCuadradoTest`, que compara los
     * anchos fila a fila.
     *
     * @param  array<string, array{periodos: int, desde: string}>  $trayectorias
     * @return list<string|int|null>
     */
    private function columnasDeMatricula(?Matricula $matricula, array $trayectorias): array
    {
        if ($matricula === null) {
            return array_fill(0, 7, '');
        }

        $clave = "{$matricula->estudiante_id}:{$matricula->promotoria_id}";
        $trayectoria = $trayectorias[$clave] ?? ['periodos' => 0, 'desde' => ''];

        // Por lo mismo que arriba: la relacion no lleva tipo, asi que el area
        // llega como un `Model` y su nombre no existe para el analizador.
        /** @var Promotoria $promotoria */
        $promotoria = $matricula->promotoria;
        /** @var Area $area */
        $area = $promotoria->area;

        return [
            $area->nombre,
            $promotoria->nombre,
            $matricula->grupo?->nombre ?? 'Sin grupo',
            $matricula->grupo?->nivel_display,
            Matricula::ESTADOS[$matricula->estado] ?? $matricula->estado,
            $trayectoria['periodos'],
            $trayectoria['desde'],
        ];
    }

    private function columnasDePersona(Perfil $perfil): array
    {
        return [
            $perfil->rol === '' ? 'Pendiente de rol' : (Perfil::ROLES[$perfil->rol] ?? $perfil->rol),
            $perfil->nombre_completo,
            $perfil->user->username,
            $perfil->esPersonal() ? null : $perfil->edad,
            $perfil->telefono,
            $perfil->user->email,
        ];
    }

    /**
     * Las respuestas de la encuesta, ya traducidas a su etiqueta.
     *
     * Se guardan como codigos (`f`, `primaria_inc`) porque asi los compara el
     * sistema, pero en una hoja de calculo eso no lo lee nadie: quien abre el
     * informe quiere «Femenino», no «f». Sin encuesta contestada, todas vacias.
     *
     * @return list<string|int|null>
     */
    private function columnasDeEncuesta(?EncuestaDemografica $encuesta): array
    {
        if ($encuesta === null) {
            return array_fill(0, 12, '');
        }

        $etiqueta = fn (array $opciones, $valor) => $valor === null || $valor === ''
            ? ''
            : ($opciones[$valor] ?? $valor);

        return [
            $etiqueta(EncuestaDemografica::GENEROS, $encuesta->genero),
            $encuesta->barrio,
            $etiqueta(EncuestaDemografica::ESTRATOS, $encuesta->estrato),
            $etiqueta(EncuestaDemografica::NIVELES_EDUCATIVOS, $encuesta->nivel_educativo),
            $etiqueta(EncuestaDemografica::OCUPACIONES, $encuesta->ocupacion),
            $etiqueta(EncuestaDemografica::ZONAS, $encuesta->zona),
            $etiqueta(EncuestaDemografica::AFILIACIONES_SALUD, $encuesta->afiliacion_salud),
            $etiqueta(EncuestaDemografica::GRUPOS_ETNICOS, $encuesta->grupo_etnico),
            $etiqueta(EncuestaDemografica::DISCAPACIDADES, $encuesta->discapacidad),
            $etiqueta(EncuestaDemografica::VICTIMAS_CONFLICTO, $encuesta->victima_conflicto_armado),
            $encuesta->autoriza_tratamiento_datos ? 'Sí' : 'No',
            $encuesta->fecha_autorizacion?->format('d/m/Y'),
        ];
    }

    /**
     * Cuanto lleva cada quien en cada promotoria, en UNA consulta.
     *
     * «Tiempo» se mide en PERIODOS cursados y no en meses, y esa es la unidad en
     * la que funciona la casa: alguien lleva «tres semestres en Guitarra», no
     * «catorce meses». Cuentan solo los periodos con matricula ACTIVA —lo que
     * de verdad curso—, asi que una solicitud que nadie confirmo no suma.
     *
     * Va agregado y no matricula por matricula porque si no serian dos consultas
     * por fila del informe, y el informe tiene una fila por persona y promotoria.
     *
     * @return array<string, array{periodos: int, desde: string}>
     */
    private function trayectorias(): array
    {
        $filas = Matricula::query()
            ->where('matriculas.estado', Matricula::ACTIVA)
            ->join('periodos', 'periodos.id', '=', 'matriculas.periodo_id')
            ->groupBy('matriculas.estudiante_id', 'matriculas.promotoria_id')
            ->selectRaw('
                matriculas.estudiante_id,
                matriculas.promotoria_id,
                COUNT(DISTINCT matriculas.periodo_id) as periodos,
                MIN(periodos.fecha_inicio) as primera
            ')
            ->get();

        $nombres = Periodo::pluck('nombre', 'fecha_inicio');
        $mapa = [];

        foreach ($filas as $fila) {
            $mapa["{$fila->estudiante_id}:{$fila->promotoria_id}"] = [
                'periodos' => (int) $fila->periodos,
                'desde' => $nombres[$fila->primera] ?? '',
            ];
        }

        return $mapa;
    }
}
