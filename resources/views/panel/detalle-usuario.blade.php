@extends('layouts.app')

@section('title', $objetivo->nombre_completo)

@section('content')
<a href="{{ route('panel') }}" class="volver">&larr; Volver al panel</a>
<h2>Ficha de usuario</h2>

<div class="carne" style="margin-bottom:1.2rem;">
  @if ($objetivo->foto_perfil)
    <img class="carne-foto" style="width:64px;height:64px;" src="{{ route('ver-foto', $objetivo) }}" alt="">
  @else
    <div class="carne-foto-vacia" style="width:64px;height:64px;"></div>
  @endif
  <div class="carne-datos">
    <div class="carne-nombre" style="font-size:1.1rem;">
      {{ $objetivo->nombre_completo }}
      @if ($papelesPendientes)
      @php($faltan = count($papelesPendientes))
      <span class="estado estado-papeles"
            title="Le faltan: {{ implode(', ', array_map(fn ($d) => $d->nombre, $papelesPendientes)) }}">
        Faltan {{ $faltan }} {{ $faltan == 1 ? 'papel' : 'papeles' }}
      </span>
      @endif
    </div>
    <div class="carne-detalle">
      {{ $objetivo->user->username }} ·
      {{ $objetivo->rol ? $objetivo->rol_display : 'Pendiente de rol' }} ·
      {{ $objetivo->user->activo ? 'Activo' : 'Inactivo' }}
    </div>
  </div>
</div>

@if (! $objetivo->rol)
  <p class="aviso">
    Esta cuenta se creó por autorregistro y todavía no tiene rol asignado, así que su
    titular no puede entrar al sistema. Asígnaselo desde Gestión &rarr; Usuarios.
  </p>
@endif

<h3>Contacto</h3>
@if ($veContacto)
<table style="max-width:520px;">
  <tr><th>Edad</th><td>{{ $objetivo->edad }} años</td></tr>
  <tr><th>Teléfono</th><td>{{ $objetivo->telefono }}</td></tr>
  {{--
    El correo va con el resto del contacto y bajo la misma puerta: es un dato de
    contacto, no de identidad, así que lo ve quien ve el teléfono. Un campo que
    se puede rellenar y no se ve en ninguna parte no sirve para nada.
  --}}
  <tr>
    <th>Correo</th>
    <td>@if ($objetivo->user->email){{ $objetivo->user->email }}@else<span class="vacio">—</span>@endif</td>
  </tr>
  @if ($esEstudiante)
  <tr>
    <th>Acudiente</th>
    <td>
      @if ($acudiente)
        {{ $acudiente->nombre }} ({{ $acudiente->telefono }})
      @elseif ($objetivo->es_menor)
        <span class="vacio">Sin acudiente registrado — es menor de edad</span>
      @else
        <span class="vacio">—</span>
      @endif
    </td>
  </tr>
  @endif
</table>
@else
  {{--
    Ver la matriz de visibilidad en `Perfil`: la edad, el teléfono y el acudiente
    de un estudiante son del administrador, del director y del profesor de SUS
    promotorías. Poder abrir la ficha no los desbloquea.
  --}}
  <p class="vacio">
    Edad, teléfono y acudiente solo los ve el personal a cargo de este estudiante.
  </p>
@endif

@if ($esEstudiante)
<h3>Trayectoria</h3>
@if ($resumen['periodos'])
<div class="dash-resumen historial-resumen">
  <div>
    <span class="dash-stat-num">{{ $resumen['periodos'] }}</span>
    <span class="dash-stat-label">
      {{ $resumen['periodos'] == 1 ? 'Periodo cursado' : 'Periodos cursados' }}
    </span>
  </div>
  <div>
    <span class="dash-stat-num">{{ $resumen['promotorias'] }}</span>
    <span class="dash-stat-label">{{ $resumen['promotorias'] == 1 ? 'Promotoría' : 'Promotorías' }}</span>
  </div>
  @if ($resumen['desde'])
  <div>
    <span class="dash-stat-num">{{ $resumen['desde']->nombre }}</span>
    <span class="dash-stat-label">Desde</span>
  </div>
  @endif
</div>
@else
  <p class="vacio">Todavía no ha cursado ningún periodo.</p>
@endif
<p>
  <a class="btn btn-secundario btn-sm" href="{{ route('historial-estudiante', $objetivo) }}">
    Ver trayectoria completa
  </a>
  {{--
    El certificado que reúne todas las promotorías vigentes. No se le ofrece al
    profesor —ni siquiera al que tiene a este estudiante en clase— porque lista
    también las promotorías que esta ficha le esconde. El de UNA matrícula, que
    es el que a él le pueden pedir, está en la trayectoria.
  --}}
  @if (\App\Support\Permisos::puedeCertificarTodo($yo, $objetivo))
  <a class="btn btn-secundario btn-sm" href="{{ route('certificado-todo', $objetivo) }}">
    Certificado de matrícula
  </a>
  @endif
  @if ($yo->rol === 'administrador')
  <a class="btn btn-secundario btn-sm" href="{{ route('detalle-estudiante', $objetivo) }}">
    Ver encuesta y documento
  </a>
  @endif
</p>
@endif

{{--
  Sale del vínculo, no del rol: un director que además dicta tiene las suyas.
  Al profesor se le enseña la sección aunque esté vacía, porque ahí el hueco es
  el dato — a nadie más le dice nada.
--}}
@if ($objetivo->rol === 'profesor' || count($promotorias))
<h3>Promotorías a cargo</h3>
@if (! count($promotorias))
  <p class="vacio">No tiene promotorías asignadas.</p>
@else
<table>
  <thead>
    <tr><th>Promotoría</th><th>Departamento</th><th>Grupos</th></tr>
  </thead>
  <tbody>
    @foreach ($promotorias as $p)
    <tr>
      <td><span class="tag-dot {{ $p->area->tag_color }}"></span>{{ $p->nombre }}</td>
      <td>{{ $p->area->nombre }}</td>
      <td>
        @forelse ($p->grupos as $g)
          {{ $g->nombre_con_nivel }} · {{ $g->horario }}@if (! $loop->last)<br>@endif
        @empty
          <span class="vacio">Sin grupos creados</span>
        @endforelse
      </td>
    </tr>
    @endforeach
  </tbody>
</table>
@endif
@endif

{{--
  Solo si hay algo que contar. Un panel de ceros no informa de nada y además
  miente por omisión: en una ficha sin clases todavía no se distingue "no ha
  faltado nunca" de "no ha empezado el periodo".
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

@if ($puedeGestionarUsuarios)
<p style="margin-top:1.5rem;">
  <a class="btn btn-sm" href="{{ route('usuario-editar', $objetivo) }}">Editar usuario</a>
</p>
@endif
@endsection

@push('scripts')
{{-- Solo el gesto de deslizar entre periodos. Las flechas funcionan sin esto. --}}
<script src="{{ asset('js/periodo.js') }}" defer></script>
@endpush
