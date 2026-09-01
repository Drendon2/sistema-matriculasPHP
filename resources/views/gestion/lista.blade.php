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
<p><a class="btn" href="{{ route($ruta_nuevo).$preset }}">+ Nuevo</a></p>

@php($losFiltros = $filtros ?? [])
@php($hayFiltros = $hay_filtros ?? false)

@if ($losFiltros)
<form method="get" class="filtros">
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

  <div class="filtro filtro-acciones">
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
<table>
  <tbody>
    @foreach ($objetos as $fila)
    @php($obj = $fila['objeto'])
    <tr>
      <td>
        @if ($mostrarTagArea)<span class="tag-dot {{ $obj->tag_color }}"></span>@endif
        @if ($rutaFila)<a href="{{ route($rutaFila, $obj) }}">{{ $obj }}</a>@else{{ $obj }}@endif
        @if ($etiquetaPlural && $fila['hijos'] !== null)
          <span class="campo-info" style="margin:0;display:inline;">— {{ $fila['hijos'] }} {{ $fila['hijos'] == 1 ? $etiquetaSingular : $etiquetaPlural }}</span>
        @endif
        @if ($fila['protegido'])
          <span class="campo-info" style="margin:0;display:inline;">· {{ $fila['protegido'] }} {{ $etiquetaProtegido }} en historial</span>
        @endif
        {{--
          Quién dicta, en su propio renglón: esta lista es la de un catálogo y en
          ella "sin asignar" no es un hueco cosmético — es la promotoría en la
          que nadie puede registrar clases.
        --}}
        @if ($mostrarProfesor)
        <span class="campo-info" style="margin:0;display:block;">
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
      <td style="text-align:right;white-space:nowrap;">
        <a href="{{ route($ruta_editar, $obj) }}">Editar</a>
        &nbsp;·&nbsp;
        {{--
          Lo que se cuenta al lado del nombre (grupos, estudiantes activos) no es
          lo que impide borrar: «Títeres — 0 grupos» se lee como vacía y resulta
          que sostiene diecinueve matrículas, retiradas incluidas. Con el enlace
          en rojo ahí puesto, la única forma de enterarse era pulsarlo y que te
          lo negaran. Aquí ya no es un enlace, y dice por qué.
        --}}
        @if ($fila['protegido'])
          <span class="campo-info" style="margin:0;display:inline;"
                title="No se puede eliminar: tiene {{ $fila['protegido'] }} {{ $etiquetaProtegido }} en su historial.">
            Eliminar
          </span>
        @else
          <a href="{{ route($ruta_eliminar, $obj) }}" style="color:var(--danger);" data-modal>Eliminar</a>
        @endif
      </td>
    </tr>
    @endforeach
  </tbody>
</table>
@endif
@endsection
