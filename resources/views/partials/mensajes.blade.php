{{--
  Mensajes de una accion anterior. Equivalente del framework de `messages` de
  Django: la vista guarda el mensaje y redirige, y aqui se pinta una sola vez.

  Los errores de CAMPO no salen aqui: van pegados a su campo, que es donde hay
  que corregirlos. Aqui solo lo que no pertenece a ningun campo concreto.
--}}
@if (session('success') || session('error'))
  <ul class="messages">
    @if (session('success'))
      <li class="success">{{ session('success') }}</li>
    @endif
    @if (session('error'))
      <li class="error">{{ session('error') }}</li>
    @endif
  </ul>
@endif
