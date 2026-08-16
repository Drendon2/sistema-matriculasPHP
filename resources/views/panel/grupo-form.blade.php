@extends('layouts.app')

@section('title', $titulo)

@section('content')
<a href="{{ route('panel') }}" class="volver">&larr; Volver al panel</a>
<h2>{{ $titulo }}</h2>
<p class="campo-info" style="margin-top:-0.8rem;">Promotoría: {{ $promotoria->nombre }}</p>

<form method="post" action="{{ $accion }}" class="form-card">
  @csrf

  <div class="field">
    <label for="nivel">Nivel</label>
    <select name="nivel" id="nivel" required>
      @foreach (\App\Models\Grupo::NIVELES as $valor => $etiqueta)
        <option value="{{ $valor }}" @selected(old('nivel', $grupo->nivel) === $valor)>{{ $etiqueta }}</option>
      @endforeach
    </select>
    @error('nivel')<div class="errorlist" style="color:var(--danger);font-size:0.82rem;">{{ $message }}</div>@enderror
  </div>

  <div class="field">
    <label for="horario">Horario</label>
    <input type="text" name="horario" id="horario" maxlength="60" required
           value="{{ old('horario', $grupo->horario) }}" placeholder="Martes y jueves 4:00–6:00 p. m.">
    @error('horario')<div class="errorlist" style="color:var(--danger);font-size:0.82rem;">{{ $message }}</div>@enderror
  </div>

  <div class="field">
    <label for="salon">Salón</label>
    <input type="text" name="salon" id="salon" maxlength="40" required value="{{ old('salon', $grupo->salon) }}">
    @error('salon')<div class="errorlist" style="color:var(--danger);font-size:0.82rem;">{{ $message }}</div>@enderror
  </div>

  <div class="field">
    <label for="cupo_maximo">Cupo máximo</label>
    <input type="number" name="cupo_maximo" id="cupo_maximo" min="0" step="1" required
           value="{{ old('cupo_maximo', $grupo->cupo_maximo) }}">
    @error('cupo_maximo')<div class="errorlist" style="color:var(--danger);font-size:0.82rem;">{{ $message }}</div>@enderror
  </div>

  <button type="submit" class="btn">Guardar</button>
</form>
@endsection
