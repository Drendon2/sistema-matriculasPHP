@extends('layouts.app')

@section('title', "Asistencia — {$clase->grupo}")

@section('content')
<p class="migas">
  <a href="{{ route('panel') }}">Panel</a><span class="migas-sep">/</span>
  <a href="{{ route('grupo-clases', $clase->grupo) }}">{{ $clase->grupo->promotoria->nombre }} · {{ $clase->grupo->nombre }}</a><span class="migas-sep">/</span>
  <span class="migas-actual">Clase del {{ $clase->fecha_hora->isoFormat('D [de] MMMM') }}</span>
</p>

<h2>Asistencia</h2>
<p class="campo-info">
  <span class="tag-dot {{ $clase->grupo->promotoria->area->tag_color }}"></span>{{ $clase->grupo->promotoria->nombre }} ·
  {{ $clase->grupo->rotulo }}
</p>

{{-- La hora es el dato que el botón existe para capturar: va en monoespaciada, como toda cifra del sistema. --}}
<p class="clase-sello">
  Clase registrada el <strong>{{ $clase->fecha_hora->isoFormat('dddd D [de] MMMM [de] YYYY') }}</strong>
  a las <span class="clase-hora">{{ $clase->fecha_hora->format('H:i') }}</span>
  @if ($clase->registradaPor)por {{ $clase->registradaPor->nombre_completo }}@endif
</p>

{{--
  Cuántas confirmaciones lleva, nunca quién las dio (ver el controlador): el
  número dice si la clase ya quedó verificada, y la lista de nombres convertiría
  la verificación en algo que reclamarle a cada estudiante.
--}}
<p class="clase-sello" style="margin-top:-0.8rem;">
  @if ($verificada)
    <span class="estado estado-activa">Verificada</span>
    La confirmaron {{ $confirmaciones }} {{ $confirmaciones == 1 ? 'estudiante' : 'estudiantes' }}.
  @elseif ($vencida)
    <span class="estado estado-retirada">Sin verificar</span>
    El plazo cerró el {{ $limiteConfirmacion->isoFormat('D [de] MMMM') }} a las
    <span class="clase-hora">{{ $limiteConfirmacion->format('H:i') }}</span>
    con {{ $confirmaciones }} de {{ $requeridas }} confirmaciones.
  @elseif ($requeridas)
    <span class="estado estado-pendiente">{{ $confirmaciones }} de {{ $requeridas }}</span>
    Tus estudiantes pueden confirmarla hasta el {{ $limiteConfirmacion->isoFormat('dddd D') }} a las
    <span class="clase-hora">{{ $limiteConfirmacion->format('H:i') }}</span>.
  @else
    Cuando la clase se registró no había nadie inscrito en el grupo, así que no hay quién la confirme.
  @endif
</p>

@if (! $estudiantes)
  <p class="vacio">Este grupo no tiene estudiantes inscritos, así que no hay lista que pasar.</p>
@else
  @if ($sinPasar)
    <p class="campo-info">
      {{ $sinPasar == 1 ? 'Falta' : 'Faltan' }} {{ $sinPasar }} de {{ count($estudiantes) }} por marcar.
    </p>
  @endif

  @if (! $puedeMarcar)
    <p class="aviso">
      Pasar lista es del profesor que dicta la promotoría: aquí ves lo que marcó, sin
      poder cambiarlo. Un registro que puede reescribir alguien que no dio la clase
      deja de ser evidencia de lo que pasó.
    </p>
  @endif

  {{--
    La misma hoja en los dos modos, y no dos pantallas distintas: el renglón, el
    orden y el vocabulario de forma son idénticos; lo único que cambia es si la
    marca es una opción que se pulsa o un marcador ya puesto.
  --}}
  <form method="post" action="{{ route('clase-asistencia', $clase) }}" class="card asistencia-lista" id="form-asistencia">
    @csrf
    @foreach ($estudiantes as $e)
    <div class="asistencia-fila">
      <span class="asistencia-nombre">
        @include('panel.foto', ['perfil' => $e['perfil']])
        @if (\App\Support\Permisos::puedeVerFicha($yo, $e['perfil']))
          <a href="{{ route('detalle-usuario', $e['perfil']) }}">{{ $e['perfil']->nombre_completo }}</a>
        @else
          {{ $e['perfil']->nombre_completo }}
        @endif
        @if ($e['cancelacion'])<span class="estado estado-cancelacion_solicitada">Pidió cancelar</span>@endif
      </span>
      <span class="asistencia-opciones">
        @include('partials.marcas-asistencia', [
          'idDeQuien' => $e['matricula']->id,
          'estado' => $e['estado'],
          'estados' => $estados,
          'puedeMarcar' => $puedeMarcar,
        ])
      </span>
    </div>
    @endforeach

    @if ($puedeMarcar)
    <div class="asistencia-pie">
      <button type="submit" class="btn">Guardar asistencia</button>
      <button type="button" class="btn btn-secundario btn-sm" id="marcar-todos">Marcar todos como asistió</button>
    </div>
    @endif
  </form>
@endif

<p style="margin-top:1.5rem;">
  <a class="volver" href="{{ route('grupo-clases', $clase->grupo) }}">← Clases de este grupo</a>
</p>

<script>
  (function () {
    var boton = document.getElementById("marcar-todos");
    var form = document.getElementById("form-asistencia");
    if (!boton || !form) { return; }
    boton.addEventListener("click", function () {
      // Solo rellena lo que está en blanco: quien ya tenía marca (una falta
      // puesta hace un momento) no se pierde por pulsar el atajo.
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
@endsection
