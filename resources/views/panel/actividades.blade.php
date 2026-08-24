@extends('layouts.app')

@section('title', 'Mis cursos y actividades')

@section('content')
<a href="{{ route('panel') }}" class="volver">&larr; Panel</a>
<h2>Cursos, talleres y grupos de proyección</h2>

@if ($actividades->isEmpty())
  <p class="vacio">
    No tienes ninguno a tu cargo. Los crea dirección, en Gestión del catálogo académico.
  </p>
@else
<p class="campo-info">
  Entra a cada uno para ver quién se inscribió por el enlace y para iniciar sus sesiones.
</p>

<table>
  <thead>
    <tr>
      <th>Actividad</th>
      <th class="num">Inscritos</th>
      <th class="num">Sesiones</th>
    </tr>
  </thead>
  <tbody>
    @foreach ($actividades as $actividad)
    <tr>
      <td>
        <a href="{{ route('panel-actividad', $actividad) }}">{{ $actividad->nombre }}</a>
        <span class="tipo-chip">{{ $actividad->etiquetaTipo() }}</span>
      </td>
      <td class="num"><span class="cupo-cifra">{{ $actividad->inscritos_count }}</span></td>
      <td class="num">
        {{-- Un grupo de proyección no tiene fechas puestas: las va acumulando
             según se ensaya, así que aquí un cero no es un aviso. --}}
        @if ($actividad->llevaFechas() && $actividad->sesiones_count === 0)
          <span class="sin-cupo">Sin fechas</span>
        @else
          <span class="cupo-cifra">{{ $actividad->sesiones_count }}</span>
        @endif
      </td>
    </tr>
    @endforeach
  </tbody>
</table>
@endif
@endsection
