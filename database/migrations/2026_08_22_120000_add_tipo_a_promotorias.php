<?php

/**
 * Una promotoria pasa a tener TIPO, y el tipo cambia como se comporta.
 *
 * Hasta ahora todo lo que ofrece la institucion era lo mismo: una promotoria
 * que dura el periodo entero y ocupa una de las plazas del estudiante. Eso
 * describe bien una casa de la cultura, pero no una academia ni un colegio, y
 * sobre todo no describe los grupos de proyeccion, que son otra cosa.
 *
 * Cuatro tipos, y solo el ultimo cambia una regla de negocio:
 *
 * - TALLER    una sola clase; la gente se inscribe y va una vez.
 * - CURSO     varias clases, pero sin llegar al final del periodo.
 * - PROGRAMA  clases durante todo el periodo. Es lo que habia hasta hoy.
 * - PROYECCION  no consume plaza del limite de matriculas: un estudiante que ya
 *               llego a su tope PUEDE entrar, porque es una actividad alineada
 *               con la matricula que ya tiene.
 *
 * Los tres primeros se diferencian HOY solo en el nombre y en cuantas clases se
 * les creen. No llevan fechas propias ni numero de sesiones a proposito: el
 * sistema no obliga nada y lo que manda es lo que se cree, que es reversible.
 * Ponerle fechas seria una validacion mas que estorba antes de saber si hace
 * falta.
 *
 * `programa` es el valor por defecto porque es exactamente lo que son todas las
 * filas que ya existen: esta migracion no cambia el comportamiento de nada.
 */

use App\Models\Promotoria;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('promotorias', function (Blueprint $table) {
            $table->string('tipo', 20)->default(Promotoria::PROGRAMA)->after('area_id');
        });

        // El mismo papel que `color_acento_hex` o `nombre_no_vacio`: la
        // aplicacion valida donde se puede dar un mensaje legible, y esto ataja
        // lo que entre por un seeder, una migracion o una mano en la base.
        $lista = collect(Promotoria::TIPOS)->map(fn ($t) => "'{$t}'")->implode(', ');

        DB::statement("
            ALTER TABLE promotorias
            ADD CONSTRAINT tipo_valido
            CHECK (tipo IN ({$lista}))
        ");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE promotorias DROP CONSTRAINT tipo_valido');

        Schema::table('promotorias', function (Blueprint $table) {
            $table->dropColumn('tipo');
        });
    }
};
