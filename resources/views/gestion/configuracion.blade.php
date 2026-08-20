@extends('layouts.app')

@section('title', 'Institución')

@section('content')
<a href="{{ route('gestion-inicio') }}" class="volver">&larr; Gestión</a>
<h2>Institución</h2>

<p class="aviso">
  Tres grupos de ajustes. La <strong>marca</strong> solo cambia cómo se ve el sistema: el nombre de
  la cabecera y los títulos, el logo de las pantallas públicas, y el color de acento del que salen
  botones, enlaces, foco y mensajes de éxito. La <strong>firma</strong> es la que sella los
  certificados de matrícula. Las <strong>reglas de matrícula</strong> sí cambian lo que los
  estudiantes pueden hacer. Ninguno de los tres toca el catálogo académico.
</p>

<div class="card">
  <form method="post" action="{{ route('gestion-configuracion') }}" enctype="multipart/form-data">
    @csrf

    <fieldset class="config-seccion">
    <legend class="config-seccion-titulo">Marca</legend>

    <div class="config-campo">
      <label class="config-etiqueta" for="nombre_institucion">Nombre de la institución</label>
      <input type="text" name="nombre_institucion" id="nombre_institucion" maxlength="80" required
             value="{{ old('nombre_institucion', $institucion->nombre_institucion) }}">
      @error('nombre_institucion')<div class="errorlist" style="color:var(--danger);font-size:0.82rem;">{{ $message }}</div>@enderror
      <p class="config-ayuda">Aparece en la cabecera y en los títulos de página.</p>
    </div>

    <div class="config-campo">
      <label class="config-etiqueta" for="logo">Logo</label>
      <div class="config-logo">
        <img class="config-logo-vista"
             src="{{ $institucion->logo ? route('logo-institucion') : asset('img/logo.webp') }}"
             alt="Logo actual" width="64" height="64">
        <p class="config-ayuda">
          {{ $institucion->logo ? 'Logo propio cargado.' : 'Se está usando el logo por defecto del proyecto.' }}
        </p>
      </div>
      <input class="config-logo-file" type="file" name="logo" id="logo" accept="image/*">
      <label class="config-logo-boton" for="logo">
        + {{ $institucion->logo ? 'Cambiar el logo' : 'Subir un logo' }}
      </label>
      <p class="config-logo-nombre" data-nombre-archivo="logo">{{ basename($institucion->logo) }}</p>
      @if ($institucion->logo)
      <label class="config-logo-quitar">
        <input type="checkbox" name="quitar_logo" value="1"> Quitar y volver al logo por defecto
      </label>
      @endif
      @error('logo')<div class="errorlist" style="color:var(--danger);font-size:0.82rem;">{{ $message }}</div>@enderror
    </div>

    <div class="config-campo">
      <label class="config-etiqueta" for="color_acento">Color de acento</label>
      <div class="config-color">
        <input type="color" name="color_acento" id="color_acento"
               value="{{ old('color_acento', $institucion->color_acento) }}">
        <span class="config-hex">{{ $institucion->color_acento }}</span>
      </div>
      @error('color_acento')<div class="errorlist" style="color:var(--danger);font-size:0.82rem;">{{ $message }}</div>@enderror
      <p class="config-ayuda">
        Único color de marca del sistema: botones, enlaces, foco y mensajes de éxito.
        Los tonos hover y de fondo se derivan de este automáticamente.
      </p>
      <div class="config-muestra">
        <span class="config-muestra-chip" style="background:{{ $institucion->color_acento }};">Acento</span>
        <span class="config-muestra-chip" style="background:{{ $institucion->color_acento_oscuro }};">Hover</span>
        <span class="config-muestra-chip config-muestra-chip-claro"
              style="background:{{ $institucion->color_acento_suave }};color:{{ $institucion->color_acento_oscuro }};">Fondo suave</span>
      </div>
      <p class="config-ayuda">Los dos últimos se derivan del acento automáticamente; no se configuran por separado.</p>
    </div>

    </fieldset>

    <fieldset class="config-seccion">
    <legend class="config-seccion-titulo">Firma para certificados</legend>

    <p class="config-ayuda" style="margin:0 0 1.2rem;">
      Un estudiante puede descargar su <strong>certificado de matrícula</strong> en PDF desde
      «Mi perfil». Lo que se cargue aquí es lo que aparece al pie de ese documento. Sin firma
      cargada el certificado se sigue generando, pero sale con el espacio de la firma en blanco.
    </p>

    <div class="config-campo">
      <label class="config-etiqueta" for="firma">Firma escaneada</label>
      <div class="config-logo">
        @if ($institucion->firma)
        <img class="config-firma-vista" src="{{ route('firma-institucion') }}" alt="Firma actual">
        @endif
        <p class="config-ayuda">
          {{ $institucion->firma
              ? 'Firma cargada.'
              : 'Todavía no hay firma. Escanea la firma sobre papel blanco y súbela.' }}
        </p>
      </div>
      <input class="config-logo-file" type="file" name="firma" id="firma" accept="image/*">
      <label class="config-logo-boton" for="firma">
        + {{ $institucion->firma ? 'Cambiar la firma' : 'Subir una firma' }}
      </label>
      <p class="config-logo-nombre" data-nombre-archivo="firma">{{ basename($institucion->firma) }}</p>
      @if ($institucion->firma)
      <label class="config-logo-quitar">
        <input type="checkbox" name="quitar_firma" value="1"> Quitar la firma
      </label>
      @endif
      @error('firma')<div class="errorlist" style="color:var(--danger);font-size:0.82rem;">{{ $message }}</div>@enderror
      <p class="config-ayuda">
        Un PNG recortado con fondo transparente es lo que mejor queda: se apoya sobre la línea
        en vez de taparla con un recuadro blanco.
      </p>
    </div>

    <div class="config-campo">
      <label class="config-etiqueta" for="firmante_nombre">Nombre de quien firma</label>
      <input type="text" name="firmante_nombre" id="firmante_nombre" maxlength="120"
             value="{{ old('firmante_nombre', $institucion->firmante_nombre) }}">
      @error('firmante_nombre')<div class="errorlist" style="color:var(--danger);font-size:0.82rem;">{{ $message }}</div>@enderror
    </div>

    <div class="config-campo">
      <label class="config-etiqueta" for="firmante_cargo">Cargo</label>
      <input type="text" name="firmante_cargo" id="firmante_cargo" maxlength="80"
             value="{{ old('firmante_cargo', $institucion->firmante_cargo) }}"
             placeholder="Directora">
      @error('firmante_cargo')<div class="errorlist" style="color:var(--danger);font-size:0.82rem;">{{ $message }}</div>@enderror
      <p class="config-ayuda">
        Se imprimen bajo la firma, en ese orden. Un garabato escaneado sin nombre ni cargo
        no identifica a nadie ante quien recibe el certificado.
      </p>
    </div>

    </fieldset>

    <fieldset class="config-seccion">
    <legend class="config-seccion-titulo">Reglas de matrícula</legend>

    <div class="config-campo">
      <label class="config-etiqueta" for="limite_promotorias_por_periodo">
        Promotorías por estudiante y periodo
      </label>
      <input type="number" name="limite_promotorias_por_periodo" id="limite_promotorias_por_periodo"
             min="1" max="{{ \App\Models\ConfiguracionInstitucion::RANURA_MAXIMA_ABSOLUTA }}" step="1" required
             value="{{ old('limite_promotorias_por_periodo', $institucion->limite_promotorias_por_periodo) }}">
      @error('limite_promotorias_por_periodo')<div class="errorlist" style="color:var(--danger);font-size:0.82rem;">{{ $message }}</div>@enderror
      <p class="config-ayuda">
        Cuántas promotorías puede cursar un mismo estudiante en un periodo.
        Cuentan las matrículas pendientes y las activas; las retiradas liberan cupo.
        Bajarlo no retira ni rompe las matrículas que ya existen: solo impide pedir
        más a quien ya esté en el nuevo límite o por encima.
      </p>
    </div>

    <div class="config-campo">
      <label class="config-interruptor">
        <input type="checkbox" name="promotorias_visibles_para_estudiantes" value="1"
               @checked(old('promotorias_visibles_para_estudiantes', $institucion->promotorias_visibles_para_estudiantes))>
        <span class="config-etiqueta">Los estudiantes ven el catálogo de promotorías</span>
      </label>
      <p class="config-ayuda">
        Con esto apagado, el estudiante no ve la pantalla para matricularse por su
        cuenta y solo consulta lo que ya tiene. Sirve para las instituciones que
        inscriben en ventanilla. No afecta a las matrículas ya hechas.
      </p>
    </div>

    </fieldset>

    <button type="submit" class="btn">Guardar</button>
  </form>
</div>

{{--
  Los documentos van FUERA del formulario de arriba y con los suyos propios:
  agregar un papel y desactivarlo son acciones sueltas que se resuelven solas, no
  un campo que se guarde junto con el color de la marca. Anidar formularios,
  además, es HTML inválido.
--}}
<div class="card" style="margin-top:1.4rem;">
  <h3 style="margin-top:0;">Documentos para matricularse</h3>
  <p class="config-ayuda" style="margin-top:-0.4rem;">
    Los papeles que esta institución exige. El estudiante los sube desde <strong>Mi perfil</strong>,
    y a quien le falte alguno obligatorio le sale una etiqueta en el panel del profesor y en su
    ficha. La copia del documento de identidad se pide siempre y no se configura aquí.
  </p>

  @if (count($documentos))
  <table>
    <thead>
      <tr><th>Documento</th><th>Obligatorio</th><th>Entregados</th><th></th></tr>
    </thead>
    <tbody>
      @foreach ($documentos as $d)
      <tr>
        <td>
          {{ $d->nombre }}
          @if (! $d->activo)<span class="estado estado-pendiente">Ya no se pide</span>@endif
          @if ($d->descripcion)<span class="campo-info" style="margin:0;display:block;">{{ $d->descripcion }}</span>@endif
        </td>
        <td>@if ($d->obligatorio)Sí @else<span class="vacio">Opcional</span>@endif</td>
        <td>{{ $d->entregados }}</td>
        <td style="text-align:right;white-space:nowrap;">
          <form action="{{ route('documento-requerido-alternar', $d) }}" method="post" style="display:inline;">
            @csrf
            {{--
              «Dejar de pedir» y no «Eliminar»: los archivos que ya subieron los
              estudiantes cuelgan de esta fila, y borrarla se llevaría la prueba
              de que en su momento cumplieron.
            --}}
            <button type="submit" class="btn btn-secundario btn-sm">
              {{ $d->activo ? 'Dejar de pedir' : 'Volver a pedir' }}
            </button>
          </form>
        </td>
      </tr>
      @endforeach
    </tbody>
  </table>
  @else
    <p class="vacio">No se pide ningún documento además de la copia del documento de identidad.</p>
  @endif

  <form action="{{ route('documento-requerido-nuevo') }}" method="post" class="doc-alta">
    @csrf
    <div class="doc-alta-campo doc-alta-ancho">
      <label class="config-etiqueta" for="doc-nombre">Nombre</label>
      <input type="text" name="nombre" id="doc-nombre" maxlength="60" required placeholder="Certificado de EPS">
    </div>
    <div class="doc-alta-campo doc-alta-ancho">
      <label class="config-etiqueta" for="doc-descripcion">Aclaración</label>
      <input type="text" name="descripcion" id="doc-descripcion" maxlength="120"
             placeholder="Vigencia no mayor a 30 días (opcional)">
    </div>
    <div class="doc-alta-campo">
      <label class="config-etiqueta" for="doc-orden">Orden</label>
      <input type="number" name="orden" id="doc-orden" min="0" step="1" value="0" style="width:5rem;" required>
    </div>
    <label class="config-interruptor doc-alta-campo">
      <input type="checkbox" name="obligatorio" value="1" checked>
      <span class="config-etiqueta">Obligatorio</span>
    </label>
    <button type="submit" class="btn btn-sm">+ Agregar documento</button>
  </form>
</div>

<script>
  // El input de archivo va oculto y la etiqueta hace de control (ver
  // .config-logo-file en app.css), así que el navegador ya no escribe por su
  // cuenta el nombre del archivo elegido. Sin esta línea, tras escoger un logo
  // la pantalla se quedaría exactamente igual que antes de escogerlo.
  (function () {
    // Cada destino dice de qué input escucha, porque ya son dos —el logo y la
    // firma— y un querySelector suelto emparejaría los dos con el primero.
    document.querySelectorAll("[data-nombre-archivo]").forEach(function (destino) {
      var entrada = document.getElementById(destino.dataset.nombreArchivo);
      if (!entrada) { return; }
      var original = destino.textContent.trim();
      entrada.addEventListener("change", function () {
        destino.textContent = entrada.files.length ? entrada.files[0].name : original;
      });
    });
  })();
</script>
@endsection
