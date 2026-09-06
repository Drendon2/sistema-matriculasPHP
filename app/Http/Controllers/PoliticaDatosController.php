<?php

namespace App\Http\Controllers;

use App\Models\ConfiguracionInstitucion;
use App\Support\PoliticaDatos;
use Illuminate\View\View;

/**
 * La politica de tratamiento de datos personales.
 *
 * PUBLICA, SIN SESION, y eso es el requisito y no una comodidad: el enlace va
 * en el pie de TODAS las pantallas, y las tres primeras que ve cualquiera
 * —entrar, inscripcion, registro— son justo las de quien todavia no tiene
 * cuenta. Una politica de tratamiento que solo se puede leer despues de
 * entregar los datos no cumple para lo que existe.
 *
 * Cuelga de `layouts.publico` tambien para quien SI tiene la sesion abierta.
 * Fue una decision, y la alternativa —repartirla en los dos envoltorios segun
 * haya sesion o no— dejaba dos pantallas que pueden divergir para un texto que
 * es el mismo y que ademas se imprime y se cita. El precio es que se pierde la
 * barra de navegacion, y por eso el pie de esta pantalla lleva su propia vuelta
 * al sistema.
 */
class PoliticaDatosController extends Controller
{
    public function __invoke(): View
    {
        $institucion = ConfiguracionInstitucion::actual();

        return view('publico.politica-datos', [
            'institucion' => $institucion,
            // Se convierte aqui y no en la plantilla: `aHtml()` ya escapa todo
            // lo que viene de la columna, y ese es el unico punto del proyecto
            // donde se imprime sin volver a escapar. Que la conversion viva en
            // una clase con nombre —y no dentro de un Blade— es lo que hace que
            // se pueda probar y que se vea de un vistazo quien escapa que.
            'contenido' => PoliticaDatos::aHtml(PoliticaDatos::texto($institucion)),
        ]);
    }
}
