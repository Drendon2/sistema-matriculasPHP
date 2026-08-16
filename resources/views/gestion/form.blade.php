@extends('layouts.app')

@section('title', $titulo)

@section('content')
<a href="{{ route($ruta_lista) }}" class="volver">&larr; Volver al listado</a>
<h2>{{ $titulo }}</h2>

{{--
  El formulario que comparten los cuatro catálogos. Los campos llegan
  declarados desde el controlador (ver `RecursoController::campos()`), que es lo
  que permite que una sola plantilla sirva para departamentos, periodos,
  promotorías y grupos sin preguntar de cuál se trata.
--}}
<form method="post" action="{{ $accion }}" class="form-card">
  @csrf

  @foreach ($campos as $campo => $spec)
  @php($valor = old($campo, $spec['valor'] ?? $objeto->{$campo}))
  <div class="field">
    <label for="{{ $campo }}">{{ $spec['etiqueta'] }}</label>

    @if ($spec['tipo'] === 'select')
      <select name="{{ $campo }}" id="{{ $campo }}" @required(! isset($spec['vacio']))>
        @isset($spec['vacio'])
          <option value="">{{ $spec['vacio'] }}</option>
        @endisset
        @foreach ($spec['opciones'] as $clave => $etiqueta)
          <option value="{{ $clave }}" @selected((string) $valor === (string) $clave)>{{ $etiqueta }}</option>
        @endforeach
      </select>
    @elseif ($spec['tipo'] === 'date')
      {{-- El <input type="date"> del navegador solo entiende aaaa-mm-dd. --}}
      <input type="date" name="{{ $campo }}" id="{{ $campo }}" required
             value="{{ $valor instanceof \Illuminate\Support\Carbon ? $valor->toDateString() : $valor }}">
    @elseif ($spec['tipo'] === 'number')
      <input type="number" name="{{ $campo }}" id="{{ $campo }}" required
             min="{{ $spec['min'] ?? 0 }}" step="1" value="{{ $valor }}">
    @else
      <input type="text" name="{{ $campo }}" id="{{ $campo }}" required
             maxlength="{{ $spec['max'] ?? 255 }}" value="{{ $valor }}">
    @endif

    @isset($spec['ayuda'])
      <span class="campo-info" style="margin:0;">{{ $spec['ayuda'] }}</span>
    @endisset

    @error($campo)<div class="errorlist" style="color:var(--danger);font-size:0.82rem;">{{ $message }}</div>@enderror
  </div>
  @endforeach

  <button type="submit" class="btn">Guardar</button>
</form>
@endsection
