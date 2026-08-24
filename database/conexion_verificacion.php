<?php

/**
 * La conexion que usan los guiones de verificacion de `database/`, con las dos
 * barreras que impiden que se lleven por delante una base que importa.
 *
 * ─── Por que existe ────────────────────────────────────────────────────────
 *
 * Antes, cada guion traia escritas a mano las credenciales y el puerto de UNA
 * maquina: `127.0.0.1:3307`, usuario y contrasena `matriculas`. Eso publicaba
 * unas credenciales en el repositorio y obligaba a editar tres archivos para
 * correrlos en cualquier otro sitio.
 *
 * Pero hacia tambien algo bueno POR ACCIDENTE: en el servidor esa conexion
 * fallaba —alli la base va por socket y con otras credenciales—, asi que un
 * guion que empieza con `TRUNCATE` de once tablas no llegaba a hacer nada.
 * Leer el `.env` quita el problema de las credenciales y **destruye esa
 * proteccion accidental**: el mismo guion, en el servidor, apuntaria a
 * produccion y la vaciaria.
 *
 * De ahi que la barrera sea ahora explicita. Una proteccion que funciona por
 * accidente es una proteccion que se pierde en el primer arreglo que parezca
 * bueno.
 *
 * ─── Las dos barreras ──────────────────────────────────────────────────────
 *
 * 1. `APP_ENV` tiene que ser `local`. Cierra en FALSO: si la variable falta, o
 *    dice cualquier otra cosa, no se conecta. Es lo que protege produccion.
 * 2. Los guiones que vacian tablas exigen ademas `--borrar-datos` en la linea
 *    de ordenes (ver `confirmarBorradoDeDatos()`). Eso protege la base de
 *    desarrollo de quien —con toda la razon— cree que un guion llamado
 *    «verificacion» solo verifica.
 *
 * Se lee el `.env` a mano y NO se ejecuta, por la misma razon que lo escribe
 * `respaldar.sh`: un `source` haria correr cualquier cosa que alguien haya
 * dejado escrita ahi. Y no se levanta Laravel entero porque el sentido de estos
 * guiones es hablar con el motor sin el ORM de por medio.
 *
 * Se usa asi:
 *
 *     $db = require __DIR__.'/conexion_verificacion.php';
 *     confirmarBorradoDeDatos($db);   // solo los que vacian tablas
 */

/**
 * Segunda barrera: exige `--borrar-datos` y dice lo que se va a llevar.
 *
 * Va aqui y no en cada guion para que los dos digan lo mismo, y para que el
 * tercero que se escriba lo tenga sin acordarse.
 */
function confirmarBorradoDeDatos(PDO $db): void
{
    if (in_array('--borrar-datos', $_SERVER['argv'] ?? [], true)) {
        return;
    }

    $guion = basename($_SERVER['argv'][0] ?? 'el guion');

    fwrite(STDERR, "\n{$guion} NO es solo una comprobacion: VACIA la base antes de empezar.\n\n");

    // Se dice cuanto hay, no solo que se va a borrar: «11 tablas» no frena a
    // nadie, «589 matriculas» si.
    foreach (['perfiles', 'matriculas', 'promotorias', 'clases', 'asistencias'] as $tabla) {
        try {
            $cuantas = (int) $db->query("SELECT COUNT(*) FROM `{$tabla}`")->fetchColumn();
        } catch (PDOException) {
            continue;
        }

        if ($cuantas > 0) {
            fwrite(STDERR, sprintf("  se perderian %6d %s\n", $cuantas, $tabla));
        }
    }

    fwrite(STDERR, "\nSi la base es desechable, vuelve a lanzarlo asi:\n");
    fwrite(STDERR, "  php {$_SERVER['argv'][0]} --borrar-datos\n\n");

    exit(1);
}

$env = dirname(__DIR__).'/.env';

if (! is_file($env)) {
    fwrite(STDERR, "No encuentro el .env: sin el no se a que base conectarme.\n");
    exit(1);
}

/** Lee una variable del .env sin interpretarlo. */
$leer = static function (string $clave) use ($env): string {
    foreach (file($env, FILE_IGNORE_NEW_LINES) as $linea) {
        if (str_starts_with($linea, $clave.'=')) {
            return trim(substr($linea, strlen($clave) + 1), " \t\"'");
        }
    }

    return '';
};

// ─── Primera barrera ───────────────────────────────────────────────────────
// Cierra en falso a proposito: se compara contra 'local' y no contra
// 'production'. Un `.env` sin APP_ENV, o con 'staging', o con una errata, no
// entra. Al reves —negar solo 'production'— cualquier valor inesperado abriria
// la puerta, que es justo lo contrario de lo que hace falta aqui.
$entorno = $leer('APP_ENV');

if ($entorno !== 'local') {
    fwrite(STDERR, "\nEste guion solo corre con APP_ENV=local.\n");
    fwrite(STDERR, 'El .env de aqui dice: '.($entorno === '' ? '(vacio o ausente)' : $entorno)."\n\n");
    fwrite(STDERR, "Vacia tablas antes de empezar. En un servidor eso es la base de la institucion:\n");
    fwrite(STDERR, "las matriculas, las asistencias y los documentos de todo el mundo.\n\n");
    exit(1);
}

$servidor = $leer('DB_HOST') ?: '127.0.0.1';
$puerto = $leer('DB_PORT') ?: '3306';
$base = $leer('DB_DATABASE');
$usuario = $leer('DB_USERNAME');
$clave = $leer('DB_PASSWORD');

if ($base === '' || $usuario === '') {
    fwrite(STDERR, "El .env no trae DB_DATABASE o DB_USERNAME.\n");
    exit(1);
}

try {
    return new PDO(
        "mysql:host={$servidor};port={$puerto};dbname={$base};charset=utf8mb4",
        $usuario,
        $clave,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException) {
    // El mensaje del motor NO se imprime tal cual: lleva el usuario y a veces el
    // servidor. Lo que hace falta saber aqui es a donde se intento entrar.
    fwrite(STDERR, "No pude conectar a {$base} en {$servidor}:{$puerto}.\n");
    fwrite(STDERR, "Revisa las credenciales del .env y que la base este levantada.\n");
    exit(1);
}
