@extends('layouts.app')

@section('title', 'Mi perfil')

@section('content')
<h2>Mi perfil</h2>

<div class="perfil-intro">
  <form method="post" action="{{ route('mi-perfil.guardar') }}" enctype="multipart/form-data" id="form-foto">
    @csrf
    <input type="hidden" name="accion" value="foto">
    <label class="perfil-avatar-wrap" for="foto_perfil">
      <span class="perfil-avatar">
        @if ($perfil->foto_perfil)
          <img src="{{ route('ver-foto', $perfil) }}" alt="">
        @else
          <span class="perfil-avatar-inicial">{{ mb_strtoupper(mb_substr($perfil->nombre_completo, 0, 1)) }}</span>
        @endif
      </span>
      <span class="perfil-avatar-badge" aria-hidden="true">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.75" stroke-linecap="round">
          <path d="M12 5v14M5 12h14"/>
        </svg>
      </span>
      {{--
        `perfil-avatar-input` no es decorativa: es la que estira el input
        invisible sobre el avatar entero para que la tarjeta sea el control. Sin
        ella el navegador pinta su «Seleccionar archivo» encima de la foto.
      --}}
      <input type="file" name="foto_perfil" id="foto_perfil" accept="image/*" class="perfil-avatar-input">
    </label>
    @error('foto_perfil')<div class="errorlist" style="color:var(--danger);font-size:0.82rem;">{{ $message }}</div>@enderror
  </form>
  <div class="perfil-nombre">{{ $perfil->nombre_completo }}</div>
  @if ($perfil->rol)<div class="perfil-rol-sub">{{ $perfil->rol_display }}</div>@endif
  <p class="campo-info" style="margin:0.4rem 0 0;">Edad: {{ $perfil->edad }} años</p>
  <div class="perfil-tel-fila perfil-tel-texto">
    <span class="campo-info" style="margin:0;">Teléfono: {{ $perfil->telefono }}</span>
    <button type="button" class="perfil-editar-btn perfil-tel-toggle" aria-label="Editar teléfono">
      <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
    </button>
  </div>
  <form method="post" action="{{ route('mi-perfil.guardar') }}" class="perfil-contacto-form perfil-tel-form">
    @csrf
    <input type="hidden" name="accion" value="contacto">
    <input type="text" name="telefono" maxlength="15" value="{{ old('telefono', $perfil->telefono) }}">
    <button type="submit" class="perfil-editar-btn" aria-label="Guardar teléfono">
      <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
    </button>
  </form>
  @error('telefono')<div class="errorlist" style="color:var(--danger);font-size:0.82rem;">{{ $message }}</div>@enderror

  {{--
    El correo es OPCIONAL y vive en la cuenta, no en el perfil. Sin él puesto se
    dice «Sin correo» en vez de dejar el renglón vacío: un hueco no distingue
    «no lo he puesto» de «esta pantalla no lo pide».

    Reutiliza las clases del teléfono porque el patrón es el mismo —texto con un
    botón que lo cambia por un campo— y son estructurales, no propias del
    teléfono.
  --}}
  <div class="perfil-tel-fila perfil-tel-texto">
    <span class="campo-info" style="margin:0;">
      Correo:
      @if ($perfil->user->email)
        {{ $perfil->user->email }}
      @else
        <span class="vacio">Sin correo</span>
      @endif
    </span>
    <button type="button" class="perfil-editar-btn perfil-tel-toggle" aria-label="Editar correo">
      <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
    </button>
  </div>
  <form method="post" action="{{ route('mi-perfil.guardar') }}" class="perfil-contacto-form perfil-tel-form">
    @csrf
    <input type="hidden" name="accion" value="correo">
    <input type="email" name="correo" maxlength="255" placeholder="tu@correo.com"
           value="{{ old('correo', $perfil->user->email) }}">
    <button type="submit" class="perfil-editar-btn" aria-label="Guardar correo">
      <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
    </button>
  </form>
  @error('correo')<div class="errorlist" style="color:var(--danger);font-size:0.82rem;">{{ $message }}</div>@enderror

  @if ($estadisticas)
  <div class="perfil-stats" style="margin-top:1rem;">
    @foreach ($estadisticas as $stat)
    <div>
      <span class="perfil-stat-num">{{ $stat['numero'] }}</span>
      <span class="perfil-stat-label">{{ $stat['etiqueta'] }}</span>
    </div>
    @endforeach
  </div>
  @endif
</div>
{{--
  El horario de la semana. Solo aparece si esta persona tiene dónde estar: al
  estudiante sin grupo asignado todavía, y a quien no dicta nada, una rejilla
  vacía no le dice nada que no sepa.
--}}
@if ($horario)
  @include('partials.horario-semanal', [
    'horario' => $horario,
    'titulo' => $perfil->rol === 'estudiante' ? 'Mi horario' : 'Mi horario de clases',
    'periodo' => \App\Models\Periodo::enCurso(),
  ])
@endif

{{--
  Solo si hay algo que contar. Un panel de ceros no informa de nada y ademas
  miente por omisión: sin clases todavía no se distingue «no he faltado nunca» de
  «no ha empezado el periodo».
--}}
@if ($asistencia)
  @include('partials.panel-asistencia', [
    'asistencia' => $asistencia,
    'periodo' => $periodo,
    'periodoAtras' => $periodoAtras,
    'periodoAdelante' => $periodoAdelante,
    'periodoEsElEnCurso' => $periodoEsElEnCurso,
  ])
@endif

<script>
  (function () {
    var toggles = document.querySelectorAll(".perfil-tel-toggle");
    toggles.forEach(function (toggle) {
      toggle.addEventListener("click", function () {
        var fila = toggle.closest(".perfil-tel-texto");
        if (!fila) { return; }
        // El formulario que sigue a ESTA fila, no el primero de la tarjeta: hay
        // dos campos en línea —teléfono y correo— y buscar por nombre de campo
        // o por el primero abría siempre el mismo.
        var form = fila.nextElementSibling;
        if (!form || !form.classList.contains("perfil-tel-form")) { return; }
        fila.style.display = "none";
        form.style.display = "inline-flex";
        var input = form.querySelector("input:not([type=hidden])");
        if (input) { input.focus(); }
      });
    });
  })();
</script>

{{--
  El certificado de matrícula. Solo para quien tiene algo que certificar ahora
  mismo: sin matrículas activas en el periodo en curso la sección no aparece,
  porque el documento saldría afirmando que esta persona cursa algo que no cursa.
--}}
@if ($certificables)
<div class="perfil-seccion">
  <div class="perfil-seccion-cabecera">
    <span class="perfil-seccion-icono icono-documento" aria-hidden="true">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/>
        <path d="M14 3v5h5"/>
        <path d="M9 13h6M9 17h4"/>
      </svg>
    </span>
    <h3>Certificado de matrícula</h3>
  </div>
  <p class="campo-info" style="margin-top:0;">
    Documento en PDF que acredita
    {{ $certificables === 1
        ? 'la promotoría que cursas'
        : 'las '.$certificables.' promotorías que cursas' }}
    en el periodo en curso, firmado por la dirección. Para certificar una sola
    promotoría, usa el botón de cada fila en <strong>Mis matrículas</strong>.
  </p>
  <a class="btn" href="{{ route('certificado-todo', $perfil) }}">Descargar el certificado</a>
</div>
@endif

@if ($datos)
<div class="perfil-seccion">
  <div class="perfil-seccion-cabecera">
    <span class="perfil-seccion-icono icono-documento" aria-hidden="true">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <rect x="3" y="5" width="18" height="14" rx="2"/>
        <circle cx="8.5" cy="10.5" r="1.8"/>
        <path d="M5.5 16.5 9 13l2 2 3-3 4.5 4.5"/>
      </svg>
    </span>
    <h3>Documento de identidad</h3>
  </div>
  @if ($datos->copia_documento)
    <p class="campo-info archivo-guardado" style="margin-top:-0.6rem;">Ya subiste una copia de tu documento.</p>
  @else
    <p class="aviso">Todavía no has subido la copia de tu documento de identidad. Es reservada: solo el administrador puede verla. No es necesaria para que el profesor confirme tu matrícula.</p>
  @endif
  <form method="post" action="{{ route('mi-perfil.guardar') }}" enctype="multipart/form-data" class="form-card">
    @csrf
    <input type="hidden" name="accion" value="documento">
    <div class="field">
      <label for="copia_documento">Copia del documento</label>
      <input type="file" name="copia_documento" id="copia_documento">
      @error('copia_documento')<div class="errorlist" style="color:var(--danger);font-size:0.82rem;">{{ $message }}</div>@enderror
    </div>
    <button type="submit" class="btn">Guardar documento</button>
  </form>
</div>
@endif

@if ($papeles)
{{--
  Los papeles que pide ESTA institución. Cada ranura es su propio envío: se suben
  a medida que se consiguen, que es como se hace en la vida real. Un solo botón
  de "guardar todo" obligaría a tenerlos todos a la mano el mismo día.
--}}
<div class="perfil-seccion">
  <div class="perfil-seccion-cabecera">
    <span class="perfil-seccion-icono icono-documento" aria-hidden="true">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/>
        <path d="M14 3v5h5"/>
      </svg>
    </span>
    <h3>Documentos para la matrícula</h3>
  </div>
  <p class="campo-info" style="margin-top:-0.6rem;">
    Lo que pide {{ $configuracion->nombre_institucion }} para dar la matrícula por completa.
    Puedes subirlos de a uno, según los vayas consiguiendo.
  </p>

  @foreach ($papeles as $p)
  <form method="post" action="{{ route('mi-perfil.guardar') }}" enctype="multipart/form-data" class="papel-fila">
    @csrf
    <input type="hidden" name="accion" value="papel">
    <input type="hidden" name="documento_id" value="{{ $p['requerido']->id }}">
    <div class="papel-datos">
      <span class="papel-nombre">
        {{ $p['requerido']->nombre }}
        @if (! $p['requerido']->obligatorio)<span class="campo-info" style="margin:0;">(opcional)</span>@endif
      </span>
      @if ($p['requerido']->descripcion)
        <span class="campo-info" style="margin:0;">{{ $p['requerido']->descripcion }}</span>
      @endif
      @if ($p['entrega'])
        <span class="campo-info archivo-guardado" style="margin:0;">Entregado — súbelo otra vez para reemplazarlo.</span>
      @elseif ($p['requerido']->obligatorio)
        <span class="estado estado-pendiente">Falta</span>
      @endif
    </div>
    <input type="file" name="archivo" aria-label="Archivo de {{ $p['requerido']->nombre }}">
    <button type="submit" class="btn btn-sm">{{ $p['entrega'] ? 'Reemplazar' : 'Subir' }}</button>
  </form>
  @endforeach
</div>
@endif

{{--
  La sección arranca abierta cuando falta algo por contestar. Plegada, una
  encuesta a medias no se distingue de una terminada, y quien la dejó así no
  tiene por qué sospechar que le falta nada.
--}}
<details class="perfil-seccion" id="bloque-encuesta" @if ($faltanPreguntas) open @endif>
  <summary class="perfil-seccion-cabecera">
    <span class="perfil-seccion-icono icono-encuesta" aria-hidden="true">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <rect x="6" y="4" width="12" height="17" rx="2"/>
        <path d="M9 4V3a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v1"/>
        <path d="M9 11h6M9 15h6M9 19h3"/>
      </svg>
    </span>
    <h3 style="margin:0;">Encuesta demográfica</h3>
    @if ($faltanPreguntas)<span class="estado estado-pendiente">Incompleta</span>@endif
    <svg class="perfil-seccion-chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6 6-6"/></svg>
  </summary>
  <p class="campo-info" style="margin-top:0.8rem;">Esta información solo la puedes ver tú y el administrador.</p>
  @if ($faltanPreguntas)
  <p class="aviso">
    Falta contestar
    {{ count($faltanPreguntas) === 1 ? 'una pregunta' : count($faltanPreguntas).' preguntas' }}:
    <strong>{{ implode(', ', $faltanPreguntas) }}</strong>.
  </p>
  @endif
  @if ($perfil->es_menor)
  <p class="aviso">
    Eres menor de edad: la autorización de tratamiento de datos debe darla tu acudiente.
    Pide al administrador que la registre.
  </p>
  @endif
  <form method="post" action="{{ route('mi-perfil.guardar') }}" class="form-card">
    @csrf
    <input type="hidden" name="accion" value="encuesta">

    @include('perfil.campo-lista', ['campo' => 'genero', 'etiqueta' => 'Género', 'obligatorio' => true])

    <div class="field">
      <label for="barrio">Barrio</label>
      <input type="text" name="barrio" id="barrio" maxlength="60" required
             value="{{ old('barrio', $encuesta?->barrio) }}">
      @error('barrio')<div class="errorlist" style="color:var(--danger);font-size:0.82rem;">{{ $message }}</div>@enderror
    </div>

    @include('perfil.campo-lista', ['campo' => 'estrato', 'etiqueta' => 'Estrato', 'obligatorio' => true])
    @include('perfil.campo-lista', ['campo' => 'nivel_educativo', 'etiqueta' => 'Nivel educativo', 'obligatorio' => true])
    @include('perfil.campo-lista', ['campo' => 'ocupacion', 'etiqueta' => 'Ocupación', 'obligatorio' => true])
    @include('perfil.campo-lista', ['campo' => 'zona', 'etiqueta' => 'Zona'])
    @include('perfil.campo-lista', ['campo' => 'afiliacion_salud', 'etiqueta' => 'Afiliación a salud'])
    @include('perfil.campo-lista', ['campo' => 'grupo_etnico', 'etiqueta' => 'Grupo étnico'])
    @include('perfil.campo-lista', ['campo' => 'discapacidad', 'etiqueta' => 'Discapacidad'])
    @include('perfil.campo-lista', [
      'campo' => 'victima_conflicto_armado',
      'etiqueta' => 'Víctima del conflicto armado',
    ])

    @if (! $perfil->es_menor)
    <div class="field">
      <label>
        <input type="checkbox" name="autoriza_tratamiento_datos" value="1"
               @checked(old('autoriza_tratamiento_datos', $encuesta?->autoriza_tratamiento_datos))>
        Autorizo el tratamiento de mis datos personales
      </label>
    </div>
    @endif

    <button type="submit" class="btn">Guardar encuesta</button>
  </form>
</details>

<script>
  (function () {
    var input = document.getElementById("foto_perfil");
    var form = document.getElementById("form-foto");
    if (!input || !form) { return; }
    input.addEventListener("change", function () {
      if (input.files && input.files.length) { form.submit(); }
    });
  })();
</script>
@endsection

@push('scripts')
{{-- Solo el gesto de deslizar entre periodos. Las flechas funcionan sin esto. --}}
<script src="{{ asset('js/periodo.js') }}" defer></script>
@endpush
