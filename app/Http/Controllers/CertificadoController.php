<?php

namespace App\Http\Controllers;

use App\Models\ConfiguracionInstitucion;
use App\Models\Matricula;
use App\Models\Perfil;
use App\Models\Periodo;
use App\Support\Imagen;
use App\Support\Permisos;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * El certificado de matricula en PDF.
 *
 * Dos formas del mismo documento, y la diferencia es lo que certifica:
 *
 * - El de UNA matricula. Es el que suele pedir un tercero —un colegio que pide
 *   la constancia de la actividad extracurricular, una empresa que da permiso de
 *   horario—: dice que esta persona esta en Guitarra, en tal periodo, con tal
 *   horario.
 * - El REUNIDO, con todas las matriculas vigentes del periodo en curso. Sirve
 *   cuando lo que hay que acreditar es la dedicacion completa en la casa.
 *
 * QUE se puede certificar es una sola regla y esta en un sitio: solo la
 * matricula ACTIVA —confirmada por quien dicta— y la FINALIZADA, que es la
 * activa de un periodo ya cerrado y acredita haber cursado. Una pendiente no se
 * certifica: nadie ha confirmado todavia que esa persona este en el curso, y un
 * papel sellado diciendo lo contrario es un problema que se descubre fuera de
 * casa. Una retirada tampoco: cuenta lo contrario de lo que el papel afirma.
 */
class CertificadoController extends Controller
{
    /** El certificado de una matricula concreta. */
    public function matricula(Request $request, Matricula $matricula): Response
    {
        $solicitante = $request->user()?->perfil;

        // 404 y no 403: que exista o no la matricula de otra persona tampoco es
        // asunto de quien pregunta. Misma linea que las fotos.
        abort_unless(Permisos::puedeCertificarMatricula($solicitante, $matricula), 404);

        $matricula->load(['estudiante.datosEstudiante', 'promotoria.area', 'promotoria.profesor', 'grupo', 'periodo']);

        abort_unless($this->esCertificable($matricula), 404, 'Esta matrícula no se puede certificar.');

        return $this->generar(
            'Certificado de matrícula',
            $matricula->estudiante,
            collect([$matricula]),
            $matricula->periodo,
            $matricula->estado_visible === Matricula::FINALIZADA
        );
    }

    /**
     * El certificado reunido: todas las matriculas vigentes del periodo EN
     * CURSO.
     *
     * Solo el periodo en curso y no el historial entero: lo que este documento
     * acredita es una situacion presente —«esta cursando»—, y una lista con
     * cinco anos de promotorias no es una constancia sino una trayectoria, que
     * es otra cosa y ya tiene su pantalla.
     */
    public function todo(Request $request, Perfil $estudiante): Response
    {
        $solicitante = $request->user()?->perfil;

        abort_unless(Permisos::puedeCertificarTodo($solicitante, $estudiante), 404);
        abort_unless($estudiante->rol === 'estudiante', 404);

        $periodo = Periodo::enCurso();

        // Sin nada que certificar se VUELVE con un aviso, no se aborta: el
        // enlace se pinta en la ficha sin consultar antes cuantas matriculas
        // vigentes tiene esa persona, y un 404 seco dejaria a quien lo pulsa
        // creyendo que el sistema se rompio en vez de que no hay nada que
        // certificar. Es el mismo trato que da la ficha a la que no se puede
        // abrir.
        $sinNada = fn (string $motivo) => redirect()
            ->back(fallback: route('panel'))
            ->with('error', $motivo);

        if ($periodo === null) {
            return $sinNada('No hay ningún periodo en curso, así que no hay matrícula vigente que certificar.');
        }

        $matriculas = Matricula::with(['promotoria.area', 'promotoria.profesor', 'grupo'])
            ->where('estudiante_id', $estudiante->id)
            ->where('periodo_id', $periodo->id)
            ->where('estado', Matricula::ACTIVA)
            ->get()
            ->sortBy(fn (Matricula $m) => $m->promotoria->nombre)
            ->values();

        if ($matriculas->isEmpty()) {
            return $sinNada(
                "{$estudiante->nombre_completo} no tiene matrículas activas en {$periodo->nombre}: "
                . 'una solicitud pendiente de confirmar no se puede certificar.'
            );
        }

        return $this->generar(
            'Certificado de matrícula',
            $estudiante,
            $matriculas,
            $periodo,
            false
        );
    }

    /**
     * ¿Es esta matricula de las que se pueden certificar?
     *
     * Se mira `estado_visible` y no `estado` porque ahi vive la distincion entre
     * la activa de un periodo abierto y la de uno ya cerrado, que es justo la
     * que cambia el verbo del documento.
     */
    private function esCertificable(Matricula $matricula): bool
    {
        return in_array(
            $matricula->estado_visible,
            [Matricula::ACTIVA, Matricula::FINALIZADA],
            true
        );
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Matricula>  $matriculas
     */
    private function generar(
        string $titulo,
        Perfil $estudiante,
        $matriculas,
        Periodo $periodo,
        bool $finalizado
    ): Response {
        $institucion = ConfiguracionInstitucion::actual();

        $pdf = Pdf::loadView('certificados.matricula', [
            'titulo' => $titulo,
            'institucion' => $institucion,
            'estudiante' => $estudiante,
            'documento' => $estudiante->datosEstudiante?->documento_identidad ?: null,
            'matriculas' => $matriculas,
            'periodo' => $periodo,
            // Cambia el verbo: «tiene matricula vigente» mientras el periodo
            // corre, «cursó» cuando ya termino. Certificar en presente algo que
            // acabo hace un ano es afirmar lo que no es.
            'finalizado' => $finalizado,
            'expedido' => now(),
            'logo' => $this->logo($institucion),
            'firma' => $this->incrustar($institucion->firma),
        ])->setPaper('letter');

        // Carta y no A4: es el tamano de papel de oficina en Colombia, y un
        // certificado se imprime.

        return $pdf->download($this->nombreDeArchivo($estudiante, $matriculas));
    }

    /**
     * El logo que encabeza el certificado.
     *
     * Con dos origenes y ese es el punto: el propio de la institucion si lo
     * cargaron, y si no el que trae el proyecto —el mismo que ya se ve en la
     * cabecera y en las pantallas publicas—. Mirar solo la fila de
     * configuracion dejaba sin logo justo a la institucion que todavia no ha
     * subido el suyo, que es la que acaba de instalar el sistema.
     *
     * El del proyecto se lee del disco y no por su URL: dompdf no sale a la red
     * a buscar nada, y aunque saliera, esto corre en el servidor y pedirse una
     * pagina a si mismo es una forma cara de leer un archivo.
     */
    private function logo(ConfiguracionInstitucion $institucion): ?string
    {
        $propio = $this->incrustar($institucion->logo);

        if ($propio !== null) {
            return $propio;
        }

        $porDefecto = public_path('img/logo.webp');

        if (! is_file($porDefecto)) {
            return null;
        }

        // WebP: lo lee GD y lo convierte, que es justo lo que hace falta —dompdf
        // por su cuenta no entiende WebP y lo dejaria como un hueco.
        return Imagen::aDataUriPng((string) file_get_contents($porDefecto));
    }

    /**
     * Una imagen del disco privado, lista para incrustar en el PDF.
     *
     * Devuelve null en cuanto algo no cuadra —no hay archivo, no esta en disco,
     * no se puede decodificar—, y el certificado se genera igual sin ella. Un
     * logo que falta no es razon para negarle a nadie su constancia.
     */
    private function incrustar(string $ruta): ?string
    {
        if ($ruta === '') {
            return null;
        }

        $disco = Storage::disk('local');

        if (! $disco->exists($ruta)) {
            return null;
        }

        return Imagen::aDataUriPng((string) $disco->get($ruta));
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Matricula>  $matriculas
     */
    private function nombreDeArchivo(Perfil $estudiante, $matriculas): string
    {
        $partes = ['certificado-matricula', Str::slug($estudiante->nombre_completo)];

        // Con una sola matricula el nombre dice cual, que es lo que distingue
        // dos certificados de la misma persona en la carpeta de descargas.
        if ($matriculas->count() === 1) {
            $partes[] = Str::slug($matriculas->first()->promotoria->nombre);
        }

        return implode('-', $partes).'.pdf';
    }
}
