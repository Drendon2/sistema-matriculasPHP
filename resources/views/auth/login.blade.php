@extends('layouts.publico')

@section('title', 'Iniciar sesión — ' . $configuracion->nombre_institucion)
@section('ancho', '360px')

@section('caja')
  <h1>Iniciar sesión</h1>

  <form method="post" action="{{ route('login.entrar') }}">
    @csrf

    <label for="username">Usuario</label>
    <input type="text" name="username" id="username" value="{{ old('username') }}"
           autocomplete="username" autofocus required>
    @error('username')
      <ul class="errorlist"><li>{{ $message }}</li></ul>
    @enderror

    <label for="password">Contraseña</label>
    <input type="password" name="password" id="password" autocomplete="current-password" required>
    @error('password')
      <ul class="errorlist"><li>{{ $message }}</li></ul>
    @enderror

    <button type="submit">Iniciar sesión</button>
  </form>

  {{--
    Aquí abajo solo va el enlace del ESTUDIANTE.

    El de profesor estuvo al lado hasta el 01/09 y se quitó porque la gente se
    equivocaba de puerta: dos frases parecidas, una debajo de otra, en la
    pantalla de un celular. Quien entra por la de profesor se queda sin rol, sin
    documento y sin matrícula, y no había forma de matricularlo después.

    La ruta `registro` SIGUE VIVA: no es una puerta cerrada, es una puerta sin
    letrero. Dirección copia el enlace desde Gestión → Usuarios y se lo manda al
    profesor nuevo, que es como se reparte también el de una actividad. El
    profesor sigue eligiendo su propia contraseña, que es el sentido de que se
    registre él y no que se la teclee un administrador.
  --}}
  <p class="enlace-pie">¿Eres estudiante nuevo? <a href="{{ route('inscripcion') }}">Inscríbete aquí</a></p>
@endsection
