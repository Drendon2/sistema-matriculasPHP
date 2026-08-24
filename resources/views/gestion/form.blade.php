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
  {{--
    El horario del grupo no es una columna sino filas aparte, así que trae su
    propio parcial —el mismo que usa el Panel— en vez de pasar por los tipos de
    campo de aquí abajo.
  --}}
  @if ($spec['tipo'] === 'sesiones')
    @include('partials.sesiones-form', [
      'sesiones' => \App\Support\HorarioDeGrupo::paraElFormulario($objeto),
    ])
    @continue
  @endif

  @php($valor = old($campo, $spec['valor'] ?? $objeto->{$campo}))
  {{--
    Casi todos los campos son obligatorios, así que lo obligatorio es el
    defecto y lo que se declara es la excepción. Un campo opcional lo es de
    verdad: el cupo de una actividad en blanco significa «sin tope», y esa
    ausencia tiene que poder escribirse.

    Se precalcula con la directiva PHP en línea, como el resto del archivo.
  --}}
  @php($obligatorio = ! ($spec['opcional'] ?? false))
  <div class="field">
    <label for="{{ $campo }}">{{ $spec['etiqueta'] }}</label>

    @if ($spec['tipo'] === 'select')
      <select name="{{ $campo }}" id="{{ $campo }}" @required($obligatorio && ! isset($spec['vacio']))>
        @isset($spec['vacio'])
          <option value="">{{ $spec['vacio'] }}</option>
        @endisset
        @foreach ($spec['opciones'] as $clave => $etiqueta)
          <option value="{{ $clave }}" @selected((string) $valor === (string) $clave)>{{ $etiqueta }}</option>
        @endforeach
      </select>
    @elseif ($spec['tipo'] === 'date')
      {{-- El <input type="date"> del navegador solo entiende aaaa-mm-dd. --}}
      <input type="date" name="{{ $campo }}" id="{{ $campo }}" @required($obligatorio)
             value="{{ $valor instanceof \Illuminate\Support\Carbon ? $valor->toDateString() : $valor }}">
    @elseif ($spec['tipo'] === 'number')
      <input type="number" name="{{ $campo }}" id="{{ $campo }}" @required($obligatorio)
             min="{{ $spec['min'] ?? 0 }}" step="1" value="{{ $valor }}">
    @else
      <input type="text" name="{{ $campo }}" id="{{ $campo }}" @required($obligatorio)
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
