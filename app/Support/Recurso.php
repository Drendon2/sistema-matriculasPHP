<?php

namespace App\Support;

/**
 * La URL de un archivo de `public/` con su fecha de modificacion detras.
 *
 * El problema: `asset('js/acciones.js')` devuelve siempre la misma URL, asi que
 * tras un despliegue el navegador sigue sirviendo de su cache el archivo viejo
 * hasta que caduque solo. Con el CSS eso es cosmetico. Con el JS no lo es:
 * `acciones.js` es donde vive el aviso de formulario rechazado, y un navegador
 * que conserve la version anterior reproduce EXACTAMENTE el fallo que ese
 * archivo vino a arreglar —quien pulsa no ve nada y concluye que el boton no
 * funciona—, con el agravante de que la aplicacion ya esta arreglada y nadie
 * mirando el servidor entenderia por que sigue pasando.
 *
 * Se usa `filemtime` y no un hash del contenido porque el despliegue trae los
 * archivos con `git pull`, que los reescribe y les pone fecha nueva: la marca
 * cambia justo cuando cambia el archivo, y sale de un `stat` en vez de leerse
 * el archivo entero en cada peticion.
 *
 * NO memoriza el resultado, a proposito. PHP ya guarda las llamadas a `stat` en
 * su propia cache y aqui son dos o tres por pagina; una cache propia solo
 * anadiria estado estatico que en las pruebas —muchas peticiones dentro de un
 * mismo proceso— devolveria la fecha vieja despues de tocar un archivo, que es
 * precisamente lo que hay que poder comprobar.
 */
class Recurso
{
    /**
     * La URL publica de `$ruta` (relativa a `public/`) con `?v=<fecha>`.
     *
     * Si el archivo no existe se devuelve la URL sin marca: el enlace ya esta
     * roto y pegarle una version no lo arregla ni lo empeora. Fallar aqui
     * tumbaria la pagina entera por un activo que falta, que es peor respuesta
     * que servirla sin el.
     */
    public static function versionado(string $ruta): string
    {
        $url = asset($ruta);
        $completa = public_path($ruta);

        if (! is_file($completa)) {
            return $url;
        }

        return $url.'?v='.filemtime($completa);
    }
}
