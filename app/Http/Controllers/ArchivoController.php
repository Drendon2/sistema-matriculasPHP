<?php

namespace App\Http\Controllers;

use App\Models\ConfiguracionInstitucion;
use App\Models\DatosEstudiante;
use App\Models\Matricula;
use App\Models\Perfil;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Sirve los archivos subidos, cada uno con su propia regla.
 *
 * NINGUNO se expone como carpeta publica, y esa es la decision de fondo: en el
 * mismo sitio conviven el logo —marca publica— con fotos de perfil y copias de
 * documentos de identidad, que tienen reglas de visibilidad estrictas (Ley 1581).
 * Abrir el directorio entero para servir el logo entregaria tambien lo demas a
 * cualquiera que adivinara un nombre de archivo.
 *
 * En el despliegue esto se traduce en algo concreto: el disco de estos archivos
 * es `storage/app/private`, que queda FUERA de `public/`. Ni siquiera hace falta
 * confiar en una regla del servidor web.
 */
class ArchivoController extends Controller
{
    /**
     * El logo de la institucion.
     *
     * Es lo contrario del resto: marca publica, la ve hasta quien no ha iniciado
     * sesion. Se sirve sin restriccion, pero por su propia ruta en vez de abrir
     * la carpeta.
     */
    public function logo(): StreamedResponse
    {
        $configuracion = ConfiguracionInstitucion::actual();

        abort_if($configuracion->logo === '', 404, 'La institución no tiene un logo propio cargado.');

        return $this->entregar($configuracion->logo);
    }

    /**
     * La firma que sella los certificados.
     *
     * Es lo contrario del logo, aunque las dos sean imagenes de la institucion y
     * vivan en la misma fila: el logo se ensena a todo el mundo y la firma solo
     * a quien la configura. Una firma escaneada en una URL abierta se la lleva
     * cualquiera para estampar el papel que quiera, y el certificado dejaria de
     * valer nada precisamente por lo que se supone que lo hace valer.
     *
     * La puerta de rol la pone la ruta; aqui solo queda el caso de que no haya
     * ninguna cargada.
     */
    public function firma(): StreamedResponse
    {
        $configuracion = ConfiguracionInstitucion::actual();

        abort_if($configuracion->firma === '', 404, 'La institución no tiene una firma cargada.');

        return $this->entregar($configuracion->firma);
    }

    /**
     * La foto de perfil de alguien, con la regla de visibilidad del modelo:
     *
     *     nombre, foto ... admin, director, profesor, companeros de la MISMA
     *                      promotoria
     *
     * Sumado a que cualquiera puede ver la suya.
     */
    public function foto(Request $request, Perfil $perfil): StreamedResponse
    {
        $solicitante = $request->user()?->perfil;

        abort_if($solicitante === null, 404);

        $permitido = $solicitante->id === $perfil->id || $solicitante->esPersonal();

        if (! $permitido) {
            $permitido = $this->sonCompaneros($solicitante, $perfil);
        }

        // Un 404 y no un 403 en los dos casos: que exista o no la foto de otra
        // persona tampoco es asunto de quien pregunta.
        abort_unless($permitido && $perfil->foto_perfil !== '', 404);

        return $this->entregar($perfil->foto_perfil);
    }

    /**
     * La copia del documento de identidad. Solo el administrador.
     *
     * Va como descarga y no incrustada: es un documento de identidad, no una
     * imagen para mirar de paso.
     */
    public function documento(DatosEstudiante $datos): StreamedResponse
    {
        abort_if($datos->copia_documento === '', 404);

        return Storage::disk('local')->download(
            $datos->copia_documento,
            basename($datos->copia_documento)
        );
    }

    /**
     * ¿Comparten promotoria Y periodo, los dos con matricula activa?
     *
     * Las dos cosas juntas: haber coincidido en Guitarra el semestre pasado no
     * da acceso a la foto de este.
     */
    private function sonCompaneros(Perfil $solicitante, Perfil $objetivo): bool
    {
        $suyas = Matricula::where('estudiante_id', $objetivo->id)
            ->where('estado', Matricula::ACTIVA)
            ->get(['promotoria_id', 'periodo_id'])
            ->map(fn (Matricula $m) => "{$m->promotoria_id}:{$m->periodo_id}")
            ->all();

        if ($suyas === []) {
            return false;
        }

        return Matricula::where('estudiante_id', $solicitante->id)
            ->where('estado', Matricula::ACTIVA)
            ->get(['promotoria_id', 'periodo_id'])
            ->contains(fn (Matricula $m) => in_array("{$m->promotoria_id}:{$m->periodo_id}", $suyas, true));
    }

    private function entregar(string $ruta): StreamedResponse
    {
        $disco = Storage::disk('local');

        abort_unless($disco->exists($ruta), 404);

        return $disco->response($ruta);
    }
}
