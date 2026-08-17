{{--
  Panel de asistencia de una ficha. Sirve para estudiante y para profesor sin
  preguntar de quién es: `fichas`, `celdas` y `leyenda` ya vienen resueltos desde
  `ResumenAsistencia`.

  El bloque en sí vive en `partials/mapa-calor`, porque el mismo dibujo lo usa
  ahora el tablero de Estadísticas para la actividad de toda la institución. Lo
  que se queda aquí es lo propio de una ficha: el título y su periodo.
--}}
<section class="asis" aria-labelledby="asis-titulo">
  {{-- Ver `renovar.blade.php`: una directiva pegada a una letra no se compila. --}}
  @php($sufijoPeriodo = $periodo ? " — {$periodo->nombre}" : '')
  <h3 id="asis-titulo">Asistencia{{ $sufijoPeriodo }}</h3>

  @include('partials.mapa-calor', [
    'mapa' => $asistencia,
    'etiquetaCalendario' => 'Calendario de asistencia del periodo, una columna por semana. El detalle está en las cifras de arriba y en la lista de clases.',
  ])
</section>
