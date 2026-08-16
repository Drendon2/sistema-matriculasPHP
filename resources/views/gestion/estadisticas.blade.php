@extends('layouts.app')

@section('title', 'Estadísticas')

@section('content')
<a href="{{ route('gestion-inicio') }}" class="volver">&larr; Gestión</a>
<h2>Estadísticas</h2>

<div class="card" style="margin-bottom: 2.2rem;">
  <div class="dash-resumen">
    <div>
      <span class="dash-stat-num">{{ $totalEstudiantesActivos }}</span>
      <span class="dash-stat-label">Estudiantes activos</span>
    </div>
    <div>
      <span class="dash-stat-num">{{ $totalPromotorias }}</span>
      <span class="dash-stat-label">Promotorías</span>
    </div>
    <div>
      <span class="dash-stat-num">{{ $totalGrupos }}</span>
      <span class="dash-stat-label">Grupos</span>
    </div>
    <div>
      <span class="dash-stat-num">{{ $totalEncuestas }}/{{ $totalConRol }}</span>
      <span class="dash-stat-label">Encuestas completadas</span>
    </div>
  </div>
</div>

@php($sufijoPeriodo = $periodoActual ? $periodoActual->nombre : '')
<h3 style="margin-top: 0.5rem;">
  Estudiantes por departamento y promotoría
  @if ($periodoActual)<span class="h4-nota">— {{ $sufijoPeriodo }}</span>@endif
</h3>
@if (! $periodoActual)
  <p class="vacio">No hay un periodo en curso, así que no hay nada que medir.</p>
@elseif (! $arbolDepartamentos)
  <p class="vacio">Todavía no hay matrículas en {{ $periodoActual->nombre }}.</p>
@else
  <p class="campo-info" style="margin: -0.4rem 0 1rem;">
    <strong>Sigue</strong> es quién continúa matriculado hoy y <strong>deja</strong> quién se
    retiró, ambos dentro de {{ $periodoActual->nombre }}.
    @if ($periodoPrevio)
      <strong>No volvió</strong> es otra cosa: de quienes cursaron {{ $periodoPrevio->nombre }},
      qué parte no regresó a esa misma promotoría.
    @endif
  </p>
  {{--
    La leyenda va una sola vez y no por fila: es lo que hace decodificable la
    forma de cada columna, y repetirla 26 veces la volvería el ruido que estas
    micro-gráficas vinieron a quitar.
  --}}
  <div class="perm-leyenda">
    <span><i class="perm-col perm-sigue"></i>Sigue</span>
    <span><i class="perm-col perm-deja"></i>Deja</span>
    @if ($periodoPrevio)<span><i class="perm-col perm-novolvio"></i>No volvió</span>@endif
  </div>
  @foreach ($arbolDepartamentos as $d)
  {{--
    Arranca plegado salvo que el departamento esté perdiendo la mitad o más de su
    gente: acortar la página no puede costar esconder justo la fila que hay que
    mirar. Es `<details>` nativo, sin JavaScript, como la encuesta de más abajo.
  --}}
  <details class="dash-departamento" id="dep-{{ $d['id'] }}" @if ($d['pct_desercion'] >= 50) open @endif>
    <summary class="stat-bar-fila dep-cabecera">
      <svg class="dep-chevron" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>
      <span class="stat-bar-etiqueta"><span class="tag-dot {{ $d['tag_class'] }}"></span>{{ $d['nombre'] }}</span>
      <div class="stat-bar-pista">
        <div class="stat-bar-relleno {{ $d['tag_class'] }}" style="width: {{ $d['porcentaje'] }}%;"></div>
      </div>
      <span class="stat-bar-num">{{ $d['total'] }}</span>
      @include('gestion.permanencia', ['fila' => $d, 'hayPrevio' => $periodoPrevio, 'tono' => $d['tag_class']])
    </summary>
    <div class="dash-promotorias">
      @foreach ($d['promotorias'] as $p)
      <div class="stat-bar-fila">
        <span class="stat-bar-etiqueta">{{ $p['etiqueta'] }}</span>
        <div class="stat-bar-pista">
          <div class="stat-bar-relleno" style="width: {{ $p['porcentaje'] }}%;"></div>
        </div>
        <span class="stat-bar-num">{{ $p['total'] }}</span>
        @include('gestion.permanencia', ['fila' => $p, 'hayPrevio' => $periodoPrevio, 'tono' => ''])
      </div>
      @endforeach
    </div>
  </details>
  @endforeach
@endif

<div class="dash-grid-2">
  <div>
    <h3>Grupos por nivel</h3>
    @include('gestion.barras', ['filas' => $gruposPorCurso])
  </div>
  <div>
    <h3>Estudiantes por periodo</h3>
    @include('gestion.barras', ['filas' => $estudiantesPorPeriodo])
  </div>
</div>

<details class="perfil-seccion" id="bloque-encuesta" style="max-width: none; margin-top: 2.6rem;">
  <summary class="perfil-seccion-cabecera">
    <span class="perfil-seccion-icono icono-encuesta" aria-hidden="true">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <rect x="6" y="4" width="12" height="17" rx="2"/>
        <path d="M9 4V3a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v1"/>
        <path d="M9 11h6M9 15h6M9 19h3"/>
      </svg>
    </span>
    <h3 style="margin:0;">Encuesta demográfica</h3>
    <svg class="perfil-seccion-chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6 6-6"/></svg>
  </summary>

  <p class="campo-info" style="margin: 0.9rem 0 1.3rem;">
    {{ $totalEncuestas }} de {{ $totalConRol }} personas con rol asignado han diligenciado la
    encuesta. Estas cifras son agregadas — ninguna respuesta individual se muestra aquí.
  </p>

  {{--
    Una encuesta a medias contaba como diligenciada y no lo estaba. Sin este
    aviso, la única señal era que las barras de esas preguntas sumaban menos que
    el total, y ni siquiera eso se veía.
  --}}
  @if ($encuestasIncompletas)
  <p class="aviso" style="margin-bottom: 1.3rem;">
    <strong>{{ $encuestasIncompletas }} de esas {{ $totalEncuestas }}
    {{ $encuestasIncompletas == 1 ? 'está incompleta' : 'están incompletas' }}</strong>
    y {{ $encuestasIncompletas == 1 ? 'aparece' : 'aparecen' }} como «Sin responder» en las
    preguntas que le faltan. Cada persona ve lo que le falta en «Mi perfil» y puede
    completarlo desde ahí. No se rellenan solas ni desde aquí — es información de la
    persona, no del sistema.
  </p>
  @endif

  <div class="dash-grid-2">
    <div>
      <h4>Género</h4>
      @include('gestion.torta', ['torta' => $generoTorta])
    </div>
    <div>
      <h4>Estrato</h4>
      @include('gestion.barras', ['filas' => $estratoStats])
    </div>
  </div>

  <h4>Autorización de tratamiento de datos</h4>
  @if ($totalEncuestas)
    <div class="dash-split">
      <div class="dash-split-si" style="width: {{ $pctAutorizaSi }}%;"></div>
      <div class="dash-split-no" style="width: {{ $pctAutorizaNo }}%;"></div>
    </div>
    <div class="dash-split-leyenda">
      <span><span class="punto" style="background: var(--accent);"></span>Autorizan ({{ $autorizaSi }})</span>
      <span><span class="punto" style="background: var(--border-strong);"></span>No autorizan ({{ $autorizaNo }})</span>
    </div>
  @else
    <p class="vacio">Sin respuestas todavía.</p>
  @endif

  {{--
    Las cifras cuadran con el total de encuestas porque quien no cae en ninguna
    opción entra como «Sin responder» al final. Eso no siempre significa que la
    persona se saltara la pregunta: también cae ahí quien tiene la encuesta a
    medias.
  --}}
  <div class="dash-grid-2" style="margin-top: 1.7rem;">
    <div>
      <h4>Nivel educativo</h4>
      @include('gestion.barras', ['filas' => $nivelEducativoStats])
    </div>
    <div>
      <h4>Ocupación</h4>
      @include('gestion.barras', ['filas' => $ocupacionStats])
    </div>
  </div>

  <div class="dash-grid-2" style="margin-top: 1.3rem;">
    <div>
      <h4>Zona <span class="h4-nota">(opcional)</span></h4>
      @include('gestion.torta', ['torta' => $zonaTorta])
    </div>
    <div>
      <h4>Afiliación a salud <span class="h4-nota">(opcional)</span></h4>
      @include('gestion.barras', ['filas' => $afiliacionSaludStats])
    </div>
  </div>

  <div class="dash-grid-2" style="margin-top: 1.3rem;">
    <div>
      <h4>Grupo étnico <span class="h4-nota">(dato sensible y opcional)</span></h4>
      @include('gestion.barras', ['filas' => $grupoEtnicoStats])
    </div>
    <div>
      <h4>Discapacidad <span class="h4-nota">(dato sensible y opcional)</span></h4>
      @include('gestion.barras', ['filas' => $discapacidadStats])
    </div>
  </div>

  <h4 style="margin-top: 1.3rem;">
    Víctima del conflicto armado <span class="h4-nota">(dato sensible y opcional)</span>
  </h4>
  @include('gestion.barras', ['filas' => $victimaConflictoStats])
</details>
@endsection
