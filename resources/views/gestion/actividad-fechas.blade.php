@extends('layouts.app')

@section('title', 'Fechas de '.$actividad->nombre)

@section('content')
{{--
  El segundo paso de crear un curso, y la pantalla donde se le cambian las
  fechas después.

  Es una rejilla de casillas y no una lista con botón de «añadir»: es la misma
  decisión que ya se tomó en el horario de un grupo, y por la misma razón —así
  no hace falta JavaScript que mantener—. Para agregar un día se llena una
  casilla vacía; para quitarlo, se borra la suya.
--}}
<a href="{{ route('actividad-curso-lista') }}" class="volver">&larr; Cursos y talleres</a>
<h2>Fechas de {{ $actividad->nombre }}</h2>

<p class="campo-info">
  Una casilla por clase. Las que dejes en blanco no cuentan, y con una sola
  fecha esto queda como un taller.
</p>

<form method="post" action="{{ route('actividad-curso-fechas', $actividad) }}" class="form-card">
  @csrf

  <div class="fechas-rejilla">
    @foreach ($fechas as $i => $fecha)
    @php($numero = $i + 1)
    <div class="field fechas-casilla">
      <label for="fecha_{{ $numero }}">Clase {{ $numero }}</label>
      <input type="date" name="fechas[]" id="fecha_{{ $numero }}"
             value="{{ old('fechas.'.$i, $fecha) }}">
    </div>
    @endforeach
  </div>

  <button type="submit" class="btn">Guardar fechas</button>
</form>
@endsection
