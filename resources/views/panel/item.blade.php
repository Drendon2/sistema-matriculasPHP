{{--
  El cuerpo de una promotoría dentro del Panel. Va aparte de `index` porque se
  repite una vez por promotoría y porque es lo que se repinta sin recargar.

  Ojo con los `@include` aquí dentro: esto es un bucle caliente. El Panel de un
  director con trescientos estudiantes pinta ~500 filas, y cada include de Blade
  —que resuelve la vista, comprueba la caché contra disco y monta un ámbito
  nuevo— cuesta a esa escala unos 0,4 ms. Tres por fila eran más de un segundo
  de render. Por eso la foto y el acudiente van escritos en línea aquí aunque
  existan como parciales para las otras pantallas, donde se pintan una vez o dos
  y la claridad manda sobre el reloj.
--}}
@php($esAdministrador = $yo->rol === 'administrador')

<p class="campo-info" style="margin-top:-0.6rem;">Profesor:
  @if ($item['promotoria']->profesor)
    @if (\App\Support\Permisos::puedeVerFicha($yo, $item['promotoria']->profesor))
      <a href="{{ route('detalle-usuario', $item['promotoria']->profesor) }}">{{ $item['promotoria']->profesor->nombre_completo }}</a>
    @else
      {{ $item['promotoria']->profesor->nombre_completo }}
    @endif
  @else
    Sin asignar
  @endif
</p>

@if ($periodo)
<div class="cupo-fila">
  <span class="cupo-etiqueta">Cupo en {{ $periodo->nombre }}</span>
  @if ($item['cupo'] === null)
    <span class="cupo-cifra cupo-cifra-libre">Sin tope</span>
  @else
    <span class="cupo-cifra{{ $item['ocupados'] >= $item['cupo'] ? ' cupo-cifra-lleno' : '' }}">{{ $item['ocupados'] }} / {{ $item['cupo'] }}</span>
  @endif
  @if ($item['puede_gestionar'])
  <form action="{{ route('panel-cupo-promotoria', $item['promotoria']) }}" method="post" class="cupo-form">
    @csrf
    <label class="cupo-form-label" for="cupo-{{ $item['promotoria']->id }}">Cambiar a</label>
    <input type="number" id="cupo-{{ $item['promotoria']->id }}" name="cupo_maximo" min="0" step="1"
           value="{{ $item['cupo'] }}" placeholder="sin tope">
    <button type="submit" class="btn btn-sm">Guardar</button>
  </form>
  @endif
</div>
@endif

@if ($item['puede_gestionar'])
<p><a class="btn btn-sm" href="{{ route('panel-grupo-nuevo', $item['promotoria']) }}">+ Nuevo grupo</a></p>
@endif

@php($totalPendientes = count($item['pendientes']))
<h4>Pendientes de confirmación{{ $totalPendientes ? " ({$totalPendientes})" : '' }}</h4>
@if (! $item['pendientes'])
  <p class="vacio">No hay solicitudes pendientes.</p>
@else
{{--
  Resolver en bloque. Al abrir matrículas llegan veinte solicitudes juntas y casi
  todas se responden igual; de a una son veinte idas y vueltas para una decisión
  ya tomada.

  Los dos botones van con el mismo peso visual que en la fila: confirmar en verde
  de acción y rechazar en rojo de retirada, sin que ninguno sea el «recomendado».

  El formulario va FUERA de la tabla y las casillas lo alcanzan con `form`, igual
  que en el reparto por grupo: cada fila ya tiene los suyos para responder a una
  sola, y anidar formularios es HTML inválido.
--}}
@php($lotePendientes = 'lote-pendientes-' . $item['promotoria']->id)
@if ($item['puede_gestionar'] && count($item['pendientes']) > 1)
<form action="{{ route('panel-pendientes-lote', $item['promotoria']) }}" method="post"
      id="{{ $lotePendientes }}" class="lote-barra">
  @csrf
  <span class="lote-cuenta" data-lote-cuenta>Ninguno marcado</span>
  <button type="submit" name="decision" value="confirmar" class="btn btn-sm" data-lote-enviar disabled>
    Confirmar marcados
  </button>
  <button type="submit" name="decision" value="rechazar" class="btn btn-retirar btn-sm" data-lote-enviar disabled>
    Rechazar marcados
  </button>
</form>
@endif

<table @if ($item['puede_gestionar'] && count($item['pendientes']) > 1) data-lote-tabla="{{ $lotePendientes }}" @endif>
  <thead>
    <tr>
      @if ($item['puede_gestionar'] && count($item['pendientes']) > 1)
      <th style="width:1%;">
        <input type="checkbox" data-lote-todos aria-label="Marcar todas las solicitudes pendientes">
      </th>
      @endif
      <th></th><th>Nombre</th><th>Trayectoria</th><th>Edad</th><th>Teléfono</th><th>Acudiente</th>
      @if ($item['puede_gestionar'])<th></th>@endif
      @if ($esAdministrador)<th></th>@endif
    </tr>
  </thead>
  <tbody>
    @foreach ($item['pendientes'] as $e)
    <tr>
      @if ($item['puede_gestionar'] && count($item['pendientes']) > 1)
      <td>
        <input type="checkbox" name="matricula_ids[]" value="{{ $e['matricula']->id }}"
               form="{{ $lotePendientes }}" data-lote-fila
               aria-label="Marcar la solicitud de {{ $e['perfil']->nombre_completo }}">
      </td>
      @endif
      <td>@if ($e['perfil']->foto_perfil)<img class="foto-mini" src="{{ route('ver-foto', $e['perfil']) }}" alt="">@endif</td>
      <td>@include('panel.nombre', ['e' => $e])</td>
      <td>
        @if ($e['renovacion'])
          <span class="trayectoria trayectoria-renovacion">Renovación</span>
        @else
          <span class="trayectoria trayectoria-nuevo">Nuevo aquí</span>
        @endif
      </td>
      <td>{{ $e['perfil']->edad }}</td>
      <td>{{ $e['perfil']->telefono }}</td>
      <td>@if ($e['acudiente']){{ $e['acudiente']->nombre }} ({{ $e['acudiente']->telefono }})@else<span class="vacio">—</span>@endif</td>
      @if ($item['puede_gestionar'])
      <td>
        <span class="accion-fila">
        <form action="{{ route('panel-confirmar-matricula', $e['matricula']) }}" method="post" style="display:inline;">
          @csrf
          <button type="submit" class="btn btn-sm">Confirmar</button>
        </form>
        <form action="{{ route('panel-rechazar-matricula', $e['matricula']) }}" method="post" style="display:inline;">
          @csrf
          <button type="submit" class="btn btn-retirar btn-sm">Rechazar</button>
        </form>
        </span>
      </td>
      @endif
      @if ($esAdministrador)
      <td><a href="{{ route('detalle-estudiante', $e['perfil']) }}">Ver detalle</a></td>
      @endif
    </tr>
    @endforeach
  </tbody>
</table>
@endif

@foreach ($item['grupos'] as $g)
  <div class="grupo-cabecera">
    <h4 style="margin:1.2rem 0 0.4rem;">
      {{ $g['grupo']->nivel_display }} · {{ $g['grupo']->horario }} · Salón {{ $g['grupo']->salon }}
      ({{ count($g['estudiantes']) }}/{{ $g['grupo']->cupo_maximo }})
    </h4>
    @if ($item['puede_gestionar'])
    <span class="acciones">
      @if ($item['puede_marcar'])
      <form action="{{ route('panel-clase-nueva', $g['grupo']) }}" method="post" style="display:inline;">
        @csrf
        @if ($g['clase_hoy'])
          <button type="submit" class="btn btn-secundario btn-sm">Seguir la lista de hoy</button>
        @else
          <button type="submit" class="btn btn-sm">Iniciar clase</button>
        @endif
      </form>
      @endif
      <a href="{{ route('grupo-clases', $g['grupo']) }}">clases</a>
      <a href="{{ route('panel-grupo-editar', $g['grupo']) }}">editar</a>
      <form action="{{ route('panel-grupo-eliminar', $g['grupo']) }}" method="post" style="display:inline;">
        @csrf
        <button type="submit" class="btn-texto">eliminar</button>
      </form>
    </span>
    @endif
  </div>
  @if (! $g['estudiantes'])
    <p class="vacio">Sin estudiantes en este grupo.</p>
  @else
  <table>
    <thead>
      <tr>
        <th></th><th>Nombre</th><th>Edad</th><th>Teléfono</th><th>Acudiente</th>
        @if ($item['puede_gestionar'])<th></th>@endif
        @if ($esAdministrador)<th></th>@endif
      </tr>
    </thead>
    <tbody>
      @foreach ($g['estudiantes'] as $e)
      <tr>
        <td>@if ($e['perfil']->foto_perfil)<img class="foto-mini" src="{{ route('ver-foto', $e['perfil']) }}" alt="">@endif</td>
        <td>@include('panel.nombre', ['e' => $e])</td>
        <td>{{ $e['perfil']->edad }}</td>
        <td>{{ $e['perfil']->telefono }}</td>
        <td>@if ($e['acudiente']){{ $e['acudiente']->nombre }} ({{ $e['acudiente']->telefono }})@else<span class="vacio">—</span>@endif</td>
        @if ($item['puede_gestionar'])
        <td>
          <form action="{{ route('panel-asignar-grupo', $e['matricula']) }}" method="post">
            @csrf
            <input type="hidden" name="grupo_id" value="">
            <button type="submit" class="btn btn-retirar btn-sm">Quitar del grupo</button>
          </form>
        </td>
        @endif
        @if ($esAdministrador)
        <td><a href="{{ route('detalle-estudiante', $e['perfil']) }}">Ver detalle</a></td>
        @endif
      </tr>
      @endforeach
    </tbody>
  </table>
  @endif
@endforeach

<h4>Sin grupo asignado</h4>
@if (! $item['sin_grupo'])
  <p class="vacio">Todos los matriculados ya tienen grupo.</p>
@else

@php($conLote = $item['puede_gestionar'] && $item['grupos'])
@if ($conLote)
{{--
  Repartir es la tarea del principio de periodo y casi todos van al mismo
  horario: de a uno son veinte idas y vueltas para una decisión ya tomada.

  El formulario va FUERA de la tabla y las casillas lo alcanzan con el atributo
  `form`. Es lo que evita anidar formularios —cada fila ya tiene el suyo para
  mandar a uno solo—, que es HTML inválido y el navegador lo desarma a su gusto.
--}}
<form action="{{ route('panel-asignar-grupo-lote', $item['promotoria']) }}" method="post"
      id="lote-{{ $item['promotoria']->id }}" class="lote-barra" data-lote>
  @csrf
  <span class="lote-cuenta" data-lote-cuenta>Ninguno marcado</span>
  <select name="grupo_id" style="width:auto;" required>
    <option value="">-- elegir grupo --</option>
    @foreach ($item['grupos'] as $g)
      <option value="{{ $g['grupo']->id }}">{{ $g['grupo']->nivel_display }} · {{ $g['grupo']->horario }} ({{ count($g['estudiantes']) }}/{{ $g['grupo']->cupo_maximo }})</option>
    @endforeach
  </select>
  <button type="submit" class="btn btn-sm" data-lote-enviar disabled>Asignar marcados</button>
</form>
@endif

<table data-lote-tabla="lote-{{ $item['promotoria']->id }}">
  <thead>
    <tr>
      @if ($conLote)
      <th style="width:1%;">
        <input type="checkbox" data-lote-todos aria-label="Marcar a todos los que no tienen grupo">
      </th>
      @endif
      <th></th><th>Nombre</th><th>Edad</th><th>Teléfono</th><th>Acudiente</th>
      @if ($item['puede_gestionar'])<th>Asignar a</th>@endif
      @if ($esAdministrador)<th></th>@endif
    </tr>
  </thead>
  <tbody>
    @foreach ($item['sin_grupo'] as $e)
    <tr>
      @if ($conLote)
      <td>
        <input type="checkbox" name="matricula_ids[]" value="{{ $e['matricula']->id }}"
               form="lote-{{ $item['promotoria']->id }}" data-lote-fila
               aria-label="Marcar a {{ $e['perfil']->nombre_completo }}">
      </td>
      @endif
      <td>@if ($e['perfil']->foto_perfil)<img class="foto-mini" src="{{ route('ver-foto', $e['perfil']) }}" alt="">@endif</td>
      <td>@include('panel.nombre', ['e' => $e])</td>
      <td>{{ $e['perfil']->edad }}</td>
      <td>{{ $e['perfil']->telefono }}</td>
      <td>@if ($e['acudiente']){{ $e['acudiente']->nombre }} ({{ $e['acudiente']->telefono }})@else<span class="vacio">—</span>@endif</td>
      @if ($item['puede_gestionar'])
      <td>
        @if ($item['grupos'])
        <form action="{{ route('panel-asignar-grupo', $e['matricula']) }}" method="post" style="display:flex;gap:0.4rem;">
          @csrf
          <select name="grupo_id" style="width:auto;">
            <option value="">-- elegir grupo --</option>
            @foreach ($item['grupos'] as $g)
              <option value="{{ $g['grupo']->id }}">{{ $g['grupo']->nivel_display }} · {{ $g['grupo']->horario }} ({{ count($g['estudiantes']) }}/{{ $g['grupo']->cupo_maximo }})</option>
            @endforeach
          </select>
          <button type="submit" class="btn btn-sm">Asignar</button>
        </form>
        @else
          <span class="vacio">Crea un grupo primero</span>
        @endif
      </td>
      @endif
      @if ($esAdministrador)
      <td><a href="{{ route('detalle-estudiante', $e['perfil']) }}">Ver detalle</a></td>
      @endif
    </tr>
    @endforeach
  </tbody>
</table>
@endif
