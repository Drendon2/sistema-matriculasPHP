{{--
  Mensajes de una accion anterior. Equivalente del framework de `messages` de
  Django: la vista guarda el mensaje y redirige, y aqui se pinta una sola vez.

  Los errores de CAMPO no salen aquí: van pegados a su campo, que es donde hay
  que corregirlos. Aquí solo lo que no pertenece a ningún campo concreto — y,
  desde el 24/08, el aviso de que HUBO un rechazo.

  Ese aviso no repite los mensajes: dice que no se guardó y cuántos campos hay
  que mirar. Hacía falta porque un error de campo, por bien puesto que esté, no
  se ve si queda fuera de pantalla, y en un formulario largo eso es lo normal.
  Un profesor leyó un nombre repetido como un tope de grupos justamente así.
--}}
@if ($errors->any())
  <ul class="messages">
    <li class="error">
      <strong>No se guardó.</strong>
      @if ($errors->count() === 1)
        Hay un campo por corregir, marcado en rojo más abajo.
      @else
        Hay {{ $errors->count() }} campos por corregir, marcados en rojo más abajo.
      @endif
    </li>
  </ul>
@endif

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
