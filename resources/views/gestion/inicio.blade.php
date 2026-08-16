@extends('layouts.app')

@section('title', 'Gestión')

@section('content')
<h2>Gestión del catálogo académico</h2>
<div class="tarjetas">
  <a class="tarjeta-enlace" href="{{ route('area-lista') }}">Departamentos</a>
  <a class="tarjeta-enlace" href="{{ route('periodo-lista') }}">Periodos</a>
  <a class="tarjeta-enlace" href="{{ route('gestion-matriculas') }}">Iniciar / finalizar matrículas</a>
  <a class="tarjeta-enlace" href="{{ route('gestion-cancelaciones') }}">
    @if ($cancelacionesPendientes)<span class="num">{{ $cancelacionesPendientes }}</span>@endif
    Cancelaciones
  </a>
  <a class="tarjeta-enlace" href="{{ route('promotoria-lista') }}">Promotorías</a>
  <a class="tarjeta-enlace" href="{{ route('gestion-cupos') }}">Cupos por promotoría</a>
  <a class="tarjeta-enlace" href="{{ route('grupo-lista') }}">Grupos</a>
  <a class="tarjeta-enlace" href="{{ route('usuario-lista') }}">Usuarios</a>
  @if ($yo->rol === 'administrador')
  <a class="tarjeta-enlace" href="{{ route('gestion-estadisticas') }}">Estadísticas</a>
  <a class="tarjeta-enlace" href="{{ route('gestion-configuracion') }}">Institución</a>
  @endif
</div>
@endsection
