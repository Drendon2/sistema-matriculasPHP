<?php

/**
 * El plazo para reponer una falta (03/10/2026, pedido del usuario).
 *
 * Una clase que la bandeja de alertas marca como FALTA le aparece al profesor
 * en el Panel para reponerla. Pasados N dias sin reponerse se SEÑALA como
 * vencida —en el Panel, en la bandeja y en sus estadisticas—. No se bloquea
 * nada: una reposicion tardia vale mas que ninguna, y el sistema no puede
 * avisar por ningun otro canal.
 *
 * - `configuracion_institucion.dias_para_reponer`: el plazo. NULO es «sin
 *   plazo», que es una decision de la entidad y no un olvido; por eso la
 *   columna admite nulo y nace en 15.
 * - `omisiones_archivadas.clasificada_en`: desde cuando corre. Cuenta desde que
 *   se dijo la causa y NO desde el dia de la clase: es cuando el profesor se
 *   entera, y una falta clasificada dos semanas tarde naceria vencida. Se
 *   rellena para las que ya tienen causa con su `updated_at`, que es lo mas
 *   cercano que hay.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('configuracion_institucion', function (Blueprint $table) {
            $table->unsignedSmallInteger('dias_para_reponer')->nullable()->default(15);
        });

        Schema::table('omisiones_archivadas', function (Blueprint $table) {
            $table->dateTime('clasificada_en')->nullable()->after('causa');
        });

        DB::table('omisiones_archivadas')
            ->whereNotNull('causa')
            ->update(['clasificada_en' => DB::raw('updated_at')]);
    }

    public function down(): void
    {
        Schema::table('omisiones_archivadas', function (Blueprint $table) {
            $table->dropColumn('clasificada_en');
        });

        Schema::table('configuracion_institucion', function (Blueprint $table) {
            $table->dropColumn('dias_para_reponer');
        });
    }
};
