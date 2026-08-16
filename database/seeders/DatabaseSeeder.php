<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Punto de entrada de `php artisan db:seed`.
 *
 * Deliberadamente NO siembra nada. Los datos de ejemplo de este proyecto crean
 * cuentas con contrasenas conocidas, y eso no tiene sitio en un repositorio: una
 * base recien migrada arranca vacia y el primer usuario se crea desde la propia
 * aplicacion.
 *
 * La clase existe igualmente —en vez de borrarse— porque Laravel la espera:
 * sin ella, `db:seed` y `migrate --seed` fallan con un error de clase no
 * encontrada en cuanto alguien clona el repositorio.
 *
 * En una maquina de desarrollo se puede dejar al lado un `DemoSeeder` con datos
 * de prueba (ignorado por git). Si esta presente se usa, y si no, esto no hace
 * nada: asi el mismo `php artisan migrate --seed` sirve en los dos casos sin
 * tener que acordarse de pasar `--class`.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        if (class_exists(DemoSeeder::class)) {
            $this->call(DemoSeeder::class);
        }
    }
}
