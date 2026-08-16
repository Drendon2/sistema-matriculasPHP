{{--
  Una tanda de barras horizontales del panel de estadísticas.

  Contexto:
    $filas ... [{etiqueta, total, porcentaje}], tal como las devuelven
               `Grafica::conPorcentaje` y `Grafica::porOpcion`.

  El mismo bloque se repetía en cada escala de la encuesta. Se extrae aquí para
  que agregar una pregunta nueva al panel sea una línea y no un copiar-pegar de
  cuatro etiquetas que hay que acordarse de renombrar.

  La fila marcada con `sin_responder` va aparte: no es una opción de la pregunta
  sino la gente que no cae en ninguna, y se dibuja en gris bajo una línea para
  que no se lea como una respuesta más. Sin ella, una pregunta contestada por dos
  de cinco personas se dibujaba entera y nada avisaba de las otras tres.
--}}
@forelse ($filas as $fila)
<div class="stat-bar-fila{{ ! empty($fila['sin_responder']) ? ' stat-bar-fila-ausente' : '' }}">
  <span class="stat-bar-etiqueta">{{ $fila['etiqueta'] }}</span>
  <div class="stat-bar-pista">
    <div class="stat-bar-relleno" style="width: {{ $fila['porcentaje'] }}%;"></div>
  </div>
  <span class="stat-bar-num">{{ $fila['total'] }}</span>
</div>
@empty
<p class="vacio">Sin respuestas todavía.</p>
@endforelse
