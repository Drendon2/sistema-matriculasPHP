@extends('layouts.app')

@section('title', 'Matrículas')

@section('content')
{{--
  Todo lo que pasa con un periodo, en su orden real: primero el que está en
  curso y su ventana, luego cómo va, y al final la lista de periodos.

  Los periodos van ABAJO y no arriba a propósito. Lo que se viene a hacer aquí
  casi siempre es abrir o cerrar la ventana del periodo en curso; crear uno
  nuevo pasa dos veces al año. Poner la lista primero obligaría a pasar por
  encima de ella cada vez para llegar a lo de siempre.

  Directivas PHP en la forma de UNA LINEA. Mezclarla con la de bloque en el
  mismo archivo deja sin compilar todo lo que quede en medio.
--}}
<a href="{{ route('gestion-inicio') }}" class="volver">&larr; Gestión</a>
<h2>Matrículas</h2>

@if (! $periodo)
  <p class="vacio">
    Ningún periodo está en curso ahora mismo. Elige cuál abajo antes de abrir
    matrículas.
  </p>
@else

<div class="card">
  <h3>{{ $periodo->nombre }}</h3>
  <p class="campo-info">
    Del {{ $periodo->fecha_inicio->format('d/m/Y') }} al {{ $periodo->fecha_fin->format('d/m/Y') }}
  </p>

  <div class="ventana-estado">
    @if ($periodo->matriculas_abiertas)
      <span class="estado estado-activa">Matrículas abiertas</span>
      <p>Ahora mismo los estudiantes nuevos pueden inscribirse y los antiguos renovar.</p>
    @else
      <span class="estado estado-pendiente">Matrículas cerradas</span>
      <p>Nadie puede inscribirse ni renovar. Las matrículas ya registradas siguen intactas.</p>
    @endif
  </div>

  <form method="post" action="{{ route('gestion-matriculas') }}">
    @csrf
    @if ($periodo->matriculas_abiertas)
      <input type="hidden" name="accion" value="cerrar">
      <button type="submit" class="btn btn-retirar">Finalizar matrículas de {{ $periodo->nombre }}</button>
    @else
      <input type="hidden" name="accion" value="abrir">
      <button type="submit" class="btn">Iniciar matrículas de {{ $periodo->nombre }}</button>
    @endif
  </form>

  {{--
    Los cupos siguen en su pantalla —son un formulario con una fila por
    promotoría— pero se llega desde aquí, que es cuando se reparten: la propia
    pantalla de cupos dice «aquí se reparten los cupos al abrir matrículas».
    Antes era una ficha suelta en la portada, lejos del momento en que se usa.
  --}}
  <p class="matriculas-cupos">
    <a href="{{ route('gestion-cupos-periodo', $periodo) }}">
      Repartir los cupos de {{ $periodo->nombre }} &rarr;
    </a>
  </p>
</div>

@if ($resumen)
<div class="card">
  <h3>Cómo va {{ $periodo->nombre }}</h3>
  {{-- Alineadas a la izquierda: `.perfil-stats` centra, que es lo correcto en
       la tarjeta estrecha del perfil y las deja flotando en mitad de una
       tarjeta de ancho completo, sin alinearse con el titulo de arriba. --}}
  <div class="perfil-stats perfil-stats-fila">
    <div>
      <span class="perfil-stat-num">{{ $resumen['estudiantes'] }}</span>
      <span class="perfil-stat-label">Estudiantes</span>
    </div>
    <div>
      <span class="perfil-stat-num">{{ $resumen['activas'] }}</span>
      <span class="perfil-stat-label">Matrículas activas</span>
    </div>
    <div>
      <span class="perfil-stat-num">{{ $resumen['pendientes'] }}</span>
      <span class="perfil-stat-label">Por confirmar</span>
    </div>
    @if ($resumen['periodo_anterior'])
    <div>
      <span class="perfil-stat-num">{{ $resumen['por_renovar'] }}</span>
      <span class="perfil-stat-label">Antiguos sin renovar</span>
    </div>
    @endif
  </div>
  @if ($resumen['periodo_anterior'])
    {{-- `.campo-ayuda` y no `.campo-info`: esta va debajo del bloque de cifras,
         no de un título, y el margen negativo de la otra la montaba encima de
         «Antiguos sin renovar» — medido, 10px de solape. --}}
    <p class="campo-ayuda" style="margin-top:0.9rem;">
      «Antiguos sin renovar» son estudiantes que estuvieron activos en
      {{ $resumen['periodo_anterior']->nombre }} y todavía no aparecen en {{ $periodo->nombre }}.
    </p>
  @endif
</div>
@endif

@endif

{{--
  LOS PERIODOS.

  Era una ficha aparte en la portada de Gestión y una pantalla de catálogo
  propia. Aquí gana algo que allí no tenía: «Poner en curso» es una acción de
  FILA, así que se ve la fecha de lo que se está eligiendo. Antes era un
  desplegable con nombres sueltos —«2026-2»— y había que acordarse de cuándo
  empezaba ese.

  `.tabla-personas` y `.tabla-catalogo` como el resto de listas de registros:
  bajo 640px cada fila pasa a ficha y las acciones caen al final a ancho
  completo. Ver DESIGN.md, la Regla de la Tabla que Deja de Serlo.
--}}
<div class="programa-cabecera" style="margin-top:2.2rem;">
  <h3>Periodos</h3>
  <a class="btn btn-blanco btn-sm" href="{{ route('periodo-nuevo') }}" data-modal>+ Nuevo</a>
</div>
<p class="campo-ayuda">
  Solo uno puede estar en curso. Al cambiarlo, el anterior deja de estarlo y sus
  matrículas se cierran en la misma operación; sus datos no se tocan.
</p>

@if ($periodos->isEmpty())
  <p class="vacio">Todavía no hay periodos. Crea el primero para poder abrir matrículas.</p>
@else
<table class="tabla-personas tabla-catalogo">
  <tbody>
    @foreach ($periodos as $p)
    @php($protegido = $p->matriculas_count + $p->clases_count)
    <tr>
      <td data-celda="detalle">
        <span class="lista-nombre">{{ $p->nombre }}</span>
        @if ($p->activo)
          <span class="estado estado-activa">En curso</span>
        @endif
        <span class="lista-nota lista-nota-bloque">
          Del {{ $p->fecha_inicio->format('d/m/Y') }} al {{ $p->fecha_fin->format('d/m/Y') }}
          @if ($protegido)
            · {{ $protegido }} {{ $protegido == 1 ? 'registro' : 'registros' }} en historial
          @endif
        </span>
      </td>
      <td data-celda="accion" class="lista-acciones">
        <span class="accion-fila">
        @if (! $p->activo)
        {{--
          Un formulario y no un enlace: pone en curso este periodo y cierra las
          matrículas del anterior en la misma transacción, o sea que cambia
          datos. `acciones.js` lo envía sin recargar como al resto.
        --}}
        <form method="post" action="{{ route('gestion-matriculas') }}">
          @csrf
          <input type="hidden" name="accion" value="poner_en_curso">
          <input type="hidden" name="periodo_id" value="{{ $p->id }}">
          <button type="submit" class="btn btn-blanco btn-sm">Poner en curso</button>
        </form>
        @endif
        <a href="{{ route('periodo-editar', $p) }}" data-modal>Editar</a>
        <span class="accion-sep">&nbsp;·&nbsp;</span>
        @if ($protegido)
          <span class="accion-inerte"
                title="No se puede eliminar: tiene {{ $protegido }} {{ $protegido == 1 ? 'registro' : 'registros' }} en su historial.">
            Eliminar
          </span>
        @else
          <a href="{{ route('periodo-eliminar', $p) }}" style="color:var(--danger);" data-modal>Eliminar</a>
        @endif
        </span>
      </td>
    </tr>
    @endforeach
  </tbody>
</table>
@endif
@endsection
