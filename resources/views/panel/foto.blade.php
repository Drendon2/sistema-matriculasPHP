{{-- La miniatura de la foto, si es que la persona subió una. --}}
@if ($perfil->foto_perfil)
  <img class="foto-mini" src="{{ route('ver-foto', $perfil) }}" alt="">
@endif
