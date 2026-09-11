@extends('layouts.app')

@section('title', 'Grupos de '.$matricula->estudiante->nombre_completo)

@section('content')
{{--
  REPARTIR A ALGUIEN EN VARIOS GRUPOS DE SU PROMOTORÍA.

  `data-modal-cuerpo` marca lo que el modal se lleva dentro. La página sigue
  existiendo entera y con su URL: sin JavaScript se abre y se lee igual, y es la
  MISMA tarjeta en los dos casos, así que no hay dos versiones que se puedan
  desincronizar. Es el criterio de las confirmaciones de borrado.

  POR QUÉ AQUÍ Y NO EN LA FILA, medido el 10/09 con el CSS real: un
  `<select multiple>` en la celda sube la fila de 68 a 136 px en escritorio y de
  112 a 179 en el teléfono, y en escritorio se maneja con ctrl+clic — un clic
  normal borra la selección anterior, o sea saca a alguien de su grupo sin
  decirlo. Aquí caben además los rótulos enteros, que miden 52 a 55 caracteres.
--}}
<div class="card" data-modal-cuerpo style="max-width:520px;">
  <h2 style="margin-top:0;">Grupos de {{ $matricula->estudiante->nombre_completo }}</h2>

  <p class="campo-ayuda">
    {{ $matricula->promotoria->nombre }} · Marca los horarios a los que asiste.
    Puede ir a más de uno.
  </p>

  @if ($grupos === [])
    {{--
      Sin grupos creados no hay nada que marcar, y una lista vacía con un botón
      de guardar se lee como que el sistema se rompió. Se dice qué falta.
    --}}
    <p class="vacio" style="margin:1rem 0 1.3rem;">
      Esta promotoría todavía no tiene grupos. Crea uno antes de repartir.
    </p>
    <div class="modal-botones">
      <a href="{{ route('panel') }}" class="btn btn-secundario" data-modal-cerrar>Volver</a>
    </div>
  @else
    <form method="post" action="{{ route('panel-guardar-grupos', $matricula) }}">
      @csrf

      {{--
        Un campo VACÍO delante de las casillas. Sin él, desmarcarlas todas no
        manda nada y el servidor no puede distinguir «sácalo de todos» de «no
        llegó el campo». Con él siempre llega algo, y la lista vacía significa
        exactamente lo que parece.
      --}}
      <input type="hidden" name="grupo_id[]" value="">

      <ul class="lista-grupos">
        @foreach ($grupos as $g)
        <li>
          <label>
            <input type="checkbox" name="grupo_id[]" value="{{ $g['grupo']->id }}"
                   @checked($g['dentro'])>
            <span>
              <strong>{{ $g['grupo']->nombre_con_nivel }}</strong>
              @if ($g['grupo']->horario)<br><span class="campo-info">{{ $g['grupo']->horario }}</span>@endif
              @if ($g['grupo']->salon) · <span class="campo-info">{{ $g['grupo']->salon }}</span>@endif
              {{--
                El cupo va aquí y no solo en el rechazo: quien reparte decide con
                esto delante, y enterarse de que el grupo está lleno DESPUÉS de
                guardar es hacerle perder el viaje. El «lleno» se dice con
                palabra y no solo con el número, que es la regla de forma del
                proyecto — nada depende solo del color.
              --}}
              <br>
              <span class="campo-info">
                {{ $g['ocupados'] }} de {{ $g['grupo']->cupo_maximo }}
                @if (! $g['dentro'] && $g['ocupados'] >= $g['grupo']->cupo_maximo)
                  · <strong>lleno</strong>
                @endif
              </span>
            </span>
          </label>
        </li>
        @endforeach
      </ul>

      <div class="modal-botones">
        <a href="{{ route('panel') }}" class="btn btn-secundario" data-modal-cerrar>Cancelar</a>
        <button type="submit" class="btn">Guardar</button>
      </div>
    </form>
  @endif
</div>
@endsection
