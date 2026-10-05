<?php

/**
 * El nombre corto de la institucion, para el icono del celular (05/10/2026,
 * pedido del usuario).
 *
 * El acceso directo de la pantalla de inicio pone el nombre bajo el icono, y
 * ahi caben unos doce caracteres: «Casa de la Cultura Luis Norberto…» salia
 * cortado en «Casa de la Cul…». Este campo es el que se pone ahi.
 *
 * VACIO significa «usa el nombre largo», que es como era hasta hoy: ninguna
 * institucion cambia nada al desplegar.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('configuracion_institucion', function (Blueprint $table) {
            $table->string('nombre_corto', 20)->default('')->after('nombre_institucion');
        });
    }

    public function down(): void
    {
        Schema::table('configuracion_institucion', function (Blueprint $table) {
            $table->dropColumn('nombre_corto');
        });
    }
};
