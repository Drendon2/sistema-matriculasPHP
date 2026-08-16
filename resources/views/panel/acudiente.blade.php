{{-- El acudiente y su teléfono, o una raya cuando no hay. --}}
@if ($acudiente)
  {{ $acudiente->nombre }} ({{ $acudiente->telefono }})
@else
  <span class="vacio">—</span>
@endif
