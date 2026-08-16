<?php

use App\Http\Controllers\Auth\InscripcionController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PostLoginController;
use App\Http\Controllers\Auth\RegistroController;
use App\Http\Controllers\CatalogoController;
use App\Http\Controllers\MatricularController;
use App\Http\Controllers\MisMatriculasController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Rutas
|--------------------------------------------------------------------------
|
| Los nombres siguen a los del original (`matriculas/urls.py`) para que las dos
| versiones se puedan comparar de un vistazo, cambiando solo el guion bajo por
| guion medio, que es la convencion de Laravel.
|
| Las rutas marcadas como PENDIENTES al final existen ya como nombre porque las
| pantallas terminadas enlazan a ellas y `route()` falla con un nombre que no
| existe. Se sustituyen por la pantalla real cuando le toque a cada una.
|
*/

// ---------------------------------------------------------------------------
// Publico, sin sesion
// ---------------------------------------------------------------------------

Route::middleware('guest')->group(function () {
    Route::get('/entrar', [LoginController::class, 'mostrar'])->name('login');
    Route::post('/entrar', [LoginController::class, 'entrar'])->name('login.entrar');

    Route::get('/registro', [RegistroController::class, 'mostrar'])->name('registro');
    Route::post('/registro', [RegistroController::class, 'guardar'])->name('registro.guardar');

    Route::get('/inscripcion', [InscripcionController::class, 'mostrar'])->name('inscripcion');
    Route::post('/inscripcion', [InscripcionController::class, 'guardar'])->name('inscripcion.guardar');
});

Route::post('/salir', [LoginController::class, 'salir'])->middleware('auth')->name('logout');

// ---------------------------------------------------------------------------
// Con sesion
// ---------------------------------------------------------------------------

Route::middleware('auth')->group(function () {
    Route::get('/post-login', PostLoginController::class)->name('post-login');

    Route::get('/pendiente-aprobacion', [PostLoginController::class, 'pendienteAprobacion'])
        ->name('pendiente-aprobacion');
});

// ---------------------------------------------------------------------------
// Estudiante
// ---------------------------------------------------------------------------

Route::middleware(['auth', 'rol:estudiante'])->group(function () {
    Route::get('/', CatalogoController::class)->name('promotorias-disponibles');

    Route::post('/matricular/{promotoria}', MatricularController::class)->name('matricular');

    Route::get('/mis-matriculas', [MisMatriculasController::class, 'index'])->name('mis-matriculas');
    Route::post('/mis-matriculas/{matricula}/retirar', [MisMatriculasController::class, 'retirar'])
        ->name('mis-matriculas.retirar');
});

// ---------------------------------------------------------------------------
// PENDIENTES de construir
// ---------------------------------------------------------------------------

Route::view('/renovar', 'pendiente')->name('renovar-matricula');
Route::view('/mis-clases', 'pendiente')->name('mis-clases');
Route::view('/mis-companeros', 'pendiente')->name('mis-companeros');
Route::view('/mi-perfil', 'pendiente')->name('mi-perfil');
Route::view('/panel', 'pendiente')->name('panel');
Route::view('/gestion', 'pendiente')->name('gestion-inicio');
Route::view('/logo', 'pendiente')->name('logo-institucion');
