<?php

/**
 * El fondo de pagina y la cabecera, configurables por institucion (05/10/2026,
 * pedido del usuario: «fondo y cabecera»).
 *
 * Hasta hoy la entidad elegia UN color, el de acento. Estos dos se suman con
 * reglas que no se deducen del esquema y viven en `ConfiguracionController`:
 *
 * - El fondo tiene que ser CLARO: el texto secundario tiene que seguir en
 *   4,5:1 sobre el. Se rechaza al guardar.
 * - La cabecera puede ser de cualquier color: el texto se elige solo (blanco u
 *   oscuro, el que mas contraste de), y solo se rechazan los tonos medios con
 *   los que ninguno de los dos llega a 4,5:1.
 * - En MODO OSCURO no se usan: un fondo claro elegido para el dia quemaria la
 *   pantalla de noche.
 *
 * VACIO significa «el de fabrica»: ninguna institucion cambia nada al desplegar.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('configuracion_institucion', function (Blueprint $table) {
            $table->string('color_fondo', 7)->default('')->after('color_acento');
            $table->string('color_cabecera', 7)->default('')->after('color_fondo');
        });
    }

    public function down(): void
    {
        Schema::table('configuracion_institucion', function (Blueprint $table) {
            $table->dropColumn(['color_fondo', 'color_cabecera']);
        });
    }
};
