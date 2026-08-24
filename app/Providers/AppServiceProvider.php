<?php

namespace App\Providers;

use App\Models\ConfiguracionInstitucion;
use App\Support\Recurso;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

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
        $this->exigirContrasenaMinima();
        $this->declararRecursoVersionado();

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

    /**
     * El minimo de la contrasena, definido en UN solo sitio.
     *
     * Hay tres formularios que crean credenciales —el autorregistro de profesor,
     * la inscripcion de estudiante y el alta desde Gestion— y hasta ahora
     * ninguno pedia longitud: se aceptaba una contrasena de un solo caracter en
     * un formulario publico, en un sistema que guarda copias de documentos de
     * identidad de menores.
     *
     * Va como `Password::defaults()` y no como un `min:8` repetido tres veces
     * porque tres copias de una regla es la forma seria de que acaben
     * discrepando: el dia que este numero suba, tiene que subir en los tres a la
     * vez o no ha subido en ninguno.
     *
     * OJO, no es un puerto: el original NO valida la contrasena por ninguna
     * parte. Su `settings.py` lista los cuatro AUTH_PASSWORD_VALIDATORS de
     * Django, pero son la plantilla que deja `startproject` y ahi nadie los
     * invoca — solo corren desde `UserCreationForm` o `SetPasswordForm`, y el
     * original registra con un `forms.Form` plano que llama directamente a
     * `create_user()`. Configuracion muerta. Esta regla es un anadido
     * deliberado, no una equivalencia.
     *
     * Se queda en la longitud y no se copian los otros tres validadores (lista
     * de contrasenas comunes, no-solo-digitos, parecido al usuario) porque eso
     * ya seria decidir politica de contrasenas, y esa decision no esta escrita
     * en ninguna parte. Si algun dia se toma, se anade aqui encadenando y los
     * tres formularios la reciben solos.
     */
    private function exigirContrasenaMinima(): void
    {
        Password::defaults(fn () => Password::min(8));
    }

    /**
     * `@recurso('js/acciones.js')`: el activo con su fecha pegada detras.
     *
     * Es la PRIMERA directiva propia del proyecto y se anade con reparo, porque
     * cada directiva es vocabulario nuevo que hay que aprender para leer una
     * plantilla. Se justifica por el numero: son diez referencias en ocho
     * plantillas, y la alternativa —escribir la llamada estatica completa en
     * cada una— es la forma segura de que la proxima se escriba con `asset()`
     * por costumbre y se quede sin version sin que nadie lo note.
     *
     * Compila a una LLAMADA, no al resultado. Importa porque el despliegue corre
     * `view:cache`: si la fecha se calculara al compilar, se congelaria la del
     * momento del despliegue y no volveria a moverse.
     *
     * Va con `e()` por el contexto —siempre dentro de un atributo HTML— aunque
     * hoy ninguna ruta de `public/` tenga nada que escapar.
     */
    private function declararRecursoVersionado(): void
    {
        // La barra inicial no es decorativa: la plantilla compilada vive en el
        // espacio de nombres global, y sin ella el nombre seria relativo.
        Blade::directive('recurso', fn (string $ruta) => '<?php echo e(\\'.Recurso::class."::versionado({$ruta})); ?>");
    }
}
