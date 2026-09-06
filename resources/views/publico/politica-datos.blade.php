@extends('layouts.publico')

@section('title', 'Tratamiento de datos personales — '.$configuracion->nombre_institucion)
@section('ancho', '760px')

@section('caja')
  {{--
    La política de tratamiento de datos personales.

    CUELGA DEL ENVOLTORIO PÚBLICO TAMBIÉN PARA QUIEN TIENE SESIÓN, y fue una
    decisión: la alternativa era repartirla en los dos envoltorios según haya
    sesión o no, y eso deja dos pantallas capaces de divergir para un texto que
    es el mismo, que se cita y que se imprime. El precio es que aquí no está la
    barra de navegación, y por eso al final hay una vuelta explícita.

    760px y no los 400 de fábrica: esto es un documento para leer seguido, no
    un formulario de cinco campos.

    El contenido viene ya convertido a HTML y YA ESCAPADO por
    `Support\PoliticaDatos`. Es el único sitio del proyecto que imprime sin
    escapar, y el escape se hace allí, trozo por trozo, antes de envolver nada
    en etiquetas — porque lo que hay en esa columna lo escribió una persona en
    un textarea de Gestión.
  --}}
  <h1>Tratamiento de datos personales</h1>

  <p class="info">
    Cómo {{ $institucion->nombre_institucion }} recoge, usa y protege los datos
    personales de quienes participan en sus procesos formativos y culturales.
  </p>

  <div class="politica">
    {!! $contenido !!}
  </div>

  <p class="politica-vuelta">
    @auth
      <a href="{{ route('post-login') }}">Volver al sistema</a>
    @else
      <a href="{{ route('login') }}">Volver a iniciar sesión</a>
    @endauth
  </p>
@endsection
