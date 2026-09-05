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

{{--
  CÓMO VA LA ESCUELA, arriba del todo desde el 04/09/2026, porque esta pasó a
  ser la pantalla donde aterriza el administrador al entrar.

  Las cifras salen de `Support\ResumenInstitucion`, que es de donde las saca
  también Estadísticas. No se calculan aquí a propósito: la misma cifra en dos
  pantallas calculada en dos sitios acaba diciendo dos cosas distintas.

  «Estudiantes activos» se acota al periodo en curso y las otras cuatro no,
  porque no dependen de él. Si no hay periodo en curso la cifra es 0 y se dice,
  en vez de enseñar un cero que se lee como «no hay nadie».
--}}
<div class="dash-resumen">
  <div>
    <span class="dash-stat-num">{{ $cifras['estudiantesActivos'] }}</span>
    <span class="dash-stat-label">
      {{ $periodo ? 'Estudiantes activos' : 'Sin periodo en curso' }}
    </span>
  </div>
  <div>
    <span class="dash-stat-num">{{ $cifras['profesores'] }}</span>
    <span class="dash-stat-label">{{ $cifras['profesores'] == 1 ? 'Profesor' : 'Profesores' }}</span>
  </div>
  <div>
    <span class="dash-stat-num">{{ $cifras['promotorias'] }}</span>
    <span class="dash-stat-label">{{ $cifras['promotorias'] == 1 ? 'Promotoría' : 'Promotorías' }}</span>
  </div>
  <div>
    <span class="dash-stat-num">{{ $cifras['grupos'] }}</span>
    <span class="dash-stat-label">{{ $cifras['grupos'] == 1 ? 'Grupo' : 'Grupos' }}</span>
  </div>
  <div>
    <span class="dash-stat-num">{{ $cifras['actividades'] }}</span>
    <span class="dash-stat-label">Cursos y talleres</span>
  </div>
</div>

{{--
  LAS TRES ÚLTIMAS ALERTAS, y no una lista completa: para eso está su pantalla,
  y el enlace lleva a ella. Aquí lo que hace falta es saber si hay algo ardiendo
  sin tener que ir a mirar.

  No se guarda ninguna cola. Las alertas se calculan al abrir la pantalla —cuatro
  consultas fijas, ver `Support\Alertas`— así que en cuanto una se resuelve deja
  de aparecer y suben las que venían detrás, sin que nadie refresque nada.

  Con las dos alertas APAGADAS no se pinta un banner vacío, que se leería como
  «no hay nada»: se dice que están apagadas y dónde se encienden. La diferencia
  entre «no hay avisos» y «no estás mirando» es justo la que importa aquí.
--}}
@if ($alertasApagadas)
  <p class="campo-ayuda">
    Las alertas están apagadas. Se encienden en
    <a href="{{ route('gestion-configuracion') }}">Institución</a>, y conviene poner antes
    su fecha de inicio.
  </p>
@elseif ($ultimasAlertas)
  <div class="alertas-banner">
    <div class="alertas-banner-cabecera">
      <span class="alertas-banner-titulo">Últimas alertas</span>
      <a href="{{ route('gestion-cancelaciones') }}">
        Ver las {{ $alertasPendientes }}
      </a>
    </div>
    <ul class="alertas-banner-lista">
      @foreach ($ultimasAlertas as $alerta)
      <li class="alertas-banner-item">
        <span class="estado {{ $alerta['tipo'] === 'abandono' ? 'estado-retirada' : 'estado-pendiente' }}">
          {{ $alerta['tipo'] === 'abandono' ? 'Abandono' : 'Sin dictar' }}
        </span>
        <span class="alertas-banner-texto">
          <strong>{{ $alerta['texto'] }}</strong>
          <span class="alertas-banner-detalle">{{ $alerta['detalle'] }}</span>
        </span>
      </li>
      @endforeach
    </ul>
  </div>
@endif

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

      Y cuenta las TRES cosas de la bandeja. Con solo las cancelaciones, la
      ficha diria «0» teniendo veinte clases sin registrar dentro.
    --}}
    @php($porAtender = $cancelacionesPendientes + $alertasPendientes)
    <span class="tarjeta-titulo">
      Alertas y cancelaciones
      @if ($porAtender)<span class="num">{{ $porAtender }}</span>@endif
    </span>
    <span class="tarjeta-nota">
      @if ($porAtender)
        Quien pidió salirse, las clases que no se dictaron y los posibles
        abandonos.
      @else
        Aquí espera lo que hay que atender. Ahora mismo no hay nada.
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
