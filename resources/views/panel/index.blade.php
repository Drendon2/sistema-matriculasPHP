{{--
  El envoltorio es variable porque esta portada se responde de dos formas: la
  página entera al abrir el Panel, y solo lo de dentro de <main> al responder a
  una acción sin recargar (ver `App\Support\Fragmento`). Por defecto, la de
  siempre.
--}}
@extends($disposicion ?? 'layouts.app')

@section('title', 'Panel')

@section('content')
<h2>Panel de promotorías</h2>

{{--
  El enlace a las actividades se pinta solo si hay alguna a la vista. Mientras
  la institución no use cursos ni grupos de proyección, lleva a una pantalla
  vacía y solo estorba.
--}}
@if ($cuantasActividades)
<p>
  <a class="btn btn-blanco btn-sm" href="{{ route('panel-actividades') }}">
    Cursos, talleres y grupos de proyección ({{ $cuantasActividades }})
  </a>
</p>
@endif

@if ($promotorias->isEmpty())
  <p class="vacio">No hay promotorías para mostrar todavía.</p>
@else
  {{--
    PLEGADO POR DEPARTAMENTO desde el 04/09/2026, a petición del usuario: un
    director tiene veintiuna promotorías y verlas todas seguidas es demasiada
    pantalla para encontrar una.

    Va CERRADO salvo que solo haya un departamento, porque entonces plegar no
    esconde nada y solo añade un clic — que es justo lo que le pasaría a un
    profesor que dicta en uno solo.

    El resumen dice cuántas promotorías tiene y cuántas solicitudes esperan
    dentro, y esa cifra es la mitad del asunto: plegado no puede significar
    escondido, así que desde fuera ya se ve dónde hay algo que hacer.

    El `id` no es decorativo: `acciones.js` reabre los <details> que lo llevan
    después de repintar. Sin él, confirmar una matrícula cerraría el
    departamento entero y devolvería a quien está resolviendo veinte seguidas al
    principio de todo.
  --}}
  @php($unSoloDepartamento = $porDepartamento->count() === 1)
  @foreach ($porDepartamento as $departamento => $delDepartamento)
  @php($pendientesDelDepartamento = $delDepartamento->sum(fn ($p) => $pendientes[$p->id] ?? 0))
  <details class="panel-departamento" id="departamento-{{ \Illuminate\Support\Str::slug($departamento) }}"
           @if ($unSoloDepartamento) open @endif>
    <summary class="panel-departamento-resumen">
      <span class="tag-dot {{ $delDepartamento->first()->area->tag_color }}"></span>{{ $departamento }}
      <span class="panel-departamento-cuenta">
        {{ $delDepartamento->count() }} {{ $delDepartamento->count() == 1 ? 'promotoría' : 'promotorías' }}
      </span>
      @if ($pendientesDelDepartamento)
        <span class="estado estado-pendiente">
          {{ $pendientesDelDepartamento }} {{ $pendientesDelDepartamento == 1 ? 'pendiente' : 'pendientes' }}
        </span>
      @endif
      <svg aria-hidden="true" class="perfil-seccion-chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6 6-6"/></svg>
    </summary>
  @foreach ($delDepartamento as $promotoria)
  @php($cuantasPendientes = $pendientes[$promotoria->id] ?? 0)
  {{--
    El id no es decorativo: es lo que permite que la promotoría siga abierta
    después de confirmar una matrícula. Ver public/js/acciones.js.

    El cuerpo NO viene aquí: llega al desplegar, desde `data-cuerpo`. Antes iba
    dentro y el resultado era que un director descargaba el catálogo entero
    —cientos de KB con trescientos estudiantes— para ver una lista de títulos
    plegados. Sin JavaScript el enlace del final sigue llevando a la promotoría,
    así que la pantalla no deja de funcionar, solo deja de ser cómoda.
  --}}
  <details class="panel-item" id="promotoria-{{ $promotoria->id }}"
           data-cuerpo="{{ route('panel-promotoria-cuerpo', $promotoria) }}">
    <summary class="panel-item-resumen">
      {{--
        El departamento YA NO se repite aquí: lo dice el <summary> del grupo que
        contiene a esta promotoría, y repetirlo en cada una de las veintiuna era
        la misma palabra veintiuna veces. El punto de color se queda, que es lo
        que permite reconocerlo de un vistazo sin leer.
      --}}
      <span class="tag-dot {{ $promotoria->area->tag_color }}"></span>{{ $promotoria->nombre }}
      @if ($cuantasPendientes)
        <span class="estado estado-pendiente">
          {{ $cuantasPendientes }} {{ $cuantasPendientes == 1 ? 'pendiente' : 'pendientes' }}
        </span>
      @endif
      <svg aria-hidden="true" class="perfil-seccion-chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6 6-6"/></svg>
    </summary>
    {{--
      El cuerpo llega YA PUESTO en una sola promotoría: aquella sobre la que se
      acaba de actuar. Sin esto, repintar `<main>` deja su destino sin marcar,
      `panel.js` lo vuelve a pedir y cambiar una fila cuesta un tercer viaje.

      `data-cargado` es lo que se lo dice a `panel.js` — la MISMA marca que él
      pone al traerlo por su cuenta, para que no haya dos maneras de saber que un
      cuerpo ya está.
    --}}
    @php($cuerpoPuesto = ($cuerpoDe ?? null) === $promotoria->id)
    <div data-cuerpo-destino @if ($cuerpoPuesto) data-cargado="si" @endif>
      @if ($cuerpoPuesto)
        @include('panel.item', $cuerpo)
      @else
        <p class="vacio" data-cuerpo-cargando>Cargando…</p>
        <noscript>
          <p><a class="btn btn-sm" href="{{ route('panel-promotoria-cuerpo', $promotoria) }}">Ver {{ $promotoria->nombre }}</a></p>
        </noscript>
      @endif
    </div>
  </details>
  @endforeach
  </details>
  @endforeach
@endif
@endsection

@push('scripts')
{{--
  Va como archivo y fuera de <main> a propósito. Los scripts que viven dentro se
  vuelven a ejecutar en cada repintado sin recarga (ver acciones.js), y los que
  delegan en `document` acumularían un oyente por repintado. Aquí se carga una
  vez y sobrevive a los cambios de <main>, que es justo lo que necesita.
--}}
<script src="@recurso('js/lote.js')" defer></script>
<script src="@recurso('js/panel.js')" defer></script>
@endpush
