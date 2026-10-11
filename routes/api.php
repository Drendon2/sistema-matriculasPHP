<?php

use App\Http\Controllers\Api\CatalogoAgenteController;
use App\Http\Controllers\Api\PersonaAgenteController;
use App\Http\Middleware\DatosPersonalesDelAgente;
use App\Http\Middleware\TokenDelAgente;
use Illuminate\Support\Facades\Route;

/*
 * La API del agente de voz (10/10/2026). SOLO LECTURA: ningun endpoint de aqui
 * escribe en la base de matriculas. Apagada sin token (ver config/agente.php).
 */
Route::prefix('v1')
    ->middleware([TokenDelAgente::class, 'throttle:agente'])
    ->group(function () {
        Route::get('catalogo', [CatalogoAgenteController::class, 'catalogo'])->name('api-agente-catalogo');
        Route::get('promotorias/{promotoria}/disponibilidad', [CatalogoAgenteController::class, 'disponibilidad'])
            ->whereNumber('promotoria')
            ->name('api-agente-disponibilidad');

        // Lo personal, solo con `agente.datos_personales` encendido.
        Route::middleware(DatosPersonalesDelAgente::class)->group(function () {
            Route::post('verificacion', [PersonaAgenteController::class, 'verificar'])->name('api-agente-verificacion');
            Route::get('me/matriculas', [PersonaAgenteController::class, 'matriculas'])->name('api-agente-matriculas');
            Route::get('me/asistencia', [PersonaAgenteController::class, 'asistencia'])->name('api-agente-asistencia');
        });
    });
