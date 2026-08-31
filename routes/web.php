<?php

use App\Http\Controllers\ArchivoController;
use App\Http\Controllers\Auth\InscripcionController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PostLoginController;
use App\Http\Controllers\Auth\RegistroController;
use App\Http\Controllers\CatalogoController;
use App\Http\Controllers\CertificadoController;
use App\Http\Controllers\ClaseController;
use App\Http\Controllers\FichaController;
use App\Http\Controllers\Gestion;
use App\Http\Controllers\InformeController;
use App\Http\Controllers\InscripcionActividadController;
use App\Http\Controllers\MatricularController;
use App\Http\Controllers\MiPerfilController;
use App\Http\Controllers\MisClasesController;
use App\Http\Controllers\MisCompanerosController;
use App\Http\Controllers\MisMatriculasController;
use App\Http\Controllers\PanelActividadController;
use App\Http\Controllers\PanelController;
use App\Http\Controllers\PanelGrupoController;
use App\Http\Controllers\RenovarController;
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
| El orden sigue al del recorrido de la gente: primero lo publico, luego el
| estudiante, luego el personal y por ultimo Gestion. Los archivos subidos van
| aparte al final, porque no son una pantalla sino una entrega controlada.
|
*/

// ---------------------------------------------------------------------------
// Publico, sin sesion
// ---------------------------------------------------------------------------

// Los tres POST de aqui van limitados, y son los unicos del sistema que lo
// necesitan: son las tres puertas que se pueden empujar sin tener cuenta. Los
// GET quedan libres — abrir el formulario no cuesta nada y limitarlo solo
// molestaria a quien recarga.
//
// El del login cuenta por usuario+IP a traves del limitador con nombre `entrar`
// (ver `AppServiceProvider`); los otros dos van por IP, que es lo que
// corresponde cuando lo que se frena es crear cuentas en masa.
// El enlace de una actividad. Va FUERA de `guest`, al contrario que las tres de
// abajo: aquellas ofrecen crear una cuenta y no tienen sentido con la sesion ya
// abierta, pero esta no crea ninguna. Y quien comparte el enlace —el profesor,
// el director— tiene que poder abrirlo para comprobar que funciona sin cerrar
// su sesion primero.
Route::get('/inscribirse/{token}', [InscripcionActividadController::class, 'mostrar'])
    ->name('actividad-inscribirse');
Route::post('/inscribirse/{token}', [InscripcionActividadController::class, 'guardar'])
    ->middleware('throttle:10,1');

Route::middleware('guest')->group(function () {
    Route::get('/entrar', [LoginController::class, 'mostrar'])->name('login');
    Route::post('/entrar', [LoginController::class, 'entrar'])
        ->middleware('throttle:entrar')
        ->name('login.entrar');

    Route::get('/registro', [RegistroController::class, 'mostrar'])->name('registro');
    Route::post('/registro', [RegistroController::class, 'guardar'])
        ->middleware('throttle:5,1')
        ->name('registro.guardar');

    Route::get('/inscripcion', [InscripcionController::class, 'mostrar'])->name('inscripcion');
    Route::post('/inscripcion', [InscripcionController::class, 'guardar'])
        ->middleware('throttle:10,1')
        ->name('inscripcion.guardar');
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
    // El retiro pasa por una pantalla intermedia que pide la encuesta de salida.
    // Es el unico momento en que esa persona sigue estando: quien se va no
    // vuelve a entrar a contestar nada.
    Route::get('/mis-matriculas/{matricula}/retirar', [MisMatriculasController::class, 'confirmarRetiro'])
        ->name('mis-matriculas.confirmar-retiro');
    Route::post('/mis-matriculas/{matricula}/retirar', [MisMatriculasController::class, 'retirar'])
        ->name('mis-matriculas.retirar');

    Route::get('/renovar', [RenovarController::class, 'mostrar'])->name('renovar-matricula');
    Route::post('/renovar', [RenovarController::class, 'guardar'])->name('renovar-matricula.guardar');

    Route::get('/mis-clases', [MisClasesController::class, 'index'])->name('mis-clases');
    Route::post('/mis-clases/{clase}/confirmar', [MisClasesController::class, 'confirmar'])
        ->name('confirmar-clase');
    Route::post('/mis-clases/{clase}/quitar-confirmacion', [MisClasesController::class, 'retirar'])
        ->name('retirar-confirmacion-clase');

    Route::get('/mis-companeros', MisCompanerosController::class)->name('mis-companeros');
});

// «Mi perfil» es de TODOS los roles, incluidas las cuentas sin rol asignado
// todavia: es donde suben su foto y contestan la encuesta mientras esperan que
// direccion les asigne uno.
Route::middleware('auth')->group(function () {
    Route::get('/mi-perfil', [MiPerfilController::class, 'mostrar'])->name('mi-perfil');
    Route::post('/mi-perfil', [MiPerfilController::class, 'guardar'])->name('mi-perfil.guardar');
});

// ---------------------------------------------------------------------------
// Panel: el personal (todos los roles menos estudiante)
// ---------------------------------------------------------------------------

Route::middleware(['auth', 'rol:administrador,director,profesor'])->group(function () {
    Route::get('/panel', [PanelController::class, 'index'])->name('panel');

    // El cuerpo de una promotoria, que el indice pide al desplegarla. Es una
    // pagina de verdad y no un fragmento de una API: sin JavaScript se abre
    // igual y se lee, que es lo que la deja funcionando sin el.
    Route::get('/panel/promotoria/{promotoria}/cuerpo', [PanelController::class, 'cuerpo'])
        ->name('panel-promotoria-cuerpo');

    Route::post('/panel/matriculas/{matricula}/confirmar', [PanelController::class, 'confirmar'])
        ->name('panel-confirmar-matricula');
    Route::post('/panel/matriculas/{matricula}/rechazar', [PanelController::class, 'rechazar'])
        ->name('panel-rechazar-matricula');
    Route::post('/panel/matriculas/{matricula}/asignar-grupo', [PanelController::class, 'asignarGrupo'])
        ->name('panel-asignar-grupo');

    Route::post('/panel/promotoria/{promotoria}/cupo', [PanelController::class, 'cupo'])
        ->name('panel-cupo-promotoria');
    Route::post('/panel/promotoria/{promotoria}/asignar-grupo-lote', [PanelController::class, 'asignarGrupoLote'])
        ->name('panel-asignar-grupo-lote');
    Route::post('/panel/promotoria/{promotoria}/pendientes-lote', [PanelController::class, 'resolverPendientesLote'])
        ->name('panel-pendientes-lote');

    // Grupos
    // Cursos, talleres y grupos de proyeccion, del lado de quien los DA. Los
    // crea Gestion; aqui se dirige lo que ya existe.
    Route::get('/panel/actividades', [PanelActividadController::class, 'index'])
        ->name('panel-actividades');
    Route::get('/panel/actividades/{actividad}', [PanelActividadController::class, 'ver'])
        ->name('panel-actividad');
    Route::post('/panel/actividades/{actividad}/iniciar-hoy', [PanelActividadController::class, 'iniciarHoy'])
        ->name('panel-actividad-iniciar-hoy');
    Route::post('/panel/sesiones/{sesion}/iniciar', [PanelActividadController::class, 'iniciar'])
        ->name('panel-actividad-iniciar');
    Route::get('/panel/sesiones/{sesion}/lista', [PanelActividadController::class, 'lista'])
        ->name('panel-actividad-lista');
    Route::post('/panel/sesiones/{sesion}/lista', [PanelActividadController::class, 'guardarLista']);
    Route::post('/panel/sesiones/{sesion}/anadir', [PanelActividadController::class, 'anadirEnSesion'])
        ->name('panel-actividad-anadir');

    Route::get('/panel/promotoria/{promotoria}/grupos/nuevo', [PanelGrupoController::class, 'crear'])
        ->name('panel-grupo-nuevo');
    Route::post('/panel/promotoria/{promotoria}/grupos/nuevo', [PanelGrupoController::class, 'guardar']);
    Route::get('/panel/grupos/{grupo}/editar', [PanelGrupoController::class, 'editar'])
        ->name('panel-grupo-editar');
    Route::post('/panel/grupos/{grupo}/editar', [PanelGrupoController::class, 'actualizar']);
    Route::post('/panel/grupos/{grupo}/eliminar', [PanelGrupoController::class, 'eliminar'])
        ->name('panel-grupo-eliminar');

    // Clases y asistencia
    Route::post('/panel/grupos/{grupo}/clase-nueva', [ClaseController::class, 'nueva'])
        ->name('panel-clase-nueva');
    Route::get('/panel/grupos/{grupo}/clases', [ClaseController::class, 'delGrupo'])
        ->name('grupo-clases');
    Route::get('/panel/clases/{clase}/asistencia', [ClaseController::class, 'asistencia'])
        ->name('clase-asistencia');
    Route::post('/panel/clases/{clase}/asistencia', [ClaseController::class, 'guardarAsistencia']);

    // Fichas
    Route::get('/panel/usuario/{usuario}', [FichaController::class, 'usuario'])
        ->name('detalle-usuario');
    Route::get('/panel/estudiante/{usuario}/historial', [FichaController::class, 'historial'])
        ->name('historial-estudiante');
});

// La ficha con encuesta y documento es solo del administrador: va en su propio
// grupo porque es el unico sitio del panel con esa puerta.
Route::get('/panel/estudiante/{usuario}', [FichaController::class, 'estudiante'])
    ->middleware(['auth', 'rol:administrador'])
    ->name('detalle-estudiante');

// ---------------------------------------------------------------------------
// Informes descargables
// ---------------------------------------------------------------------------
//
// Dos alcances distintos, dos puertas distintas. El de estudiantes es operativo
// —la lista para pasar lista o llamar a una familia— y lo baja el personal, con
// el propio informe acotado a lo que cada quien puede ver. El de la institucion
// lleva la encuesta demografica con nombre, asi que es del administrador y de
// nadie mas: la misma puerta que la copia del documento de identidad.

Route::get('/informes/estudiantes', [InformeController::class, 'estudiantes'])
    ->middleware(['auth', 'rol:administrador,director,profesor'])
    ->name('informe-estudiantes');

Route::get('/informes/institucion', [InformeController::class, 'institucion'])
    ->middleware(['auth', 'rol:administrador'])
    ->name('informe-institucion');

// ---------------------------------------------------------------------------
// Certificados de matricula
// ---------------------------------------------------------------------------
//
// Sin `rol:` a proposito, y no es un descuido: quien puede bajar cada
// certificado NO se decide por el rol sino por el vinculo con la matricula —el
// propio estudiante, direccion, o el profesor que dicta esa promotoria—, y esa
// regla vive en `Permisos`. Un middleware de rol aqui dejaria fuera al
// estudiante, que es quien mas lo usa.

Route::get('/certificado/matricula/{matricula}', [CertificadoController::class, 'matricula'])
    ->middleware('auth')
    ->name('certificado-matricula');

Route::get('/certificado/estudiante/{estudiante}', [CertificadoController::class, 'todo'])
    ->middleware('auth')
    ->name('certificado-todo');

// ---------------------------------------------------------------------------
// Archivos servidos de forma controlada (nunca como carpeta publica)
// ---------------------------------------------------------------------------

Route::get('/logo', [ArchivoController::class, 'logo'])->name('logo-institucion');

// La firma NO va abierta como el logo, y esa es toda la diferencia entre las dos
// imagenes de la institucion. Una firma escaneada colgada en una URL publica se
// la lleva cualquiera para estampar el papel que quiera; solo la ve el personal,
// que es quien la configura y quien la reconoce.
Route::get('/firma', [ArchivoController::class, 'firma'])
    ->middleware(['auth', 'rol:administrador,director'])
    ->name('firma-institucion');

Route::get('/foto/{perfil}', [ArchivoController::class, 'foto'])
    ->middleware('auth')
    ->name('ver-foto');

Route::get('/documento/{datos}', [ArchivoController::class, 'documento'])
    ->middleware(['auth', 'rol:administrador'])
    ->name('descargar-documento');

// ---------------------------------------------------------------------------
// Gestion: el catalogo academico y la institucion (direccion)
// ---------------------------------------------------------------------------

Route::middleware(['auth', 'rol:administrador,director'])->prefix('gestion')->group(function () {
    Route::get('/', Gestion\InicioController::class)->name('gestion-inicio');

    // Ventana de matriculas: sin pantalla propia, se pinta en la portada (gestion-inicio)
    Route::post('/matriculas', [Gestion\MatriculasController::class, 'guardar'])->name('gestion-matriculas');

    Route::get('/cupos', [Gestion\CuposController::class, 'mostrar'])->name('gestion-cupos');
    Route::get('/cupos/{periodo}', [Gestion\CuposController::class, 'mostrar'])->name('gestion-cupos-periodo');
    Route::post('/cupos/{periodo}', [Gestion\CuposController::class, 'guardar']);

    // Cancelaciones
    Route::get('/cancelaciones', [Gestion\CancelacionesController::class, 'index'])
        ->name('gestion-cancelaciones');
    // El lote va ANTES que la de abajo: con `{matricula}` por delante, la palabra
    // «lote» se leeria como el id de una matricula y el enlace implicito
    // devolveria un 404 en vez de llegar aqui.
    Route::post('/cancelaciones/lote', [Gestion\CancelacionesController::class, 'resolverLote'])
        ->name('gestion-cancelaciones-lote');
    Route::post('/cancelaciones/{matricula}/{decision}', [Gestion\CancelacionesController::class, 'resolver'])
        ->name('gestion-resolver-cancelacion');

    // Departamentos: sin pantalla propia, se pintan en la portada (gestion-inicio).
    // "Nueva área" tampoco: es un modal en esa misma portada.
    Route::post('/areas/nueva', [Gestion\AreaController::class, 'guardar'])->name('area-nueva');
    Route::get('/areas/{objeto}/editar', [Gestion\AreaController::class, 'editar'])->name('area-editar');
    Route::post('/areas/{objeto}/editar', [Gestion\AreaController::class, 'actualizar']);
    Route::get('/areas/{objeto}/eliminar', [Gestion\AreaController::class, 'confirmarBorrado'])
        ->name('area-eliminar');
    Route::post('/areas/{objeto}/eliminar', [Gestion\AreaController::class, 'eliminar']);
    Route::get('/areas/{area}/promotorias', [Gestion\PromotoriaController::class, 'porArea'])
        ->name('promotorias-por-area');

    // Periodos: sin pantalla propia — crear/editar/eliminar son modales en la
    // tarjeta "Periodo en curso" de la portada de Gestión (gestion-inicio).
    Route::post('/periodos/nuevo', [Gestion\PeriodoController::class, 'guardar'])->name('periodo-nuevo');
    Route::post('/periodos/{objeto}/editar', [Gestion\PeriodoController::class, 'actualizar'])->name('periodo-editar');
    Route::post('/periodos/{objeto}/eliminar', [Gestion\PeriodoController::class, 'eliminar'])->name('periodo-eliminar');

    // Promotorias
    Route::get('/promotorias', [Gestion\PromotoriaController::class, 'index'])->name('promotoria-lista');
    Route::get('/promotorias/nueva', [Gestion\PromotoriaController::class, 'crear'])->name('promotoria-nueva');
    Route::post('/promotorias/nueva', [Gestion\PromotoriaController::class, 'guardar']);
    Route::get('/promotorias/{objeto}/editar', [Gestion\PromotoriaController::class, 'editar'])
        ->name('promotoria-editar');
    Route::post('/promotorias/{objeto}/editar', [Gestion\PromotoriaController::class, 'actualizar']);
    Route::get('/promotorias/{objeto}/eliminar', [Gestion\PromotoriaController::class, 'confirmarBorrado'])
        ->name('promotoria-eliminar');
    Route::post('/promotorias/{objeto}/eliminar', [Gestion\PromotoriaController::class, 'eliminar']);
    Route::get('/promotorias/{promotoria}/grupos', [Gestion\GrupoController::class, 'porPromotoria'])
        ->name('grupos-por-promotoria');

    // Grupos
    Route::get('/grupos', [Gestion\GrupoController::class, 'index'])->name('grupo-lista');
    Route::get('/grupos/nuevo', [Gestion\GrupoController::class, 'crear'])->name('grupo-nuevo');
    Route::post('/grupos/nuevo', [Gestion\GrupoController::class, 'guardar']);
    Route::get('/grupos/{objeto}/editar', [Gestion\GrupoController::class, 'editar'])->name('grupo-editar');
    Route::post('/grupos/{objeto}/editar', [Gestion\GrupoController::class, 'actualizar']);
    Route::get('/grupos/{objeto}/eliminar', [Gestion\GrupoController::class, 'confirmarBorrado'])
        ->name('grupo-eliminar');
    Route::post('/grupos/{objeto}/eliminar', [Gestion\GrupoController::class, 'eliminar']);
    Route::get('/grupos/{grupo}/estudiantes', [Gestion\GrupoController::class, 'estudiantes'])
        ->name('grupo-estudiantes');
    Route::get('/grupos/brecha-edad', [Gestion\GrupoController::class, 'brechaEdad'])->name('grupo-brecha-edad');

    // Cursos y talleres
    Route::get('/cursos', [Gestion\CursoTallerController::class, 'index'])->name('actividad-curso-lista');
    Route::get('/cursos/nuevo', [Gestion\CursoTallerController::class, 'crear'])->name('actividad-curso-nueva');
    Route::post('/cursos/nuevo', [Gestion\CursoTallerController::class, 'guardar']);
    Route::get('/cursos/{objeto}/editar', [Gestion\CursoTallerController::class, 'editar'])
        ->name('actividad-curso-editar');
    Route::post('/cursos/{objeto}/editar', [Gestion\CursoTallerController::class, 'actualizar']);
    Route::get('/cursos/{objeto}/eliminar', [Gestion\CursoTallerController::class, 'confirmarBorrado'])
        ->name('actividad-curso-eliminar');
    Route::post('/cursos/{objeto}/eliminar', [Gestion\CursoTallerController::class, 'eliminar']);
    Route::post('/cursos/{objeto}/enlace', [Gestion\CursoTallerController::class, 'alternarEnlace'])
        ->name('actividad-curso-enlace');
    // Las fechas son el segundo paso de crear un curso, y donde se le cambian
    // despues. Van con {objeto} como las de arriba: `buscar()` de esa pantalla
    // ya se encarga de que no se llegue a un grupo de proyeccion.
    Route::get('/cursos/{objeto}/fechas', [Gestion\CursoTallerController::class, 'fechas'])
        ->name('actividad-curso-fechas');
    Route::post('/cursos/{objeto}/fechas', [Gestion\CursoTallerController::class, 'guardarFechas']);

    // Grupos de proyeccion
    Route::get('/proyeccion', [Gestion\ProyeccionController::class, 'index'])
        ->name('actividad-proyeccion-lista');
    Route::get('/proyeccion/nuevo', [Gestion\ProyeccionController::class, 'crear'])
        ->name('actividad-proyeccion-nueva');
    Route::post('/proyeccion/nuevo', [Gestion\ProyeccionController::class, 'guardar']);
    Route::get('/proyeccion/{objeto}/editar', [Gestion\ProyeccionController::class, 'editar'])
        ->name('actividad-proyeccion-editar');
    Route::post('/proyeccion/{objeto}/editar', [Gestion\ProyeccionController::class, 'actualizar']);
    Route::get('/proyeccion/{objeto}/eliminar', [Gestion\ProyeccionController::class, 'confirmarBorrado'])
        ->name('actividad-proyeccion-eliminar');
    Route::post('/proyeccion/{objeto}/eliminar', [Gestion\ProyeccionController::class, 'eliminar']);
    Route::post('/proyeccion/{objeto}/enlace', [Gestion\ProyeccionController::class, 'alternarEnlace'])
        ->name('actividad-proyeccion-enlace');

    // Usuarios
    Route::get('/usuarios', [Gestion\UsuarioController::class, 'index'])->name('usuario-lista');
    Route::get('/usuarios/nuevo', [Gestion\UsuarioController::class, 'crear'])->name('usuario-nuevo');
    Route::post('/usuarios/nuevo', [Gestion\UsuarioController::class, 'guardar']);
    Route::get('/usuarios/{usuario}/editar', [Gestion\UsuarioController::class, 'editar'])
        ->name('usuario-editar');
    Route::post('/usuarios/{usuario}/editar', [Gestion\UsuarioController::class, 'actualizar']);
    Route::post('/usuarios/{usuario}/alternar-activo', [Gestion\UsuarioController::class, 'alternarActivo'])
        ->name('usuario-alternar-activo');
});

// La institucion y las estadisticas son SOLO del administrador: la primera es la
// identidad de la entidad y una regla que gobierna las matriculas de todo el
// mundo; las segundas agregan la encuesta demografica.
Route::middleware(['auth', 'rol:administrador'])->prefix('gestion')->group(function () {
    Route::get('/institucion', [Gestion\ConfiguracionController::class, 'mostrar'])
        ->name('gestion-configuracion');
    Route::post('/institucion', [Gestion\ConfiguracionController::class, 'guardar']);
    Route::post('/institucion/documentos/nuevo', [Gestion\ConfiguracionController::class, 'documentoNuevo'])
        ->name('documento-requerido-nuevo');
    Route::post('/institucion/documentos/{documento}/alternar', [Gestion\ConfiguracionController::class, 'documentoAlternar'])
        ->name('documento-requerido-alternar');

    // Sin periodo se ensena el en curso; con periodo, ese. Dos rutas y no una
    // con parametro opcional porque el nombre sin parametro es el que usan el
    // menu y la portada, y asi no tienen que saber en que periodo estamos.
    Route::get('/estadisticas', Gestion\EstadisticasController::class)->name('gestion-estadisticas');
    Route::get('/estadisticas/{periodo}', Gestion\EstadisticasController::class)
        ->name('gestion-estadisticas-periodo');
});
