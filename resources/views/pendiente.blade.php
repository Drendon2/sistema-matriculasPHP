@extends('layouts.publico')

@section('title', 'En construcción — ' . $configuracion->nombre_institucion)

@section('caja')
  <h1>Todavía no está construida</h1>
  <p class="info">
    Esta pantalla forma parte de la migración y aún no se ha portado desde el
    sistema original. El marcador existe para que los enlaces de las pantallas
    ya terminadas no se rompan mientras tanto.
  </p>
  <p class="enlace-pie"><a href="{{ route('login') }}">Volver al inicio de sesión</a></p>
@endsection
