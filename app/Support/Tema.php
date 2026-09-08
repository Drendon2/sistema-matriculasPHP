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
 * ─── TRES ESTADOS, NO DOS ──────────────────────────────────────────────────
 *
 * Sin galleta se sigue al sistema, que es lo que hace bien la inmensa mayoria
 * de las veces —y en un telefono es lo que la persona ya decidio una vez para
 * todas sus aplicaciones—. La galleta solo existe para quien quiere lo
 * contrario de lo que dice su sistema. Por eso «sistema» se guarda BORRANDO la
 * galleta y no escribiendo la palabra: el estado por defecto no deja rastro.
 */
class Tema
{
    public const GALLETA = 'tema';

    /** Los dos que se pueden forzar. «sistema» es la ausencia de los dos. */
    public const OPCIONES = ['sistema', 'claro', 'oscuro'];

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

    /** Lo que hay que marcar en el selector de Mi perfil. */
    public static function elegido(Request $peticion): string
    {
        return self::delAparato($peticion) ?: 'sistema';
    }

    /**
     * La galleta que guarda la eleccion, o la que la borra.
     *
     * NO es `httpOnly`, y eso es deliberado: no hay nada que proteger —dice si
     * alguien prefiere el fondo oscuro— y dejarla legible permite que el dia
     * que haga falta un cambio instantaneo sin recargar, el guion pueda leerla
     * sin preguntarle al servidor.
     */
    public static function galleta(string $eleccion): Cookie
    {
        if (! in_array($eleccion, self::OPCIONES, true) || $eleccion === 'sistema') {
            // Borrar y no escribir «sistema»: el estado por defecto no deja
            // rastro, asi que volver a el es quitar la galleta.
            return cookie()->forget(self::GALLETA);
        }

        return cookie(self::GALLETA, $eleccion, 60 * 24 * self::DIAS, httpOnly: false);
    }
}
