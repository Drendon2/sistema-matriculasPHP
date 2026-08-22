<?php

/**
 * Cuantos grupos de proyeccion puede acumular una persona, ademas de sus
 * promotorias.
 *
 * Es un segundo tope y no «sin tope» a proposito. Una proyeccion no gasta plaza
 * del limite de matriculas, pero SI gasta una ranura del esquema: `ranura` es
 * NOT NULL y el CHECK `ranura_valida` la corta en RANURA_MAXIMA_ABSOLUTA. Sin
 * un segundo numero habria que hacer la ranura nullable, que es un cambio mucho
 * mayor para una libertad que nadie ha pedido todavia.
 *
 * De ahi sale la unica regla que ata los dos numeros: la SUMA no puede pasar de
 * RANURA_MAXIMA_ABSOLUTA, o habria matriculas que no encuentran ranura donde
 * colocarse. Se valida en `ConfiguracionController`, que es donde se puede
 * explicar; aqui solo queda el rango de cada uno por separado, porque un CHECK
 * de MariaDB no puede leer la configuracion de otra fila para sumar.
 *
 * Empieza en 2, que es lo que pidio la institucion.
 */

use App\Models\ConfiguracionInstitucion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('configuracion_institucion', function (Blueprint $table) {
            $table->unsignedTinyInteger('limite_proyecciones_por_periodo')
                ->default(2)
                ->after('limite_promotorias_por_periodo');
        });

        // Admite 0: una institucion que no ofrezca proyecciones no tiene por que
        // reservarles ranuras. El limite de promotorias, en cambio, empieza en 1
        // porque un sistema donde nadie puede matricularse no tiene sentido.
        $techo = ConfiguracionInstitucion::RANURA_MAXIMA_ABSOLUTA - 1;

        DB::statement("
            ALTER TABLE configuracion_institucion
            ADD CONSTRAINT limite_proyecciones_valido
            CHECK (limite_proyecciones_por_periodo BETWEEN 0 AND {$techo})
        ");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE configuracion_institucion DROP CONSTRAINT limite_proyecciones_valido');

        Schema::table('configuracion_institucion', function (Blueprint $table) {
            $table->dropColumn('limite_proyecciones_por_periodo');
        });
    }
};
