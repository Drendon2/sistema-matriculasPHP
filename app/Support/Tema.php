<?php

namespace App\Support;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Claro, oscuro, o lo que diga el sistema operativo.
 *
 * ─── POR QUE UNA GALLETA Y NO UNA COLUMNA ──────────────────────────────────
 *
 * La preferencia se guarda en una galleta y no en la base de datos, y es una
 * decision con tres razones:
 *
 * 1. EL SERVIDOR TIENE QUE SABERLO ANTES DE PINTAR. La alternativa —leerlo con
 *    JavaScript al cargar— significa que la pagina se pinta en claro y salta a
 *    oscuro un instante despues: un fogonazo blanco en la cara de quien
 *    encendio el modo oscuro precisamente para no tener uno. Con la galleta, el
 *    `<html>` sale ya estampado del servidor.
 * 2. FUNCIONA ANTES DE ENTRAR. La pantalla de login no sabe quien mira; una
 *    columna en `users` no sirve de nada ahi.
 * 3. EL TEMA ES DEL APARATO, NO DE LA PERSONA. Quien tiene el telefono en
 *    oscuro y el computador en claro quiere exactamente eso, y una preferencia
 *    que le sigue entre dispositivos se la rompe.
 *
 * ─── DOS OPCIONES QUE SE PUEDEN ELEGIR, Y UN ESTADO INICIAL QUE NO ─────────
 *
 * Hasta el 12/09/2026 habia TRES opciones y «lo que diga mi dispositivo» era
 * una de ellas, en un selector de radios en Mi perfil. Ese dia el usuario pidio
 * un solo boton en el menu, sol y luna, y con el se fue esa tercera opcion.
 * Esta seccion sustituye a la que decia «tres estados, no dos».
 *
 * LO QUE NO CAMBIO ES EL ESTADO INICIAL: quien no ha elegido nunca no tiene
 * galleta, y sin galleta se sigue al sistema. Eso lo hace el CSS con
 * `color-scheme: light dark` y no hay forma mejor de empezar — en un telefono
 * es lo que la persona ya decidio una vez para todas sus aplicaciones. O sea
 * que «seguir al sistema» sigue siendo de donde se parte; lo que ya no hay es
 * un control para VOLVER ahi.
 *
 * SE DECIDIO CON ESE COSTE DELANTE: en cuanto alguien toca el boton, su aparato
 * queda fijado a claro o a oscuro y solo borrando la galleta del navegador
 * vuelve a seguir al sistema. El usuario lo eligio asi el 12/09/2026, con la
 * alternativa —dejar «dispositivo» en Mi perfil como camino de vuelta— sobre la
 * mesa. Si algun dia molesta, lo que hay que devolver es esa tercera opcion, no
 * inventar otra.
 */
class Tema
{
    public const GALLETA = 'tema';

    /**
     * Los dos que se pueden elegir. Ya no entra «sistema»: desde el 12/09/2026
     * no hay pantalla que lo ofrezca, y dejarlo aceptado en la ruta seria una
     * puerta sin picaporte — codigo que sostiene una regla que ninguna pantalla
     * usa es justo lo que en este proyecto dejo divergir un cupo durante dias.
     * Hay una prueba que comprueba que la ruta lo RECHAZA.
     */
    public const OPCIONES = ['claro', 'oscuro'];

    /** Un ano: es una preferencia de aspecto, no una sesion. */
    private const DIAS = 365;

    /**
     * Lo que hay que poner en `data-tema`, o cadena vacia para seguir al
     * sistema.
     */
    public static function delAparato(Request $peticion): string
    {
        $valor = (string) $peticion->cookie(self::GALLETA, '');

        return in_array($valor, ['claro', 'oscuro'], true) ? $valor : '';
    }

    /**
     * La galleta que guarda la eleccion.
     *
     * NO es `httpOnly`, y eso se queda: no hay nada que proteger, porque dice
     * si alguien prefiere el fondo oscuro.
     *
     * PERO OJO CON LO QUE ESO NO DA. Aqui decia que dejarla legible permitiria
     * que un guion la leyera «sin preguntarle al servidor», y eso NUNCA FUE
     * CIERTO: `EncryptCookies` esta en la pila y `tema` no esta exceptuada, asi
     * que lo que llega a `document.cookie` es un bloque cifrado con iv, valor y
     * firma. JavaScript ve la galleta y no su contenido, y una escrita a mano no
     * la descifra el servidor: la descarta en silencio. Comprobado el
     * 12/09/2026 leyendo el `Set-Cookie` de esta misma ruta.
     *
     * Por eso `public/js/tema.js` cambia el atributo del `<html>` para que el
     * color se vea al instante y manda IGUAL el POST por detras, que es lo
     * unico que deja la eleccion guardada.
     */
    public static function galleta(string $eleccion): Cookie
    {
        if (! in_array($eleccion, self::OPCIONES, true)) {
            // No deberia llegar: la ruta valida contra OPCIONES antes. Se queda
            // como suelo, y borrar es lo correcto para un valor que no se
            // entiende — deja el aparato siguiendo a su sistema, que es de donde
            // se parte.
            return cookie()->forget(self::GALLETA);
        }

        return cookie(self::GALLETA, $eleccion, 60 * 24 * self::DIAS, httpOnly: false);
    }
}
