@extends('layouts.app')

@section('title', $titulo)

@section('content')
{{--
  La pantalla de listado que comparten periodos, promotorías y grupos —
  Departamentos vive ahora en la portada de Gestión, ver `gestion.inicio`.
  Recibe:

    $objetos ............ [{objeto, hijos, protegido}]
    $ruta_nuevo/editar/eliminar
    $ruta_fila .......... a dónde lleva el nombre (el nivel de abajo), si aplica
    $etiqueta_singular/plural ... qué se cuenta al lado del nombre
    $etiqueta_protegido . qué impide borrarlo
    $preset_campo/valor . para que "+ Nuevo" llegue con el padre ya elegido
    $migas .............. la ruta de vuelta cuando se entra por la jerarquía
    $filtros ............ opcional: [{nombre, etiqueta, vacio, opciones, valor}]
                          Solo Grupos los usa hoy. Los otros dos catálogos no
                          pasan nada y la barra no se pinta, que es por lo que va
                          en el parcial y no aquí: la tabla, el estado vacío y el
                          botón de nuevo son los mismos que usa Departamentos.
--}}
@php($migas = $migas ?? [])

@if ($migas)
<p class="migas">
  @foreach ($migas as $m)<a href="{{ $m['url'] }}">{{ $m['texto'] }}</a><span class="migas-sep">/</span>@endforeach<span class="migas-actual">{{ $titulo }}</span>
</p>
@else
<a href="{{ route('gestion-inicio') }}" class="volver">&larr; Gestión</a>
@endif
<h2>{{ $titulo }}</h2>

@include('gestion.partials.tabla')
@endsection
