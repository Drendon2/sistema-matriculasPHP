@extends('layouts.app')

@section('title', 'Mis compañeros')

@section('content')
<h2>Mis compañeros</h2>

@if (! $promotorias)
  <p class="vacio">No estás matriculado en ninguna promotoría por ahora.</p>
@else
  @foreach ($promotorias as $item)
  <h3>{{ $item['promotoria']->nombre }}</h3>
  @if (! count($item['companeros']))
    <p class="vacio">Todavía no tienes compañeros en esta promotoría.</p>
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
        <div class="carne-detalle">{{ $item['promotoria']->nombre }}</div>
      </div>
    </div>
    @endforeach
  </div>
  @endif
  @endforeach
@endif
@endsection
