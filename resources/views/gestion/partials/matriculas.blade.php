{{--
  La ventana de matrículas del periodo en curso. Vive en la portada de
  Gestión (ver `gestion.inicio`) y no en pantalla aparte.

  Recibe lo que arma `MatriculasController::datos()`: $periodo, $resumen,
  $periodos.
--}}
<div class="card">
  <h3>Periodos</h3>
  <p class="campo-info">
    Solo puede haber uno. Al cambiarlo, el anterior deja de estar en curso y sus matrículas
    se cierran automáticamente; sus datos no se tocan.
  </p>
  @if (count($periodos))
  <form method="post" action="{{ route('gestion-matriculas') }}" class="cupo-form" style="margin-left:0;">
    @csrf
    <input type="hidden" name="accion" value="poner_en_curso">
    <label class="sr-solo" for="id_periodo_en_curso">Periodo en curso</label>
    <select name="periodo_id" id="id_periodo_en_curso" style="width:auto;">
      @foreach ($periodos as $p)
        <option value="{{ $p->id }}" @selected($p->activo)>{{ $p->nombre }}@if ($p->activo) — en curso @endif</option>
      @endforeach
    </select>
    <button type="submit" class="btn btn-sm">Poner en curso</button>
  </form>
  @else
    <p class="vacio">Todavía no hay periodos creados. Créalos con «+ Nuevo» abajo.</p>
  @endif

  <p class="periodo-acciones">
    <button type="button" class="btn btn-sm" data-abre-modal="modal-periodo-nuevo">+ Nuevo</button>
    @if (count($periodos))
      <button type="button" class="btn btn-blanco btn-sm" data-abre-modal="modal-periodo-editar">Editar</button>
      <button type="button" class="btn btn-blanco btn-sm" data-abre-modal="modal-periodo-eliminar">Eliminar</button>
    @endif
  </p>

  {{--
    Lo que sigue es el RESULTADO de elegir arriba cuál periodo está en curso,
    no una tarjeta aparte: por eso el separador y no un `<h3>` propio —el
    título de la tarjeta sigue siendo "Periodos".
  --}}
  <div class="periodo-resultado">
    @if (! $periodo)
      <p class="vacio" style="margin:0;">
        Ningún periodo está en curso ahora mismo. Elige uno arriba antes de abrir matrículas.
      </p>
    @else
      <div class="periodo-resultado-cabecera">
        <h4>{{ $periodo->nombre }}</h4>
        @if ($periodo->matriculas_abiertas)
          <span class="estado estado-activa">Matrículas abiertas</span>
        @else
          <span class="estado estado-pendiente">Matrículas cerradas</span>
        @endif
      </div>
      <p class="campo-info" style="margin-top:0;">
        Del {{ $periodo->fecha_inicio->format('d/m/Y') }} al {{ $periodo->fecha_fin->format('d/m/Y') }}
      </p>
      <p class="campo-info" style="margin-top:-0.6rem;">
        @if ($periodo->matriculas_abiertas)
          Ahora mismo los estudiantes nuevos pueden inscribirse y los antiguos renovar.
        @else
          Nadie puede inscribirse ni renovar. Las matrículas ya registradas siguen intactas.
        @endif
      </p>

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
    @endif
  </div>
</div>

@if ($periodo && $resumen)
<div class="card">
  <h3>Cómo va {{ $periodo->nombre }}</h3>
  <div class="perfil-stats">
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
    <p class="campo-info">
      «Antiguos sin renovar» son estudiantes que estuvieron activos en
      {{ $resumen['periodo_anterior']->nombre }} y todavía no aparecen en {{ $periodo->nombre }}.
    </p>
  @endif
</div>
@endif
