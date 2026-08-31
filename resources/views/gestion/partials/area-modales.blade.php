{{--
  El modal de "Nueva área" (Departamentos). Mismo mecanismo que los modales de
  Periodo (`periodo-modales.blade.php`): abrir/cerrar los resuelve
  `gestion-periodo-modales.js` de forma genérica, vía `[data-abre-modal]` /
  `[data-cierra-modal]` — no hace falta JS propio para este modal.

  El marcador `_origen` también se repite aquí: "nombre" es tanto el campo de
  esta área como el de los dos modales de Periodo, así que sin él, un error de
  validación en cualquiera de los tres no sabría a cuál `old()` pertenece.
--}}
@php($origenAreaNueva = old('_origen') === 'area-nueva')

<dialog id="modal-area-nueva" class="modal">
  <h2>Nueva área</h2>
  <form method="post" action="{{ route('area-nueva') }}">
    @csrf
    <input type="hidden" name="_origen" value="area-nueva">

    <div class="field">
      <label for="an-nombre">Nombre</label>
      <input type="text" name="nombre" id="an-nombre" maxlength="60" required
             value="{{ $origenAreaNueva ? old('nombre') : '' }}">
      @if ($origenAreaNueva)
        @error('nombre')<div class="errorlist" style="color:var(--danger);font-size:0.82rem;">{{ $message }}</div>@enderror
      @endif
    </div>

    <div class="modal-acciones">
      <button type="submit" class="btn">Guardar</button>
      <button type="button" class="btn btn-secundario" data-cierra-modal>Cancelar</button>
    </div>
  </form>
</dialog>

@if ($origenAreaNueva)
<script>
  (function () {
    var dialog = document.getElementById('modal-area-nueva');
    if (dialog && typeof dialog.showModal === 'function') { dialog.showModal(); }
  })();
</script>
@endif
