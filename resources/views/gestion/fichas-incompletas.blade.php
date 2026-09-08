@extends('layouts.app')

@section('title', 'Fichas por completar')

@section('content')
{{--
  LA TERCERA BANDEJA: a quién le falta algo por completar.

  Pantalla propia y no una sección más dentro de Alertas, y eso salió de medir:
  en producción son ~810 personas de 839. Metidas en la bandeja la convertirían
  en un muro — es lo que ya pasó con las 596 clases no dictadas.

  Los dos filtros no son adorno. El de PROMOTORÍA existe para un uso concreto:
  sacar la lista de una y pedirle a su profesor que persiga esos datos, y por eso
  la pantalla dice también quién la dicta. El de MOTIVO, porque sin él quien
  busca al único menor sin acudiente tendría que recorrer 804 consentimientos.
--}}
<a href="{{ route('gestion-cancelaciones') }}" class="volver">&larr; Alertas y cancelaciones</a>
<h2>Fichas por completar</h2>

<p class="campo-ayuda" style="margin-bottom:1.4rem;">
  Personas a las que les falta algo por entregar, contestar o corregir. Nadie está
  bloqueado por estar aquí: la lista existe para saber a quién pedírselo.
</p>

<form method="get">
  <div class="filtros">
    {{--
      El de promotoría va PRIMERO porque es el que da sentido a la pantalla: se
      filtra por una promotoría para mandarle la lista a su profesor.
    --}}
    <div class="filtro">
      <label for="f-promotoria">Promotoría</label>
      <select name="promotoria" id="f-promotoria">
        <option value="">Todas</option>
        @foreach ($promotorias->groupBy('area.nombre') as $areaNombre => $delArea)
        <optgroup label="{{ $areaNombre }}">
          @foreach ($delArea as $p)
            <option value="{{ $p->id }}" @selected($promotoria === $p->id)>{{ $p->nombre }}</option>
          @endforeach
        </optgroup>
        @endforeach
      </select>
    </div>

    <div class="filtro">
      <label for="f-motivo">Qué le falta</label>
      <select name="motivo" id="f-motivo">
        <option value="">Cualquier cosa</option>
        @foreach ($motivos as $clave => $etiqueta)
          @if ($porMotivo[$clave] > 0)
            <option value="{{ $clave }}" @selected($motivo === $clave)>
              {{ $etiqueta }} ({{ $porMotivo[$clave] }})
            </option>
          @endif
        @endforeach
      </select>
    </div>

    <div class="filtro filtro-acciones filtros-botones">
      <button type="submit" class="btn btn-sm">Filtrar</button>
      @if ($hayFiltros)
        <a class="btn btn-blanco btn-sm" href="{{ route('gestion-fichas-incompletas') }}">Limpiar</a>
      @endif
    </div>
  </div>
</form>

@php($elegida = $promotoria ? $promotorias->firstWhere('id', $promotoria) : null)

@if ($elegida)
  {{--
    A QUIÉN MANDARLE ESTA LISTA. Es la razón por la que existe el filtro, así que
    se dice en voz alta y no se deja deducir del nombre de la promotoría.
  --}}
  <p class="campo-ayuda" style="margin-bottom:1.2rem;">
    @if ($elegida->profesor)
      Esta lista es de <strong>{{ $elegida->nombre }}</strong>, que dicta
      <strong>{{ $elegida->profesor->nombre_completo }}</strong>@if ($elegida->profesor->telefono) ·
      {{ $elegida->profesor->telefono }}@endif.
    @else
      <strong>{{ $elegida->nombre }}</strong> no tiene profesor asignado, así que no
      hay a quién pedirle que persiga estos datos.
    @endif
  </p>
@endif

@if ($hayFiltros)
  <p class="campo-ayuda" style="margin-bottom:1rem;">
    {{ $filtradas }} de {{ $total }} fichas.
  </p>
@endif

@if (! count($fichas))
  <p class="vacio">
    @if ($hayFiltros)
      Nadie cumple ese filtro. Prueba a quitarlo.
    @else
      No falta nada: todas las fichas están completas.
    @endif
  </p>
@else
{{--
  `.tabla-personas` porque bajo 640px esto tiene que dejar de ser tabla. Es una
  lista de registros, no una rejilla: la posición de la celda no es el dato.
--}}
<table class="tabla-personas tabla-catalogo">
  <thead>
    <tr>
      <th>Persona</th>
      <th>Teléfono</th>
      <th>Qué le falta</th>
    </tr>
  </thead>
  <tbody>
    {{--
      Las filas son ARRAYS PLANOS y no modelos, y eso no es un capricho del que
      las armó: hidratar los 841 perfiles con sus relaciones reventaba la
      memoria. Cada fila trae ya todo lo que se pinta aquí, así que esta
      plantilla no va a buscar nada — y por eso tampoco puede provocar un N+1.
      El porqué entero está en `Support\FichasIncompletas`.
    --}}
    @foreach ($fichas as $ficha)
    <tr>
      <td data-celda="detalle">
        <span class="lista-nombre">
          @if ($ficha['es_estudiante'])
            <a href="{{ route('detalle-estudiante', $ficha['id']) }}">{{ $ficha['nombre'] }}</a>
          @else
            <a href="{{ route('detalle-usuario', $ficha['id']) }}">{{ $ficha['nombre'] }}</a>
          @endif
        </span>
        <span class="lista-nota lista-nota-bloque">
          {{ $ficha['rol_display'] }}
          @if ($ficha['promotorias'])
            ·
            @foreach ($ficha['promotorias'] as $vinculo)
              {{ $vinculo['nombre'] }}@if (! $loop->last), @endif
            @endforeach
          @endif
        </span>
      </td>
      <td data-label="Teléfono">
        {{--
          El teléfono se pinta aquí a petición del encargo: la lista se usa para
          llamar. Es el mismo dato que ya enseña «Posibles abandonos» en la
          pantalla de al lado, para el mismo público y con el mismo fin.

          De un MENOR se da además el del acudiente, porque es a quien hay que
          llamar de verdad — es la razón por la que la institución lo registra.
        --}}
        @if ($ficha['telefono'] !== '')
          {{ $ficha['telefono'] }}
        @else
          <span class="lista-nota">Sin teléfono</span>
        @endif
        @if ($ficha['es_menor'] && $ficha['acudiente'] && $ficha['acudiente']['telefono'] !== '')
          <span class="lista-nota lista-nota-bloque">
            Acudiente: {{ $ficha['acudiente']['nombre'] }} ·
            {{ $ficha['acudiente']['telefono'] }}
          </span>
        @endif
      </td>
      <td data-label="Qué le falta">
        <ul class="faltantes">
          @foreach ($ficha['detalles'] as $detalle)
            <li>{{ $detalle }}</li>
          @endforeach
        </ul>
      </td>
    </tr>
    @endforeach
  </tbody>
</table>

{{ $fichas->links() }}
@endif
@endsection
