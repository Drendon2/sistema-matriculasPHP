{{--
  Las cifras de permanencia que acompañan a una barra del árbol de departamentos,
  como una micro-gráfica de columnas verticales al final del renglón.

  Contexto:
    $fila ....... un nodo del árbol, ya pasado por `Grafica::conPermanencia`
    $hayPrevio .. si hay periodo anterior con el que comparar
    $tono ....... clase de color de Área (`tag-N`) para la columna de
                  permanencia, o vacío para heredar el gris de las filas de
                  promotoría

  Van en columnas y no en texto porque la comparación que importa es entre
  renglones, no dentro de uno: la pregunta es en qué promotoría se está yendo la
  gente. Tres cifras en monoespaciada no se alineaban entre filas y además
  empujaban la barra de magnitud, que es la que manda.

  Van en vertical y no como una segunda barra horizontal por lo mismo de siempre:
  dos barras horizontales en un renglón obligan a leer dos escalas en la misma
  dirección y se confunden. Girada 90°, la micro-gráfica se lee como otra cosa.

  Cada columna se distingue por FORMA antes que por color, igual que el marcador
  de estado. El porcentaje exacto va en `title` y el renglón entero lleva su
  `aria-label`: la gráfica es el vistazo, no la única vía al dato.
--}}
@php($tono = $tono ?? '')
@php($noVolvio = $fila['pct_no_renovo'])
@php($resumenNoRetorno = $hayPrevio ? ($noVolvio !== null ? ", no volvió {$noVolvio}%" : ', sin referencia de no retorno') : '')

<span class="perm" role="img"
      aria-label="Sigue {{ $fila['pct_continuan'] }}%, deja {{ $fila['pct_desercion'] }}%{{ $resumenNoRetorno }}.">
  <i class="perm-col perm-sigue"
     style="height: {{ $fila['pct_continuan'] }}%;@if ($tono) background: var(--{{ $tono }});@endif"
     title="{{ $fila['pct_continuan'] }}% sigue matriculado"></i>
  <i class="perm-col perm-deja" style="height: {{ $fila['pct_desercion'] }}%;"
     title="{{ $fila['pct_desercion'] }}% se retiró durante el periodo"></i>
  @if ($hayPrevio)
    @if ($noVolvio !== null)
      <i class="perm-col perm-novolvio" style="height: {{ $noVolvio }}%;"
         title="{{ $noVolvio }}% no volvió del periodo anterior"></i>
    @else
      <i class="perm-col perm-sinref" title="Sin gente del periodo anterior con la que comparar"></i>
    @endif
  @endif
</span>
