{{--
  La ventana de matrículas del periodo en curso. Vive en la portada de
  Gestión (ver `gestion.inicio`) y no en pantalla aparte.

  Recibe lo que arma `MatriculasController::datos()`: $periodo, $periodos.
  ($resumen lo pinta `gestion.partials.resumen-periodo`, arriba del todo.)
--}}
<div class="card">
  <div class="periodo-columnas">
    {{-- Izquierda: elegir cuál está en curso, y el catálogo de periodos. --}}
    <div>
      <div class="fila-titulo-accion">
        <h3>Periodos</h3>
        <button type="button" class="btn btn-sm" data-abre-modal="modal-periodo-nuevo">Nuevo</button>
      </div>
      <p class="campo-info">
        Selecciona el periodo que quieres Poner en curso, Editar o Eliminar.
      </p>

      @if (count($periodos))
      <form method="post" action="{{ route('gestion-matriculas') }}" class="periodo-selector">
        @csrf
        <input type="hidden" name="accion" value="poner_en_curso">
        <label class="sr-solo" for="id_periodo_en_curso">Periodo en curso</label>
        <select name="periodo_id" id="id_periodo_en_curso">
          @foreach ($periodos as $p)
            <option value="{{ $p->id }}" @selected($p->activo)>{{ $p->nombre }}@if ($p->activo) — en curso @endif</option>
          @endforeach
        </select>

        <p class="periodo-acciones">
          <button type="submit" class="btn btn-sm">Poner en curso</button>
          <button type="button" class="btn btn-blanco btn-sm" data-abre-modal="modal-periodo-editar">Editar</button>
          <button type="button" class="btn btn-blanco btn-sm" data-abre-modal="modal-periodo-eliminar">Eliminar</button>
        </p>
      </form>
      @else
        <p class="vacio">Todavía no hay periodos creados. Créalos con «Nuevo» arriba.</p>
      @endif
    </div>

    {{--
      Derecha: el RESULTADO de haber elegido a la izquierda cuál periodo está
      en curso — por eso el borde que las separa y no un `<h3>` propio.
    --}}
    <div class="periodo-resultado">
      @if (! $periodo)
        <p class="vacio" style="margin:0;">
          Ningún periodo está en curso ahora mismo. Elige uno a la izquierda antes de abrir matrículas.
        </p>
      @else
        <div class="periodo-resultado-cabecera">
          <h4>{{ $periodo->nombre }}</h4>
          @if ($periodo->matriculas_abiertas)
            <span class="estado estado-activa">Matrículas abiertas</span>
          @else
            <span class="estado estado-cerrada">Matrículas cerradas</span>
          @endif
        </div>
        <p class="campo-info periodo-resultado-fechas" style="margin-top:0;">
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
            <button type="submit" class="btn">Finalizar matrículas de {{ $periodo->nombre }}</button>
          @else
            <input type="hidden" name="accion" value="abrir">
            <button type="submit" class="btn">Iniciar matrículas de {{ $periodo->nombre }}</button>
          @endif
        </form>
      @endif
    </div>
  </div>

  <p class="periodo-acciones" style="margin-top:1.2rem;padding-top:1.1rem;border-top:1px solid var(--border);align-items:center;justify-content:space-between;">
    <a class="btn" href="{{ route('gestion-cupos') }}">Cupos por promotoría</a>
    <span class="campo-info" style="margin:0;">Modifica los cupos máximos permitidos para cada promotoría.</span>
  </p>
</div>
