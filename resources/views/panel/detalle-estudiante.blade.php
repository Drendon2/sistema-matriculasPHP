@extends('layouts.app')

@section('title', $estudiante->nombre_completo)

@section('content')
<a href="{{ route('panel') }}" class="volver">&larr; Volver al panel</a>
<h2>Ficha de estudiante</h2>

@include('panel.carne', ['estudiante' => $estudiante])

<p style="margin-top:-0.8rem;">
  <a class="btn btn-secundario btn-sm" href="{{ route('historial-estudiante', $estudiante) }}">
    Ver trayectoria
  </a>
</p>

<h3>Documento</h3>
@if ($datos)
  <p>Documento de identidad: {{ $datos->documento_identidad }}</p>
  @if ($datos->acudiente)
    <p>Acudiente: {{ $datos->acudiente->nombre }} ({{ $datos->acudiente->telefono }})</p>
  @endif
  @if ($datos->copia_documento)
    <p><a class="btn" href="{{ route('descargar-documento', $datos) }}">Descargar copia del documento</a></p>
  @else
    <p class="vacio">No hay copia de documento cargada.</p>
  @endif
@else
  <p class="vacio">Este estudiante no tiene datos de estudiante registrados.</p>
@endif

<h3>Encuesta demográfica</h3>
@if ($encuesta)
<table style="max-width:520px;">
  <tr><th>Género</th><td>{{ $encuesta->etiqueta('genero') }}</td></tr>
  <tr><th>Barrio</th><td>{{ $encuesta->barrio }}</td></tr>
  <tr><th>Estrato</th><td>{{ $encuesta->etiqueta('estrato') }}</td></tr>
  <tr><th>Nivel educativo</th><td>{{ $encuesta->etiqueta('nivel_educativo') }}</td></tr>
  <tr><th>Ocupación</th><td>{{ $encuesta->etiqueta('ocupacion') }}</td></tr>
  <tr><th>Zona</th><td>{{ $encuesta->etiqueta('zona') }}</td></tr>
  <tr><th>Afiliación a salud</th><td>{{ $encuesta->etiqueta('afiliacion_salud') }}</td></tr>
  <tr><th>Grupo étnico</th><td>{{ $encuesta->etiqueta('grupo_etnico') }}</td></tr>
  <tr><th>Discapacidad</th><td>{{ $encuesta->etiqueta('discapacidad') }}</td></tr>
  <tr>
    <th>Víctima del conflicto armado</th>
    <td>{{ $encuesta->etiqueta('victima_conflicto_armado') }}</td>
  </tr>
  <tr>
    <th>Autoriza tratamiento de datos</th>
    <td>
      @if ($encuesta->autoriza_tratamiento_datos)
        Sí ({{ $encuesta->fecha_autorizacion?->format('d/m/Y H:i') }})
      @else
        No
      @endif
    </td>
  </tr>
</table>
@else
  <p class="vacio">Este estudiante todavía no ha diligenciado su encuesta.</p>
@endif
{{--
  GESTIÓN ASISTIDA desde la ficha, añadido el 04/09/2026 a petición del usuario.

  Es el camino natural: cuando alguien escribe por WhatsApp diciendo que no
  encuentra un botón, se llega a su ficha por su nombre desde cualquier listado.
  La otra puerta —Gestión → Usuarios— obliga a filtrar por rol primero, porque
  ese listado ordena por rol y pagina de 50: los profesores caen en la página
  cinco y parecía que la opción solo existía para directores.

  Va con `post` porque cambia de sesión, no porque navegue. Y la comprobación es
  la misma función que vuelve a mirar el controlador: aquí decide si se PINTA,
  allí si se PUEDE.
--}}
@if (\App\Support\GestionAsistida::puedeAsistirA($yo, $estudiante))
{{--
  `data-recarga-completa` porque esto cambia la IDENTIDAD de la sesión.
  `acciones.js` intercepta los formularios de dentro de `<main>` y repinta solo
  `<main>`; la barra de gestión asistida y el menú viven FUERA, así que sin este
  atributo la sesión cambia y la pantalla no se entera hasta que el navegador
  navegue de verdad. Se notó pulsando «Mi perfil», que era lo primero que lo
  provocaba.
--}}
<form action="{{ route('gestion-asistida-iniciar', $estudiante) }}" method="post" style="margin-top:1.5rem;"
      data-recarga-completa>
  @csrf
  <button type="submit" class="btn btn-secundario btn-sm">
    Gestionar como {{ $estudiante->nombre_completo }}
  </button>
</form>
@endif
@endsection
