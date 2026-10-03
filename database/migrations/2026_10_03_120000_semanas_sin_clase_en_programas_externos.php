<?php

/**
 * La alerta semanal de los programas externos (03/10/2026, pedido del usuario).
 *
 * Un programa externo no tiene horario: la clase existe solo cuando el profesor
 * oprime «Iniciar clase de hoy» allá. Asi que la unica omision que se puede
 * deducir es la de una SEMANA (lunes a domingo) entera sin ninguna clase
 * iniciada. Se calcula al abrir, como las otras alertas; lo que se guarda es
 * solo lo que alguien decidio sobre ella.
 *
 * - `instituciones_externas.clases_desde` / `clases_hasta`: entre que fechas se
 *   dicta alla. Decision del usuario: se pone al crear la institucion. SIN
 *   fecha de inicio no se avisa —una institucion de antes no empieza a avisar
 *   sola el dia del despliegue—; sin fecha de fin se avisa hasta que alguien la
 *   ponga, que es lo que detiene la alerta cuando termina el convenio.
 * - `omisiones_externas`: la causa de una semana sin clase, con las mismas
 *   tres que las de los grupos (excusa, falta, festivo o cierre). La semana se
 *   guarda por su LUNES. Sin reposicion: sin horario no hay contra que medir
 *   una clase de mas.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instituciones_externas', function (Blueprint $table) {
            $table->date('clases_desde')->nullable()->after('telefono');
            $table->date('clases_hasta')->nullable()->after('clases_desde');
        });

        DB::statement('
            ALTER TABLE instituciones_externas
            ADD CONSTRAINT clases_externas_en_orden
            CHECK (clases_hasta IS NULL OR clases_desde IS NULL OR clases_hasta >= clases_desde)
        ');

        Schema::create('omisiones_externas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actividad_id')->constrained('actividades')->cascadeOnDelete();
            // El LUNES de la semana sin clase.
            $table->date('semana');
            $table->string('causa', 12);
            $table->foreignId('clasificada_por_id')->nullable()
                ->constrained('perfiles')->nullOnDelete();
            $table->timestamps();

            $table->unique(['actividad_id', 'semana'], 'omision_externa_unica_por_semana');
        });

        DB::statement("
            ALTER TABLE omisiones_externas
            ADD CONSTRAINT causa_de_omision_externa_valida
            CHECK (causa IN ('excusa', 'falta', 'institucion'))
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('omisiones_externas');

        DB::statement('ALTER TABLE instituciones_externas DROP CONSTRAINT clases_externas_en_orden');

        Schema::table('instituciones_externas', function (Blueprint $table) {
            $table->dropColumn(['clases_desde', 'clases_hasta']);
        });
    }
};
