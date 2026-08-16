@extends('layouts.app')

@section('title', (string) $grupo)

@section('content')
<p class="migas">
  @foreach ($migas as $m)<a href="{{ $m['url'] }}">{{ $m['texto'] }}</a><span class="migas-sep">/</span>@endforeach<span class="migas-actual">{{ $grupo->nivel_display }} · {{ $grupo->horario }}</span>
</p>
<h2>{{ $grupo->nivel_display }} · {{ $grupo->horario }} · Salón {{ $grupo->salon }}</h2>
<p class="campo-info" style="margin-top:-0.8rem;">
  {{ count($estudiantes) }}/{{ $grupo->cupo_maximo }} cupos ocupados
</p>

{{--
  El único camino desde Gestión al registro de clases. Dirección navega el
  catálogo por aquí, no por el Panel, y sin este enlace la asistencia —que sí
  puede consultar, aunque no editar— quedaba a un clic que no existía en ninguna
  parte de su recorrido.
--}}
<p><a class="btn btn-secundario btn-sm" href="{{ route('grupo-clases', $grupo) }}">Ver clases y asistencia</a></p>

@if (! $estudiantes)
  <p class="vacio">Todavía no hay estudiantes en este grupo.</p>
@else
<table>
  <thead>
    <tr>
      <th></th><th>Nombre</th><th>Edad</th><th>Teléfono</th><th>Acudiente</th>
      @if ($yo->rol === 'administrador')<th></th>@endif
    </tr>
  </thead>
  <tbody>
    @foreach ($estudiantes as $e)
    <tr>
      <td>@include('panel.foto', ['perfil' => $e['perfil']])</td>
      <td>
        @if (\App\Support\Permisos::puedeVerFicha($yo, $e['perfil']))
          <a href="{{ route('detalle-usuario', $e['perfil']) }}">{{ $e['perfil']->nombre_completo }}</a>
        @else
          {{ $e['perfil']->nombre_completo }}
        @endif
      </td>
      <td>{{ $e['perfil']->edad }}</td>
      <td>{{ $e['perfil']->telefono }}</td>
      <td>@include('panel.acudiente', ['acudiente' => $e['acudiente']])</td>
      @if ($yo->rol === 'administrador')
      <td><a href="{{ route('detalle-estudiante', $e['perfil']) }}">Ver detalle</a></td>
      @endif
    </tr>
    @endforeach
  </tbody>
</table>
@endif
@endsection
