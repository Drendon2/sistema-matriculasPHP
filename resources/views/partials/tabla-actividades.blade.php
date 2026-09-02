{{--
  LA TABLA DE ACTIVIDADES, sin su pantalla alrededor.

  Igual que `partials/tabla-catalogo`: desde el 01/09/2026 tiene dos casas —la
  pantalla de un tipo solo y la sección de «Programas formativos»—, y copiarla
  habría sido garantizar que las dos copias se separaran.

  Recibe `actividades`, `ruta_editar`, `ruta_eliminar`, `ruta_enlace` y `modal`
  tal como los trae el controlador.

  Directivas PHP en la forma de UNA LINEA, como el resto del archivo: mezclarla
  con la de bloque deja sin compilar todo lo que quede en medio.
--}}
@if ($actividades->isEmpty())
  {{-- Ver el mismo comentario en `partials/tabla-catalogo`. --}}
  <p class="vacio">{{ $vacio_texto ?? 'Todavía no hay nada aquí.' }}</p>
@else
{{--
  `.tabla-personas` porque bajo 640px cada fila pasa a ficha: aqui las acciones
  son cuatro —abrir o cerrar el enlace, fechas, editar, eliminar— y tres de
  ellas eran enlaces de texto de 20px pegados con puntos en medio, la ultima el
  borrado. `.tabla-catalogo` porque la primera celda es una frase, no campos
  etiquetados. Es una lista de registros, no una rejilla.
--}}
<table class="tabla-personas tabla-catalogo tabla-menu-fila">
  <thead>
    <tr>
      <th>Nombre</th>
      <th>Responsable</th>
      <th class="num">Inscritos</th>
      <th></th>
    </tr>
  </thead>
  <tbody>
    @foreach ($actividades as $actividad)
    {{--
      Los dos conteos se precalculan con la directiva PHP en línea, como el
      resto del proyecto: pegar una directiva a una letra la deja sin compilar.
    --}}
    @php($cuantas = $actividad->sesiones_count)
    @php($apuntados = $actividad->inscritos_count)
    @php($admite = $actividad->admiteInscripciones($apuntados))
    <tr>
      <td data-celda="detalle">
        <span class="lista-nombre">{{ $actividad->nombre }}</span>
        <span class="tipo-chip">{{ $actividad->etiquetaTipo() }}</span>
        {{--
          Un curso sin fechas está a medio crear: no se puede iniciar nada ni
          decirle a nadie cuándo es, y sin este renglón la fila se ve igual que
          la de uno terminado.
        --}}
        @if ($actividad->llevaFechas())
          <span class="lista-nota lista-nota-bloque">
            @if ($cuantas)
              {{ $cuantas }} {{ $cuantas == 1 ? 'clase' : 'clases' }}
            @else
              <strong>Sin fechas todavía.</strong> Ponlas para poder iniciarlas.
            @endif
          </span>
        @endif

        {{--
          El enlace, en un campo que se puede seleccionar de un tirón: esto se
          copia y se pega en un WhatsApp, y un texto suelto obliga a arrastrar
          el ratón por encima sin pasarse.
        --}}
        <label class="sr-solo" for="enlace_{{ $actividad->id }}">Enlace de {{ $actividad->nombre }}</label>
        <div class="enlace-fila">
          <input class="enlace-copiable" type="text" id="enlace_{{ $actividad->id }}"
                 readonly value="{{ $actividad->enlace() }}">
        </div>
      </td>
      <td data-label="Responsable">
        @if (\App\Support\Permisos::puedeVerFicha($yo, $actividad->responsable))
          <a href="{{ route('detalle-usuario', $actividad->responsable) }}">{{ $actividad->responsable->nombre_completo }}</a>
        @else
          {{ $actividad->responsable->nombre_completo }}
        @endif
      </td>
      <td class="num" data-label="Inscritos">
        {{-- Sin tope no es cero: es que nadie puso uno. --}}
        @if ($actividad->cupo_maximo === null)
          <span class="cupo-cifra cupo-cifra-libre">{{ $apuntados }} / ∞</span>
        @elseif ($apuntados >= $actividad->cupo_maximo)
          <span class="cupo-cifra cupo-cifra-lleno">{{ $apuntados }} / {{ $actividad->cupo_maximo }}</span>
        @else
          <span class="cupo-cifra">{{ $apuntados }} / {{ $actividad->cupo_maximo }}</span>
        @endif
        {{--
          Por qué no admite gente. Lleno y cerrado son dos cosas distintas: la
          primera se arregla subiendo el cupo y la segunda con el botón de al
          lado, y decir solo «no admite» dejaría adivinando cuál toca.
        --}}
        @if (! $admite)
          <span class="lista-nota lista-nota-bloque">
            {{ $actividad->abierta ? 'Cupos llenos' : 'Enlace cerrado' }}
          </span>
        @endif
      </td>
      {{--
        El interruptor del enlace se queda A LA VISTA y el resto pasa al menú, y
        la línea entre los dos es la frecuencia: abrir o cerrar el enlace es lo
        que se hace con una actividad en marcha —«ya empezamos, no reciban
        más»—, mientras que poner fechas, editar o borrar son de montarla.
      --}}
      <td data-celda="accion" class="lista-acciones lista-acciones-menu">
        <span class="accion-fila">
        <form method="post" action="{{ route($ruta_enlace, $actividad) }}" class="actividad-enlace-form">
          @csrf
          <button type="submit" class="btn btn-blanco btn-sm">
            {{ $actividad->abierta ? 'Cerrar enlace' : 'Abrir enlace' }}
          </button>
        </form>
        @include('partials.menu-fila', [
            'etiqueta' => $actividad->nombre,
            'opciones' => array_values(array_filter([
                $actividad->llevaFechas()
                    ? ['texto' => 'Fechas', 'url' => route('actividad-curso-fechas', $actividad)]
                    : null,
                ['texto' => 'Editar', 'url' => route($ruta_editar, $actividad), 'modal' => $modal ?? false],
                [
                    'texto' => 'Eliminar',
                    'url' => route($ruta_eliminar, $actividad),
                    'modal' => true,
                    'borrar' => true,
                ],
            ])),
        ])
        </span>
      </td>
    </tr>
    @endforeach
  </tbody>
</table>
@endif
