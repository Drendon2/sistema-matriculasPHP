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
  <nav>
    @auth
      @if ($yo?->rol === 'estudiante')
        @if ($configuracion->promotorias_visibles_para_estudiantes)
          <a href="{{ route('promotorias-disponibles') }}">Promotorías disponibles</a>
        @endif
        <a href="{{ route('mis-matriculas') }}">Mis matrículas</a>
        <a href="{{ route('mis-clases') }}">Mis clases</a>
        <a href="{{ route('mis-companeros') }}">Mis compañeros</a>
      @elseif ($yo?->rol)
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
{{--
  LA BARRA DE GESTIÓN ASISTIDA. Va FUERA de <main> y encima de todo, porque
  mientras dure hay que verla en todas las pantallas: quien la olvida está
  actuando desde la cuenta de otra persona sin saberlo, y eso es exactamente lo
  que no puede pasar.

  Dice a nombre de quién se está trabajando, y su botón de salir manda a una
  ruta que NO pide rol de administrador — ver `routes/web.php`. En cuanto la
  asistencia empieza, para el middleware quien navega es el profesor, así que
  una puerta de administrador en el botón de salir dejaría a quien entra
  encerrado hasta cerrar sesión.
--}}
@php($asistiendo = \App\Support\GestionAsistida::administrador())
@if ($asistiendo)
<div class="barra-asistida">
  <span class="barra-asistida-texto">
    <strong>Gestión asistida</strong>
    Estás trabajando como <strong>{{ $yo?->nombre_completo }}</strong>.
    Todo queda registrado a nombre de {{ $asistiendo->nombre_completo }}.
  </span>
  <form action="{{ route('gestion-asistida-salir') }}" method="post">
    @csrf
    <button type="submit" class="btn btn-blanco btn-sm">Volver a mi cuenta</button>
  </form>
</div>
@endif

<main>
  @include('partials.mensajes')
  @yield('content')
</main>

{{--
  LO QUE SE LE DICE A UN LECTOR DE PANTALLA CUANDO ALGO PASA.

  Estas dos cajas van VACIAS y viven FUERA de <main> a proposito, y las dos
  cosas son el arreglo entero. `acciones.js` responde a una accion cambiando el
  contenido de <main> sin navegar; un lector de pantalla no anuncia eso, asi que
  quien no ve la pantalla no se entera ni de que se guardo ni de que NO se
  guardo. Es el mismo fallo que ya costo un profesor en produccion —creyo que
  habia un tope de grupos porque no vio el aviso— pero total, no parcial.

  Y tienen que PREEXISTIR: una region viva que se inserta ya con texto dentro no
  se anuncia de forma fiable en todos los lectores. Por eso estan aqui vacias
  desde la primera carga y `pintar()` solo les escribe el texto. Fuera de <main>
  porque dentro las borraria el propio repintado.

  Son DOS y no una porque el tono no es el mismo: lo que salio bien espera turno
  (`status`, cortes) y un rechazo interrumpe (`alert`), que es lo que se quiere
  de algo que dice que el trabajo no se guardo. Cambiar `aria-live` sobre la
  marcha en una sola caja no es fiable; tener dos, si.

  No se ven: `.sr-solo` es la misma clase que ya usan las etiquetas de Cupos y
  de los enlaces copiables. En pantalla el aviso sigue siendo el de `.messages`,
  que no cambia.
--}}
<div class="sr-solo" role="status" aria-live="polite" data-voz="bien"></div>
<div class="sr-solo" role="alert" aria-live="assertive" data-voz="mal"></div>

<script src="@recurso('js/acciones.js')" defer></script>
@stack('scripts')
</body>
</html>
