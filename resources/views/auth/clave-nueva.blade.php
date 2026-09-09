@extends('layouts.publico')

@section('title', 'Nueva contraseña — ' . $configuracion->nombre_institucion)
@section('ancho', '380px')

@section('caja')
  <h1>Nueva contraseña</h1>

  <p class="info">
    Escríbela dos veces. Si tenías la sesión abierta en otro dispositivo, ahí
    tendrás que volver a entrar con la nueva.
  </p>

  <form method="post" action="{{ route('clave-nueva.guardar', ['token' => $token]) }}">
    @csrf

    {{--
      EL TOKEN VIAJA EN LA URL Y NO EN UN CAMPO OCULTO, igual que en el GET que
      trajo aquí. Es una decisión y no un descuido: en un campo oculto el enlace
      del correo tendría que llevarlo igualmente para poder pintar esta página,
      así que estaría en los dos sitios y no en uno. Lo que sí importa es que
      esta pantalla no se pueda marcar ni compartir sin querer, y de eso se
      encarga que el enlace se gaste al usarlo y caduque en una hora.

      El ojo para ver la contraseña lo pone `ver-clave.js`, que carga el
      envoltorio público entero — no la plantilla. El porqué está en CLAUDE.md:
      un botón de «ver la clave» escrito en el Blade es un botón que no hace
      nada cuando no hay JavaScript.
    --}}
    <label for="password">Contraseña nueva</label>
    <input type="password" name="password" id="password"
           autocomplete="new-password" autofocus required>
    @error('password')
      <ul class="errorlist"><li>{{ $message }}</li></ul>
    @enderror

    <label for="password_confirmation">Repítela</label>
    <input type="password" name="password_confirmation" id="password_confirmation"
           autocomplete="new-password" required>

    <button type="submit">Guardar la contraseña</button>
  </form>

  <p class="enlace-pie"><a href="{{ route('login') }}">Volver a iniciar sesión</a></p>
@endsection
