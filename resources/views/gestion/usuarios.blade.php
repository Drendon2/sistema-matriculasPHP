@extends('layouts.app')

@section('title', 'Usuarios')

@section('content')
<a href="{{ route('gestion-inicio') }}" class="volver">&larr; Gestión</a>
<h2>Usuarios</h2>
<p><a class="btn" href="{{ route('usuario-nuevo') }}">+ Nuevo usuario</a></p>

<form method="get" class="filtros">
  <div class="filtro">
    <label for="f-rol">Rol</label>
    <select name="rol" id="f-rol">
      <option value="">Todos</option>
      @foreach ($roles as $valor => $etiqueta)
        <option value="{{ $valor }}" @selected($seleccion['rol'] === $valor)>{{ $etiqueta }}</option>
      @endforeach
      <option value="{{ $rolPendiente }}" @selected($seleccion['rol'] === $rolPendiente)>
        Pendiente de rol
      </option>
    </select>
  </div>

  <div class="filtro">
    <label for="f-area">Departamento</label>
    <select name="area" id="f-area">
      <option value="">Todos</option>
      @foreach ($areas as $area)
        <option value="{{ $area->id }}" @selected($seleccion['area']?->id === $area->id)>{{ $area->nombre }}</option>
      @endforeach
    </select>
  </div>

  <div class="filtro">
    <label for="f-promotoria">Promotoría</label>
    <select name="promotoria" id="f-promotoria">
      <option value="">Todas</option>
      @foreach ($promotorias->groupBy('area.nombre') as $areaNombre => $delArea)
      <optgroup label="{{ $areaNombre }}">
        @foreach ($delArea as $p)
          <option value="{{ $p->id }}" @selected($seleccion['promotoria']?->id === $p->id)>{{ $p->nombre }}</option>
        @endforeach
      </optgroup>
      @endforeach
    </select>
  </div>

  <div class="filtro">
    <label for="f-grupo">Grupo</label>
    <select name="grupo" id="f-grupo">
      <option value="">Todos</option>
      @foreach ($grupos->groupBy('promotoria.nombre') as $promotoriaNombre => $deLaPromotoria)
      <optgroup label="{{ $promotoriaNombre }}">
        @foreach ($deLaPromotoria as $g)
          <option value="{{ $g->id }}" @selected($seleccion['grupo']?->id === $g->id)>
            {{ $g->nivel_display }} · {{ $g->horario }}
          </option>
        @endforeach
      </optgroup>
      @endforeach
    </select>
  </div>

  <div class="filtro">
    <label for="f-periodo">Periodo</label>
    <select name="periodo" id="f-periodo">
      @foreach ($periodos as $p)
        <option value="{{ $p->id }}" @selected($seleccion['periodo']?->id === $p->id)>
          {{ $p->nombre }}@if ($p->activo) (en curso) @endif
        </option>
      @endforeach
    </select>
  </div>

  <div class="filtro filtro-acciones">
    <button type="submit" class="btn btn-sm">Filtrar</button>
    @if ($hayFiltros)
      <a class="btn btn-blanco btn-sm" href="{{ route('usuario-lista') }}">Limpiar</a>
    @endif
  </div>
</form>

@if ($hayFiltros)
<p class="filtros-nota">
  {{ $perfiles->count() }} {{ $perfiles->count() == 1 ? 'usuario' : 'usuarios' }}
  @if ($seleccion['area'] || $seleccion['promotoria'] || $seleccion['grupo'])
    · matrículas de <strong>{{ $seleccion['periodo']?->nombre ?: 'ningún periodo' }}</strong>,
    sin contar las retiradas
  @endif
</p>
@endif

@if ($perfiles->isEmpty())
  @if ($hayFiltros)
    <p class="vacio">Ningún usuario coincide con estos filtros.</p>
  @else
    <p class="vacio">Todavía no hay usuarios.</p>
  @endif
@else
<table>
  <thead>
    <tr>
      <th>Nombre</th>
      <th>Usuario</th>
      <th>Rol</th>
      <th>Promotorías</th>
      <th>Estado</th>
      <th></th>
    </tr>
  </thead>
  <tbody>
    @foreach ($perfiles as $perfil)
    <tr>
      <td>
        @if (\App\Support\Permisos::puedeVerFicha($yo, $perfil))
          <a href="{{ route('detalle-usuario', $perfil) }}">{{ $perfil->nombre_completo }}</a>
        @else
          {{ $perfil->nombre_completo }}
        @endif
      </td>
      <td>{{ $perfil->user->username }}</td>
      <td>
        @if ($perfil->rol)
          {{ $perfil->rol_display }}
        @else
          <span class="estado estado-pendiente">Pendiente de rol</span>
        @endif
      </td>
      {{--
        Una misma columna para dos vínculos distintos: quien DICTA la promotoría
        y quien está MATRICULADO en ella. Se lee igual en la lista —«a qué anda
        vinculada esta persona»— y separarlas en dos columnas dejaría cada una
        medio vacía, porque ninguna persona tiene las dos.
      --}}
      <td>
        @php($dictadas = $perfil->promotoriasDictadas)
        @php($matriculas = $matriculasPorPerfil[$perfil->id] ?? collect())
        @if ($dictadas->isNotEmpty())
          @foreach ($dictadas as $p)
            <span class="tag-dot {{ $p->area->tag_color }}"></span>{{ $p->nombre }}@if (! $loop->last)<br>@endif
          @endforeach
        @elseif ($matriculas->isNotEmpty())
          @foreach ($matriculas as $m)
            <span class="tag-dot {{ $m->promotoria->area->tag_color }}"></span>{{ $m->promotoria->nombre }}@if (! $loop->last)<br>@endif
          @endforeach
        @else
          <span class="vacio">—</span>
        @endif
      </td>
      <td>
        @if ($perfil->user->activo)
          <span class="estado estado-activa">Activo</span>
        @else
          <span class="estado estado-retirada">Inactivo</span>
        @endif
      </td>
      {{--
        La cuenta de un administrador solo la toca otro administrador. Se
        precalcula porque la usan las dos acciones de la fila.

        Va en la forma de UNA LÍNEA, con la asignación entre paréntesis, y no en
        la de bloque abierto y cerrado. Este archivo ya usa la de una línea más
        arriba, y Blade extrae los bloques de PHP crudo con una expresión regular
        perezosa ANTES de compilar ninguna directiva. Con las dos formas
        mezcladas en el mismo archivo, esa expresión abre en la primera de una
        línea y cierra en el cierre del bloque, se traga las veinticinco líneas
        que haya en medio y las deja sin compilar: el `if`, el `foreach` y el
        resto salen impresos como texto.

        Cuidado también con lo que se escribe AQUÍ DENTRO: un comentario que
        nombre esas dos directivas literalmente dispara la misma extracción y se
        acaba pintando el comentario entero en pantalla. Por eso este las
        describe en vez de escribirlas. Es prima hermana de la trampa de una
        directiva pegada a una letra.
      --}}
      @php($puedeTocarla = \App\Support\Permisos::puedeEditarUsuario($yo, $perfil))
      <td style="text-align:right;white-space:nowrap;">
        <span class="accion-fila">
        @if ($puedeTocarla)
        <a href="{{ route('usuario-editar', $perfil) }}">Editar</a>
        @endif
        @if ($puedeTocarla && $perfil->user_id !== auth()->id())
        <form action="{{ route('usuario-alternar-activo', $perfil) }}" method="post" style="display:inline;">
          @csrf
          @if ($perfil->user->activo)
            <button type="submit" class="btn btn-retirar btn-sm">Desactivar</button>
          @else
            <button type="submit" class="btn btn-sm">Activar</button>
          @endif
        </form>
        @endif
        </span>
      </td>
    </tr>
    @endforeach
  </tbody>
</table>
@endif
@endsection
