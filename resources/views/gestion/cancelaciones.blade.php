@extends('layouts.app')

@section('title', 'Cancelaciones')

@section('content')
<a href="{{ route('gestion-inicio') }}" class="volver">&larr; Gestión</a>
<h2>Cancelaciones por resolver</h2>

<p class="campo-info" style="margin-top:-0.8rem;">
  Mientras una cancelación esté aquí, el estudiante <strong>sigue matriculado</strong> y su
  cupo sigue ocupado. Aprobarla lo retira y libera el cupo.
</p>

@if (! count($pendientes))
  <p class="vacio">No hay cancelaciones pendientes.</p>
@else
{{--
  Resolver en bloque. Al cerrar un periodo esta cola se llena y casi todas se
  resuelven igual.

  «Rechazar marcadas» se pinta siempre, aunque en la selección haya mayores de
  edad: el servidor resuelve las que puede y dice por su nombre a quién no,
  porque esconder el botón según lo marcado obligaría a explicar en pantalla una
  regla que se entiende mucho mejor en la respuesta. Y sigue sin haber botón
  recomendado — en verde de acción, «Rechazar» empujaría a negar la salida de un
  niño, y esa decisión no la debe inclinar el sistema.
--}}
@if (count($pendientes) > 1)
<form action="{{ route('gestion-cancelaciones-lote') }}" method="post" id="lote-cancelaciones" class="lote-barra">
  @csrf
  <span class="lote-cuenta" data-lote-cuenta>Ninguno marcado</span>
  <button type="submit" name="decision" value="aprobar" class="btn btn-retirar btn-sm" data-lote-enviar disabled>
    Aprobar retiros marcados
  </button>
  <button type="submit" name="decision" value="rechazar" class="btn btn-secundario btn-sm" data-lote-enviar disabled>
    Rechazar marcadas
  </button>
</form>
@endif

<table @if (count($pendientes) > 1) data-lote-tabla="lote-cancelaciones" @endif>
  <thead>
    <tr>
      @if (count($pendientes) > 1)
      <th style="width:1%;">
        <input type="checkbox" data-lote-todos aria-label="Marcar todas las cancelaciones">
      </th>
      @endif
      <th>Estudiante</th>
      <th>Promotoría</th>
      <th>Periodo</th>
      <th>Acudiente</th>
      <th></th>
    </tr>
  </thead>
  <tbody>
    @foreach ($pendientes as $m)
    @php($acudiente = $m->estudiante->datosEstudiante?->acudiente)
    <tr>
      @if (count($pendientes) > 1)
      <td>
        <input type="checkbox" name="matricula_ids[]" value="{{ $m->id }}"
               form="lote-cancelaciones" data-lote-fila
               aria-label="Marcar la cancelación de {{ $m->estudiante->nombre_completo }}">
      </td>
      @endif
      <td>
        @if (\App\Support\Permisos::puedeVerFicha($yo, $m->estudiante))
          <a href="{{ route('detalle-usuario', $m->estudiante) }}">{{ $m->estudiante->nombre_completo }}</a>
        @else
          {{ $m->estudiante->nombre_completo }}
        @endif
        @if ($m->cancelacion_es_rechazable)
          <span class="estado estado-pendiente" style="margin-left:0.4rem;">Menor de edad</span>
        @endif
      </td>
      <td>
        <span class="tag-dot {{ $m->promotoria->area->tag_color }}"></span>{{ $m->promotoria->nombre }}
        <span class="historial-area">{{ $m->promotoria->area->nombre }}</span>
      </td>
      <td>{{ $m->periodo->nombre }}</td>
      <td>@include('panel.acudiente', ['acudiente' => $acudiente])</td>
      <td style="text-align:right;white-space:nowrap;">
        <span class="accion-fila">
          <form action="{{ route('gestion-resolver-cancelacion', [$m, 'aprobar']) }}" method="post" style="display:inline;">
            @csrf
            <button type="submit" class="btn btn-retirar btn-sm">Aprobar retiro</button>
          </form>
          {{--
            Rechazar solo cabe con menores: la pausa existe para hablar con el
            acudiente antes de que un niño se salga por su cuenta. A un mayor de
            edad no se le discute la decisión, así que el botón ni aparece — y el
            controlador lo vuelve a comprobar, porque ocultarlo no impide enviar
            el formulario a mano.

            Secundario y no primario: en verde de acción, «Rechazar» empujaría al
            director a negar la salida de un niño, y esa decisión no la debe
            inclinar el sistema. Ninguno de los dos botones es el recomendado.
          --}}
          @if ($m->cancelacion_es_rechazable)
          <form action="{{ route('gestion-resolver-cancelacion', [$m, 'rechazar']) }}" method="post" style="display:inline;">
            @csrf
            <button type="submit" class="btn btn-secundario btn-sm">Rechazar</button>
          </form>
          @endif
        </span>
      </td>
    </tr>
    @endforeach
  </tbody>
</table>
@endif
@endsection

@push('scripts')
{{--
  Solo la casilla de «todos» y la cuenta de marcados. Sin JavaScript la pantalla
  funciona igual: se marcan las casillas a mano y se envía.
--}}
<script src="{{ asset('js/lote.js') }}" defer></script>
@endpush
