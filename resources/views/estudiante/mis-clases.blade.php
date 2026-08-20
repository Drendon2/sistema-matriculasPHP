@extends('layouts.app')

@section('title', 'Mis clases')

@section('content')
@php($sufijoPeriodo = $periodo ? " — {$periodo->nombre}" : '')
<h2>Mis clases{{ $sufijoPeriodo }}</h2>

<p class="aviso">
  Cuando tu profesor da una clase, la registra aquí. <strong>Confirmar es decir que esa clase
  sí se dio</strong>: con eso {{ $configuracion->nombre_institucion }} puede verificar que se
  dictó, sin depender solo de quien la registró. Tienes <strong>{{ $horasPlazo }} horas</strong>
  desde que empezó la clase; dentro de ese plazo puedes también quitar tu confirmación si
  te equivocaste. Después ya no se puede cambiar.
</p>

@if (! $filas)
  <p class="vacio">
    Todavía no hay clases registradas en tus grupos. Aparecerán aquí en cuanto tu profesor
    inicie la primera.
  </p>
@else
  @if ($porConfirmar)
    <p class="campo-info">
      {{ $porConfirmar == 1 ? 'Te falta' : 'Te faltan' }} {{ $porConfirmar }} por confirmar.
    </p>
  @endif

  <div class="card clase-lista">
    @foreach ($filas as $f)
    <div class="clase-fila">
      <span class="clase-datos">
        <span class="clase-titulo">
          <span class="tag-dot {{ $f['clase']->grupo->promotoria->area->tag_color }}"></span>{{ $f['clase']->grupo->promotoria->nombre }} · {{ $f['clase']->grupo->nombre }}
        </span>
        <span class="clase-cuando">
          {{ $f['clase']->fecha_hora->isoFormat('dddd D [de] MMMM') }} · <span class="clase-hora">{{ $f['clase']->fecha_hora->format('H:i') }}</span>
          @if ($f['abierta'] && ! $f['verificada'])
            · plazo hasta el {{ $f['limite']->isoFormat('dddd D') }} a las {{ $f['limite']->format('H:i') }}
          @endif
        </span>
      </span>

      @if ($f['verificada'])
        <span class="estado estado-activa">Verificada</span>
      @elseif ($f['vencida'])
        {{-- Ya no espera respuesta: el plazo se acabó sin reunir las que pedía. --}}
        <span class="estado estado-retirada">Sin verificar</span>
      @else
        {{-- Cuántas faltan, no solo "pendiente": el estudiante decide si su confirmación hace falta. --}}
        <span class="estado estado-pendiente">{{ $f['confirmaciones'] }} de {{ $f['requeridas'] }} confirmaciones</span>
      @endif

      <span class="clase-accion">
        @if (! $f['abierta'])
          {{-- Cerrado: no hay botón, así que el renglón tiene que decir por qué. --}}
          <span class="clase-mia">
            @if ($f['confirmada_por_mi'])La confirmaste · @endif Plazo cerrado
          </span>
        @elseif ($f['confirmada_por_mi'])
          <span class="clase-mia">La confirmaste</span>
          <form action="{{ route('retirar-confirmacion-clase', $f['clase']) }}" method="post">
            @csrf
            <button type="submit" class="btn btn-retirar btn-sm">Quitar mi confirmación</button>
          </form>
        @else
          <form action="{{ route('confirmar-clase', $f['clase']) }}" method="post">
            @csrf
            <button type="submit" class="btn btn-sm">Sí, esta clase se dio</button>
          </form>
        @endif
      </span>
    </div>
    @endforeach
  </div>
@endif
@endsection
