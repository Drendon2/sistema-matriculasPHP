{{--
  Envoltorio de las pantallas CON sesion.

  Puerto de `matriculas/templates/matriculas/base.html`. El sistema de diseno
  vive en public/css/app.css, extraido tal cual del <style> del original; en
  linea solo queda el color de marca, que es lo unico que cambia por
  institucion y por eso no se puede cachear.
--}}
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>@hasSection('title')@yield('title') — @endif{{ $configuracion->nombre_institucion }}</title>
<link rel="stylesheet" href="@recurso('css/app.css')">
{{--
  Marca configurable: sobreescribe SOLO el acento y sus dos tonos derivados.
  El resto del sistema de diseño (neutros, estados, colores de Área) no se toca.
--}}
<style>
  :root {
    --accent: {{ $configuracion->color_acento }};
    --accent-dark: {{ $configuracion->color_acento_oscuro }};
    --accent-soft: {{ $configuracion->color_acento_suave }};
  }
</style>
</head>
<body>
<header>
  <div class="marca-header">
    <img src="{{ $configuracion->logo ? route('logo-institucion') : asset('img/logo.webp') }}"
         alt="" width="30" height="30">
    <h1>{{ $configuracion->nombre_institucion }}</h1>
  </div>

  {{--
    Posicionada aparte (no dentro de .marca-header ni de <nav>) porque su
    alineación no depende de esos dos bloques: se ancla en la misma posición
    horizontal donde arranca el contenido de <main> —el mismo cálculo que usa
    ese `margin: 0 auto` de 960px—, para que quede a plomo con el título
    "Cómo va" de la portada de Gestión, esté uno debajo del otro o no.
  --}}
  @if (in_array($yo?->rol, ['director', 'administrador'], true))
    <form action="{{ route('usuario-lista') }}" method="get" class="nav-buscar">
      <svg class="nav-buscar-icono" viewBox="0 0 20 20" fill="none" aria-hidden="true">
        <circle cx="8.5" cy="8.5" r="6" stroke="currentColor" stroke-width="1.6"/>
        <line x1="13.1" y1="13.1" x2="17" y2="17" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>
      </svg>
      <label for="nav-buscar-usuario" class="sr-solo">Buscar usuario</label>
      <input type="search" name="q" id="nav-buscar-usuario"
             placeholder="Nombre, usuario, teléfono, correo o documento"
             title="Busca un usuario por su nombre, teléfono, correo o identificación">
    </form>
  @endif

  <nav>
    @auth
      @if ($yo?->rol === 'estudiante')
        @if ($configuracion->promotorias_visibles_para_estudiantes)
          <a href="{{ route('promotorias-disponibles') }}">Promotorías disponibles</a>
        @endif
        <a href="{{ route('mis-matriculas') }}">Mis matrículas</a>
        <a href="{{ route('mis-clases') }}">Mis clases</a>
        <a href="{{ route('mis-companeros') }}">Mis compañeros</a>
      @elseif ($yo?->rol === 'profesor')
        {{--
          Director y administrador ya tienen "Gestión" como su entrada
          principal (ver `PostLoginController`); Panel les sigue abierto por
          ruta si alguno además dicta una promotoría, pero no necesita su
          propio enlace en el nav para esos dos roles.
        --}}
        <a href="{{ route('panel') }}">Panel</a>
      @endif

      @if (in_array($yo?->rol, ['director', 'administrador'], true))
        <a href="{{ route('gestion-inicio') }}">Gestión</a>
      @endif

      @if ($yo)
        <a href="{{ route('mi-perfil') }}">Mi perfil</a>
      @endif

      <form action="{{ route('logout') }}" method="post" style="display:inline">
        @csrf
        <button type="submit" class="btn btn-blanco btn-sm">
          Cerrar sesión ({{ auth()->user()->username }})
        </button>
      </form>
    @endauth
  </nav>
</header>
<main>
  @include('partials.mensajes')
  @yield('content')
</main>

<script src="@recurso('js/acciones.js')" defer></script>
@stack('scripts')
</body>
</html>
