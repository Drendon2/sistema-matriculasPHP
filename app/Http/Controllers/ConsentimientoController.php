<?php

namespace App\Http\Controllers;

use App\Models\ConfiguracionInstitucion;
use App\Models\Perfil;
use App\Support\Imagen;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * El formato de autorizacion de tratamiento de datos y uso de imagen, en PDF.
 *
 * Es el unico papel de los que pide la institucion que el sistema IMPRIME. Los
 * demas requeridos son ranuras donde el estudiante sube algo que ya tiene —la
 * cedula, el certificado de EPS—; este no existe hasta que alguien lo baja, lo
 * firma y lo devuelve por la misma ranura.
 *
 * DOS VERSIONES, Y LA DIFERENCIA NO ES COSMETICA. Un menor de edad no puede
 * otorgar por si mismo esta autorizacion: la da su acudiente, en representacion
 * suya (Ley 1581 de 2012, art. 7). Asi que el formato del menor identifica a
 * DOS personas —quien participa y quien autoriza— y lo firma la segunda. El de
 * mayor de edad identifica y firma una sola.
 *
 * LA VERSION SE ELIGE POR LA EDAD DEL DIA EN QUE SE DESCARGA, y eso es lo
 * correcto aunque parezca fragil: lo que el papel documenta es un
 * consentimiento dado en una fecha, y quien lo dio entonces siendo menor lo dio
 * validamente. Cumplir 18 despues no invalida lo firmado ni obliga a repetirlo;
 * si la entidad quiere una autorizacion propia del ya mayor, la pide y esa
 * persona baja el formato que le toca ahora.
 *
 * LAS DOS AUTORIZACIONES VAN SEPARADAS y cada una con su par de casillas. No es
 * un adorno del diseno: son finalidades distintas y una de ellas —el uso de la
 * imagen— no puede condicionar la matricula, asi que tiene que poder negarse
 * sin negar la otra. Un solo «acepto todo» al pie convertiria la negativa en
 * imposible y la autorizacion en invalida.
 */
class ConsentimientoController extends Controller
{
    /** Las dos versiones. La clave es la que viaja por la URL. */
    private const VERSIONES = ['mayor', 'menor'];

    /**
     * El formato de quien lo pide, con sus datos ya escritos.
     *
     * Solo estudiantes: son los unicos que tienen ranura donde devolverlo. El
     * personal no sube papeles en este sistema.
     */
    public function mio(Request $request): Response
    {
        $perfil = $request->user()?->perfil;

        abort_if($perfil === null || $perfil->rol !== 'estudiante', 404);

        return $this->generar($perfil->es_menor ? 'menor' : 'mayor', $perfil);
    }

    /**
     * El formato EN BLANCO, para imprimir y repartir.
     *
     * Existe porque no todo el mundo llega por el sistema: en la practica hay
     * quien se matricula en ventanilla y firma el papel ahi mismo, y hasta que
     * exista la inscripcion manual —que hoy no existe— la administracion
     * necesita poder imprimir el formato sin entrar a la cuenta de nadie.
     *
     * Sin datos precargados a proposito: un formato en blanco con el nombre de
     * otra persona impresa es un problema, no una comodidad.
     */
    public function formato(string $tipo): Response
    {
        abort_unless(in_array($tipo, self::VERSIONES, true), 404);

        return $this->generar($tipo, null);
    }

    private function generar(string $version, ?Perfil $estudiante): Response
    {
        $institucion = ConfiguracionInstitucion::actual();

        $acudiente = $version === 'menor'
            ? $estudiante?->datosEstudiante?->acudiente
            : null;

        $pdf = Pdf::loadView('certificados.consentimiento', [
            'institucion' => $institucion,
            'esMenor' => $version === 'menor',
            'estudiante' => $estudiante,
            'documento' => $estudiante?->datosEstudiante?->documento_identidad ?: null,
            'acudiente' => $acudiente,
            // La direccion de la politica, escrita en el papel. Quien firma
            // tiene derecho a leer antes que autoriza, y el papel viaja
            // impreso: sin la URL dentro, ese derecho se queda en la pantalla
            // desde la que se descargo.
            'politica' => route('politica-datos'),
            'expedido' => now(),
            'logo' => $this->logo($institucion),
        ])->setPaper('letter');

        return $pdf->download($this->nombreDeArchivo($version, $estudiante));
    }

    /**
     * El nombre del archivo que se descarga.
     *
     * Con el nombre de quien lo va a firmar cuando se sabe: en la carpeta de
     * descargas del celular, tres «consentimiento.pdf» seguidos no se
     * distinguen, y este es un papel que una familia baja para varios hijos.
     */
    private function nombreDeArchivo(string $version, ?Perfil $estudiante): string
    {
        if ($estudiante === null) {
            return "autorizacion-datos-{$version}-de-edad.pdf";
        }

        return 'autorizacion-datos-'.Str::slug($estudiante->nombre_completo).'.pdf';
    }

    /**
     * El logo que encabeza el formato.
     *
     * Misma regla que el certificado y por la misma razon: el propio de la
     * institucion si lo cargaron, y si no el que trae el proyecto. Mirar solo
     * la fila de configuracion dejaba sin logo justo a la entidad que acaba de
     * instalar el sistema, que es la que mas lo necesita — este papel sale de
     * casa firmado.
     *
     * El del proyecto se lee del disco y no por su URL: dompdf no sale a la red
     * a buscar nada.
     */
    private function logo(ConfiguracionInstitucion $institucion): ?string
    {
        if ($institucion->logo !== '') {
            $disco = Storage::disk('local');

            if ($disco->exists($institucion->logo)) {
                return Imagen::aDataUriPng((string) $disco->get($institucion->logo));
            }
        }

        $porDefecto = public_path('img/logo.webp');

        if (! is_file($porDefecto)) {
            return null;
        }

        // WebP: lo lee GD y lo convierte. dompdf por su cuenta no lo entiende y
        // lo dejaria como un hueco.
        return Imagen::aDataUriPng((string) file_get_contents($porDefecto));
    }
}
