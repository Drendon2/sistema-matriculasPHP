{{--
  Gráfica de torta con su leyenda.

  Contexto:
    $torta ... salida de `Grafica::torta`: {sectores, leyenda, total}

  Los sectores son un <circle> cada uno con el trazo tan grueso como el diámetro:
  el trazo rellena el disco, y `stroke-dasharray` recorta el arco que le toca.
  El grupo va rotado -90° para que la torta empiece a las 12 y no a las 3, que es
  por donde arranca el trazo de un círculo en SVG.

  Los sectores CASAN entre sí, sin hueco: el orden del `@foreach` importa, porque
  cada uno se mete medio píxel debajo del siguiente para tapar la costura del
  suavizado (ver `Grafica::SOLAPE`). Pintados en otro orden, el solape quedaría
  por debajo y la costura volvería.

  La leyenda no es decorativa: lleva el número y el porcentaje de cada opción,
  que es lo que permite leer la gráfica sin depender del color (uno de los tonos
  queda por debajo de 3:1 contra el fondo, y esa es su compensación obligatoria).
  También lista las opciones en cero, que por definición no dibujan sector: sin
  ella, una opción sin respuestas desaparecería sin explicación.

  Los números van con `number_format(..., 2, '.', '')` y no tal cual: en SVG la
  coma NO es un separador decimal, es el separador entre valores. Escrito
  `stroke-dasharray="73,4 188,5"` deja de significar «73.4 pintado, 188.5 en
  blanco» y pasa a ser un patrón de cuatro tramos que rellena casi el disco
  entero. En el original esto se resolvía con `{% localize off %}`; aquí PHP
  formatea con punto por defecto, pero se fija de todos modos para que un cambio
  de configuración regional no vuelva a romper el dibujo en silencio.
--}}
@if (! $torta['total'])
  <p class="vacio">Sin respuestas todavía.</p>
@else
<div class="torta-bloque">
  <svg class="torta" viewBox="0 0 120 120" role="img"
       aria-label="Gráfica de torta; los valores están en la lista contigua.">
    <g transform="rotate(-90 60 60)">
      @foreach ($torta['sectores'] as $s)
      <circle cx="60" cy="60" r="30" fill="none"
              stroke="{{ $s['color'] }}" stroke-width="60"
              stroke-dasharray="{{ number_format($s['trazo'], 2, '.', '') }} {{ number_format($s['resto'], 2, '.', '') }}"
              stroke-dashoffset="{{ number_format($s['desfase'], 2, '.', '') }}"></circle>
      @endforeach
    </g>
  </svg>

  <ul class="torta-leyenda">
    @foreach ($torta['leyenda'] as $e)
    <li @class(['torta-leyenda-cero' => ! $e['total']])>
      <span class="torta-punto" style="background: {{ $e['color'] }};"></span>
      <span class="torta-etiqueta">{{ $e['etiqueta'] }}</span>
      <span class="torta-cifra">{{ $e['total'] }}</span>
      <span class="torta-parte">{{ $e['parte'] }}%</span>
    </li>
    @endforeach
  </ul>
</div>
@endif
