@extends('layouts.publico')

@section('title', 'Crear cuenta — ' . $configuracion->nombre_institucion)

@section('caja')
  <h1>Crear cuenta</h1>
  <p class="info">
    Para profesores. Un director o administrador te asignará el rol antes de que
    puedas entrar al sistema.
  </p>

  <form method="post" action="{{ route('registro.guardar') }}">
    @csrf

    <label for="username">Usuario</label>
    <input type="text" name="username" id="username" value="{{ old('username') }}"
           maxlength="150" autocomplete="username" required>
    @error('username')<ul class="errorlist"><li>{{ $message }}</li></ul>@enderror

    <label for="password">Contraseña</label>
    <input type="password" name="password" id="password" autocomplete="new-password" required>
    @error('password')<ul class="errorlist"><li>{{ $message }}</li></ul>@enderror

    <label for="password_confirmation">Confirmar contraseña</label>
    <input type="password" name="password_confirmation" id="password_confirmation"
           autocomplete="new-password" required>

    <label for="nombre_completo">Nombre completo</label>
    <input type="text" name="nombre_completo" id="nombre_completo"
           value="{{ old('nombre_completo') }}" maxlength="90" required>
    @error('nombre_completo')<ul class="errorlist"><li>{{ $message }}</li></ul>@enderror

    {{-- type="date" espera aaaa-mm-dd; cualquier otro formato lo deja en blanco. --}}
    <label for="fecha_nacimiento">Fecha de nacimiento</label>
    <input type="date" name="fecha_nacimiento" id="fecha_nacimiento"
           value="{{ old('fecha_nacimiento') }}" required>
    @error('fecha_nacimiento')<ul class="errorlist"><li>{{ $message }}</li></ul>@enderror

    <label for="telefono">Teléfono</label>
    <input type="text" name="telefono" id="telefono" value="{{ old('telefono') }}"
           maxlength="15" inputmode="tel" required>
    @error('telefono')<ul class="errorlist"><li>{{ $message }}</li></ul>@enderror

    <p class="campo-info">
      La foto de perfil se sube después, ya con la sesión iniciada, desde «Mi perfil».
    </p>

    <button type="submit">Crear cuenta</button>
  </form>

  <p class="enlace-pie">¿Ya tienes cuenta? <a href="{{ route('login') }}">Inicia sesión</a></p>
@endsection
