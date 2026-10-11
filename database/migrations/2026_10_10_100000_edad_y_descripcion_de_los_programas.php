<?php

/*
 * Edad minima, descripcion y palabras clave de los programas (10/10/2026).
 *
 * Las pide el agente de voz: la gente llama diciendo «algo de tambores» y no
 * el nombre del programa, y la edad tiene que filtrarse en el SERVIDOR para
 * que el modelo no pueda ofrecer lo que no corresponde.
 *
 * LAS TRES NACEN VACIAS y se quedan asi hasta que alguien las escriba al crear
 * o editar (decision del usuario). Vacia significa «no se ha dicho» y no filtra
 * a nadie: una edad minima de 0 sembrada aqui seria una afirmacion que nadie
 * hizo.
 *
 * En `actividades` solo la edad: un curso o un taller se busca por su nombre,
 * que ya dice lo que es.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('promotorias', function (Blueprint $table) {
            $table->unsignedTinyInteger('edad_minima')->nullable()->after('profesor_id');
            $table->string('descripcion', 300)->nullable()->after('edad_minima');
            $table->string('palabras_clave', 300)->nullable()->after('descripcion');
        });

        Schema::table('actividades', function (Blueprint $table) {
            $table->unsignedTinyInteger('edad_minima')->nullable()->after('cupo_maximo');
        });
    }

    public function down(): void
    {
        Schema::table('promotorias', function (Blueprint $table) {
            $table->dropColumn(['edad_minima', 'descripcion', 'palabras_clave']);
        });

        Schema::table('actividades', function (Blueprint $table) {
            $table->dropColumn('edad_minima');
        });
    }
};
