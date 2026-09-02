@extends('layouts.app')

@section('title', 'Cupos por promotoría')

@section('content')
<a href="{{ route('gestion-inicio') }}" class="volver">&larr; Gestión</a>
@php($sufijoPeriodo = $periodo ? " — {$periodo->nombre}" : '')
<h2>Cupos por promotoría{{ $sufijoPeriodo }}</h2>

@if (! $periodo)
  <p class="vacio">Todavía no hay periodos creados. Crea uno en Gestión → Periodos.</p>
@else

@if (count($periodos) > 1)
<p class="cupo-periodos">
  @foreach ($periodos as $p)
    <a href="{{ route('gestion-cupos-periodo', $p) }}"
       class="cupo-periodo{{ $p->id === $periodo->id ? ' cupo-periodo-actual' : '' }}">{{ $p->nombre }}@if ($p->activo) ·@endif</a>
  @endforeach
</p>
@endif

<p class="aviso">
  @if ($periodo->activo)
    Este es el periodo activo: aquí se reparten los cupos al abrir matrículas.
    Deja una casilla <strong>vacía</strong> para que esa promotoría no tenga tope.
    Ocupan cupo las matrículas <strong>pendientes y activas</strong>; las retiradas lo liberan.
  @else
    {{ $periodo->nombre }} no es el periodo activo. Sus cupos se muestran como histórico y no se pueden editar.
  @endif
</p>

<form method="post" action="{{ route('gestion-cupos-periodo', $periodo) }}">
  @csrf
  {{--
    `.tabla-personas` porque bajo 640px esto tiene que dejar de ser tabla: la
    casilla del cupo es la cuarta columna y a 390px quedaba fuera de la pantalla,
    al otro lado de un arrastre de 90px. O sea que la pantalla de repartir cupos
    escondía justo la casilla en la que se reparten. Es una lista de registros y
    no una rejilla —la posición de la celda no es el dato—, así que cumple la
    regla del DESIGN.md.
  --}}
  <table class="tabla-personas tabla-catalogo">
    <thead>
      <tr>
        <th>Promotoría</th>
        <th>Profesor</th>
        <th class="num">Ocupados</th>
        <th>Cupo máximo</th>
      </tr>
    </thead>
    <tbody>
      @foreach ($filas as $fila)
      @php($p = $fila['promotoria'])
      <tr>
        <td data-celda="detalle">
          <span class="tag-dot {{ $p->area->tag_color }}"></span><span class="lista-nombre">{{ $p->nombre }}</span>
          <span class="lista-nota">— {{ $p->area->nombre }}</span>
        </td>
        <td data-label="Profesor">
          @if ($p->profesor && \App\Support\Permisos::puedeVerFicha($yo, $p->profesor))
            <a href="{{ route('detalle-usuario', $p->profesor) }}">{{ $p->profesor->nombre_completo }}</a>
          @else
            {{ $p->profesor?->nombre_completo ?: 'Sin asignar' }}
          @endif
        </td>
        <td class="num" data-label="Ocupados">
          @if ($fila['cupo'] === null)
            <span class="cupo-cifra cupo-cifra-libre">{{ $fila['ocupados'] }} / ∞</span>
          @elseif ($fila['ocupados'] >= $fila['cupo'])
            <span class="cupo-cifra cupo-cifra-lleno">{{ $fila['ocupados'] }} / {{ $fila['cupo'] }}</span>
          @else
            <span class="cupo-cifra">{{ $fila['ocupados'] }} / {{ $fila['cupo'] }}</span>
          @endif
        </td>
        {{--
          `data-celda="accion"` y no un `data-label`: en la ficha esto no es un
          dato que se lee sino el control que se viene a tocar, y esa marca es la
          que lo baja al final separado por una línea, a ancho completo.
        --}}
        <td data-celda="accion" data-label="Cupo">
          <label class="sr-solo" for="cupo-{{ $p->id }}">Cupo máximo de {{ $p->nombre }}</label>
          <input type="number" id="cupo-{{ $p->id }}" name="cupo_{{ $p->id }}"
                 min="0" step="1" value="{{ $fila['cupo'] }}" placeholder="sin tope"
                 class="cupo-input" @disabled(! $periodo->activo)>
        </td>
      </tr>
      @endforeach
    </tbody>
  </table>

  @if ($periodo->activo)
  <p style="margin-top:1.2rem;">
    <button type="submit" class="btn">Guardar cupos de {{ $periodo->nombre }}</button>
  </p>
  @endif
</form>
@endif
@endsection
