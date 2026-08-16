{{-- La cabecera con foto, nombre y contacto que abre las fichas de estudiante. --}}
<div class="carne" style="margin-bottom:1.5rem;">
  @if ($estudiante->foto_perfil)
    <img class="carne-foto" style="width:64px;height:64px;" src="{{ route('ver-foto', $estudiante) }}" alt="">
  @else
    <div class="carne-foto-vacia" style="width:64px;height:64px;"></div>
  @endif
  <div class="carne-datos">
    <div class="carne-nombre" style="font-size:1.1rem;">{{ $estudiante->nombre_completo }}</div>
    <div class="carne-detalle">Edad: {{ $estudiante->edad }} · Teléfono: {{ $estudiante->telefono }}</div>
  </div>
</div>
