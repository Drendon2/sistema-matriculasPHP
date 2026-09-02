@extends('layouts.app')

@section('title', $titulo)

@section('content')
{{--
  La pantalla de listado que comparten los cuatro catálogos. Recibe:

    $objetos ............ [{objeto, hijos, protegido}]
    $ruta_nuevo/editar/eliminar
    $ruta_fila .......... a dónde lleva el nombre (el nivel de abajo), si aplica
    $etiqueta_singular/plural ... qué se cuenta al lado del nombre
    $etiqueta_protegido . qué impide borrarlo
    $preset_campo/valor . para que "+ Nuevo" llegue con el padre ya elegido
    $migas .............. la ruta de vuelta cuando se entra por la jerarquía
    $filtros ............ opcional: [{nombre, etiqueta, vacio, opciones, valor}]
                          Solo Grupos los usa hoy. Los otros tres catálogos no
                          pasan nada y la barra no se pinta, que es por lo que va
                          aquí y no en una plantilla aparte: la tabla, el estado
                          vacío y el botón de nuevo son los mismos.
--}}
@php($migas = $migas ?? [])
@php($rutaFila = $ruta_fila ?? null)
@php($etiquetaSingular = $etiqueta_singular ?? null)
@php($etiquetaPlural = $etiqueta_plural ?? null)
@php($etiquetaProtegido = $etiqueta_protegido ?? 'registros')
{{-- El grupo dice que no: su horario es media pantalla. Ver `RecursoController::cabeEnModal()`. --}}
@php($abreEnModal = $modal ?? false)
@php($mostrarTagArea = $mostrar_tag_area ?? false)
@php($mostrarProfesor = $mostrar_profesor ?? false)
@php($preset = isset($preset_campo) ? '?'.$preset_campo.'='.$preset_valor : '')

@if ($migas)
<p class="migas">
  @foreach ($migas as $m)<a href="{{ $m['url'] }}">{{ $m['texto'] }}</a><span class="migas-sep">/</span>@endforeach<span class="migas-actual">{{ $titulo }}</span>
</p>
@else
<a href="{{ route('gestion-inicio') }}" class="volver">&larr; Gestión</a>
@endif
<h2>{{ $titulo }}</h2>
{{-- El espacio antes de la directiva no sobra: pegada a una letra, Blade lee
     «algo@if» como un correo y se come el @endif que le toca. --}}
<p><a class="btn" href="{{ route($ruta_nuevo).$preset }}" @if ($abreEnModal) data-modal @endif>+ Nuevo</a></p>

@php($losFiltros = $filtros ?? [])
@php($hayFiltros = $hay_filtros ?? false)

@if ($losFiltros)
{{--
  Plegada. Tres desplegables a 44px son 297px antes de que empiece la tabla, y
  esta pantalla se usa desde el teléfono. El resumen dice cuántos hay puestos
  para que plegado no acabe significando escondido. Ver `.filtros-plegables`.
--}}
@php($cuantosFiltros = collect($losFiltros)->filter(fn ($f) => (string) $f['valor'] !== '')->count())
<form method="get">
<details class="filtros-plegables">
  <summary class="filtros-resumen">
    Filtros
    @if ($cuantosFiltros)
      <span class="filtros-cuenta">{{ $cuantosFiltros }} {{ $cuantosFiltros == 1 ? 'puesto' : 'puestos' }}</span>
    @endif
    <svg class="perfil-seccion-chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>
  </summary>
  <div class="filtros">
  @foreach ($losFiltros as $f)
  <div class="filtro">
    <label for="f-{{ $f['nombre'] }}">{{ $f['etiqueta'] }}</label>
    <select name="{{ $f['nombre'] }}" id="f-{{ $f['nombre'] }}">
      <option value="">{{ $f['vacio'] }}</option>
      @if (! empty($f['agrupadas']))
        {{-- La jerarquía del catálogo se ve en el propio desplegable, sin tener
             que elegir antes el departamento y recargar. --}}
        @foreach ($f['opciones'] as $grupo => $opciones)
          <optgroup label="{{ $grupo }}">
            @foreach ($opciones as $valor => $etiqueta)
              <option value="{{ $valor }}" @selected((string) $f['valor'] === (string) $valor)>{{ $etiqueta }}</option>
            @endforeach
          </optgroup>
        @endforeach
      @else
        @foreach ($f['opciones'] as $valor => $etiqueta)
          <option value="{{ $valor }}" @selected((string) $f['valor'] === (string) $valor)>{{ $etiqueta }}</option>
        @endforeach
      @endif
    </select>
  </div>
  @endforeach

  </div>
</details>

  {{--
    Los dos botones FUERA del pliegue: «Limpiar» es la salida de un filtro
    puesto, y esconderla detrás del mismo panel que hay que abrir para quitarlo
    sería dejar la salida dentro de la trampa.
  --}}
  <div class="filtro filtro-acciones filtros-botones">
    <button type="submit" class="btn btn-sm">Filtrar</button>
    @if ($hayFiltros)
      <a class="btn btn-blanco btn-sm" href="{{ route($ruta_lista) }}">Limpiar</a>
    @endif
  </div>
</form>

@if ($hayFiltros && isset($nota_filtros))
  <p class="filtros-nota">{{ $nota_filtros }}</p>
@endif
@endif

@if (! $objetos)
  {{-- Sin nada y sin filtros son dos cosas distintas: «no hay grupos» manda a
       crear uno, «ninguno coincide» manda a soltar el filtro. --}}
  @if ($hayFiltros)
    <p class="vacio">Ninguno coincide con estos filtros.</p>
  @else
    <p class="vacio">Todavía no hay nada aquí.</p>
  @endif
@else
{{--
  `.tabla-personas` no es solo para personas: es la lista de REGISTROS que bajo
  640px deja de ser tabla y pasa a ser una ficha por fila. Aquí hace falta por lo
  de siempre —las acciones—: «Editar» y «Eliminar» eran dos enlaces de texto de
  20px de alto, pegados con un punto en medio, y uno de ellos borra. En la ficha
  son dos botones a ancho completo, uno debajo del otro.

  `.tabla-catalogo` encima porque esta fila no son campos etiquetados sino una
  frase, y su primera celda tiene que fluir como texto. No es una rejilla: la
  posición de la celda no es el dato, así que la regla del DESIGN.md se cumple.
--}}
<table class="tabla-personas tabla-catalogo">
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
      <td data-celda="accion" class="lista-acciones">
        <span class="accion-fila">
        <a href="{{ route($ruta_editar, $obj) }}" @if ($abreEnModal) data-modal @endif>Editar</a>
        <span class="accion-sep">&nbsp;·&nbsp;</span>
        {{--
          Lo que se cuenta al lado del nombre (grupos, estudiantes activos) no es
          lo que impide borrar: «Títeres — 0 grupos» se lee como vacía y resulta
          que sostiene diecinueve matrículas, retiradas incluidas. Con el enlace
          en rojo ahí puesto, la única forma de enterarse era pulsarlo y que te
          lo negaran. Aquí ya no es un enlace, y dice por qué.
        --}}
        @if ($fila['protegido'])
          <span class="accion-inerte"
                title="No se puede eliminar: tiene {{ $fila['protegido'] }} {{ $etiquetaProtegido }} en su historial.">
            Eliminar
          </span>
        @else
          <a href="{{ route($ruta_eliminar, $obj) }}" style="color:var(--danger);" data-modal>Eliminar</a>
        @endif
        </span>
      </td>
    </tr>
    @endforeach
  </tbody>
</table>
@endif
@endsection
