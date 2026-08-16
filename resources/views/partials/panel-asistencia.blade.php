{{--
  Panel de asistencia de una ficha. Sirve para estudiante y para profesor sin
  preguntar de quién es: `fichas`, `celdas` y `leyenda` ya vienen resueltos desde
  `ResumenAsistencia`.

  Las cifras de la leyenda no son adorno. El verde de "asistió" queda en 2.74:1
  contra el blanco —validado, no estimado— y por debajo de 3:1 el color deja de
  sostenerse solo: las cifras visibles son su compensación obligatoria. Por lo
  mismo cada celda lleva su texto al pasar, y "con excusa" y "sin marcar" se
  distinguen además por forma (punto interior y contorno punteado), que es la
  Regla del Marcador de DESIGN.md aplicada a un cuadro de once píxeles.
--}}
<section class="asis" aria-labelledby="asis-titulo">
  <h3 id="asis-titulo">Asistencia@if ($periodo) — {{ $periodo->nombre }}@endif</h3>

  <div class="asis-fichas">
    @foreach ($asistencia['fichas'] as $f)
    <div class="asis-ficha">
      <span class="asis-ficha-etiqueta">{{ $f['etiqueta'] }}</span>
      <strong class="asis-ficha-valor">{{ $f['valor'] }}</strong>
      @if (! empty($f['nota']))<span class="asis-ficha-nota">{{ $f['nota'] }}</span>@endif
    </div>
    @endforeach
  </div>

  @if ($asistencia['celdas'])
  <div class="asis-cal-envoltorio">
    {{--
      Siete filas y una columna por semana. Las celdas van en orden y es el CSS
      quien las reparte en columnas (`grid-auto-flow: column`): así la plantilla
      no tiene que agrupar por semanas ni el modelo devolver una lista de listas.
    --}}
    <div class="asis-cal" role="img"
         aria-label="Calendario de asistencia del periodo. El detalle está en las cifras de arriba y en la lista de clases.">
      @foreach ($asistencia['celdas'] as $c)
        <span class="asis-cel {{ $c['clase'] }}" title="{{ $c['titulo'] }}"></span>
      @endforeach
    </div>
  </div>

  <ul class="asis-leyenda">
    @foreach ($asistencia['leyenda'] as $l)
    <li>
      <span class="asis-cel {{ $l['clase'] }}"></span>{{ $l['etiqueta'] }}@if ($l['valor'] !== null) <strong>{{ $l['valor'] }}</strong>@endif
    </li>
    @endforeach
  </ul>
  @endif
</section>
