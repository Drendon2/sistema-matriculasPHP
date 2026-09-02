@extends('layouts.app')

@section('title', 'Gestión')

@section('content')
{{--
  LA PORTADA DE GESTION.

  Tenía DOCE fichas y cinco de ellas llevaban al mismo sitio por caminos
  distintos: «Departamentos», «Promotorías» y «Grupos» son tres puertas al mismo
  árbol —el descenso ya existía, con sus migas— y las fichas planas entraban a
  media altura saltándoselo; «Cursos y talleres» y «Grupos de proyección» son la
  otra mitad de lo mismo, que es lo que la institución ofrece. Y «Periodos» era
  una ficha aparte de «Iniciar / finalizar matrículas», cuando crear un periodo
  es el primer paso de abrir uno.

  Quedan cuatro destinos y dos de administrador, y cada uno dice qué hay dentro.
  Eso último no es adorno: al agrupar, el nombre de la ficha deja de nombrar una
  pantalla y pasa a nombrar un sitio, y sin el renglón de debajo habría que
  entrar a averiguar qué se guardó dónde. Es el mismo patrón que ya usaban las
  dos fichas de informes de más abajo.
--}}
<h2>Gestión</h2>

<div class="tarjetas">
  <a class="tarjeta-enlace" href="{{ route('gestion-matriculas') }}">
    Matrículas
    <span class="tarjeta-nota">
      Abrir y cerrar la ventana del periodo en curso, repartir los cupos y crear
      periodos nuevos.
    </span>
  </a>

  {{--
    La cifra es lo único que avisa de que hay gente esperando respuesta: sin
    ella habría que entrar a mirar. Por eso esta ficha no se agrupó dentro de
    Matrículas aunque hable de matrículas — una bandeja con trabajo pendiente
    tiene que verse desde fuera.
  --}}
  <a class="tarjeta-enlace" href="{{ route('gestion-cancelaciones') }}">
    {{--
      La cifra va DESPUES del nombre y no antes. Suelta encima, la ficha se leia
      «18 · Cancelaciones»: el numero llegaba antes que la palabra que dice de
      que es, y con seis fichas que ahora empiezan todas por su nombre, esa era
      la unica que empezaba por otra cosa. A la derecha se sigue viendo de lejos,
      que es para lo que esta.
    --}}
    <span class="tarjeta-titulo">
      Cancelaciones
      @if ($cancelacionesPendientes)<span class="num">{{ $cancelacionesPendientes }}</span>@endif
    </span>
    <span class="tarjeta-nota">
      @if ($cancelacionesPendientes)
        {{ $cancelacionesPendientes == 1 ? 'Una persona pidió' : 'Personas que pidieron' }}
        salirse y {{ $cancelacionesPendientes == 1 ? 'espera' : 'esperan' }} respuesta.
      @else
        Quien pide salirse de una promotoría espera aquí. Ahora mismo no hay nadie.
      @endif
    </span>
  </a>

  <a class="tarjeta-enlace" href="{{ route('gestion-programas') }}">
    Programas formativos
    <span class="tarjeta-nota">
      Departamentos, promotorías y grupos. Cursos y talleres. Grupos de
      proyección.
    </span>
  </a>

  <a class="tarjeta-enlace" href="{{ route('usuario-lista') }}">
    Usuarios
    <span class="tarjeta-nota">
      Altas, roles, cuentas activas y el enlace para registrar a un profesor.
    </span>
  </a>

  @if ($yo->rol === 'administrador')
  <a class="tarjeta-enlace" href="{{ route('gestion-estadisticas') }}">
    Estadísticas
    <span class="tarjeta-nota">
      Cómo va el periodo: permanencia, asistencia y las encuestas.
    </span>
  </a>

  <a class="tarjeta-enlace" href="{{ route('gestion-configuracion') }}">
    Institución
    <span class="tarjeta-nota">
      Nombre, logo, color y qué documentos se piden al matricularse.
    </span>
  </a>
  @endif
</div>

<h2 style="margin-top:2.4rem;">Informes descargables</h2>
<p class="campo-info">
  Se abren con Excel o con Hojas de cálculo de Google.
</p>

<div class="tarjetas">
  <a class="tarjeta-enlace" href="{{ route('informe-estudiantes') }}">
    Estudiantes por grupo
    <span class="tarjeta-nota">
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
    <span class="tarjeta-nota">
      Incluye la <strong>encuesta demográfica con nombre</strong> y datos de
      menores. Trátalo como confidencial.
    </span>
  </a>
  @endif
</div>
@endsection
