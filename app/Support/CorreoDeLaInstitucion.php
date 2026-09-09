<?php

namespace App\Support;

use App\Models\ConfiguracionInstitucion;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Support\Facades\Mail;

/**
 * Por donde salen los correos de esta entidad.
 *
 * ─── DOS ORIGENES, Y UNO MANDA SOBRE EL OTRO ───────────────────────────────
 *
 * Manda lo que haya escrito en Gestion → Institucion; si no hay nada, lo del
 * `.env`. No es indecision: son dos situaciones reales distintas.
 *
 * - LA PANTALLA existe porque este producto se instala en casas ajenas y el
 *   `.env` vive detras de un SSH. Sin ella, encender la recuperacion de
 *   contrasena en una entidad nueva exige una consola, que es justo lo que no
 *   se hace — y cuando no se hace la funcion no falla, MIENTE: el enlace se
 *   escribe en un registro y quien lo pidio ve la pantalla de siempre.
 *
 * - EL `.env` sigue valiendo porque la produccion de hoy ya se configura por
 *   ahi y un despliegue no puede apagarle el correo a nadie, y porque quien
 *   monte esto en un servidor propio con un relay interno prefiere el archivo.
 *
 * ─── SE REGISTRA UN REMITENTE CON NOMBRE, NO SE PISA EL POR DEFECTO ────────
 *
 * Se anade `mail.mailers.institucion` a la configuracion de la peticion y se
 * pide ESE por su nombre. La alternativa obvia —escribir `mail.default` o sus
 * credenciales antes de enviar— deja la peticion entera con el correo de la
 * entidad puesto, y el dia que algo mas mande un correo lo mandaria por ahi sin
 * que nadie lo decidiera. Aqui no cambia nada de lo que ya existe: se agrega
 * uno mas y solo lo usa quien lo pide por su nombre.
 *
 * Y HAY UNA SEGUNDA RAZON, que es la que no se ve: `Mail::build()` —la otra
 * forma de armar un remitente al vuelo, y la primera que se escribio aqui— **no
 * la falsea `Mail::fake()`**. `MailFake` no tiene ese metodo, asi que su
 * `__call` lo reenvia al gestor de VERDAD: una prueba de este camino no
 * capturaria el correo con `assertSent` y ademas intentaria abrir una conexion
 * SMTP de verdad desde la suite. `Mail::mailer('institucion')` si lo falsea
 * —`MailFake::mailer()` se devuelve a si mismo— asi que lo que corre en las
 * pruebas es lo mismo que corre en el servidor.
 *
 * ─── EL «DE:» ES EL PROPIO USUARIO ─────────────────────────────────────────
 *
 * Y no hay campo para cambiarlo. Casi todos los proveedores rechazan un
 * remitente distinto del buzon autenticado, asi que un campo aparte solo sirve
 * para que alguien escriba dos cosas distintas y se pase la tarde buscando por
 * que no llegan los correos. El nombre que acompana a la direccion es el de la
 * institucion, que ya existe.
 *
 * El «De:» lo pone el propio Mailable llamando a `remitente()`, y no se deja al
 * remitente global: ese sale de `config('mail.from')`, o sea del `.env`, y con
 * las credenciales de la pantalla puestas la direccion correcta es la del buzon
 * autenticado. Un «De:» que no coincide con el buzon es de las cosas que un
 * proveedor rechaza sin explicar por que.
 */
final class CorreoDeLaInstitucion
{
    /**
     * El nombre con el que se registra el remitente de la entidad.
     *
     * Es un remitente MAS, no el por defecto: nada que no lo pida por este
     * nombre cambia de camino.
     */
    private const NOMBRE = 'institucion';

    /** Si la entidad escribió sus credenciales en Gestión → Institución. */
    public static function configuradoEnLaPantalla(?ConfiguracionInstitucion $institucion = null): bool
    {
        $institucion ??= ConfiguracionInstitucion::actual();

        return trim((string) $institucion->correo_servidor) !== ''
            && trim((string) $institucion->correo_usuario) !== ''
            && (string) $institucion->correo_clave !== '';
    }

    /**
     * Si de verdad se puede mandar un correo, venga de donde venga.
     *
     * Con el `.env` en `log` la respuesta es NO, aunque el envio «funcione»:
     * eso escribe el correo en un archivo y no lo manda a nadie. Es la
     * distincion que hace falta para que la pantalla pueda decir la verdad, y
     * la que no se ve mirando si el envio lanzo o no.
     */
    public static function hayPorDondeMandar(?ConfiguracionInstitucion $institucion = null): bool
    {
        if (self::configuradoEnLaPantalla($institucion)) {
            return true;
        }

        return ! in_array(config('mail.default'), ['log', 'array', null], true);
    }

    /** El remitente: dirección y nombre. */
    public static function remitente(?ConfiguracionInstitucion $institucion = null): ?array
    {
        $institucion ??= ConfiguracionInstitucion::actual();

        if (self::configuradoEnLaPantalla($institucion)) {
            return [
                'address' => trim((string) $institucion->correo_usuario),
                'name' => $institucion->nombre_institucion,
            ];
        }

        $delArchivo = (string) config('mail.from.address');

        return $delArchivo === '' ? null : [
            'address' => $delArchivo,
            'name' => (string) config('mail.from.name'),
        ];
    }

    /**
     * El remitente por el que hay que enviar.
     *
     * Devuelve el CONTRATO y no la clase concreta `Illuminate\Mail\Mailer`, y
     * eso importa: bajo `Mail::fake()` lo que vuelve es un `MailFake`, que
     * implementa el contrato y no hereda de aquella. Con el tipo concreto, toda
     * prueba que mande un correo revienta con un error de tipo.
     */
    public static function remitenteQueEnvia(?ConfiguracionInstitucion $institucion = null): Mailer
    {
        $institucion ??= ConfiguracionInstitucion::actual();

        if (! self::configuradoEnLaPantalla($institucion)) {
            return Mail::mailer();
        }

        config(['mail.mailers.'.self::NOMBRE => [
            'transport' => 'smtp',
            'host' => trim((string) $institucion->correo_servidor),
            'port' => (int) $institucion->correo_puerto,
            // `smtps` es SSL directo y `smtp` es STARTTLS. Es el mismo
            // vocabulario que `MAIL_SCHEME`, para no traducir en dos sitios.
            'scheme' => (string) $institucion->correo_cifrado,
            'username' => trim((string) $institucion->correo_usuario),
            'password' => (string) $institucion->correo_clave,
            // El mismo tope corto que el `.env`, y por lo mismo: esto se envia
            // SIN cola, asi que quien pulsa el boton espera al servidor de
            // correo. Sin tope, esa espera se la come el CDN a los ~60 s.
            'timeout' => (int) config('mail.mailers.smtp.timeout', 10),
        ]]);

        return Mail::mailer(self::NOMBRE);
    }
}
