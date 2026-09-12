{{--
  Envoltorio de las pantallas CON sesion.

  Puerto de `matriculas/templates/matriculas/base.html`. El sistema de diseno
  vive en public/css/app.css, extraido tal cual del <style> del original; en
  linea solo queda el color de marca, que es lo unico que cambia por
  institucion y por eso no se puede cachear.
--}}
<!DOCTYPE html>
{{--
  `data-tema` lo estampa el SERVIDOR leyendo la galleta, no un guion al
  cargar: con JavaScript la pagina pinta en claro y salta a oscuro un
  instante despues, que es un fogonazo blanco en la cara de quien encendio
  el modo oscuro justo para no tener uno. Vacio significa «sigue al
  sistema», que es lo que hace el CSS cuando el atributo no esta.
--}}
<html lang="es"@if ($tema !== '') data-tema="{{ $tema }}"@endif>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>@hasSection('title')@yield('title') — @endif{{ $configuracion->nombre_institucion }}</title>
<link rel="stylesheet" href="@recurso('css/app.css')">
{{--
  Marca configurable: sobreescribe SOLO el acento y sus dos tonos derivados.
  El resto del sistema de diseño (neutros, estados, colores de Área) no se toca.
--}}
@php($oscuro = $configuracion->acento_oscuro_trio)
<style>
  /*
    La marca, en sus DOS versiones. El acento de una institucion no sobrevive a
    un fondo oscuro —el verde de fabrica da 2,98:1— asi que la version oscura no
    es la misma con otro nombre: se aclara conservando el tono, y los otros dos
    invierten su papel. El como esta en `Support\Color`.

    El respaldo de la primera linea de cada par es lo que salva a un navegador
    que no conoce `light-dark()`: descarta la segunda por invalida y se queda
    con el color claro, en vez de dejar el token sin valor.
  */
  :root {
    --accent: {{ $configuracion->color_acento }};
    --accent: light-dark({{ $configuracion->color_acento }}, {{ $oscuro['claro'] }});
    --accent-dark: {{ $configuracion->color_acento_oscuro }};
    --accent-dark: light-dark({{ $configuracion->color_acento_oscuro }}, {{ $oscuro['hover'] }});
    --accent-soft: {{ $configuracion->color_acento_suave }};
    --accent-soft: light-dark({{ $configuracion->color_acento_suave }}, {{ $oscuro['suave'] }});
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

      {{--
        CLARO U OSCURO, EN UN SOLO BOTÓN. Antes esto era un selector de tres
        radios en Mi perfil; se movió aquí el 12/09/2026 a petición del usuario,
        y con él se fue la opción «lo que diga mi dispositivo».

        SON DOS BOTONES Y NO UNO, y de eso depende que funcione sin JavaScript.
        Cada uno manda su valor; cuál se ve lo decide el CSS a partir de lo que
        está pintado AHORA MISMO —incluido el caso de quien no ha elegido nunca,
        donde manda su sistema y el servidor no puede saberlo—. Con un solo
        botón habría que calcular el valor contrario en el servidor, que es justo
        lo que ahí no se sabe.

        Va junto a «Cerrar sesión» porque es un ajuste del aparato y no un sitio
        al que se navega.
      --}}
      <form action="{{ route('tema') }}" method="post" class="tema-forma" data-tema-forma>
        @csrf
        <button type="submit" name="tema" value="oscuro" class="tema-boton tema-a-oscuro"
                aria-label="Cambiar a modo oscuro">
          <svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none"
               stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/>
          </svg>
        </button>
        <button type="submit" name="tema" value="claro" class="tema-boton tema-a-claro"
                aria-label="Cambiar a modo claro">
          <svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none"
               stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="12" cy="12" r="4"/>
            <path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>
          </svg>
        </button>
      </form>

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
  {{--
    `data-recarga-completa` aunque hoy no haga falta: esta barra vive FUERA de
    `<main>`, así que `acciones.js` ni lo mira. Eso es una casualidad de
    colocación y no una decisión escrita — mover la barra dentro por cualquier
    razón de diseño rompería el botón sin que nada fallara, y este es el caso
    peligroso: creer que saliste sin haber salido.
  --}}
  <form action="{{ route('gestion-asistida-salir') }}" method="post" data-recarga-completa>
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

{{--
  El pie. FUERA de <main> por la misma razón que las dos cajas de arriba: lo que
  vive dentro se lo lleva el repintado de `acciones.js`, y `layouts.fragmento`
  —que es lo que va dentro de <main>— no lo incluye. Metido ahí, el enlace a la
  política de tratamiento de datos desaparecería tras la primera acción hecha
  sin recargar, sin que nada fallara ni avisara.
--}}
@include('partials.pie')

<script src="@recurso('js/acciones.js')" defer></script>
{{--
  El ojo para ver la contraseña. Con la sesión iniciada lo necesitan dos
  pantallas y las dos son MODALES —el formulario de usuario y la confirmación
  de borrado—, así que el guion no se conforma con barrer al arrancar: observa
  lo que se inserta después. Por eso da igual que vaya detrás de `acciones.js`.
--}}
<script src="@recurso('js/ver-clave.js')" defer></script>
{{--
  El selector de día de «Clases de la semana», en la portada del Panel. Lo monta
  este guion y NO la plantilla, igual que el ojo de la contraseña: un selector
  que filtra sin JavaScript es un control que no hace nada. Sin él se ven los
  seis días seguidos, que es una pantalla útil.

  Observa el DOM porque la portada del Panel se repinta sin recargar, así que
  da igual que vaya detrás de `acciones.js`.
--}}
<script src="@recurso('js/clases-del-dia.js')" defer></script>
{{--
  El cambio de tema sin recargar. El botón del menú funciona sin este guion
  —manda su formulario y la página vuelve del otro color—; lo que añade es que
  el color cambie en el acto.
--}}
<script src="@recurso('js/tema.js')" defer></script>
@stack('scripts')
</body>
</html>
