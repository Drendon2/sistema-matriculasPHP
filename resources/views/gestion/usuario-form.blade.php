@extends('layouts.app')

@section('title', $titulo)

@section('content')
<a href="{{ route('usuario-lista') }}" class="volver">&larr; Volver al listado</a>

{{--
  `data-modal-cuerpo` es lo que el modal se lleva dentro cuando se abre desde el
  listado, y por eso el título vive AQUÍ y ya no encima: suelto quedaría flotando
  sobre el fondo oscurecido, fuera de la tarjeta. Sigue siendo una página con su
  URL —sin JavaScript se abre y se rellena igual—, y es la misma tarjeta en los
  dos casos.

  Este formulario es el caso que DESIGN.md daba como ejemplo de lo que NO va en
  un modal, por sus once campos. Se cambió el 02/09/2026 a petición del usuario:
  es el que más se abre —asignar rol a quien se acaba de registrar— y sacarlo de
  la lista para eso costaba el viaje de ida y vuelta. El diálogo se ancla arriba
  y la tarjeta se desplaza por dentro, que es lo que hace que quepa.
--}}
<form method="post" action="{{ $accion }}" enctype="multipart/form-data" class="form-card"
      @if ($modal ?? false) data-modal-cuerpo @endif>
  @csrf
  <input type="hidden" name="volver" value="{{ $volver ?? '' }}">
  <h2 style="margin-top:0;">{{ $titulo }}</h2>

  <div class="field">
    <label for="username">Usuario</label>
    <input type="text" name="username" id="username" maxlength="150" required
           value="{{ old('username', $perfil->user?->username) }}">
    @error('username')<div class="errorlist" style="color:var(--danger);font-size:0.82rem;">{{ $message }}</div>@enderror
  </div>

  <div class="field">
    <label for="password">Contraseña{{ $esCreacion ? '' : ' temporal' }}</label>
    <input type="password" name="password" id="password" @required($esCreacion) autocomplete="new-password">
    @if (! $esCreacion)
      <div class="campo-ayuda">Déjalo en blanco para no cambiar la contraseña.</div>
    @endif
    @error('password')<div class="errorlist" style="color:var(--danger);font-size:0.82rem;">{{ $message }}</div>@enderror
  </div>

  <div class="field">
    <label for="rol">Rol</label>
    {{--
      Solo los roles que QUIEN MIRA puede repartir: un director no ve
      «Administrador» en la lista. El corte de verdad esta en el controlador
      —esconder una opcion no cierra el POST—, pero ofrecer algo que va a
      rebotar es peor que no ofrecerlo.
    --}}
    <select name="rol" id="rol" required>
      {{--
        La opción vacía, y NO es un adorno: es la que impide un ascenso por
        descuido.

        Quien se autorregistra queda con el rol en cadena vacía, que no está en
        esta lista. Sin esta opción ninguna quedaba marcada, así que el navegador
        preseleccionaba la PRIMERA — «Administrador» para un administrador,
        «Director de escuela» para un director—, y abrir la ficha de un profesor
        que espera su rol y guardar sin bajar el desplegable lo convertía en
        administrador sin que nadie lo hubiera pedido ni visto.

        Va deshabilitada, así que con `required` el formulario no se envía hasta
        que alguien elija a propósito. Solo se pinta cuando hace falta: en una
        cuenta que ya tiene rol sería una opción muerta en mitad de la lista.
      --}}
      @if (old('rol', $perfil->rol) === '')
        <option value="" selected disabled>Sin rol asignado — elige uno</option>
      @endif
      @foreach ($roles as $valor)
        <option value="{{ $valor }}" @selected(old('rol', $perfil->rol) === $valor)>
          {{ \App\Models\Perfil::ROLES[$valor] }}
        </option>
      @endforeach
    </select>
    @error('rol')<div class="errorlist" style="color:var(--danger);font-size:0.82rem;">{{ $message }}</div>@enderror
  </div>

  {{--
    Opcional a propósito: muchos de los matriculados son menores sin correo
    propio. Se avisa en el campo para que quien da el alta no se invente uno.
  --}}
  <div class="field">
    <label for="correo">Correo electrónico</label>
    <input type="email" name="correo" id="correo" maxlength="255"
           value="{{ old('correo', $perfil->user?->email) }}">
    <p class="campo-ayuda">Opcional. Puede repetirse: los hermanos suelen compartir el del acudiente.</p>
    @error('correo')<div class="errorlist" style="color:var(--danger);font-size:0.82rem;">{{ $message }}</div>@enderror
  </div>

  <div class="field">
    <label for="nombre_completo">Nombre completo</label>
    <input type="text" name="nombre_completo" id="nombre_completo" maxlength="90" required
           value="{{ old('nombre_completo', $perfil->nombre_completo) }}">
    @error('nombre_completo')<div class="errorlist" style="color:var(--danger);font-size:0.82rem;">{{ $message }}</div>@enderror
  </div>

  <div class="field">
    <label for="fecha_nacimiento">Fecha de nacimiento</label>
    <input type="date" name="fecha_nacimiento" id="fecha_nacimiento" required
           value="{{ old('fecha_nacimiento', $perfil->fecha_nacimiento?->toDateString()) }}">
    @error('fecha_nacimiento')<div class="errorlist" style="color:var(--danger);font-size:0.82rem;">{{ $message }}</div>@enderror
  </div>

  <div class="field">
    <label for="telefono">Teléfono</label>
    <input type="text" name="telefono" id="telefono" maxlength="15" required
           value="{{ old('telefono', $perfil->telefono) }}">
    @error('telefono')<div class="errorlist" style="color:var(--danger);font-size:0.82rem;">{{ $message }}</div>@enderror
  </div>

  <div class="field">
    <label for="foto_perfil">Foto de perfil</label>
    @if ($perfil->exists && $perfil->foto_perfil)
      <div style="display:flex;align-items:center;gap:0.6rem;margin-bottom:0.4rem;">
        <img src="{{ route('ver-foto', $perfil) }}" alt="" class="foto-mini" style="width:40px;height:40px;">
        <span style="font-size:0.8rem;color:var(--ink-soft);">Foto actual — deja este campo vacío para conservarla.</span>
      </div>
    @endif
    <input type="file" name="foto_perfil" id="foto_perfil" accept="image/*">
    @error('foto_perfil')<div class="errorlist" style="color:var(--danger);font-size:0.82rem;">{{ $message }}</div>@enderror
  </div>

  {{--
    Los datos de estudiante solo aplican a ese rol. Se pintan siempre y se
    ocultan con JavaScript en vez de recargar al cambiar el desplegable: sin
    sesión de red de por medio, el formulario responde al instante y sigue
    funcionando entero si el script no llega a ejecutarse.
  --}}
  <div id="campos-estudiante">
    <h4 style="margin-top:0;">Datos de estudiante</h4>

    <div class="field">
      <label for="documento_identidad">Documento de identidad</label>
      <input type="text" name="documento_identidad" id="documento_identidad" maxlength="15"
             value="{{ old('documento_identidad', $datos?->documento_identidad) }}">
      @error('documento_identidad')<div class="errorlist" style="color:var(--danger);font-size:0.82rem;">{{ $message }}</div>@enderror
    </div>

    <div class="field">
      <label for="acudiente_nombre">Nombre del acudiente</label>
      <input type="text" name="acudiente_nombre" id="acudiente_nombre" maxlength="90"
             value="{{ old('acudiente_nombre', $acudiente?->nombre) }}">
      <div class="campo-ayuda">Obligatorio si el estudiante es menor de edad.</div>
      @error('acudiente_nombre')<div class="errorlist" style="color:var(--danger);font-size:0.82rem;">{{ $message }}</div>@enderror
      @error('acudiente')<div class="errorlist" style="color:var(--danger);font-size:0.82rem;">{{ $message }}</div>@enderror
    </div>

    <div class="field">
      <label for="acudiente_telefono">Teléfono del acudiente</label>
      <input type="text" name="acudiente_telefono" id="acudiente_telefono" maxlength="15"
             value="{{ old('acudiente_telefono', $acudiente?->telefono) }}">
      @error('acudiente_telefono')<div class="errorlist" style="color:var(--danger);font-size:0.82rem;">{{ $message }}</div>@enderror
    </div>
  </div>

  {{--
    «Cancelar» sirve en los dos sitios: dentro del modal es lo que lo cierra, y
    en la página suelta es la salida que no obliga a subir hasta el «Volver».
  --}}
  <div class="modal-botones" style="margin-top:1.3rem;">
    <button type="submit" class="btn">Guardar</button>
    <a href="{{ route('usuario-lista') }}" class="btn btn-secundario" data-modal-cerrar>Cancelar</a>
  </div>

  {{--
    El guion va DENTRO del formulario y no después, y no es cosmético: el modal
    se lleva lo que esté marcado con `data-modal-cuerpo`, así que ahí fuera se
    quedaría en la página y dentro del diálogo el desplegable de rol no haría
    nada. Que además se ejecute al insertarlo lo resuelve `acciones.js`, porque
    un <script> insertado en el DOM no corre por sí solo.
  --}}
  <script>
  (function () {
    var rol = document.getElementById("rol");
    var camposEstudiante = document.getElementById("campos-estudiante");
    if (!rol || !camposEstudiante) { return; }

    function actualizar() {
      camposEstudiante.style.display = (rol.value === "estudiante") ? "" : "none";
    }

    rol.addEventListener("change", actualizar);
    actualizar();
  })();
  </script>
</form>
@endsection
