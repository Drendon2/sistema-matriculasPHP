@extends('layouts.app')

@section('title', 'Mis compañeros')

@section('content')
<h2>Mis compañeros</h2>

@if (! $clases)
  <p class="vacio">No estás matriculado en ninguna promotoría por ahora.</p>
@else
  @foreach ($clases as $item)
  <h3>{{ $item['promotoria']->nombre }}</h3>
  {{--
    Compañero es quien comparte GRUPO, no promotoría: quien va los martes no se
    cruza con quien va los jueves. Por eso el rótulo del grupo va aquí y no como
    un adorno — es lo que explica a quién se está viendo y por qué no está el
    resto de la promotoría.

    Y por eso los dos vacíos dicen cosas distintas. «No tienes grupo» es una
    gestión pendiente de la dirección; «no hay nadie más» es un hecho sobre el
    grupo. Con un solo mensaje para los dos, a quien le falta grupo le diríamos
    que no tiene compañeros, que no es verdad y no le dice qué esperar.
  --}}
  @if (! $item['grupo'])
    <p class="vacio">
      Todavía no tienes grupo en esta promotoría. Cuando la dirección te asigne uno,
      aquí verás a tus compañeros de clase.
    </p>
  @else
  <p class="campo-info" style="margin:0 0 0.6rem;">{{ $item['grupo']->rotulo }}</p>
  @if (! count($item['companeros']))
    <p class="vacio">Por ahora eres la única persona en este grupo.</p>
  @else
  <div style="display:flex;flex-direction:column;gap:0.6rem;">
    @foreach ($item['companeros'] as $companero)
    <div class="carne">
      @if ($companero->foto_perfil)
        <img class="carne-foto" src="{{ route('ver-foto', $companero) }}" alt="">
      @else
        <div class="carne-foto-vacia"></div>
      @endif
      <div class="carne-datos">
        <div class="carne-nombre">{{ $companero->nombre_completo }}</div>
        <div class="carne-detalle">{{ $item['grupo']->nombre_con_nivel }}</div>
      </div>
    </div>
    @endforeach
  </div>
  @endif
  @endif
  @endforeach
@endif
@endsection
