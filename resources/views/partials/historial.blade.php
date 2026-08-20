{{--
  Historial de matrículas agrupado por periodo.

  Lo comparten «Mis matrículas» (el estudiante mirando lo suyo) y la trayectoria
  que consulta el personal, que son la misma información leída por dos públicos
  distintos.

  Contexto:
    $historial ......... salida de Matricula::historialPorPeriodo()
    $modo .............. "estudiante" o "personal"; cambia qué columnas se
                         muestran. El estudiante ya sabe quién le da clase pero
                         no cuándo se inscribió; el personal, al revés.
    $periodoActualId ... solo en modo estudiante, para ofrecer «Cancelar
                         matrícula» únicamente en el periodo en curso.
--}}
@php($modo = $modo ?? 'estudiante')
@php($periodoActualId = $periodoActualId ?? null)

@foreach ($historial as $bloque)
<section class="historial-bloque">
  <div class="historial-cabecera">
    <h3 class="historial-periodo">{{ $bloque['periodo']->nombre }}</h3>
    @if ($bloque['en_curso'])
      <span class="historial-marca">En curso</span>
    @else
      <span class="historial-fechas">
        {{ $bloque['periodo']->fecha_inicio->translatedFormat('M Y') }} –
        {{ $bloque['periodo']->fecha_fin->translatedFormat('M Y') }}
      </span>
    @endif
  </div>

  <table>
    <thead>
      <tr>
        <th>Promotoría</th>
        @if ($modo === 'personal')<th>Profesor</th>@endif
        <th>Grupo</th>
        @if ($modo === 'estudiante')<th>Inscripción</th>@endif
        <th>Estado</th>
        <th></th>
        @if ($modo === 'estudiante')<th></th>@endif
      </tr>
    </thead>
    <tbody>
      @foreach ($bloque['matriculas'] as $m)
      <tr>
        <td>
          <span class="tag-dot {{ $m->promotoria->area->tag_color }}"></span>{{ $m->promotoria->nombre }}
          <span class="historial-area">{{ $m->promotoria->area->nombre }}</span>
        </td>

        @if ($modo === 'personal')
        <td>{{ $m->promotoria->profesor?->nombre_completo ?: '—' }}</td>
        @endif

        <td>
          @if ($m->grupo)
            {{ $m->grupo->nombre_con_nivel }} · {{ $m->grupo->horario }}
          @elseif ($m->estado === \App\Models\Matricula::ACTIVA)
            <span class="vacio">Por asignar</span>
          @else
            <span class="vacio">—</span>
          @endif
        </td>

        @if ($modo === 'estudiante')
        <td class="num">{{ $m->fecha->format('d/m/Y') }}</td>
        @endif

        {{--
          `estado_visible` y no `estado`: una activa de un periodo cerrado se lee
          como «Finalizada». No es un valor guardado, se deduce del calendario.
        --}}
        <td>
          <span class="estado estado-{{ $m->estado_visible }}">{{ $m->estado_visible_display }}</span>
        </td>

        {{--
          El certificado, en los dos modos: el estudiante baja el suyo y el
          personal se lo baja a quien lo pide en ventanilla. Solo aparece cuando
          hay algo que certificar —matrícula activa o finalizada— y quien mira
          tiene derecho a ella; una pendiente o una retirada no lo enseñan,
          porque no hay nada que un papel pueda afirmar de ellas.
        --}}
        <td class="historial-certificado">
          @php($certificable = in_array($m->estado_visible, [\App\Models\Matricula::ACTIVA, \App\Models\Matricula::FINALIZADA], true))
          @if ($certificable && \App\Support\Permisos::puedeCertificarMatricula($yo, $m))
            <a class="btn btn-secundario btn-sm" href="{{ route('certificado-matricula', $m) }}">
              Certificado
            </a>
          @endif
        </td>

        @if ($modo === 'estudiante')
        <td>
          @if ($m->periodo_id !== $periodoActualId)
            <span class="periodo-terminado">Periodo terminado</span>
          @elseif ($m->cancelacion_pendiente)
            <span class="periodo-terminado">En trámite</span>
          @elseif (in_array($m->estado, [\App\Models\Matricula::ACTIVA, \App\Models\Matricula::PENDIENTE], true))
            {{--
              Una matricula ACTIVA pasa por la pantalla de salida, que pide la
              encuesta; una PENDIENTE no, porque nunca tuvo clase y no hay nada
              que valorar — ahi el boton sigue siendo inmediato, como era.
            --}}
            @if ($m->estado === \App\Models\Matricula::ACTIVA)
              <a class="btn btn-retirar btn-sm" href="{{ route('mis-matriculas.confirmar-retiro', $m) }}">
                Cancelar matrícula
              </a>
            @else
              <form action="{{ route('mis-matriculas.retirar', $m) }}" method="post">
                @csrf
                <button type="submit" class="btn btn-retirar btn-sm">Retirar solicitud</button>
              </form>
            @endif
          @endif
        </td>
        @endif
      </tr>
      @endforeach
    </tbody>
  </table>
</section>
@endforeach
