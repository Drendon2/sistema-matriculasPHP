@extends('layouts.app')

@section('title', 'Panel')

@section('content')
<h2>Panel de promotorías</h2>

@if (! $datos)
  <p class="vacio">No hay promotorías para mostrar todavía.</p>
@else
  @foreach ($datos as $item)
  {{--
    El id no es decorativo: es lo que permite que la promotoría siga abierta
    después de confirmar una matrícula. Ver public/js/acciones.js.
  --}}
  <details class="panel-item" id="promotoria-{{ $item['promotoria']->id }}">
    <summary class="panel-item-resumen">
      <span class="tag-dot {{ $item['promotoria']->area->tag_color }}"></span>{{ $item['promotoria']->nombre }}
      <span style="color:var(--ink-faint);font-weight:400;">({{ $item['promotoria']->area->nombre }})</span>
      @if ($item['pendientes'])
        <span class="estado estado-pendiente">
          {{ count($item['pendientes']) }} {{ count($item['pendientes']) == 1 ? 'pendiente' : 'pendientes' }}
        </span>
      @endif
      <svg class="perfil-seccion-chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6 6-6"/></svg>
    </summary>
    @include('panel.item', ['item' => $item])
  </details>
  @endforeach
@endif

{{--
  Los adornos del lote: la casilla de "todos" y la cuenta de marcados. El lote
  funciona sin esto —se marcan las casillas a mano y se envía—, así que aquí solo
  hay comodidad, nunca reglas.

  Va UNA sola vez y por delegación, no dentro de `panel.item`: ese se incluye una
  vez por promotoría y habría tantas copias del script como promotorías tenga el
  panel. Además, al repintar sin recarga los scripts del <main> se vuelven a
  ejecutar (ver public/js/acciones.js), y delegar en `document` es lo que hace
  que eso no acumule oyentes sobre elementos que ya no existen.
--}}
<script>
  (function () {
    function tablaDe(casilla) {
      return casilla.closest("table[data-lote-tabla]");
    }

    function casillasDe(tabla) {
      return tabla ? tabla.querySelectorAll("[data-lote-fila]") : [];
    }

    function refrescar(tabla) {
      if (!tabla) { return; }
      var form = document.getElementById(tabla.getAttribute("data-lote-tabla"));
      if (!form) { return; }

      var todas = casillasDe(tabla);
      var marcadas = 0;
      todas.forEach(function (c) { if (c.checked) { marcadas++; } });

      var cuenta = form.querySelector("[data-lote-cuenta]");
      if (cuenta) {
        cuenta.textContent = marcadas === 0 ? "Ninguno marcado"
          : marcadas + (marcadas === 1 ? " marcado" : " marcados");
      }
      var boton = form.querySelector("[data-lote-enviar]");
      if (boton) { boton.disabled = marcadas === 0; }

      var todos = tabla.querySelector("[data-lote-todos]");
      if (todos) {
        todos.checked = marcadas > 0 && marcadas === todas.length;
        // Ni marcada ni vacía: con la mitad seleccionada, cualquiera de los dos
        // estados llanos diría una mentira sobre lo que va a pasar al pulsarla.
        todos.indeterminate = marcadas > 0 && marcadas < todas.length;
      }
    }

    document.addEventListener("change", function (evento) {
      var origen = evento.target;
      if (origen.matches("[data-lote-todos]")) {
        var tabla = tablaDe(origen);
        casillasDe(tabla).forEach(function (c) { c.checked = origen.checked; });
        refrescar(tabla);
      } else if (origen.matches("[data-lote-fila]")) {
        refrescar(tablaDe(origen));
      }
    });

    // Al cargar y después de cada repintado: el navegador conserva las casillas
    // marcadas al volver atrás, y la cuenta tiene que reflejarlo.
    document.querySelectorAll("table[data-lote-tabla]").forEach(refrescar);
  })();
</script>
@endsection
