@extends('layouts.publico')

@section('title', 'Cuenta pendiente — ' . $configuracion->nombre_institucion)
@section('ancho', '520px')

@section('caja')
  <h1>Tu cuenta está pendiente de aprobación</h1>
  <p class="info">
    Ya creaste tu cuenta correctamente, pero todavía no tienes un rol asignado.
    Un director o administrador debe asignártelo antes de que puedas entrar al
    sistema.
  </p>
  <p class="campo-info">
    Si ya hablaste con ellos y sigues viendo este mensaje, avísales para que
    revisen tu cuenta en «Gestión de usuarios».
  </p>

  <form method="post" action="{{ route('logout') }}">
    @csrf
    <button type="submit">Cerrar sesión</button>
  </form>
@endsection
