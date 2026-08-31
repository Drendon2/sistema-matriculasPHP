@extends('layouts.app')

@section('title', 'Gestión')

@section('content')
@include('gestion.partials.resumen-periodo')

<div class="card">
  <h3>Buscar usuario</h3>
  <form method="get" action="{{ route('usuario-lista') }}" class="cupo-form" style="margin-left:0;">
    <label class="sr-solo" for="busqueda-usuario">Buscar usuario</label>
    <input type="search" name="q" id="busqueda-usuario"
           placeholder="Nombre, usuario, teléfono, correo o documento" style="max-width:22rem;">
    <button type="submit" class="btn btn-sm">Buscar</button>
  </form>
</div>

<h2 style="margin-top:2rem;">Departamentos</h2>
@include('gestion.partials.tabla', [
  'ruta_nuevo' => 'area-nueva',
  'ruta_editar' => 'area-editar',
  'ruta_eliminar' => 'area-eliminar',
])

{{--
  Cursos, talleres y proyección van justo debajo del árbol académico y no
  mezclados con Periodos/Promotorías/Grupos: no cuelgan de un departamento ni
  pasan por matrícula, y ponerlas al lado de esas invita a buscar ahí lo que
  aquí se hace por un enlace.
--}}
<div class="tarjetas" style="margin-top:1rem;">
  <a class="tarjeta-enlace" href="{{ route('actividad-curso-lista') }}">Cursos y talleres</a>
  <a class="tarjeta-enlace" href="{{ route('actividad-proyeccion-lista') }}">Grupos de proyección</a>
</div>

<h2 style="margin-top:2rem;">Matrículas</h2>
@include('gestion.partials.matriculas')
@include('gestion.partials.periodo-modales')

<h2 style="margin-top:2rem;">Gestión del catálogo académico</h2>
<div class="tarjetas">
  <a class="tarjeta-enlace" href="{{ route('gestion-cancelaciones') }}">
    @if ($cancelacionesPendientes)<span class="num">{{ $cancelacionesPendientes }}</span>@endif
    Cancelaciones
  </a>
  @if ($yo->rol === 'administrador')
  <a class="tarjeta-enlace" href="{{ route('gestion-estadisticas') }}">Estadísticas</a>
  <a class="tarjeta-enlace" href="{{ route('gestion-configuracion') }}">Institución</a>
  @endif
</div>

<h2 style="margin-top:2rem;">Informes descargables</h2>
<p class="campo-info" style="margin-top:-0.8rem;">
  Se abren con Excel o con Hojas de cálculo de Google.
</p>

<div class="tarjetas">
  <a class="tarjeta-enlace" href="{{ route('informe-estudiantes') }}">
    Estudiantes por grupo
    <span class="campo-info" style="display:block;margin:0.2rem 0 0;font-weight:400;">
      Del periodo en curso, con teléfono y acudiente.
    </span>
  </a>
  @if ($yo->rol === 'administrador')
  {{--
    El aviso NO es decorativo. Este informe lleva la encuesta demográfica con
    nombre y apellido, que es el dato más protegido del sistema: en pantalla solo
    se enseña agregado y anónimo, precisamente para que contestarla no tenga
    consecuencias. El archivo rompe esa garantía en cuanto sale de aquí, y quien
    lo descarga tiene que saberlo ANTES de pulsar, no después.
  --}}
  <a class="tarjeta-enlace" href="{{ route('informe-institucion') }}">
    Informe completo de la institución
    <span class="campo-info" style="display:block;margin:0.2rem 0 0;font-weight:400;">
      Incluye la <strong>encuesta demográfica con nombre</strong> y datos de
      menores. Trátalo como confidencial.
    </span>
  </a>
  @endif
</div>
@endsection

@push('scripts')
<script src="@recurso('js/gestion-periodo-modales.js')" defer></script>
@endpush
