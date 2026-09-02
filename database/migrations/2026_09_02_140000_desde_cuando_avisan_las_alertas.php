<?php

/**
 * Desde cuando cuentan las alertas de un periodo.
 *
 * Sale de produccion el mismo dia que las alertas: aparecieron 596 avisos desde
 * enero, y casi todos eran del tiempo en que el periodo ya habia empezado pero
 * las clases todavia no. El periodo arranca cuando se abren las matriculas —hay
 * semanas de inscribir gente y armar grupos antes de que nadie de una clase— asi
 * que `fecha_inicio` no sirve como origen: el aviso era correcto y era inutil.
 *
 * Va en el PERIODO y no en la configuracion de la institucion, que era la otra
 * opcion. Cada semestre las clases arrancan en una fecha distinta, asi que en la
 * configuracion habria que acordarse de moverla en cada periodo nuevo — y el dia
 * que a alguien se le olvide el fallo es el contrario y peor: alertas que NO
 * salen, y de esas no avisa nadie.
 *
 * NULA significa «desde el inicio del periodo», que es como se comportaba hasta
 * hoy. Asi los periodos que ya existen no cambian de comportamiento al migrar, y
 * quien no quiera usar esto no tiene que llenar nada.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('periodos', function (Blueprint $table) {
            $table->date('alertas_desde')->nullable()->after('fecha_fin');
        });
    }

    public function down(): void
    {
        Schema::table('periodos', function (Blueprint $table) {
            $table->dropColumn('alertas_desde');
        });
    }
};
