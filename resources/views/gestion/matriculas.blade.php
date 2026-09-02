@extends('layouts.app')

@section('title', 'Iniciar y finalizar matrículas')

@section('content')
<a href="{{ route('gestion-inicio') }}" class="volver">&larr; Gestión</a>
<h2>Iniciar y finalizar matrículas</h2>

<div class="card">
  <h3>Periodo en curso</h3>
  <p class="campo-info">
    Solo puede haber uno. Al cambiarlo, el anterior deja de estar en curso y sus matrículas
    se cierran automáticamente; sus datos no se tocan.
  </p>
  @if (count($periodos))
  <form method="post" action="{{ route('gestion-matriculas') }}" class="cupo-form" style="margin-left:0;">
    @csrf
    <input type="hidden" name="accion" value="poner_en_curso">
    <label class="sr-solo" for="id_periodo_en_curso">Periodo en curso</label>
    <select name="periodo_id" id="id_periodo_en_curso" style="width:auto;">
      @foreach ($periodos as $p)
        <option value="{{ $p->id }}" @selected($p->activo)>{{ $p->nombre }}@if ($p->activo) — en curso @endif</option>
      @endforeach
    </select>
    <button type="submit" class="btn btn-sm">Poner en curso</button>
  </form>
  @else
    <p class="vacio">Todavía no hay periodos creados. Créalos en Gestión → Periodos.</p>
  @endif
</div>

@if (! $periodo)
  <p class="vacio">
    Ningún periodo está en curso ahora mismo. Elige uno arriba antes de abrir matrículas.
  </p>
@else

<div class="card">
  <h3>{{ $periodo->nombre }}</h3>
  <p class="campo-info">
    Del {{ $periodo->fecha_inicio->format('d/m/Y') }} al {{ $periodo->fecha_fin->format('d/m/Y') }}
  </p>

  <div class="ventana-estado">
    @if ($periodo->matriculas_abiertas)
      <span class="estado estado-activa">Matrículas abiertas</span>
      <p>Ahora mismo los estudiantes nuevos pueden inscribirse y los antiguos renovar.</p>
    @else
      <span class="estado estado-pendiente">Matrículas cerradas</span>
      <p>Nadie puede inscribirse ni renovar. Las matrículas ya registradas siguen intactas.</p>
    @endif
  </div>

  <form method="post" action="{{ route('gestion-matriculas') }}">
    @csrf
    @if ($periodo->matriculas_abiertas)
      <input type="hidden" name="accion" value="cerrar">
      <button type="submit" class="btn btn-retirar">Finalizar matrículas de {{ $periodo->nombre }}</button>
    @else
      <input type="hidden" name="accion" value="abrir">
      <button type="submit" class="btn">Iniciar matrículas de {{ $periodo->nombre }}</button>
    @endif
  </form>
</div>

@if ($resumen)
<div class="card">
  <h3>Cómo va {{ $periodo->nombre }}</h3>
  <div class="perfil-stats">
    <div>
      <span class="perfil-stat-num">{{ $resumen['estudiantes'] }}</span>
      <span class="perfil-stat-label">Estudiantes</span>
    </div>
    <div>
      <span class="perfil-stat-num">{{ $resumen['activas'] }}</span>
      <span class="perfil-stat-label">Matrículas activas</span>
    </div>
    <div>
      <span class="perfil-stat-num">{{ $resumen['pendientes'] }}</span>
      <span class="perfil-stat-label">Por confirmar</span>
    </div>
    @if ($resumen['periodo_anterior'])
    <div>
      <span class="perfil-stat-num">{{ $resumen['por_renovar'] }}</span>
      <span class="perfil-stat-label">Antiguos sin renovar</span>
    </div>
    @endif
  </div>
  @if ($resumen['periodo_anterior'])
    {{-- `.campo-ayuda` y no `.campo-info`: esta va debajo del bloque de cifras,
         no de un titulo, y el margen negativo de la otra la montaba encima de
         «Antiguos sin renovar» — medido, 10px de solape. --}}
    <p class="campo-ayuda" style="margin-top:0.9rem;">
      «Antiguos sin renovar» son estudiantes que estuvieron activos en
      {{ $resumen['periodo_anterior']->nombre }} y todavía no aparecen en {{ $periodo->nombre }}.
    </p>
  @endif
</div>
@endif

@endif
@endsection
