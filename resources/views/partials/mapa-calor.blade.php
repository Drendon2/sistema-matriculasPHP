{{--
  Las cifras, el calendario y la leyenda de un mapa de calor.

  Sin título ni <section>: lo pone quien lo incluye, porque el mismo bloque sirve
  para «Asistencia» en una ficha y para «Actividad de la institución» en el
  tablero, y son dos cosas distintas aunque se pinten igual.

  El `<div class="mapa-calor">` NO es decorativo: es donde viven las variables de
  medida de la rejilla (`--cal-celda`, `--cal-hueco`). Estaban en `.asis` —la
  sección de una ficha— y al traer el mapa a Estadísticas, que no es una ficha,
  el calendario salió con siete filas de cero píxeles. Aquí viajan con el bloque
  y no pueden quedarse atrás.

  Espera `$mapa` con `fichas`, `celdas` y `leyenda`, tal como los devuelve
  `ResumenAsistencia`. Las cifras de la leyenda no son adorno: el verde de
  «asistió» queda en 2.74:1 contra el blanco —validado, no estimado— y por debajo
  de 3:1 el color deja de sostenerse solo. Por lo mismo cada celda lleva su texto
  al pasar, y «con excusa» y «sin marcar» se distinguen además por forma, que es
  la Regla del Marcador de DESIGN.md aplicada a un cuadro de once píxeles.
--}}
<div class="mapa-calor">
<div class="asis-fichas">
  @foreach ($mapa['fichas'] as $f)
  <div class="asis-ficha">
    <span class="asis-ficha-etiqueta">{{ $f['etiqueta'] }}</span>
    <strong class="asis-ficha-valor">{{ $f['valor'] }}</strong>
    @if (! empty($f['nota']))<span class="asis-ficha-nota">{{ $f['nota'] }}</span>@endif
  </div>
  @endforeach
</div>

@if ($mapa['celdas'])
<div class="asis-cal-envoltorio">
  <div class="asis-cal-marco">
    {{--
      Las iniciales del día, alineadas con las siete filas. Sin ellas la rejilla
      se puede mirar pero no leer: un cuadro suelto no dice si el hueco de los
      jueves es que no hay clase ese día o que faltó tres semanas seguidas — que
      es justo el patrón que se viene a buscar aquí.

      El domingo va aunque casi nunca haya clase: la fila existe igual en la
      rejilla, y quitarla escondería una sesión registrada en domingo —un ensayo,
      un montaje— en vez de enseñarla.

      `aria-hidden` porque la rejilla entera ya se anuncia con su propio
      `aria-label`: leer «L M W J V S D» suelto no le dice nada a nadie.
    --}}
    <div class="asis-dias" aria-hidden="true">
      <span>L</span><span>M</span><span>W</span><span>J</span><span>V</span><span>S</span><span>D</span>
    </div>

    {{--
      Siete filas y una columna por semana. Las celdas van en orden y es el CSS
      quien las reparte en columnas (`grid-auto-flow: column`): así la plantilla
      no tiene que agrupar por semanas ni el modelo devolver una lista de listas.
    --}}
    <div class="asis-cal" role="img" aria-label="{{ $etiquetaCalendario ?? 'Calendario del periodo, una columna por semana. El detalle está en las cifras de arriba.' }}">
      @foreach ($mapa['celdas'] as $c)
        <span class="asis-cel {{ $c['clase'] }}" title="{{ $c['titulo'] }}"></span>
      @endforeach
    </div>
  </div>
</div>

<ul class="asis-leyenda">
  @foreach ($mapa['leyenda'] as $l)
  <li>
    <span class="asis-cel {{ $l['clase'] }}"></span>{{ $l['etiqueta'] }}@if ($l['valor'] !== null) <strong>{{ $l['valor'] }}</strong>@endif
  </li>
  @endforeach
</ul>
@endif
</div>
