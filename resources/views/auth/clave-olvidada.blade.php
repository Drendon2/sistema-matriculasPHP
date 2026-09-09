@extends('layouts.publico')

@section('title', 'Recuperar la contraseña — ' . $configuracion->nombre_institucion)
@section('ancho', '380px')

@section('caja')
  <h1>Recuperar la contraseña</h1>

  <p class="info">
    Escribe tu usuario o el correo que tengas registrado y te enviamos un enlace
    para poner una contraseña nueva.
  </p>

  <form method="post" action="{{ route('clave-olvidada.enviar') }}">
    @csrf

    {{--
      UN SOLO CAMPO PARA LAS DOS COSAS, y eso salió de mirar los datos: en
      producción 179 personas usan su correo como nombre de usuario y otras 239
      su nombre con espacios, así que ni siquiera está claro qué recuerda cada
      quien haber escrito. Con dos campos, o con uno que solo aceptara el
      usuario, la mitad se queda fuera por elegir mal la casilla.

      `autocomplete="username"` y no `email` por lo mismo: el gestor de
      contraseñas del teléfono ofrece lo que se guardó al entrar, que es lo que
      esta persona necesita recordar.
    --}}
    <label for="cuenta">Usuario o correo</label>
    <input type="text" name="cuenta" id="cuenta" value="{{ old('cuenta') }}"
           autocomplete="username" autocapitalize="none" spellcheck="false"
           maxlength="255" autofocus required>
    @error('cuenta')
      <ul class="errorlist"><li>{{ $message }}</li></ul>
    @enderror

    <button type="submit">Enviarme el enlace</button>
  </form>

  {{--
    ESTO SE LE DICE A TODO EL MUNDO Y NO SOLO A QUIEN FALLE, y no es un adorno:
    la pantalla contesta lo mismo pase lo que pase —para no delatar quién tiene
    cuenta aquí— así que quien no tenga correo registrado no va a recibir nunca
    un aviso que se lo explique. Hoy son 853 personas de 885.
  --}}
  <p class="enlace-pie">
    ¿No tienes un correo registrado en el sistema? Entonces este enlace no te va
    a llegar: pídele a la institución que te cambie la contraseña.
  </p>
  <p class="enlace-pie"><a href="{{ route('login') }}">Volver a iniciar sesión</a></p>
@endsection
