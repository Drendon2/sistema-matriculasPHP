<?php

namespace Tests\Support;

use App\Support\ConexionQueReintenta;
use PDO;
use ReflectionClass;
use Throwable;

/**
 * Un conector que no toca la red: lanza lo que se le diga, en orden, y cuenta
 * los intentos.
 *
 * Sobrescribe `createPdoConnection` y no `createConnection` para que lo que se
 * ejercite sea el bucle de verdad, con el `Connector` de Laravel en medio.
 *
 * Vive en su propio archivo y no dentro del de la prueba porque `tests/` esta
 * bajo PSR-4: dos clases en un archivo dejan el autoload en falta.
 */
class ConectorDeGuion extends ConexionQueReintenta
{
    public int $intentos = 0;

    /** @param  array<int, Throwable|null>  $guion */
    public function __construct(private array $guion) {}

    protected function createPdoConnection($dsn, $username, $password, $options): PDO
    {
        $this->intentos++;
        $fallo = array_shift($this->guion);

        if ($fallo !== null) {
            throw $fallo;
        }

        // Un PDO sin construir: aqui no se consulta nada, solo hace falta que el
        // bucle tenga algo que devolver. Y sin SQLite, que en este proyecto no
        // se usa ni para esto.
        return (new ReflectionClass(PDO::class))->newInstanceWithoutConstructor();
    }
}
