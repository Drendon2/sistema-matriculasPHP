@extends('layouts.app')

@section('title', "Trayectoria de {$estudiante->nombre_completo}")

@section('content')
<a href="{{ route('panel') }}" class="volver">&larr; Volver al panel</a>
<h2>Trayectoria</h2>

@include('panel.carne', ['estudiante' => $estudiante])

@if (! $historial)
  <p class="vacio">Este estudiante todavía no tiene ninguna matrícula registrada.</p>
@else
  @if ($resumen['periodos'])
  <div class="dash-resumen historial-resumen">
    <div>
      <span class="dash-stat-num">{{ $resumen['periodos'] }}</span>
      <span class="dash-stat-label">
        {{ $resumen['periodos'] == 1 ? 'Periodo cursado' : 'Periodos cursados' }}
      </span>
    </div>
    <div>
      <span class="dash-stat-num">{{ $resumen['promotorias'] }}</span>
      <span class="dash-stat-label">{{ $resumen['promotorias'] == 1 ? 'Promotoría' : 'Promotorías' }}</span>
    </div>
    @if ($resumen['desde'])
    <div>
      <span class="dash-stat-num">{{ $resumen['desde']->nombre }}</span>
      <span class="dash-stat-label">Desde</span>
    </div>
    @endif
  </div>
  @else
  <p class="vacio">
    Todavía no ha cursado ningún periodo: lo que sigue son solicitudes sin confirmar o retiradas.
  </p>
  @endif

  @include('partials.historial', ['modo' => 'personal', 'historial' => $historial])
@endif

<p style="margin-top:1.5rem;">
  @if (\App\Support\Permisos::puedeCertificarTodo($yo, $estudiante))
  <a class="btn btn-secundario btn-sm" href="{{ route('certificado-todo', $estudiante) }}">
    Certificado de matrícula
  </a>
  @endif
  @if ($yo->rol === 'administrador')
  <a class="btn btn-secundario btn-sm" href="{{ route('detalle-estudiante', $estudiante) }}">
    Ver ficha completa
  </a>
  @endif
</p>
@endsection
