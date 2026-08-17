{{--
  El paso entre periodos: flechas a los lados del nombre.

  Recibe URLS ya armadas y no una ruta con parámetros, porque los tres sitios que
  lo usan no las construyen igual: Estadísticas lleva el periodo en el camino
  —el tablero ENTERO es de ese periodo— y los perfiles lo llevan como parámetro
  de consulta, porque ahí el periodo solo mueve un panel de la página y el resto
  —la foto, los papeles, la encuesta— no cambia.

    $periodo ..... el que se está viendo
    $urlAtras .... o null si es el más antiguo
    $urlAdelante . o null si es el más reciente
    $enCurso ..... para pintar el distintivo

  Las flechas van a los lados porque es como se lee un calendario: atrás a la
  izquierda, adelante a la derecha. Cuando no hay a dónde ir se pintan igual,
  apagadas, en vez de desaparecer: si se quitaran, al llegar al extremo la que
  queda se correría justo donde estaba la otra y un clic de más saltaría dos
  periodos sin querer.

  En el móvil se puede además deslizar con el dedo sobre la barra (periodo.js).
  Es un atajo, no el único camino: los enlaces funcionan sin JavaScript.
--}}
<nav class="periodo-nav" aria-label="Periodo" data-periodo-nav>
  @if ($urlAtras)
    <a class="periodo-flecha" href="{{ $urlAtras }}" rel="prev"
       aria-label="Periodo anterior" data-periodo-atras>&larr;</a>
  @else
    <span class="periodo-flecha periodo-flecha-off" aria-hidden="true">&larr;</span>
  @endif

  <span class="periodo-actual">
    {{ $periodo->nombre }}
    @if ($enCurso)<span class="estado estado-activa">En curso</span>@endif
  </span>

  @if ($urlAdelante)
    <a class="periodo-flecha" href="{{ $urlAdelante }}" rel="next"
       aria-label="Periodo siguiente" data-periodo-adelante>&rarr;</a>
  @else
    <span class="periodo-flecha periodo-flecha-off" aria-hidden="true">&rarr;</span>
  @endif
</nav>
