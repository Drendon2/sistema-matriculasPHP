@extends('layouts.app')

@section('title', 'Mis matrículas')

@section('content')
<h2>Mis matrículas</h2>

@if (! $historial)
  <p class="vacio">Todavía no tienes matrículas.</p>
@else

  {{--
    Solo cuentan las matrículas ACTIVAS: es lo que el estudiante realmente
    cursó. Un estudiante que solo tiene solicitudes pendientes no ve cifras,
    y eso es correcto — todavía no ha cursado nada.
  --}}
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
      <span class="dash-stat-label">
        {{ $resumen['promotorias'] == 1 ? 'Promotoría' : 'Promotorías' }}
      </span>
    </div>
    @if ($resumen['desde'])
    <div>
      <span class="dash-stat-num">{{ $resumen['desde']->nombre }}</span>
      <span class="dash-stat-label">Desde</span>
    </div>
    @endif
  </div>
  @endif

  @include('partials.historial', ['modo' => 'estudiante'])
@endif
@endsection
