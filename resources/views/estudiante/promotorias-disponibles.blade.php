@extends('layouts.app')

@section('title', 'Promotorías disponibles')

@section('content')
{{--
  Ojo con pegar una directiva a una palabra (`disponibles@if`): Blade no la
  compila, porque un `@` precedido de letra lo trata como parte de un correo.
  Por eso el sufijo del periodo se arma antes y aquí solo se imprime.
--}}
@php($sufijoPeriodo = $periodo ? " — {$periodo->nombre}" : '')
<h2>Promotorías disponibles{{ $sufijoPeriodo }}</h2>

@if ($clasesPorConfirmar)
<div class="renovar-llamada">
  <p>
    Tienes <strong>{{ $clasesPorConfirmar }} {{ $clasesPorConfirmar == 1 ? 'clase' : 'clases' }}</strong>
    sin confirmar. Confirmar es decir que la clase sí se dio: es lo que le permite a
    {{ $configuracion->nombre_institucion }} verificar que se dictó.
  </p>
  <a class="btn" href="{{ route('mis-clases') }}">Confirmar mis clases</a>
</div>
@endif

@if (! $periodo)
  <p class="vacio">No hay un periodo de matrícula activo en este momento.</p>
@elseif (! $promotorias)
  <p class="vacio">Todavía no hay promotorías creadas.</p>
@else

  @if ($renovables && $matriculasAbiertas)
  <div class="renovar-llamada">
    <p>
      Ya cursaste <strong>{{ $periodoAnterior->nombre }}</strong>. No tienes que volver a inscribirte:
      renueva tu matrícula con un botón y una encuesta corta.
    </p>
    <a class="btn" href="{{ route('renovar-matricula') }}">Renovar mi matrícula</a>
  </div>
  @endif

  @if (! $matriculasAbiertas)
  <p class="aviso">
    <strong>Las matrículas están cerradas en este momento.</strong>
    {{ $configuracion->nombre_institucion }} recibe inscripciones solo al principio y a mitad de año.
    Tus matrículas actuales no se ven afectadas.
  </p>
  @endif

  <p class="aviso">
    Puedes estar en hasta {{ $cuposLimite }} promotorías por periodo.
    Llevas <span class="cupo-contador">{{ $cuposUsados }} de {{ $cuposLimite }}</span> cupos ocupados.
    @if ($cuposUsados >= $cuposLimite)
      Para entrar a otra, retira antes una de tus matrículas.
    @endif
  </p>

  <table>
    <thead>
      <tr>
        <th>Promotoría</th>
        <th>Área</th>
        <th>Profesor</th>
        <th class="num">Cupo</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      @foreach ($promotorias as $item)
      <tr>
        <td>{{ $item['promotoria']->nombre }}</td>
        <td>
          <span class="tag-dot {{ $item['promotoria']->area->tag_color }}"></span>{{ $item['promotoria']->area->nombre }}
        </td>
        <td>{{ $item['promotoria']->profesor?->nombre_completo ?: 'Sin asignar' }}</td>
        <td class="num">
          @if ($item['cupo'] === null)
            <span class="cupo-cifra cupo-cifra-libre">{{ $item['ocupados'] }} / ∞</span>
          @elseif ($item['ocupados'] >= $item['cupo'])
            <span class="cupo-cifra cupo-cifra-lleno">{{ $item['ocupados'] }} / {{ $item['cupo'] }}</span>
          @else
            <span class="cupo-cifra">{{ $item['ocupados'] }} / {{ $item['cupo'] }}</span>
          @endif
        </td>
        <td>
          @if ($item['matricula'])
            <span class="estado estado-{{ $item['matricula']->estado }}">
              {{ \App\Models\Matricula::ESTADOS[$item['matricula']->estado] }}
            </span>
          @elseif (! $matriculasAbiertas)
            <span class="sin-cupo">Matrículas cerradas</span>
          @elseif ($item['llena'])
            <span class="sin-cupo">Promotoría llena</span>
          @elseif ($item['bloqueada'])
            <span class="sin-cupo">Sin cupo libre</span>
          @else
            <form action="{{ route('matricular', $item['promotoria']) }}" method="post">
              @csrf
              <button type="submit" class="btn">Matricularme</button>
            </form>
          @endif
        </td>
      </tr>
      @endforeach
    </tbody>
  </table>

  <p class="campo-info" style="margin-top:1rem;">
    Al matricularte no eliges horario: primero el profesor confirma tu inscripción,
    y luego te asigna un grupo según su disponibilidad.
  </p>
@endif
@endsection
