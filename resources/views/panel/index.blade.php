@extends('layouts.app')

@section('title', 'Panel')

@section('content')
<h2>Panel de promotorías</h2>

{{--
  El enlace a las actividades se pinta solo si hay alguna a la vista. Mientras
  la institución no use cursos ni grupos de proyección, lleva a una pantalla
  vacía y solo estorba.
--}}
@if ($cuantasActividades)
<p>
  <a class="btn btn-blanco btn-sm" href="{{ route('panel-actividades') }}">
    Cursos, talleres y grupos de proyección ({{ $cuantasActividades }})
  </a>
</p>
@endif

@if ($promotorias->isEmpty())
  <p class="vacio">No hay promotorías para mostrar todavía.</p>
@else
  @foreach ($promotorias as $promotoria)
  @php($cuantasPendientes = $pendientes[$promotoria->id] ?? 0)
  {{--
    El id no es decorativo: es lo que permite que la promotoría siga abierta
    después de confirmar una matrícula. Ver public/js/acciones.js.

    El cuerpo NO viene aquí: llega al desplegar, desde `data-cuerpo`. Antes iba
    dentro y el resultado era que un director descargaba el catálogo entero
    —cientos de KB con trescientos estudiantes— para ver una lista de títulos
    plegados. Sin JavaScript el enlace del final sigue llevando a la promotoría,
    así que la pantalla no deja de funcionar, solo deja de ser cómoda.
  --}}
  <details class="panel-item" id="promotoria-{{ $promotoria->id }}"
           data-cuerpo="{{ route('panel-promotoria-cuerpo', $promotoria) }}">
    <summary class="panel-item-resumen">
      <span class="tag-dot {{ $promotoria->area->tag_color }}"></span>{{ $promotoria->nombre }}
      <span style="color:var(--ink-faint);font-weight:400;">({{ $promotoria->area->nombre }})</span>
      @if ($cuantasPendientes)
        <span class="estado estado-pendiente">
          {{ $cuantasPendientes }} {{ $cuantasPendientes == 1 ? 'pendiente' : 'pendientes' }}
        </span>
      @endif
      <svg class="perfil-seccion-chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6 6-6"/></svg>
    </summary>
    <div data-cuerpo-destino>
      <p class="vacio" data-cuerpo-cargando>Cargando…</p>
      <noscript>
        <p><a class="btn btn-sm" href="{{ route('panel-promotoria-cuerpo', $promotoria) }}">Ver {{ $promotoria->nombre }}</a></p>
      </noscript>
    </div>
  </details>
  @endforeach
@endif
@endsection

@push('scripts')
{{--
  Va como archivo y fuera de <main> a propósito. Los scripts que viven dentro se
  vuelven a ejecutar en cada repintado sin recarga (ver acciones.js), y los que
  delegan en `document` acumularían un oyente por repintado. Aquí se carga una
  vez y sobrevive a los cambios de <main>, que es justo lo que necesita.
--}}
<script src="@recurso('js/lote.js')" defer></script>
<script src="@recurso('js/panel.js')" defer></script>
@endpush
