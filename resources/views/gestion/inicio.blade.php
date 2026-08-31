@extends('layouts.app')

@section('title', 'Gestión')

@section('content')
@include('gestion.partials.resumen-periodo')

{{--
  El botón "Nuevo" sube a esta fila —alineado a la derecha, con el mismo ancho
  que la tabla de abajo— en vez de vivir dentro de `gestion.partials.tabla`
  como en los demás catálogos: aquí Departamentos es la cabecera de su propia
  sección, no un listado más.
--}}
<div class="fila-titulo-accion" style="margin-top:2rem;">
  <h2>Departamentos</h2>
  <button type="button" class="btn btn-sm" data-abre-modal="modal-area-nueva">Nuevo</button>
</div>
<div class="tabla-departamentos">
  @include('gestion.partials.tabla', [
    'ruta_nuevo' => 'area-nueva',
    'ruta_editar' => 'area-editar',
    'ruta_eliminar' => 'area-eliminar',
  ])
</div>
@include('gestion.partials.area-modales')

{{--
  Cursos, talleres y proyección van justo debajo del árbol académico y no
  mezclados con Periodos/Promotorías/Grupos: no cuelgan de un departamento ni
  pasan por matrícula, y ponerlas al lado de esas invita a buscar ahí lo que
  aquí se hace por un enlace.
--}}
<div class="tarjetas tarjetas-derecha" style="margin-top:1rem;">
  <a class="tarjeta-enlace" href="{{ route('actividad-curso-lista') }}">Cursos y talleres</a>
  <a class="tarjeta-enlace" href="{{ route('actividad-proyeccion-lista') }}">Grupos de proyección</a>
</div>

<h2 style="margin-top:2rem;">Matrículas</h2>
@include('gestion.partials.matriculas')
@include('gestion.partials.periodo-modales')

@if ($yo->rol === 'administrador')
<h2 style="margin-top:2rem;">Gestión del catálogo académico</h2>
<div class="tarjetas">
  <a class="tarjeta-enlace" href="{{ route('gestion-estadisticas') }}">Estadísticas</a>
  <a class="tarjeta-enlace" href="{{ route('gestion-configuracion') }}">Institución</a>
</div>
@endif

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
