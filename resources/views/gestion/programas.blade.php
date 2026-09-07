@extends('layouts.app')

@section('title', 'Programas formativos')

@section('content')
{{--
  Las tres cosas que la institución ofrece, seguidas y en su orden real. El
  porqué de que esta pantalla exista está en `ProgramasController`.

  Cada sección incluye el MISMO parcial que usa la pantalla de ese catálogo, con
  lo que le da su propio controlador. Aquí no se consulta ni se maqueta nada por
  segunda vez: lo único propio de esta pantalla es el orden y las cabeceras.
--}}
<a href="{{ route('gestion-inicio') }}" class="volver">&larr; Gestión</a>
<h2>Programas formativos</h2>

{{--
  CADA SECCIÓN SE NOMBRA A SÍ MISMA, y las tres cosas de aquí abajo son la misma
  decisión: en esta pantalla hay tres listas seguidas y quien no la ve necesita
  poder saltar entre ellas.

  - `aria-labelledby` colgando del `<h3>` convierte cada `<section>` en un punto
    de referencia con nombre. Un `<section>` sin nombre no es ninguno: se
    anuncia como un contenedor más y no aparece en la lista de regiones.
  - Los tres botones de crear decían «+ Nuevo» los tres. Quien los recorre por
    teclado o con un lector oye «+ Nuevo, + Nuevo, + Nuevo» sin manera de saber
    cuál abre un departamento y cuál un taller. El texto visible se queda corto
    —el botón vive al lado de su título y ahí «+ Nuevo» se entiende— y el nombre
    accesible lo completa. El visible sigue contenido en el accesible, que es lo
    que pide el criterio de etiqueta en el nombre.
--}}
<section class="programa-seccion" aria-labelledby="seccion-departamentos">
  <div class="programa-cabecera">
    <h3 id="seccion-departamentos">Departamentos</h3>
    <a class="btn btn-blanco btn-sm" href="{{ route('area-nueva') }}" data-modal
       aria-label="Nuevo departamento">+ Nuevo</a>
  </div>
  <p class="campo-ayuda">
    Cada departamento agrupa sus promotorías, y cada promotoría sus grupos con
    horario. En las tres listas, el nombre entra.
  </p>

  @include('partials.tabla-catalogo', $departamentos + ['vacio_texto' => 'Todavía no hay departamentos. Crea el primero para poder abrir promotorías.'])

  {{--
    Las dos listas planas, como enlaces y no como fichas: por el árbol se llega
    a los grupos de UNA promotoría, y la lista completa de grupos es el único
    sitio del sistema donde se filtra por profesor. Sin este renglón esa
    capacidad no tendría puerta.
  --}}
  <p class="programa-atajos">
    <a href="{{ route('promotoria-lista') }}">Ver todas las promotorías</a>
    <span class="programa-atajo-sep">·</span>
    <a href="{{ route('grupo-lista') }}">Ver todos los grupos</a>
  </p>
</section>

<section class="programa-seccion" aria-labelledby="seccion-cursos">
  <div class="programa-cabecera">
    <h3 id="seccion-cursos">Cursos y talleres</h3>
    <a class="btn btn-blanco btn-sm" href="{{ route('actividad-curso-nueva') }}" data-modal
       aria-label="Nuevo curso o taller">+ Nuevo</a>
  </div>
  <p class="campo-ayuda">
    No pasan por matrícula: se entra por un enlace que alguien comparte, sin
    cuenta.
  </p>

  @include('partials.tabla-actividades', $cursos + ['vacio_texto' => 'Todavía no hay cursos ni talleres.'])
</section>

<section class="programa-seccion" aria-labelledby="seccion-proyeccion">
  <div class="programa-cabecera">
    <h3 id="seccion-proyeccion">Grupos de proyección</h3>
    <a class="btn btn-blanco btn-sm" href="{{ route('actividad-proyeccion-nueva') }}" data-modal
       aria-label="Nuevo grupo de proyección">+ Nuevo</a>
  </div>

  @include('partials.tabla-actividades', $proyeccion + ['vacio_texto' => 'Todavía no hay grupos de proyección.'])
</section>
@endsection

@push('scripts')
<script src="@recurso('js/copiar-enlace.js')" defer></script>
@endpush
