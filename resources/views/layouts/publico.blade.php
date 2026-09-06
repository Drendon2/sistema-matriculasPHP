{{--
  Envoltorio de las pantallas SIN sesion: login, registro, inscripcion y la
  pagina de cuenta pendiente de rol.

  Puerto de `matriculas/templates/matriculas/base_publico.html`. El sistema de
  diseno vive en public/css/publico.css, extraido tal cual del <style> del
  original; lo unico que sigue yendo en linea es el color de marca, que es lo
  unico que cambia por institucion.
--}}
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>@yield('title', $configuracion->nombre_institucion)</title>
<link rel="stylesheet" href="@recurso('css/publico.css')">
<style>
  :root {
    --accent: {{ $configuracion->color_acento }};
    --accent-dark: {{ $configuracion->color_acento_oscuro }};
    --accent-soft: {{ $configuracion->color_acento_suave }};
    --caja-ancho: @yield('ancho', '400px');
  }
</style>
</head>
<body>
  <div class="envoltorio">
    <div class="marca">
      <img class="escudo"
           src="{{ $configuracion->logo ? route('logo-institucion') : asset('img/logo.webp') }}"
           alt="{{ $configuracion->nombre_institucion }}" width="60" height="60">
      <span>{{ $configuracion->nombre_institucion }}</span>
    </div>
    <div class="caja">
      @include('partials.mensajes')
      @yield('caja')
    </div>
    {{--
      El pie va DENTRO del envoltorio y no suelto en el <body>: aquí el body es
      un contenedor flex centrado, y un hijo más se colocaría al LADO de la caja
      en vez de debajo.
    --}}
    @include('partials.pie')
  </div>
  {{--
    El ojo para ver la contraseña. Va aquí y no en cada formulario porque las
    tres pantallas con campo de clave —entrar, inscripción y registro— cuelgan
    de este envoltorio, y el guion no hace nada donde no hay ninguno.
  --}}
  <script src="@recurso('js/ver-clave.js')" defer></script>
  @stack('scripts')
</body>
</html>
