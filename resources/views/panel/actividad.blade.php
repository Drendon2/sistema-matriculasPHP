@extends('layouts.app')

@section('title', $actividad->nombre)

@section('content')
{{--
  El lado de quien DA la actividad. Gestión la creó y le puso fechas; aquí se
  ve quién se apuntó y se oprime «Iniciar» cuando la clase empieza de verdad.

  Dirección abre esta pantalla en solo lectura: ver es cosa suya, iniciar es de
  quien estuvo en el salón. Por eso `$dirige` gobierna cada botón — pintar uno
  que al pulsarlo rebota es peor que no pintarlo.
--}}
<a href="{{ route('panel-actividades') }}" class="volver">&larr; Cursos y actividades</a>
<h2>{{ $actividad->nombre }} <span class="tipo-chip">{{ $actividad->etiquetaTipo() }}</span></h2>

@if (! $dirige)
  <p class="aviso">
    Esto lo dirige <strong>{{ $actividad->responsable->nombre_completo }}</strong>.
    Puedes verlo, pero iniciar las sesiones y pasar lista le toca a quien está a cargo.
  </p>
@endif

{{--
  `$apuntados` llega del controlador y NO se saca de `$inscritos`.

  Antes se calculaba aquí con `->count()`, correcto mientras la lista venía
  entera. Desde que la ficha pagina, `count()` son los de la página: de esta
  cifra cuelga si el enlace sigue admitiendo gente, así que una actividad con
  200 inscritos se habría leído como que tiene 50 y el enlace habría seguido
  abierto pasado el cupo.

  Se sigue pasando precalculado por lo de siempre: `admiteInscripciones()` sin
  argumento lanza un COUNT desde la plantilla, que es lo que cerró D-03.
--}}
<div class="card">
  <h3>El enlace para inscribirse</h3>
  @if ($actividad->admiteInscripciones($apuntados))
    <p class="campo-info" style="margin-top:0;">
      Compártelo con quien quieras inscribir. No necesitan cuenta.
    </p>
  @else
    <p class="campo-info" style="margin-top:0;">
      Ya no recibe gente: {{ $actividad->abierta ? 'se llenaron los cupos' : 'dirección cerró el enlace' }}.
    </p>
  @endif
  <label class="sr-solo" for="enlace">Enlace de {{ $actividad->nombre }}</label>
  {{-- El boton de copiar lo añade `copiar-enlace.js`: sin JavaScript no serviría. --}}
  <div class="enlace-fila">
    <input class="enlace-copiable" type="text" id="enlace" readonly value="{{ $actividad->enlace() }}">
  </div>
</div>

<div class="card">
  <h3>Sesiones</h3>

  {{--
    Un grupo de proyección no tiene fechas puestas: ensaya cuando toca y la
    sesión nace al oprimir el botón, igual que una clase de promotoría. Por eso
    aquí el botón va arriba y no en una fila.
  --}}
  @if (! $actividad->llevaFechas() && $dirige)
  <form method="post" action="{{ route('panel-actividad-iniciar-hoy', $actividad) }}">
    @csrf
    <button type="submit" class="btn">Iniciar {{ $actividad->etiquetaSesion() }} de hoy</button>
  </form>
  @endif

  @if ($sesiones->isEmpty())
    <p class="vacio">
      @if ($actividad->llevaFechas())
        Todavía no tiene fechas. Las pone dirección, en Gestión → Cursos y talleres.
      @else
        Todavía no se ha hecho ningún {{ $actividad->etiquetaSesion() }}.
      @endif
    </p>
  @else
  <table>
    <thead>
      <tr>
        <th>{{ $actividad->llevaFechas() ? 'Clase' : 'Fecha' }}</th>
        <th>Estado</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      @foreach ($sesiones as $i => $sesion)
      <tr>
        <td>
          @if ($actividad->llevaFechas())
            <strong>{{ $i + 1 }}.</strong>
          @endif
          {{ $sesion->fecha->format('d/m/Y') }}
        </td>
        <td>
          @if ($sesion->yaEmpezo())
            <span class="estado estado-activa">Iniciada</span>
            <span class="campo-info" style="margin:0;display:block;">
              {{ $sesion->iniciada_en->format('d/m/Y \a \l\a\s H:i') }}
              @if ($sesion->iniciadaPor)
                · {{ $sesion->iniciadaPor->nombre_completo }}
              @endif
            </span>
          @else
            <span class="estado estado-pendiente">Sin iniciar</span>
          @endif
        </td>
        <td style="text-align:right;">
          @if ($sesion->yaEmpezo())
            {{--
              La lista se ofrece a todos los que ven la pantalla, no solo a
              quien dirige: dirección la abre en solo lectura, que es
              exactamente para lo que necesita entrar.
            --}}
            <a class="btn btn-blanco btn-sm" href="{{ route('panel-actividad-lista', $sesion) }}">
              @if ($sesion->asistencias_count)
                Lista ({{ $sesion->asistencias_count }})
              @else
                Pasar lista
              @endif
            </a>
          @elseif ($dirige)
          <form method="post" action="{{ route('panel-actividad-iniciar', $sesion) }}">
            @csrf
            <button type="submit" class="btn btn-sm">Iniciar</button>
          </form>
          @endif
        </td>
      </tr>
      @endforeach
    </tbody>
  </table>
  @endif
</div>

<div class="card">
  <h3>Inscritos <span class="cupo-cifra">{{ $apuntados }}</span></h3>

  @if ($inscritos->isEmpty())
    <p class="vacio">Todavía no se ha inscrito nadie por el enlace.</p>
  @else
  {{--
    `.tabla-personas` desde el 11/09/2026, cuando esta tabla ganó una acción:
    bajo 640px cada fila se vuelve ficha. Es una lista de registros y no una
    rejilla —la posición de la celda no es el dato— y sin esto el botón de
    «Certificado» quedaba al otro lado de un arrastre horizontal en el teléfono,
    que es donde se usa casi todo esto. Es la trampa que este proyecto ya pagó
    dos veces.
  --}}
  <table class="tabla-personas tabla-catalogo">
    <thead>
      <tr>
        <th>Nombre</th>
        <th class="num">Edad</th>
        <th>Teléfono</th>
        <th>Correo</th>
        <th>Asistencia</th>
        @if ($actividad->llevaFechas())
        <th></th>
        @endif
      </tr>
    </thead>
    <tbody>
      @foreach ($inscritos as $inscrito)
      @php($suya = $asistencias[$inscrito->id] ?? ['sesiones' => 0, 'asistidas' => 0, 'porcentaje' => 0, 'certificable' => false])
      <tr>
        <td data-celda="detalle">
          {{ $inscrito->nombre_completo }}
          {{--
            Quien además es estudiante de la casa. Se sabe porque el documento
            coincidió, y decirlo aquí es lo que permite saber cuántos de los
            propios están en el coro.
          --}}
          @if ($inscrito->perfil)
            <span class="campo-info" style="margin:0;display:block;">Estudiante de la institución</span>
          @endif
        </td>
        <td class="num" data-label="Edad">
          @if ($inscrito->fecha_nacimiento)
            {{ \App\Models\Perfil::edadDe($inscrito->fecha_nacimiento) }}
          @else
            <span class="vacio">—</span>
          @endif
        </td>
        <td data-label="Teléfono">{{ $inscrito->telefono ?: '—' }}</td>
        <td data-label="Correo">{{ $inscrito->correo ?: '—' }}</td>
        <td data-label="Asistencia">
          @if ($suya['sesiones'] === 0)
            {{-- Sin lista tomada no hay cifra que dar, y un «0%» diría de cada
                 inscrito algo falso: que no fue, cuando lo que pasa es que
                 todavía nadie ha pasado lista. --}}
            <span class="vacio">Sin lista tomada</span>
          @else
            {{ $suya['asistidas'] }} de {{ $suya['sesiones'] }}
            <span class="lista-nota">({{ $suya['porcentaje'] }}%)</span>
          @endif
        </td>
        @if ($actividad->llevaFechas())
        {{--
          `data-celda="accion"` y no `data-label`: en la ficha del teléfono esto
          no es un dato con rótulo, es lo que se pulsa. Y cuando no se puede, el
          renglón dice POR QUÉ en vez de quedarse vacío — si no, quien mira una
          fila sin botón no sabe si le falta asistencia o si el sistema falló.
        --}}
        <td data-celda="accion" class="lista-acciones">
          @if ($suya['certificable'])
            <a class="btn btn-sm" href="{{ route('certificado-actividad', [$actividad, $inscrito]) }}">Certificado</a>
          @elseif ($suya['sesiones'] > 0)
            <span class="lista-nota">Menos del {{ $minimoCertificado }}%</span>
          @endif
        </td>
        @endif
      </tr>
      @endforeach
    </tbody>
  </table>
  {{ $inscritos->links() }}
  @endif
</div>
@endsection

@push('scripts')
{{--
  Solo añade el botón de copiar al enlace. Se comparte por WhatsApp y casi
  siempre desde el celular, donde seleccionar un texto largo a dedo es lo peor
  de la pantalla.
--}}
<script src="@recurso('js/copiar-enlace.js')" defer></script>
@endpush
