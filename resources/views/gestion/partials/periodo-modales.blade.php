{{--
  Los tres modales de la tarjeta "Periodo en curso": crear, editar, eliminar.
  Reciben lo mismo que esa tarjeta ($periodos, ver `MatriculasController::datos()`).

  Cada formulario lleva un campo oculto `_origen` para saber, si la validación
  falla, cuál de los tres modales tiene que reabrirse: `acciones.js` intercepta
  el envío y, al fallar `$request->validate()`, Laravel redirige de vuelta a
  esta misma página con `$errors`/`old()` en sesión — pero un render nuevo
  siempre trae los <dialog> cerrados. El <script> del final reabre el que
  corresponda; se ejecuta porque `acciones.js` ya recrea cualquier <script>
  que venga dentro del <main> reemplazado.
--}}
@php($origenNuevo = old('_origen') === 'periodo-nuevo')
@php($origenEditar = old('_origen') === 'periodo-editar')
@php($periodoEnError = $origenEditar ? $periodos->firstWhere('id', (int) old('periodo_id')) : null)
{{-- Sin periodos, Editar/Eliminar ni se ofrecen (ver la tarjeta); esto solo
     evita que `route('periodo-editar', null)` reviente al pintar el modal. --}}
@php($periodoPorDefecto = $periodoEnError ?? $periodos->first())

<dialog id="modal-periodo-nuevo" class="modal">
  <h2>Nuevo periodo</h2>
  <form method="post" action="{{ route('periodo-nuevo') }}">
    @csrf
    <input type="hidden" name="_origen" value="periodo-nuevo">

    <div class="field">
      <label for="np-nombre">Nombre</label>
      <input type="text" name="nombre" id="np-nombre" maxlength="20" required
             value="{{ $origenNuevo ? old('nombre') : '' }}">
      <span class="campo-info" style="margin:0;">Por ejemplo, 2026-1</span>
      @if ($origenNuevo)
        @error('nombre')<div class="errorlist" style="color:var(--danger);font-size:0.82rem;">{{ $message }}</div>@enderror
      @endif
    </div>
    <div class="field">
      <label for="np-inicio">Fecha de inicio</label>
      <input type="date" name="fecha_inicio" id="np-inicio" required
             value="{{ $origenNuevo ? old('fecha_inicio') : '' }}">
      @if ($origenNuevo)
        @error('fecha_inicio')<div class="errorlist" style="color:var(--danger);font-size:0.82rem;">{{ $message }}</div>@enderror
      @endif
    </div>
    <div class="field">
      <label for="np-fin">Fecha de fin</label>
      <input type="date" name="fecha_fin" id="np-fin" required
             value="{{ $origenNuevo ? old('fecha_fin') : '' }}">
      @if ($origenNuevo)
        @error('fecha_fin')<div class="errorlist" style="color:var(--danger);font-size:0.82rem;">{{ $message }}</div>@enderror
      @endif
    </div>

    <div class="modal-acciones">
      <button type="submit" class="btn">Guardar</button>
      <button type="button" class="btn btn-secundario" data-cierra-modal>Cancelar</button>
    </div>
  </form>
</dialog>

<dialog id="modal-periodo-editar" class="modal">
  <h2>Editar periodo</h2>
  <form method="post"
        action="{{ $periodoPorDefecto ? route('periodo-editar', $periodoPorDefecto) : '' }}"
        data-form-periodo-editar>
    @csrf
    <input type="hidden" name="_origen" value="periodo-editar">
    <input type="hidden" name="periodo_id" value="{{ $periodoEnError?->id ?? '' }}" data-campo-periodo-id>

    <div class="field">
      <label for="pe-select">Periodo</label>
      <select id="pe-select" data-select-periodo-editar>
        <option value="">Selecciona un periodo…</option>
        @foreach ($periodos as $p)
          <option value="{{ $p->id }}"
                  data-editar-url="{{ route('periodo-editar', $p) }}"
                  data-nombre="{{ $p->nombre }}"
                  data-fecha-inicio="{{ $p->fecha_inicio->toDateString() }}"
                  data-fecha-fin="{{ $p->fecha_fin->toDateString() }}"
                  @selected($periodoEnError && $periodoEnError->id === $p->id)>
            {{ $p->nombre }}
          </option>
        @endforeach
      </select>
    </div>

    <div data-campos-periodo-editar @if (! $origenEditar) hidden @endif>
      <div class="field">
        <label for="pe-nombre">Nombre</label>
        <input type="text" name="nombre" id="pe-nombre" maxlength="20" required
               value="{{ $origenEditar ? old('nombre') : ($periodoEnError?->nombre ?? '') }}">
        @error('nombre')<div class="errorlist" style="color:var(--danger);font-size:0.82rem;">{{ $message }}</div>@enderror
      </div>
      <div class="field">
        <label for="pe-inicio">Fecha de inicio</label>
        <input type="date" name="fecha_inicio" id="pe-inicio" required
               value="{{ $origenEditar ? old('fecha_inicio') : ($periodoEnError?->fecha_inicio?->toDateString() ?? '') }}">
        @error('fecha_inicio')<div class="errorlist" style="color:var(--danger);font-size:0.82rem;">{{ $message }}</div>@enderror
      </div>
      <div class="field">
        <label for="pe-fin">Fecha de fin</label>
        <input type="date" name="fecha_fin" id="pe-fin" required
               value="{{ $origenEditar ? old('fecha_fin') : ($periodoEnError?->fecha_fin?->toDateString() ?? '') }}">
        @error('fecha_fin')<div class="errorlist" style="color:var(--danger);font-size:0.82rem;">{{ $message }}</div>@enderror
      </div>

      <div class="modal-acciones">
        <button type="submit" class="btn">Aplicar</button>
      </div>
    </div>

    {{-- Fuera de `data-campos-periodo-editar`: tiene que poder salirse del
         modal aunque todavía no haya elegido un periodo. --}}
    <div class="modal-acciones">
      <button type="button" class="btn btn-secundario" data-cierra-modal>Cancelar</button>
    </div>
  </form>
</dialog>

<dialog id="modal-periodo-eliminar" class="modal">
  <h2>Eliminar periodo</h2>
  <form method="post" data-form-periodo-eliminar>
    <div data-seccion-elegir>
      <div class="field">
        <label for="pel-select">Periodo</label>
        <select id="pel-select" data-select-periodo-eliminar>
          <option value="">Selecciona un periodo…</option>
          @foreach ($periodos as $p)
            @php($dep = \App\Support\Dependencias::de($p))
            <option value="{{ $p->id }}"
                    data-eliminar-url="{{ route('periodo-eliminar', $p) }}"
                    data-bloqueos="{{ $dep['bloqueos'] }}"
                    data-arrastre="{{ $dep['arrastre'] }}">
              {{ $p->nombre }}
            </option>
          @endforeach
        </select>
      </div>

      <p class="campo-info" data-indicador-seleccion hidden></p>

      <div class="modal-acciones">
        <button type="button" class="btn btn-retirar" data-pedir-confirmacion disabled>Eliminar</button>
        <button type="button" class="btn btn-secundario" data-cierra-modal>Cancelar</button>
      </div>
    </div>

    <div data-seccion-confirmar hidden>
      <p class="campo-info" data-texto-confirmar></p>
      @csrf
      <input type="hidden" name="_origen" value="periodo-eliminar">
      <div class="modal-acciones">
        <button type="submit" class="btn btn-retirar" data-boton-si-eliminar>Sí, eliminar</button>
        <button type="button" class="btn btn-secundario" data-volver-a-elegir>No</button>
      </div>
    </div>
  </form>
</dialog>

@if (old('_origen') === 'periodo-nuevo' || old('_origen') === 'periodo-editar')
<script>
  (function () {
    var id = { 'periodo-nuevo': 'modal-periodo-nuevo', 'periodo-editar': 'modal-periodo-editar' }[@json(old('_origen'))];
    var dialog = id && document.getElementById(id);
    if (dialog && typeof dialog.showModal === 'function') { dialog.showModal(); }
  })();
</script>
@endif
