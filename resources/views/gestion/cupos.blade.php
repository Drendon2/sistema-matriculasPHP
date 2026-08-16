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
  <table>
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
        <td>
          <span class="tag-dot {{ $p->area->tag_color }}"></span>{{ $p->nombre }}
          <span class="campo-info" style="margin:0;display:inline;">— {{ $p->area->nombre }}</span>
        </td>
        <td>
          @if ($p->profesor && \App\Support\Permisos::puedeVerFicha($yo, $p->profesor))
            <a href="{{ route('detalle-usuario', $p->profesor) }}">{{ $p->profesor->nombre_completo }}</a>
          @else
            {{ $p->profesor?->nombre_completo ?: 'Sin asignar' }}
          @endif
        </td>
        <td class="num">
          @if ($fila['cupo'] === null)
            <span class="cupo-cifra cupo-cifra-libre">{{ $fila['ocupados'] }} / ∞</span>
          @elseif ($fila['ocupados'] >= $fila['cupo'])
            <span class="cupo-cifra cupo-cifra-lleno">{{ $fila['ocupados'] }} / {{ $fila['cupo'] }}</span>
          @else
            <span class="cupo-cifra">{{ $fila['ocupados'] }} / {{ $fila['cupo'] }}</span>
          @endif
        </td>
        <td>
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
