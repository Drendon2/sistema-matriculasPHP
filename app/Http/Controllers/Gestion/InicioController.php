<?php

namespace App\Http\Controllers\Gestion;

use App\Http\Controllers\Controller;
use App\Models\ConfiguracionInstitucion;
use App\Models\Matricula;
use App\Models\Periodo;
use App\Support\Alertas;
use Illuminate\View\View;

/** La portada de Gestion: las fichas que llevan a cada pantalla. */
class InicioController extends Controller
{
    public function __invoke(): View
    {
        $config = ConfiguracionInstitucion::actual();
        $periodo = Periodo::enCurso();

        // La cifra de la ficha es lo unico que avisa de que hay algo esperando:
        // sin ella habria que entrar a mirar. Desde el 02/09/2026 cuenta las
        // TRES cosas de esa bandeja y no solo las cancelaciones, porque si
        // contara una sola la ficha diria «0» con veinte clases sin registrar.
        $cancelaciones = Matricula::where('estado', Matricula::CANCELACION_SOLICITADA)->count();

        $alertas = 0;

        if ($periodo !== null) {
            if ($config->alerta_clase_no_dictada) {
                $alertas += Alertas::clasesNoDictadas($periodo)->count();
            }

            if ($config->alerta_abandono) {
                $alertas += Alertas::posiblesAbandonos($periodo)->count();
            }
        }

        return view('gestion.inicio', [
            'cancelacionesPendientes' => $cancelaciones,
            'alertasPendientes' => $alertas,
        ]);
    }
}
