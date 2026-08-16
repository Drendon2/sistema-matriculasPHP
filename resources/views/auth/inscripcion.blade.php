@extends('layouts.publico')

@section('title', 'Inscripción — ' . $configuracion->nombre_institucion)
@section('ancho', '440px')

@php
  // Los cupos: el primero es obligatorio, el resto opcionales y ocultos hasta
  // que la persona los pide. Tantos como permita la configuración.
  $campos = ['promotoria'];
  for ($n = 2; $n <= $limite; $n++) { $campos[] = "promotoria_{$n}"; }
@endphp

@section('caja')
  <h1>Inscripción de estudiante</h1>
  <p class="info">
    @if ($periodo && $matriculasAbiertas)
      Crea tu cuenta y quedas inscrito para el periodo <strong>{{ $periodo->nombre }}</strong>.
      El profesor debe confirmar tu inscripción antes de asignarte un grupo.
    @elseif ($periodo)
      Las matrículas de <strong>{{ $periodo->nombre }}</strong> están cerradas en este momento.
      {{ $configuracion->nombre_institucion }} recibe inscripciones solo al principio y a mitad de año.
    @else
      No hay un periodo de matrícula activo en este momento.
    @endif
  </p>

  @if ($periodo && ! $matriculasAbiertas)
  <p class="aviso">
    Si ya estudiaste aquí antes, no tienes que inscribirte de nuevo: cuando se abran las
    matrículas podrás renovar desde tu cuenta.
  </p>
  @endif

  @if ($periodo && $matriculasAbiertas)
  <form method="post" action="{{ route('inscripcion.guardar') }}" data-recarga-completa>
    @csrf

    <fieldset>
      <legend>Cuenta</legend>

      <label for="username">Usuario</label>
      <input type="text" name="username" id="username" value="{{ old('username') }}"
             maxlength="150" autocomplete="username" required>
      @error('username')<ul class="errorlist"><li>{{ $message }}</li></ul>@enderror

      <label for="password">Contraseña</label>
      <input type="password" name="password" id="password" autocomplete="new-password" required>
      @error('password')<ul class="errorlist"><li>{{ $message }}</li></ul>@enderror

      <label for="password_confirmation">Confirmar contraseña</label>
      <input type="password" name="password_confirmation" id="password_confirmation"
             autocomplete="new-password" required>
    </fieldset>

    <fieldset>
      <legend>Datos personales</legend>

      <label for="nombre_completo">Nombre completo</label>
      <input type="text" name="nombre_completo" id="nombre_completo"
             value="{{ old('nombre_completo') }}" maxlength="90" required>
      @error('nombre_completo')<ul class="errorlist"><li>{{ $message }}</li></ul>@enderror

      <label for="fecha_nacimiento">Fecha de nacimiento</label>
      <input type="date" name="fecha_nacimiento" id="fecha_nacimiento"
             value="{{ old('fecha_nacimiento') }}" required>
      @error('fecha_nacimiento')<ul class="errorlist"><li>{{ $message }}</li></ul>@enderror

      <label for="telefono">Teléfono</label>
      <input type="text" name="telefono" id="telefono" value="{{ old('telefono') }}"
             maxlength="15" inputmode="tel" required>
      @error('telefono')<ul class="errorlist"><li>{{ $message }}</li></ul>@enderror
    </fieldset>

    <fieldset>
      <legend>Documento</legend>

      <label for="documento_identidad">Documento de identidad</label>
      <input type="text" name="documento_identidad" id="documento_identidad"
             value="{{ old('documento_identidad') }}" maxlength="15" required>
      @error('documento_identidad')<ul class="errorlist"><li>{{ $message }}</li></ul>@enderror

      <p class="aviso">
        Por seguridad, aquí no se sube ningún archivo: una vez creada tu cuenta, inicia
        sesión y ve a «Mi perfil» para subir tu foto y la copia de tu documento de
        identidad. Eso no retrasa que el profesor confirme tu inscripción.
      </p>
    </fieldset>

    <fieldset class="campo-condicional" id="fieldset-acudiente">
      <legend>Acudiente (obligatorio si eres menor de edad)</legend>
      <p class="campo-condicional-nota" id="acudiente-nota" aria-live="polite">
        Se activa automáticamente si tu fecha de nacimiento indica que eres menor de edad.
      </p>

      <label for="acudiente_nombre">
        Nombre del acudiente <span class="campo-requerido" id="acudiente-nombre-requerido" hidden>*</span>
      </label>
      <input type="text" name="acudiente_nombre" id="acudiente_nombre"
             value="{{ old('acudiente_nombre') }}" maxlength="90">
      @error('acudiente_nombre')<ul class="errorlist"><li>{{ $message }}</li></ul>@enderror

      <label for="acudiente_telefono">Teléfono del acudiente</label>
      <input type="text" name="acudiente_telefono" id="acudiente_telefono"
             value="{{ old('acudiente_telefono') }}" maxlength="15" inputmode="tel">
      @error('acudiente_telefono')<ul class="errorlist"><li>{{ $message }}</li></ul>@enderror
    </fieldset>

    <fieldset class="promotorias" id="promotorias">
      <legend>Tus promotorías</legend>
      <p class="promo-nota">
        @if ($limite == 1)
          Este periodo puedes inscribirte en <strong>una promotoría</strong>.
        @else
          Puedes inscribirte en hasta <strong>{{ $limite }} promotorías</strong> este periodo.
          Empieza por la principal y añade las demás si quieres.
        @endif
      </p>

      @foreach ($campos as $indice => $campo)
        @php($primero = $indice === 0)
        <div class="promo-campo{{ $primero ? '' : ' promo-extra' }}"
             @if (! $primero && ! old($campo)) hidden @endif>
          <label for="{{ $campo }}">
            @if ($primero)
              Promotoría principal
            @else
              Promotoría {{ $indice + 1 }} <span class="promo-opcional">opcional</span>
            @endif
          </label>
          <select name="{{ $campo }}" id="{{ $campo }}" @if ($primero) required @endif>
            <option value="">-- elegir --</option>
            @foreach ($catalogo as $promotoria)
              <option value="{{ $promotoria->id }}" @selected(old($campo) == $promotoria->id)>
                {{ $promotoria->nombre }} ({{ $promotoria->area->nombre }})
              </option>
            @endforeach
          </select>
          @error($campo)<ul class="errorlist"><li>{{ $message }}</li></ul>@enderror
          @unless ($primero)
            <button type="button" class="promo-quitar">Quitar este cupo</button>
          @endunless
        </div>
      @endforeach

      @if ($limite > 1)
        <button type="button" class="promo-anadir">+ Añadir otra promotoría</button>
      @endif

      <p class="promo-contador"><span data-cuenta>0 de {{ $limite }}</span> cupos usados</p>

      <button type="submit">Crear cuenta e inscribirme</button>
    </fieldset>
  </form>
  @endif

  <p class="enlace-pie"><a href="{{ route('login') }}">Ya tengo cuenta, iniciar sesión</a></p>
@endsection

@push('scripts')
  @if ($periodo && $matriculasAbiertas)
    <script src="{{ asset('js/inscripcion.js') }}"></script>
  @endif
@endpush
