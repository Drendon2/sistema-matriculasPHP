<?php

namespace App\Http\Controllers\Gestion;

use App\Http\Controllers\Controller;
use App\Models\ConfiguracionInstitucion;
use App\Models\DocumentoRequerido;
use App\Models\Periodo;
use App\Rules\ImagenProcesable;
use App\Support\Imagen;
use App\Support\PoliticaDatos;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

/**
 * Ajustes de la institucion: la marca, el limite de promotorias y que papeles se
 * piden.
 *
 * Solo administrador. No es catalogo academico —que si comparte con el
 * director—: es la identidad de toda la entidad y una regla que gobierna las
 * matriculas de todo el mundo.
 */
class ConfiguracionController extends Controller
{
    /**
     * El lado mayor de la firma, en pixeles.
     *
     * Mas ancha que una foto de perfil y por una razon concreta: una firma es
     * un trazo apaisado y fino, y reducida a 800 px de ancho los rasgos se
     * empastan al imprimirse. En el certificado ocupa unos 5 cm, asi que 1000
     * px dan holgura de sobra sin que el archivo se dispare.
     */
    private const LADO_FIRMA = 1000;

    public function mostrar(): View
    {
        return view('gestion.configuracion', [
            'institucion' => ConfiguracionInstitucion::actual(),
            // Para que la ayuda del textarea diga la verdad: el campo se ve
            // vacio en los dos casos, y «vacio» significa cosas distintas antes
            // y despues de haber escrito algo.
            'politicaEsLaDeFabrica' => PoliticaDatos::esLaDeFabrica(ConfiguracionInstitucion::actual()),
            // Para el aviso de la fecha de las alertas. Tenerla aqui y no en el
            // periodo tiene un riesgo conocido: que se quede vieja al cambiar de
            // semestre y apague las alertas sin decirlo. Eso no lo arregla el
            // esquema; lo arregla que la pantalla lo diga.
            'periodoEnCurso' => Periodo::enCurso(),
            // Los desactivados tambien se listan: son los que dejaron de pedirse
            // pero conservan lo entregado, y esconderlos haria creer que se
            // perdieron.
            'documentos' => DocumentoRequerido::query()
                ->withCount(['entregas as entregados' => fn ($q) => $q->where('archivo', '!=', '')])
                ->ordenados()
                ->get(),
        ]);
    }

    public function guardar(Request $request): RedirectResponse
    {
        $configuracion = ConfiguracionInstitucion::actual();

        $datos = $request->validate([
            'nombre_institucion' => ['required', 'string', 'max:80'],
            // Los cuatro datos de la entidad son OPCIONALES, y no por descuido:
            // se anadieron el 06/09/2026 a una instalacion que ya estaba
            // corriendo, y exigirlos habria dejado esta pantalla imposible de
            // guardar —para cambiar el color de acento, por ejemplo— hasta que
            // alguien los rellenara. La pagina publica se lee igual sin ellos:
            // esconde el renglon que falta.
            'entidad_nit' => ['nullable', 'string', 'max:40'],
            'entidad_direccion' => ['nullable', 'string', 'max:160'],
            'entidad_correo' => ['nullable', 'email', 'max:120'],
            'entidad_telefono' => ['nullable', 'string', 'max:40'],
            // Sin tope de largo: es un texto legal y el de fabrica ya ocupa
            // varias pantallas. La columna es TEXT.
            'politica_datos' => ['nullable', 'string'],
            // Las dos finalidades SI llevan tope: son una frase que se incrusta
            // dentro de otra, en la politica y en el papel que se firma. Un
            // parrafo entero ahi rompe las dos.
            'finalidad_datos' => ['nullable', 'string', 'max:255'],
            'finalidad_imagen' => ['nullable', 'string', 'max:255'],
            'logo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096', new ImagenProcesable],
            'firma' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096', new ImagenProcesable],
            'firmante_nombre' => ['nullable', 'string', 'max:120'],
            'firmante_cargo' => ['nullable', 'string', 'max:80'],
            'color_acento' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'limite_promotorias_por_periodo' => [
                'required', 'integer', 'min:1', 'max:'.ConfiguracionInstitucion::RANURA_MAXIMA_ABSOLUTA,
            ],
            'promotorias_visibles_para_estudiantes' => ['nullable', 'boolean'],
            'alerta_clase_no_dictada' => ['nullable', 'boolean'],
            'alerta_abandono' => ['nullable', 'boolean'],
            'recordar_encuesta' => ['nullable', 'boolean'],
            // El maximo no es capricho: una racha mas larga que el periodo no
            // se alcanza nunca y la alerta quedaria apagada sin decirlo.
            'faltas_para_abandono' => ['required', 'integer', 'min:2', 'max:20'],
            'alertas_desde' => ['nullable', 'date'],
        ], [
            'color_acento.regex' => 'El color de acento debe ir en formato #rrggbb.',
        ], [
            'firma' => 'firma',
            'firmante_nombre' => 'nombre de quien firma',
            'firmante_cargo' => 'cargo de quien firma',
            'entidad_nit' => 'NIT',
            'entidad_direccion' => 'dirección',
            'entidad_correo' => 'correo de contacto',
            'entidad_telefono' => 'teléfono',
            'politica_datos' => 'texto de la política',
            'finalidad_datos' => 'finalidad del tratamiento de datos',
            'finalidad_imagen' => 'finalidad del uso de imagen',
        ]);

        // Quitar el logo es una casilla aparte y no "subir vacio": dejar el
        // campo de archivo en blanco significa conservar el que hay, que es lo
        // que uno espera al venir solo a cambiar el color.
        if ($request->boolean('quitar_logo') && $configuracion->logo !== '') {
            Storage::disk('local')->delete($configuracion->logo);
            $configuracion->logo = '';
        }

        if ($request->hasFile('logo')) {
            // El logo es lo unico de esta pantalla que sale a internet en cada
            // pagina, asi que pasa por la misma conversion que las fotos.
            $ruta = 'institucion/logo-'.uniqid().'.webp';
            Storage::disk('local')->put($ruta, Imagen::aWebp($request->file('logo'), 320));

            if ($configuracion->logo !== '') {
                Storage::disk('local')->delete($configuracion->logo);
            }

            $configuracion->logo = $ruta;
        }

        // La firma, con la misma pareja de casilla-y-archivo que el logo. Se
        // guarda en PNG y no en WebP como todo lo demas: el generador de PDF no
        // entiende WebP, y una firma en WebP saldria como un hueco en el
        // certificado sin que nada fallara en pantalla.
        if ($request->boolean('quitar_firma') && $configuracion->firma !== '') {
            Storage::disk('local')->delete($configuracion->firma);
            $configuracion->firma = '';
        }

        if ($request->hasFile('firma')) {
            $ruta = 'institucion/firma-'.uniqid().'.png';
            Storage::disk('local')->put($ruta, Imagen::aPng($request->file('firma'), self::LADO_FIRMA));

            if ($configuracion->firma !== '') {
                Storage::disk('local')->delete($configuracion->firma);
            }

            $configuracion->firma = $ruta;
        }

        $configuracion->nombre_institucion = $datos['nombre_institucion'];
        // Los dos textos del firmante se guardan recortados y admiten quedarse
        // vacios: una institucion puede tener la firma escaneada antes de haber
        // decidido como se escribe el cargo.
        $configuracion->firmante_nombre = trim($datos['firmante_nombre'] ?? '');
        $configuracion->firmante_cargo = trim($datos['firmante_cargo'] ?? '');
        $configuracion->entidad_nit = trim($datos['entidad_nit'] ?? '');
        $configuracion->entidad_direccion = trim($datos['entidad_direccion'] ?? '');
        $configuracion->entidad_correo = trim($datos['entidad_correo'] ?? '');
        $configuracion->entidad_telefono = trim($datos['entidad_telefono'] ?? '');
        // VACIA SE GUARDA COMO NULL, y esa distincion es la funcion entera del
        // campo: null significa «publica el texto de fabrica», que se escribe
        // solo con el nombre y el contacto de esta entidad y se pone al dia
        // cuando cambian. Guardando '' se publicaria una politica en blanco, y
        // ademas no habria forma de volver atras desde la pantalla.
        //
        // El `?: null` no sobra aunque `ConvertEmptyStringsToNull` ya lo haga:
        // aqui se recorta antes, asi que un textarea con solo espacios o saltos
        // de linea —que ese middleware deja pasar— tambien vuelve al de fabrica.
        $configuracion->politica_datos = trim($datos['politica_datos'] ?? '') ?: null;
        // Estas dos guardan '' y no null: la columna no admite nulo y su vacio
        // significa lo mismo —«usa la de fabrica»—, que resuelve el modelo.
        $configuracion->finalidad_datos = trim($datos['finalidad_datos'] ?? '');
        $configuracion->finalidad_imagen = trim($datos['finalidad_imagen'] ?? '');
        $configuracion->color_acento = strtolower($datos['color_acento']);
        $configuracion->limite_promotorias_por_periodo = $datos['limite_promotorias_por_periodo'];
        $configuracion->promotorias_visibles_para_estudiantes = $request->boolean('promotorias_visibles_para_estudiantes');
        $configuracion->alerta_clase_no_dictada = $request->boolean('alerta_clase_no_dictada');
        $configuracion->alerta_abandono = $request->boolean('alerta_abandono');
        $configuracion->recordar_encuesta = $request->boolean('recordar_encuesta');
        $configuracion->faltas_para_abandono = (int) $request->input('faltas_para_abandono');
        // Vacia se guarda como NULL: es lo que significa «desde el inicio del
        // periodo». Quien lo consigue de verdad es el middleware
        // `ConvertEmptyStringsToNull` de Laravel; el `?: null` es el cinturon
        // para el dia que ese middleware se quite, y no es adorno — con una
        // cadena vacia, MariaDB rechaza el INSERT con «Incorrect date value» y
        // la pantalla contesta un 500.
        $configuracion->alertas_desde = $request->input('alertas_desde') ?: null;
        $configuracion->save();

        $respuesta = redirect()->route('gestion-configuracion')
            ->with('success', 'Configuración de la institución actualizada.');

        // El contraste no bloquea: una marca clara puede ser legitima, pero el
        // texto blanco de los botones deja de leerse y hay que avisarlo.
        $razon = $configuracion->contraste_texto_boton;

        if ($razon < 4.5) {
            $respuesta->with(
                'error',
                'Ojo: el texto blanco sobre ese color de acento queda en '
                .number_format($razon, 1).':1 de contraste, por debajo del mínimo de 4.5:1. '
                .'Los botones serán difíciles de leer; considera un tono más oscuro.'
            );
        }

        return $respuesta;
    }

    /** Agrega un papel a la lista de los que se piden. */
    public function documentoNuevo(Request $request): RedirectResponse
    {
        $datos = $request->validate([
            'nombre' => ['required', 'string', 'max:60'],
            'descripcion' => ['nullable', 'string', 'max:120'],
            'obligatorio' => ['nullable', 'boolean'],
            'orden' => ['required', 'integer', 'min:0'],
        ]);

        // `activo` no se pide: un documento se crea pidiendose. Dejar de pedirlo
        // es una accion aparte en la lista, y no una casilla que se pueda
        // desmarcar sin darse cuenta mientras se escribe el nombre.
        $documento = DocumentoRequerido::create([
            'nombre' => $datos['nombre'],
            'descripcion' => $datos['descripcion'] ?? '',
            'obligatorio' => $request->boolean('obligatorio'),
            'orden' => $datos['orden'],
            'activo' => true,
        ]);

        return redirect()->route('gestion-configuracion')
            ->with('success', "«{$documento->nombre}» ya se le pide a los estudiantes.");
    }

    /**
     * Deja de pedir un papel, o vuelve a pedirlo.
     *
     * No lo borra a proposito. Los archivos que ya subieron los estudiantes
     * cuelgan del requisito: borrarlo se llevaria por delante la prueba de que
     * en su momento cumplieron, y eso no se puede deshacer.
     */
    public function documentoAlternar(DocumentoRequerido $documento): RedirectResponse
    {
        $documento->activo = ! $documento->activo;
        $documento->save();

        return redirect()->route('gestion-configuracion')->with(
            'success',
            $documento->activo
                ? "«{$documento->nombre}» vuelve a pedirse."
                : "«{$documento->nombre}» deja de pedirse. Lo ya entregado se conserva."
        );
    }
}
