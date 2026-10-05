<?php

/**
 * El domingo tambien es dia de clase (05/10/2026, pedido del usuario: «el dia
 * domingo en todo»).
 *
 * Hasta hoy la semana de la casa iba de lunes a sabado y el motor lo
 * garantizaba con `dia_valido` (1-6). Se ensancha a 1-7, que es el
 * `dayOfWeekIso` del domingo: el orden ISO-8601 se mantiene y ordenar por el
 * numero sigue siendo ordenar por la semana.
 *
 * Solo ENSANCHA: ninguna fila existente queda fuera, asi que no hay datos que
 * mover. La vuelta atras si puede fallar, y es lo correcto: con un grupo que ya
 * da clase el domingo, volver a 1-6 no tiene donde poner esa fila.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE sesiones_grupo DROP CONSTRAINT dia_valido');
        DB::statement('ALTER TABLE sesiones_grupo ADD CONSTRAINT dia_valido CHECK (dia BETWEEN 1 AND 7)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE sesiones_grupo DROP CONSTRAINT dia_valido');
        DB::statement('ALTER TABLE sesiones_grupo ADD CONSTRAINT dia_valido CHECK (dia BETWEEN 1 AND 6)');
    }
};
