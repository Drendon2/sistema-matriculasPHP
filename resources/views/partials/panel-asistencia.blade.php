{{--
  Panel de asistencia de una ficha. Sirve para estudiante y para profesor sin
  preguntar de quién es: `fichas`, `celdas` y `leyenda` ya vienen resueltos desde
  `ResumenAsistencia`.

  El bloque en sí vive en `partials/mapa-calor`, porque el mismo dibujo lo usa
  ahora el tablero de Estadísticas para la actividad de toda la institución. Lo
  que se queda aquí es lo propio de una ficha: el título y su periodo.
--}}
<section class="asis" aria-labelledby="asis-titulo">
  <h3 id="asis-titulo">Asistencia</h3>

  {{--
    El paso entre periodos, cuando quien incluye esto lo ofrece. Solo se ofrecen
    los periodos donde esta persona TIENE algo (ver
    `ResumenAsistencia::navegacionDePeriodos`), así que ninguna flecha lleva a un
    panel vacío.

    El nombre del periodo ya no va pegado al título: lo dice la barra, y decirlo
    dos veces en el mismo renglón sobra. Donde no hay barra —si alguna pantalla
    incluye el panel sin navegación— vuelve al título, para que nunca se quede
    sin decir de qué periodo habla.
  --}}
  @php($hayNav = ($periodoAtras ?? null) || ($periodoAdelante ?? null))

  @if ($hayNav && $periodo)
    @include('partials.periodo-nav', [
      'periodo' => $periodo,
      'urlAtras' => $periodoAtras ?? null,
      'urlAdelante' => $periodoAdelante ?? null,
      'enCurso' => $periodoEsElEnCurso ?? false,
    ])
  @elseif ($periodo)
    <p class="campo-info" style="margin:-0.6rem 0 0.9rem;">{{ $periodo->nombre }}</p>
  @endif

  @include('partials.mapa-calor', [
    'mapa' => $asistencia,
    'etiquetaCalendario' => 'Calendario de asistencia del periodo, una columna por semana. El detalle está en las cifras de arriba y en la lista de clases.',
  ])
</section>
