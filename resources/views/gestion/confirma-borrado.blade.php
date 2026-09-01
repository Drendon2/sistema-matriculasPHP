@extends('layouts.app')

@section('title', 'Eliminar')

@section('content')
{{--
  `data-modal-cuerpo` marca lo que el modal se lleva dentro. La página sigue
  existiendo entera y con su URL: sin JavaScript se abre y se lee igual, y es la
  MISMA tarjeta en los dos casos, así que no hay dos versiones que se puedan
  desincronizar. Ver el modal en `acciones.js`.
--}}
<div class="card" data-modal-cuerpo style="max-width:480px;">
@if ($bloqueos)
  {{--
    Preguntar «¿seguro?» para negarse después es hacer perder el viaje: quien
    llegó hasta aquí ya decidió, y la respuesta se sabía antes de pintar la
    página. Así que aquí no hay pregunta ni botón de eliminar — solo el porqué y
    la salida.
  --}}
  <h2>No se puede eliminar «{{ $objeto }}»</h2>
  <p>
    Todavía tiene <strong>{{ $bloqueos }}</strong>. Borrarlo se llevaría por
    delante ese historial, así que el sistema no lo permite.
  </p>
  <p class="campo-info">
    Si ya no se va a ofrecer, ponle <strong>cupo 0</strong> en el periodo nuevo
    en vez de borrarlo: nadie se puede matricular y lo ya cursado se conserva.
  </p>
  <p style="margin-bottom:0;">
    <a href="{{ route($ruta_lista) }}" class="btn btn-secundario" data-modal-cerrar>Volver</a>
  </p>
@else
  <h2>¿Eliminar «{{ $objeto }}»?</h2>
  @if ($arrastre)
  {{--
    "No se puede deshacer" a secas se queda corto cuando además arrastra cosas:
    los grupos cuelgan en cascada y se van sin preguntar, y con ellos las clases
    dictadas. Decir cuántos son es lo que hace que la confirmación signifique
    algo.
  --}}
  <p class="campo-info" style="margin-top:-0.6rem;">
    Se llevará también <strong>{{ $arrastre }}</strong>. No se puede deshacer.
  </p>
  @else
  <p class="campo-info" style="margin-top:-0.6rem;">Esta acción no se puede deshacer.</p>
  @endif

  <form method="post" action="{{ $accion }}" class="modal-botones" style="display:flex;gap:0.6rem;">
    @csrf
    <input type="hidden" name="volver" value="{{ $volver }}">
    <button type="submit" class="btn btn-retirar">Sí, eliminar</button>
    <a href="{{ route($ruta_lista) }}" class="btn btn-secundario" data-modal-cerrar>Cancelar</a>
  </form>
@endif
</div>
@endsection
