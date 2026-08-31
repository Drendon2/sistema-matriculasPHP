{{--
  "Cómo va" el periodo en curso: lo primero que se ve al entrar a Gestión, por
  delante incluso de Departamentos — es el pulso del periodo, no parte del
  catálogo académico. Recibe $periodo/$resumen de `MatriculasController::datos()`.
--}}
@if ($periodo && $resumen)
<div class="card">
  <h3>Cómo va {{ $periodo->nombre }}</h3>
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
  @if ($resumen['periodo_anterior'])
    <p class="campo-info">
      «Antiguos sin renovar» son estudiantes que estuvieron activos en
      {{ $resumen['periodo_anterior']->nombre }} y todavía no aparecen en {{ $periodo->nombre }}.
    </p>
  @endif
</div>
@endif
