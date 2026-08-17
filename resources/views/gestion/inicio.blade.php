@extends('layouts.app')

@section('title', 'Gestión')

@section('content')
<h2>Gestión del catálogo académico</h2>
<div class="tarjetas">
  <a class="tarjeta-enlace" href="{{ route('area-lista') }}">Departamentos</a>
  <a class="tarjeta-enlace" href="{{ route('periodo-lista') }}">Periodos</a>
  <a class="tarjeta-enlace" href="{{ route('gestion-matriculas') }}">Iniciar / finalizar matrículas</a>
  <a class="tarjeta-enlace" href="{{ route('gestion-cancelaciones') }}">
    @if ($cancelacionesPendientes)<span class="num">{{ $cancelacionesPendientes }}</span>@endif
    Cancelaciones
  </a>
  <a class="tarjeta-enlace" href="{{ route('promotoria-lista') }}">Promotorías</a>
  <a class="tarjeta-enlace" href="{{ route('gestion-cupos') }}">Cupos por promotoría</a>
  <a class="tarjeta-enlace" href="{{ route('grupo-lista') }}">Grupos</a>
  <a class="tarjeta-enlace" href="{{ route('usuario-lista') }}">Usuarios</a>
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
