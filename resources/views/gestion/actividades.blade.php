@extends('layouts.app')

@section('title', $titulo)

@section('content')
{{--
  El listado de actividades: cursos y talleres en una pantalla, grupos de
  proyección en la otra. Las dos usan esta plantilla y solo cambian los textos
  que trae el controlador.

  NO reusa `gestion.lista`. Aquella sirve al árbol académico —departamentos,
  periodos, promotorías, grupos— y todos enseñan lo mismo: un nombre, qué
  cuelga de él y qué impide borrarlo. Una actividad tiene además un cupo y un
  enlace que se comparte, y estirar la plantilla común para que quepan es como
  se acaba con una plantilla que pregunta de qué pantalla se trata.
--}}
<a href="{{ route('gestion-inicio') }}" class="volver">&larr; Gestión</a>
<h2>{{ $titulo }}</h2>
<p><a class="btn" href="{{ route($ruta_nuevo) }}">+ Nuevo</a></p>

@if ($actividades->isEmpty())
  <p class="vacio">Todavía no hay nada aquí.</p>
@else
<table>
  <thead>
    <tr>
      <th>Nombre</th>
      <th>Responsable</th>
      <th class="num">Cupo</th>
      <th></th>
    </tr>
  </thead>
  <tbody>
    @foreach ($actividades as $actividad)
    <tr>
      <td>
        {{ $actividad->nombre }}
        <span class="tipo-chip">{{ $actividad->etiquetaTipo() }}</span>
        {{--
          Cuántas clases tiene, y el aviso cuando todavía no tiene ninguna. Un
          curso sin fechas está a medio crear: no se puede iniciar nada ni
          decirle a nadie cuándo es, y sin este renglón la fila se ve igual que
          la de uno terminado.

          Se precalcula con la directiva PHP en línea: pegar una directiva a
          una letra la deja sin compilar.
        --}}
        @php($cuantas = $actividad->sesiones_count)
        @if ($actividad->llevaFechas())
          <span class="campo-info" style="margin:0;display:block;">
            @if ($cuantas)
              {{ $cuantas }} {{ $cuantas == 1 ? 'clase' : 'clases' }}
            @else
              <strong>Sin fechas todavía.</strong> Ponlas para poder iniciarlas.
            @endif
          </span>
        @endif
        @if (! $actividad->abierta)
          <span class="campo-info" style="margin:0;display:block;">Enlace cerrado</span>
        @endif
      </td>
      <td>
        @if (\App\Support\Permisos::puedeVerFicha($yo, $actividad->responsable))
          <a href="{{ route('detalle-usuario', $actividad->responsable) }}">{{ $actividad->responsable->nombre_completo }}</a>
        @else
          {{ $actividad->responsable->nombre_completo }}
        @endif
      </td>
      <td class="num">
        {{-- Sin tope no es cero: es que nadie puso uno. --}}
        @if ($actividad->cupo_maximo === null)
          <span class="cupo-cifra cupo-cifra-libre">∞</span>
        @else
          <span class="cupo-cifra">{{ $actividad->cupo_maximo }}</span>
        @endif
      </td>
      <td style="text-align:right;white-space:nowrap;">
        @if ($actividad->llevaFechas())
          <a href="{{ route('actividad-curso-fechas', $actividad) }}">Fechas</a>
          &nbsp;·&nbsp;
        @endif
        <a href="{{ route($ruta_editar, $actividad) }}">Editar</a>
        &nbsp;·&nbsp;
        <a href="{{ route($ruta_eliminar, $actividad) }}" style="color:var(--danger);">Eliminar</a>
      </td>
    </tr>
    @endforeach
  </tbody>
</table>
@endif
@endsection
