<?php

namespace App\Support;

use Illuminate\Database\Connectors\MariaDbConnector;
use PDOException;
use Throwable;

/**
 * Vuelve a intentar la conexion cuando el motor la rechaza por saturacion.
 *
 * Existe por un fallo medido en produccion el 08/09/2026: el hosting compartido
 * rechaza la conexion al socket de MariaDB en rafagas de un segundo, con
 * `SQLSTATE[HY000] [2002] Operation not permitted`. Es el UNICO error de
 * produccion que aparece en el registro —todos los dias, entre las 9 y las 11 de
 * la manana, que es cuando la gente usa el sistema— y no lo provoca ninguna
 * pantalla: la base son 4,5 MB y las consultas contestan en 1-2 ms. Es la
 * maquina compartida, con una carga media de 26 a 35, frenando al contenedor.
 *
 * LA TRAMPA QUE JUSTIFICA ESTE ARCHIVO: Laravel YA reintenta la conexion
 * perdida, en `Connector::createConnection()`, y por eso parece que aqui no
 * hace falta nada. Pero decide con la lista de `LostConnectionDetector`, que
 * trae `Operation now in progress` y `Operation in progress` y NO trae
 * `Operation not permitted`. O sea que el reintento que ya existe no se dispara
 * justamente con el unico error que este sistema tiene. Antes de borrar esta
 * clase «porque el framework ya lo hace», busca esa cadena en esa lista.
 *
 * Se reintenta y no se deja fallar porque el rechazo es INMEDIATO —es un EPERM,
 * no un tiempo de espera agotado— asi que un reintento cuesta lo que cueste la
 * espera y nada mas. Sin el, quien pulsa ve la pantalla en blanco del CDN.
 *
 * Y no reintenta cualquier cosa: una contrasena mala o una base que no existe
 * fallan a la primera, porque insistir no las va a arreglar y solo retrasaria
 * el mensaje que dice que hacer.
 */
class ConexionQueReintenta extends MariaDbConnector
{
    /**
     * Lo que se espera entre intentos, en milisegundos.
     *
     * Son dos reintentos —tres intentos en total— y el peor caso son 480 ms
     * anadidos a una peticion que iba a fallar de todas formas. El numero sale
     * de que las rafagas medidas duran menos de un segundo; alargarlo mas
     * convertiria un fallo rapido en una espera, que es el problema que se
     * viene a arreglar.
     */
    public const ESPERAS_MS = [120, 360];

    /**
     * Los rechazos que SI vale la pena reintentar.
     *
     * Todos significan lo mismo: el motor esta ahi pero ahora mismo no puede
     * atender. El primero es el de produccion; los demas son sus vecinos, y se
     * escriben aunque hoy no aparezcan porque son el mismo caso y el dia que
     * salgan nadie estara mirando esta lista.
     *
     * OJO con lo que NO esta: `Access denied for user` y `Unknown database`
     * quedan fuera a proposito. Son errores de configuracion, no de carga:
     * reintentarlos no arregla nada y solo retrasa el mensaje que dice cual es
     * el problema.
     */
    /**
     * Los mismos rechazos, por CODIGO del motor.
     *
     * Existe porque los textos vienen traducidos: en Windows el mismo fallo de
     * conexion llega como «No se puede establecer una conexion ya que el equipo
     * de destino denego...», que no casa con ninguna cadena inglesa. Se vio
     * probando esto el 08/09/2026. En el servidor de hoy el error llega en
     * ingles —esta literal en el registro de produccion— pero esto es un
     * producto que se instala en casas ajenas, y el idioma del sistema no lo
     * elegimos nosotros.
     *
     * 2002 y 2003 son «no pude conectar»; 2006 y 2013 son «se cayo a mitad»;
     * 1040 y 1203 son los dos topes de conexiones. Fuera quedan a proposito
     * 1045 (contrasena mala) y 1049 (base inexistente), que son configuracion.
     */
    private const CODIGOS_TRANSITORIOS = [2002, 2003, 2006, 2013, 1040, 1203];

    private const TRANSITORIOS = [
        'Operation not permitted',
        'Too many connections',
        'max_user_connections',
        'Resource temporarily unavailable',
        "Can't create a new thread",
        'Connection refused',
        'Connection timed out',
        'server has gone away',
    ];

    /** @param  array<string, mixed>  $config */
    public function createConnection($dsn, array $config, array $options)
    {
        $intentos = count(self::ESPERAS_MS) + 1;

        for ($i = 0; $i < $intentos; $i++) {
            try {
                return parent::createConnection($dsn, $config, $options);
            } catch (Throwable $e) {
                if ($i === $intentos - 1 || ! self::esTransitorio($e)) {
                    throw $e;
                }

                usleep(self::ESPERAS_MS[$i] * 1000);
            }
        }

        // Inalcanzable: el bucle o devuelve la conexion o relanza en la ultima
        // vuelta. Esta aqui para que el tipo de retorno sea cierto.
        throw new \RuntimeException('No se pudo conectar con la base de datos.');
    }

    /** ¿Este rechazo es de los que se arreglan solos volviendo a pedirlo? */
    public static function esTransitorio(Throwable $e): bool
    {
        // El codigo primero: no depende del idioma del sistema.
        if ($e instanceof PDOException
            && in_array((int) ($e->errorInfo[1] ?? 0), self::CODIGOS_TRANSITORIOS, true)) {
            return true;
        }

        foreach (self::TRANSITORIOS as $senal) {
            if (str_contains($e->getMessage(), $senal)) {
                return true;
            }
        }

        return false;
    }
}
