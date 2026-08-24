@extends('layouts.app')

@section('title', 'Lista — '.$actividad->nombre)

@section('content')
{{--
  Pasar lista de UNA sesión de actividad.

  Es la hermana de `panel.asistencia`, la de las promotorías, y se parece a
  propósito: el renglón, las tres opciones y el vocabulario son los mismos, así
  que quien ya pasa lista de sus grupos no tiene que aprender otra pantalla. Lo
  que cambia es de qué cuelga —aquí no hay matrícula— y que estos inscritos no
  tienen cuenta con la que confirmar después que la sesión se dio.
--}}
<p class="migas">
  <a href="{{ route('panel-actividades') }}">Cursos y actividades</a><span class="migas-sep">/</span>
  <a href="{{ route('panel-actividad', $actividad) }}">{{ $actividad->nombre }}</a><span class="migas-sep">/</span>
  <span class="migas-actual">{{ ucfirst($actividad->etiquetaSesion()) }} del {{ $sesion->fecha->format('d/m/Y') }}</span>
</p>

<h2>Lista de asistencia</h2>

{{--
  La frase lleva a la persona de sujeto, o el verbo en impersonal si no la hay.
  No es estilo: «taller», «ensayo» y «clase» no concuerdan igual, y cualquier
  participio detrás sale mal en dos de los tres casos. La primera versión de
  esto decía «Taller iniciada el», y se vio en pantalla y no en las pruebas.
--}}
<p class="clase-sello">
  @if ($sesion->iniciadaPor)
    {{ $sesion->iniciadaPor->nombre_completo }} inició {{ $actividad->etiquetaSesionConArticulo() }} el
  @else
    Empezó {{ $actividad->etiquetaSesionConArticulo() }} el
  @endif
  <strong>{{ $sesion->iniciada_en->isoFormat('dddd D [de] MMMM [de] YYYY') }}</strong>
  a las <span class="clase-hora">{{ $sesion->iniciada_en->format('H:i') }}</span>
</p>

@if (! $dirige)
  <p class="aviso">
    Pasar lista le toca a <strong>{{ $actividad->responsable->nombre_completo }}</strong>, que
    es quien dirige esto: aquí ves lo que marcó, sin poder cambiarlo. Un registro que
    puede reescribir alguien que no estuvo deja de ser evidencia de lo que pasó.
  </p>
@endif

@if ($inscritos->isEmpty())
  <p class="vacio">Todavía no hay nadie inscrito, así que no hay lista que pasar.</p>
@else
{{--
  La misma hoja en los dos modos, y no dos pantallas distintas: lo único que
  cambia es si la marca es una opción que se pulsa o un marcador ya puesto.
--}}
<form method="post" action="{{ route('panel-actividad-lista', $sesion) }}" class="card asistencia-lista" id="form-asistencia">
  @csrf
  @foreach ($inscritos as $fila)
  @php($inscrito = $fila['inscrito'])
  <div class="asistencia-fila">
    <span class="asistencia-nombre">
      {{ $inscrito->nombre_completo }}
      @if ($inscrito->origen === \App\Models\InscritoActividad::EN_SESION)
        <span class="campo-info" style="margin:0;display:block;">Añadido en clase</span>
      @endif
    </span>
    <span class="asistencia-opciones">
      @if ($dirige)
        @foreach ($estados as $valor => $etiqueta)
        <label class="asistencia-opcion asistencia-opcion-{{ $valor }}">
          <input type="radio" name="estado_{{ $inscrito->id }}" value="{{ $valor }}"
                 @checked($fila['estado'] === $valor)>{{ $etiqueta }}
        </label>
        @endforeach
      @elseif ($fila['estado'])
        <span class="estado">{{ $estados[$fila['estado']] }}</span>
      @else
        <span class="vacio">Sin marcar</span>
      @endif
    </span>
  </div>
  @endforeach

  @if ($dirige)
  <div class="asistencia-pie">
    <button type="submit" class="btn">Guardar lista</button>
    <button type="button" class="btn btn-secundario btn-sm" id="marcar-todos">Marcar todos como asistió</button>
  </div>
  @endif
</form>
@endif

@if ($dirige)
<div class="card">
  <h3>Llegó alguien sin inscribirse</h3>
  <p class="campo-info" style="margin-top:0;">
    Solo el nombre: nadie le va a pedir el documento con la clase empezando.
    Queda inscrito en {{ $actividad->nombre }} y marcado como que asistió hoy.
  </p>
  <form method="post" action="{{ route('panel-actividad-anadir', $sesion) }}" class="cupo-form" style="margin-left:0;">
    @csrf
    <label class="sr-solo" for="nombre_completo">Nombre completo</label>
    <input type="text" name="nombre_completo" id="nombre_completo" maxlength="90"
           placeholder="Nombre completo" style="width:18rem;margin:0;" required>
    <button type="submit" class="btn btn-sm">Añadir a la lista</button>
  </form>
  @error('nombre_completo')<div class="errorlist" style="color:var(--danger);font-size:0.82rem;">{{ $message }}</div>@enderror
</div>
@endif

<p style="margin-top:1.5rem;">
  <a class="volver" href="{{ route('panel-actividad', $actividad) }}">&larr; {{ $actividad->nombre }}</a>
</p>

{{--
  El atajo, solo para quien puede marcar. Sin esto se le manda a dirección un
  script que cablea un botón que no existe en su página — y de paso su propio
  texto contiene un `type="radio"` que hace pasar por lista lo que no lo es.
--}}
@if ($dirige)
<script>
  (function () {
    var boton = document.getElementById("marcar-todos");
    var form = document.getElementById("form-asistencia");
    if (!boton || !form) { return; }
    boton.addEventListener("click", function () {
      // Solo rellena lo que está en blanco: quien ya tenía marca (una falta
      // puesta hace un momento) no se pierde por pulsar el atajo. Es el mismo
      // atajo, y la misma cautela, que la hoja de las promotorías.
      var grupos = {};
      form.querySelectorAll('input[type="radio"]').forEach(function (radio) {
        grupos[radio.name] = grupos[radio.name] || [];
        grupos[radio.name].push(radio);
      });
      Object.keys(grupos).forEach(function (nombre) {
        var marcado = grupos[nombre].some(function (radio) { return radio.checked; });
        if (marcado) { return; }
        grupos[nombre].forEach(function (radio) {
          if (radio.value === "asistio") { radio.checked = true; }
        });
      });
    });
  })();
</script>
@endif
@endsection
