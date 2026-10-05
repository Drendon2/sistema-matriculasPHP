<?php

/**
 * El numero de documento del PERSONAL (05/10/2026, pedido del usuario).
 *
 * Hasta hoy el documento solo existia en `datos_estudiante`, que es la ficha
 * del estudiante: un profesor no tenia donde guardarlo, y el registro de
 * profesores no lo pedia. Va en `perfiles` y no dandole una ficha de estudiante
 * al profesor, porque esa ficha arrastra acudiente, papeles y consentimiento,
 * que son cosas de un estudiante.
 *
 * NULO es legitimo: las cuentas anteriores no lo tienen, y el estudiante lo
 * sigue guardando en su ficha. A un profesor o director que entra sin el se le
 * pide antes de dejarle seguir (`App\Http\Middleware\DatosDelPersonal`).
 *
 * Unico entre el personal, con los nulos libres (MariaDB y PostgreSQL admiten
 * varios NULL bajo un indice unico).
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('perfiles', function (Blueprint $table) {
            $table->string('documento_identidad', 15)->nullable()->unique()->after('telefono');
        });
    }

    public function down(): void
    {
        Schema::table('perfiles', function (Blueprint $table) {
            $table->dropUnique(['documento_identidad']);
            $table->dropColumn('documento_identidad');
        });
    }
};
