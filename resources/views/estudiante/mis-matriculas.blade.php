@extends('layouts.app')

@section('title', 'Mis matrículas')

@section('content')
<h2>Mis matrículas</h2>

{{--
  LA PUERTA A «PROMOTORÍAS DISPONIBLES» VIVE AQUÍ desde el 12/09/2026, y antes
  era un enlace del menú. Se movió MIDIENDO: con el menú convertido en barra
  inferior, el estudiante tenía SEIS celdas en 375px —62px cada una— y
  «Promotorías» pedía 65, así que se recortaba a «PROMOT…». Caben cuatro con su
  rótulo entero, y de los cinco destinos éste es el que menos se abre una vez
  empezado el periodo. Decisión del usuario ese día, con la medida delante.

  Éste es su sitio natural: se entra a matricularse desde donde se ven las
  matrículas que ya se tienen. Va con el MISMO interruptor que tenía en el menú
  —`promotorias_visibles_para_estudiantes`—; sin él, una entidad que matricula
  en ventanilla enlazaría a una pantalla cerrada.

  Y pesa distinto según el estado: para quien no tiene ninguna es la acción
  principal y va en botón, porque «Todavía no tienes matrículas» sin una salida
  al lado es un callejón. Para quien ya tiene, un enlace tranquilo.
--}}
@if (! $historial)
  <p class="vacio">Todavía no tienes matrículas.</p>
  @if ($configuracion->promotorias_visibles_para_estudiantes)
    <p><a class="btn" href="{{ route('promotorias-disponibles') }}">Ver promotorías disponibles</a></p>
  @endif
@else
  @if ($configuracion->promotorias_visibles_para_estudiantes)
    <p class="campo-ayuda">
      <a href="{{ route('promotorias-disponibles') }}">Ver promotorías disponibles</a>
    </p>
  @endif

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
