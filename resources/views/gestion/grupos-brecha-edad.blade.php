@extends('layouts.app')

@section('title', 'Grupos con brecha de edad')

@section('content')
<a href="{{ route('gestion-inicio') }}" class="volver">&larr; Gestión</a>
<h2>Grupos con brecha de edad</h2>
<p class="campo-info" style="margin-top:-0.8rem;">
  Grupos del periodo en curso donde la diferencia entre el estudiante mayor y el menor
  es de {{ \App\Http\Controllers\Gestion\GrupoController::BRECHA_MINIMA }} años o más.
</p>

@if ($filas->isEmpty())
  <p class="vacio">Ningún grupo tiene esa brecha ahora mismo.</p>
@else
<table>
  <thead>
    <tr>
      <th>Grupo</th>
      <th>Departamento</th>
      <th>Menor</th>
      <th>Mayor</th>
      <th>Brecha</th>
    </tr>
  </thead>
  <tbody>
    @foreach ($filas as $fila)
    <tr>
      <td>
        <span class="tag-dot {{ $fila['grupo']->promotoria->area->tag_color }}"></span>
        <a href="{{ route('grupo-estudiantes', $fila['grupo']) }}">{{ $fila['grupo'] }}</a>
      </td>
      <td>{{ $fila['grupo']->promotoria->area->nombre }}</td>
      <td>{{ $fila['menor'] }}</td>
      <td>{{ $fila['mayor'] }}</td>
      <td>{{ $fila['brecha'] }} años</td>
    </tr>
    @endforeach
  </tbody>
</table>
@endif
@endsection
