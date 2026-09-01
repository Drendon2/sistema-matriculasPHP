@extends('layouts.app')

@section('title', 'Usuarios')

@section('content')
<a href="{{ route('gestion-inicio') }}" class="volver">&larr; Gestión</a>
<h2>Usuarios</h2>
<p><a class="btn" href="{{ route('usuario-nuevo') }}">+ Nuevo usuario</a></p>

{{--
  El enlace de registro de profesor, que ya NO está en la pantalla de inicio de
  sesión.

  Estuvo ahí, debajo del de estudiante, y la gente se equivocaba de puerta: quien
  entraba por la de profesor quedaba sin rol, sin documento y sin matrícula.
  Ahora es un enlace que dirección manda a quien va a dictar, igual que el de una
  actividad; la ruta sigue abierta, lo que se quitó es el letrero público.

  Plegado y no a la vista: se usa el día que entra un profesor nuevo, no todos
  los días, y esta pantalla ya llega cargada de filtros en un celular.
--}}
<details class="perfil-seccion" style="max-width:none;margin:0 0 1.2rem;">
  <summary class="perfil-seccion-cabecera" style="margin:0;">
    <span class="perfil-seccion-icono icono-documento" aria-hidden="true">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <path d="M10 13a5 5 0 0 0 7.5.5l3-3a5 5 0 0 0-7-7l-1.7 1.7"/>
        <path d="M14 11a5 5 0 0 0-7.5-.5l-3 3a5 5 0 0 0 7 7l1.7-1.7"/>
      </svg>
    </span>
    <h3 style="margin:0;">El enlace para registrar a un profesor</h3>
    <svg class="perfil-seccion-chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6 6-6"/></svg>
  </summary>

  {{--
    El margen va escrito a mano porque `.campo-info` trae uno superior NEGATIVO
    —está pensada para ir pegada bajo un título, que aporta el suyo inferior—, y
    dentro de un `<details>` eso la sube encima del resumen. Es el mismo apaño
    que ya lleva la encuesta de Estadísticas, por lo mismo.
  --}}
  <p class="campo-info" style="margin:0.9rem 0 0.9rem;">
    Mándaselo a quien va a dictar. Crea su cuenta y elige su propia contraseña;
    queda <strong>pendiente de rol</strong> hasta que se lo asignes aquí.
  </p>
  <label class="sr-solo" for="enlace_registro">Enlace de registro de profesor</label>
  <div class="enlace-fila">
    <input class="enlace-copiable" type="text" id="enlace_registro"
           readonly value="{{ route('registro') }}">
  </div>
</details>

<form method="get" class="filtros">
  {{--
    El buscador va PRIMERO: es el camino directo a una persona concreta, y los
    otros cinco son filtros que acotan un conjunto. Buscar por nombre o usuario
    y nada mas — el documento y el telefono no se buscan a proposito, ver el
    comentario del controlador.
  --}}
  <div class="filtro filtro-buscar">
    <label for="f-buscar">Buscar</label>
    <input type="search" name="buscar" id="f-buscar"
           value="{{ $seleccion['buscar'] }}"
           placeholder="Nombre o usuario"
           autocomplete="off">
  </div>

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
            {{ $g->rotulo_breve }}
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
  {{ $perfiles->total() }} {{ $perfiles->total() == 1 ? 'usuario' : 'usuarios' }}
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
{{--
  `.tabla-personas` porque esto es una lista de REGISTROS de personas, que es
  justo su caso, y no una rejilla donde la posición de la celda sea el dato.

  Sin ella, a 390px la tabla mide 675 y se queda con `overflow-x`: había que
  arrastrarla 318px para llegar a «Editar» y «Desactivar», o sea que sus
  acciones estaban escondidas desde antes de que existiera «Eliminar». Se vio
  abriendo la página en un ancho de teléfono; ninguna prueba lo miraba.
--}}
<table class="tabla-personas">
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
    {{-- Una vez y no una por fila: el rol de quien mira no cambia a mitad de la tabla. --}}
    @php($soyAdministrador = $yo?->rol === 'administrador')
    @foreach ($perfiles as $perfil)
    <tr>
      <td data-celda="nombre">
        @if (\App\Support\Permisos::puedeVerFicha($yo, $perfil))
          <a href="{{ route('detalle-usuario', $perfil) }}">{{ $perfil->nombre_completo }}</a>
        @else
          {{ $perfil->nombre_completo }}
        @endif
      </td>
      <td data-label="Usuario">{{ $perfil->user->username }}</td>
      <td data-label="Rol">
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
      <td data-label="Promotorías">
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
      <td data-label="Estado">
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
      <td data-celda="accion" style="text-align:right;white-space:nowrap;">
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
        {{--
          «Eliminar» solo lo ve el administrador, y solo está VIVO cuando de
          verdad se puede borrar. Es la misma regla que la lista de catálogos:
          con el enlace rojo siempre puesto, la única forma de enterarse de que
          una cuenta está protegida era pulsarlo y que te lo negaran.

          El conteo que decide viene con la consulta de la página, no se
          pregunta aquí: `Dependencias::estaBloqueado` usa lo que `withCount()`
          ya trajo.

          Es un enlace y no un botón: lleva a la pantalla de confirmación, que es
          la que pide la contraseña. Nada de este listado borra nada.
        --}}
        @if ($soyAdministrador && $puedeTocarla && $perfil->user_id !== auth()->id())
          @if (\App\Support\Dependencias::estaBloqueado($perfil))
            <span class="campo-info" style="margin:0;display:inline;"
                  title="No se puede eliminar: tiene historial en el sistema. Desactívala en su lugar.">
              Eliminar
            </span>
          @else
            <a href="{{ route('usuario-eliminar', $perfil) }}" style="color:var(--danger);">Eliminar</a>
          @endif
        @endif
        </span>
      </td>
    </tr>
    @endforeach
  </tbody>
</table>
{{ $perfiles->links() }}
@endif
@endsection

@push('scripts')
<script src="@recurso('js/copiar-enlace.js')" defer></script>
@endpush
