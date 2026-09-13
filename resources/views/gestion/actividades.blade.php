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
{{--
  Crear una actividad es del ADMINISTRADOR desde el 12/09/2026: un director la
  gestiona si se la asignan —poniendolo de responsable— pero no la crea. El
  boton se esconde Y la ruta esta cerrada; esconder el enlace no cierra la
  puerta, que es regla de esta casa.
--}}
@if ($yo->rol === 'administrador')
<p><a class="btn" href="{{ route($ruta_nuevo) }}" @if ($modal ?? false) data-modal @endif>+ Nuevo</a></p>
@endif

@include('partials.tabla-actividades')
@endsection

@push('scripts')
<script src="@recurso('js/copiar-enlace.js')" defer></script>
@endpush
