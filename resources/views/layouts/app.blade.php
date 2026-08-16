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
<title>@yield('title', 'Matrículas') — {{ $configuracion->nombre_institucion }}</title>
<link rel="stylesheet" href="{{ asset('css/app.css') }}">
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
@php($perfil = auth()->user()?->perfil)
<header>
  <div class="marca-header">
    <img src="{{ $configuracion->logo ? route('logo-institucion') : asset('img/logo.webp') }}"
         alt="" width="30" height="30">
    <h1>Matrículas {{ $configuracion->nombre_institucion }}</h1>
  </div>
  <nav>
    @auth
      @if ($perfil?->rol === 'estudiante')
        @if ($configuracion->promotorias_visibles_para_estudiantes)
          <a href="{{ route('promotorias-disponibles') }}">Promotorías disponibles</a>
        @endif
        <a href="{{ route('mis-matriculas') }}">Mis matrículas</a>
        <a href="{{ route('mis-clases') }}">Mis clases</a>
        <a href="{{ route('mis-companeros') }}">Mis compañeros</a>
      @elseif ($perfil?->rol)
        <a href="{{ route('panel') }}">Panel</a>
      @endif

      @if (in_array($perfil?->rol, ['director', 'administrador'], true))
        <a href="{{ route('gestion-inicio') }}">Gestión</a>
      @endif

      @if ($perfil)
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

<script src="{{ asset('js/acciones.js') }}" defer></script>
@stack('scripts')
</body>
</html>
