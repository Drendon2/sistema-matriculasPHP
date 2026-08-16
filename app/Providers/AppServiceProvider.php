<?php

namespace App\Providers;

use App\Models\ConfiguracionInstitucion;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        /**
         * La marca de la institucion, disponible en TODAS las plantillas.
         *
         * Equivale al context processor del original. Se resuelve una vez por
         * peticion y con `View::composer('*')` en vez de `View::share()` para
         * que no se consulte la base de datos en las peticiones que no pintan
         * ninguna vista (los POST que solo redirigen, por ejemplo).
         *
         * `ConfiguracionInstitucion::actual()` devuelve valores por defecto en
         * memoria si la tabla todavia no existe, asi que un proyecto recien
         * clonado y sin migrar no se cae aqui.
         */
        View::composer('*', function ($view) {
            $view->with('configuracion', ConfiguracionInstitucion::actual());
        });

        /**
         * El Perfil de quien mira, disponible en TODAS las plantillas como
         * `$yo`.
         *
         * Media docena de vistas necesitan saber quien esta mirando para decidir
         * si un nombre va como enlace o como texto, y pasarlo desde cada
         * controlador significaba acordarse en cada uno. Se llama `$yo` y no
         * `$perfil` para no chocar con la variable local de las vistas que
         * hablan del perfil de OTRA persona, que es justo donde mas se usa.
         *
         * Es null sin sesion, y las plantillas publicas no lo tocan.
         */
        View::composer('*', function ($view) {
            $view->with('yo', auth()->user()?->perfil);
        });

        // En Hostinger el SSL termina antes de PHP, asi que la aplicacion ve
        // http y generaria enlaces y formularios en http dentro de una pagina
        // https. Forzarlo aqui evita el aviso de contenido mixto.
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }
    }
}
