{{--
  LA TABLA DE UN CATALOGO, sin su pantalla alrededor.

  Sale de `gestion/lista.blade.php` porque desde el 01/09/2026 tiene DOS casas:
  esa pantalla, que enseña un catálogo solo, y «Programas formativos», que
  enseña tres seguidos en secciones. Copiarla habría sido garantizar que las dos
  copias se separaran — es lo mismo que dice `RecursoController` de las cuatro
  pantallas que comparte.

  Recibe lo que trae el controlador tal cual (`objetos`, `ruta_editar`,
  `ruta_eliminar`, `modal`, y las etiquetas), y normaliza aquí sus opcionales
  para que quien lo incluya no tenga que saberse la lista.

  Las directivas PHP van en la forma de UNA LINEA. La de bloque, mezclada con
  esta en el mismo archivo, deja sin compilar todo lo que quede en medio.
--}}
@php($rutaFila = $ruta_fila ?? null)
@php($etiquetaSingular = $etiqueta_singular ?? null)
@php($etiquetaPlural = $etiqueta_plural ?? null)
@php($etiquetaProtegido = $etiqueta_protegido ?? 'registros')
@php($abreEnModal = $modal ?? false)
@php($mostrarTagArea = $mostrar_tag_area ?? false)
@php($mostrarProfesor = $mostrar_profesor ?? false)
@php($hayFiltros = $hay_filtros ?? false)

@if (! $objetos)
  {{-- Sin nada y sin filtros son dos cosas distintas: «no hay grupos» manda a
       crear uno, «ninguno coincide» manda a soltar el filtro. --}}
  @if ($hayFiltros)
    <p class="vacio">Ninguno coincide con estos filtros.</p>
  @else
    {{-- «Todavía no hay nada aquí» valía cuando la lista era la pantalla
         entera. En «Programas formativos» hay tres seguidas y «aquí» deja de
         decir cuál, así que quien la incluye puede nombrarla. --}}
    <p class="vacio">{{ $vacio_texto ?? 'Todavía no hay nada aquí.' }}</p>
  @endif
@else
{{--
  `.tabla-personas` no es solo para personas: es la lista de REGISTROS que bajo
  640px deja de ser tabla y pasa a ser una ficha por fila.

  Las acciones van en un menú (`.menu-fila`), no sueltas en la fila: no son lo
  que se viene a hacer a un catálogo, y puestas a la vista eran lo único
  pulsable de cada fila. `.tabla-menu` es lo que en el teléfono pega ese menú
  arriba a la derecha de la ficha, junto al nombre.

  `.tabla-catalogo` encima porque esta fila no son campos etiquetados sino una
  frase, y su primera celda tiene que fluir como texto. No es una rejilla: la
  posición de la celda no es el dato, así que la regla del DESIGN.md se cumple.
--}}
<table class="tabla-personas tabla-catalogo tabla-menu-esquina">
  <tbody>
    @foreach ($objetos as $fila)
    @php($obj = $fila['objeto'])
    <tr>
      <td data-celda="detalle">
        @if ($mostrarTagArea)<span class="tag-dot {{ $obj->tag_color }}"></span>@endif
        <span class="lista-nombre">@if ($rutaFila)<a href="{{ route($rutaFila, $obj) }}">{{ $obj }}</a>@else{{ $obj }}@endif</span>
        @if ($etiquetaPlural && $fila['hijos'] !== null)
          <span class="lista-nota">— {{ $fila['hijos'] }} {{ $fila['hijos'] == 1 ? $etiquetaSingular : $etiquetaPlural }}</span>
        @endif
        @if ($fila['protegido'])
          <span class="lista-nota">· {{ $fila['protegido'] }} {{ $etiquetaProtegido }} en historial</span>
        @endif
        {{--
          Quién dicta, en su propio renglón: esta lista es la de un catálogo y en
          ella "sin asignar" no es un hueco cosmético — es la promotoría en la
          que nadie puede registrar clases.
        --}}
        @if ($mostrarProfesor)
        <span class="lista-nota lista-nota-bloque">
          Profesor:
          @if ($obj->profesor)
            @if (\App\Support\Permisos::puedeVerFicha($yo, $obj->profesor))
              <a href="{{ route('detalle-usuario', $obj->profesor) }}">{{ $obj->profesor->nombre_completo }}</a>
            @else
              {{ $obj->profesor->nombre_completo }}
            @endif
          @else
            Sin asignar
          @endif
        </span>
        @endif
      </td>
      {{--
        Lo que se cuenta al lado del nombre (grupos, estudiantes activos) no es
        lo que impide borrar: «Títeres — 0 grupos» se lee como vacía y resulta
        que sostiene diecinueve matrículas, retiradas incluidas. Por eso la
        opción de borrar sale APAGADA y con su porqué, en vez de dejar que la
        pulses para que te lo nieguen.
      --}}
      <td data-celda="accion" class="lista-acciones lista-acciones-menu">
        @include('partials.menu-fila', [
            'etiqueta' => $obj,
            'opciones' => [
                ['texto' => 'Editar', 'url' => route($ruta_editar, $obj), 'modal' => $abreEnModal],
                [
                    'texto' => 'Eliminar',
                    'url' => $fila['protegido'] ? null : route($ruta_eliminar, $obj),
                    'modal' => true,
                    'borrar' => true,
                    'porque' => 'Tiene '.$fila['protegido'].' '.$etiquetaProtegido.' en su historial.',
                ],
            ],
        ])
      </td>
    </tr>
    @endforeach
  </tbody>
</table>
@endif
