{{--
  "Cómo va" el periodo en curso: lo primero que se ve al entrar a Gestión, por
  delante incluso de Departamentos — es el pulso del periodo, no parte del
  catálogo académico. Recibe $periodo/$resumen de `MatriculasController::datos()`.

  El título va suelto, fuera de tarjeta —es cabecera de sección, no contenido—;
  las cifras sí van en su propia tarjeta, y las alertas (seguimiento, brecha de
  edad, cancelaciones) en una fila de tarjetas-enlace, todas con el mismo trato.
--}}
@if ($periodo && $resumen)
<h2>Cómo va {{ $periodo->nombre }}</h2>

<div class="card card-translucida">
  <div class="perfil-stats">
    <div>
      <span class="perfil-stat-num">{{ $resumen['estudiantes'] }}</span>
      <span class="perfil-stat-label">Estudiantes</span>
    </div>
    <div>
      <span class="perfil-stat-num">{{ $resumen['activas'] }}</span>
      <span class="perfil-stat-label">Matrículas activas</span>
    </div>
    <div>
      <span class="perfil-stat-num">{{ $resumen['pendientes'] }}</span>
      <span class="perfil-stat-label">Por confirmar</span>
    </div>
    @if ($resumen['periodo_anterior'])
    <div>
      <span class="perfil-stat-num">{{ $resumen['por_renovar'] }}</span>
      <span class="perfil-stat-label">Antiguos sin renovar</span>
    </div>
    @endif
  </div>
</div>

{{--
  Alertas: lo que en esta portada pide atención, más el acceso directo a
  Cancelaciones — las tres como tarjeta-enlace, mismo trato visual. El de
  seguimiento solo lo ve administración —el mismo corte que ya tiene esa
  sección en Estadísticas—; el de brecha de edad y el de cancelaciones no
  tienen ese reparo de privacidad, así que los ve también dirección.
--}}
<div class="resumen-alertas">
  @if ($yo->rol === 'administrador' && $seguimientoPendiente > 0)
    <a class="tarjeta-enlace" href="{{ route('gestion-estadisticas') }}#seguimiento">
      <span class="num">{{ $seguimientoPendiente }}</span>
      {{ $seguimientoPendiente === 1 ? 'encuesta' : 'encuestas' }} para seguimiento
    </a>
  @endif
  @if ($gruposConBrechaDeEdad > 0)
    <a class="tarjeta-enlace" href="{{ route('grupo-brecha-edad') }}">
      <span class="num">{{ $gruposConBrechaDeEdad }}</span>
      {{ $gruposConBrechaDeEdad === 1 ? 'grupo' : 'grupos' }} con brecha de edad
    </a>
  @endif
  <a class="tarjeta-enlace" href="{{ route('gestion-cancelaciones') }}">
    @if ($cancelacionesPendientes)<span class="num">{{ $cancelacionesPendientes }}</span>@endif
    Cancelaciones
  </a>
</div>
@endif
