<?php

/**
 * Por que no se dicto una clase, y con que clase se repuso.
 *
 * Hasta hoy la bandeja de alertas solo sabia ARCHIVAR una clase no dictada:
 * «ya lo hable con quien dicta». Eso limpiaba la bandeja y no decia nada mas,
 * asi que una incapacidad y una falta sin aviso pesaban igual en «Mis
 * estadisticas», y nadie llevaba la cuenta de lo que habia que reponer.
 * Decision del usuario del 03/10/2026: al atender la alerta se dice POR QUE.
 *
 * - `excusa`: el profesor tenia excusa. No se repone y no le cuenta como
 *   perdida.
 * - `falta`: no la dio y no hay excusa. Le cuenta como perdida —aunque la
 *   reponga: la clase de ese dia no se dio igual— y le aparece en el Panel en
 *   «Clases por reemplazar».
 * - `institucion`: ese dia no habia clase por decision de la casa (festivo,
 *   evento, cierre). Ni le cuenta ni se repone.
 *
 * NULO es lo que ya habia: archivada sin clasificar. Sigue contando como
 * perdida, que es como contaba, hasta que alguien la clasifique.
 *
 * `repuesta_en_id` es la clase que la repuso, y es UNICA: una clase repone una
 * falta y no dos. La reposicion se registra como cualquier clase —lista y
 * confirmacion de los estudiantes—, asi que tiene la misma evidencia detras y
 * no depende de que alguien marque «ya la repuso» a mano. `nullOnDelete`: si
 * esa clase desaparece, la falta vuelve a estar por reponer, que es la verdad.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('omisiones_archivadas', function (Blueprint $table) {
            $table->string('causa', 12)->nullable()->after('fecha');
            $table->foreignId('repuesta_en_id')->nullable()->after('causa')
                ->unique()->constrained('clases')->nullOnDelete();
        });

        DB::statement("
            ALTER TABLE omisiones_archivadas
            ADD CONSTRAINT causa_de_omision_valida
            CHECK (causa IS NULL OR causa IN ('excusa', 'falta', 'institucion'))
        ");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE omisiones_archivadas DROP CONSTRAINT causa_de_omision_valida');

        Schema::table('omisiones_archivadas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('repuesta_en_id');
            $table->dropColumn('causa');
        });
    }
};
