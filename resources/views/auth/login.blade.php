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

  <p class="enlace-pie">¿Eres profesor y no tienes cuenta? <a href="{{ route('registro') }}">Regístrate aquí</a></p>
  <p class="enlace-pie">¿Eres estudiante nuevo? <a href="{{ route('inscripcion') }}">Inscríbete aquí</a></p>
@endsection
