@extends('layouts.publico')

@section('title', $actividad->nombre.' — '.$configuracion->nombre_institucion)
@section('ancho', '440px')

@section('caja')
  {{--
    Lo que abre el enlace de un curso, un taller o un grupo de proyección.

    Sin sesión y sin cuenta: quien llega aquí lo hace porque alguien le pasó la
    dirección. Cinco campos, pensados para llenarse en el celular.
  --}}
  <h1>{{ $actividad->nombre }}</h1>

  <p class="info">
    {{ $actividad->etiquetaTipo() }} de {{ $configuracion->nombre_institucion }}.
    @if ($actividad->cupo_maximo)
      Cupo limitado a {{ $actividad->cupo_maximo }} personas.
    @endif
  </p>

  @if ($sesiones->isNotEmpty())
  <p class="info">
    <strong>{{ $sesiones->count() == 1 ? 'Fecha' : 'Fechas' }}:</strong>
    {{ $sesiones->map(fn ($s) => $s->fecha->format('d/m/Y'))->implode(' · ') }}
  </p>
  @endif

  @if (! $admite)
    {{--
      Se dice el nombre y el motivo, no un 404: quien llega con este enlace lo
      recibió de alguien, y necesita saber si llegó tarde o si se equivocó de
      sitio.
    --}}
    <p class="aviso">
      @if ($actividad->abierta)
        <strong>Ya no quedan cupos.</strong>
        Acércate a {{ $configuracion->nombre_institucion }} para saber si habrá otro grupo.
      @else
        <strong>Las inscripciones están cerradas.</strong>
        Acércate a {{ $configuracion->nombre_institucion }} si necesitas entrar.
      @endif
    </p>
  @else
  <form method="post" action="{{ route('actividad-inscribirse', $actividad->token) }}">
    @csrf

    <label for="nombre_completo">Nombre completo</label>
    <input type="text" name="nombre_completo" id="nombre_completo"
           value="{{ old('nombre_completo') }}" maxlength="90" autocomplete="name" required>
    @error('nombre_completo')<ul class="errorlist"><li>{{ $message }}</li></ul>@enderror

    <label for="documento">Documento de identidad</label>
    <input type="text" name="documento" id="documento"
           value="{{ old('documento') }}" maxlength="15" required>
    @error('documento')<ul class="errorlist"><li>{{ $message }}</li></ul>@enderror

    <label for="fecha_nacimiento">Fecha de nacimiento</label>
    <input type="date" name="fecha_nacimiento" id="fecha_nacimiento"
           value="{{ old('fecha_nacimiento') }}" required>
    @error('fecha_nacimiento')<ul class="errorlist"><li>{{ $message }}</li></ul>@enderror

    <label for="telefono">Teléfono</label>
    <input type="text" name="telefono" id="telefono"
           value="{{ old('telefono') }}" maxlength="15" autocomplete="tel" required>
    @error('telefono')<ul class="errorlist"><li>{{ $message }}</li></ul>@enderror

    <label for="correo">Correo electrónico <span class="promo-opcional">(opcional)</span></label>
    <input type="email" name="correo" id="correo"
           value="{{ old('correo') }}" maxlength="120" autocomplete="email">
    @error('correo')<ul class="errorlist"><li>{{ $message }}</li></ul>@enderror

    <button type="submit">Inscribirme</button>
  </form>
  @endif
@endsection
