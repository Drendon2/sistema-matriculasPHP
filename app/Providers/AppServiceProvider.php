<?php

namespace App\Providers;

use App\Models\ConfiguracionInstitucion;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

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

        $this->limitarIntentos();

        // En Hostinger el SSL termina antes de PHP, asi que la aplicacion ve
        // http y generaria enlaces y formularios en http dentro de una pagina
        // https. Forzarlo aqui evita el aviso de contenido mixto.
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }
    }

    /**
     * Cuantos intentos de inicio de sesion se admiten, y contados contra que.
     *
     * La clave es la pareja USUARIO + IP, no la IP sola, y esa es toda la
     * decision. Una escuela entera sale a internet por una sola direccion: si el
     * contador fuera por IP, treinta estudiantes entrando a la vez desde la sala
     * de computo se bloquearian entre si, y el sistema castigaria justamente el
     * uso normal que existe para atender. Contando por pareja, cada cuenta lleva
     * su propio marcador desde cada sitio: quien machaca una cuenta ajena se
     * queda sin intentos en un minuto, y el de al lado no se entera.
     *
     * Contrapartida asumida y consciente: esto NO frena el barrido —probar una
     * misma contrasena contra mil usuarios distintos—, porque cada usuario nuevo
     * estrena contador. Cerrar esa puerta pide un tope adicional por IP, y ese
     * tope es exactamente el que volveria a bloquear la sala de computo. Se deja
     * fuera a proposito; si algun dia hace falta, el sitio es aqui y el numero
     * tiene que salir de cuanta gente entra de verdad a la vez.
     *
     * El registro y la inscripcion no pasan por aqui: se limitan por IP con el
     * `throttle:` de sus rutas, porque ahi lo que se frena es la creacion masiva
     * de cuentas y no hay ninguna cuenta previa contra la cual contar.
     */
    private function limitarIntentos(): void
    {
        RateLimiter::for('entrar', function (Request $request) {
            // En minusculas para que `Ana` y `ana` compartan contador: el login
            // no distingue mayusculas y dos contadores separados darian el doble
            // de intentos por escribirlo distinto.
            $usuario = Str::lower(trim((string) $request->input('username')));

            return Limit::perMinute(5)->by($usuario.'|'.$request->ip());
        });
    }
}
