{{--
  El certificado de asistencia a un curso o un taller, tal como sale impreso.

  Como el de matricula, NO extiende el layout: lo convierte dompdf, que entiende
  CSS 2.1 y nada de lo que el layout usa —variables, flex, grid— llega a
  pintarse. De ahi las medidas en puntos y los colores escritos.

  VA HORIZONTAL, y eso no es solo `setPaper(..., 'landscape')`: lo que cambia de
  verdad es que el ALTO disponible baja de 792 pt a 612. Un certificado es un
  papel que se enmarca, asi que la forma es la correcta, pero la garantia de UNA
  HOJA se vuelve mas estrecha que en el de matricula, no menos. Por eso este
  documento es deliberadamente corto: un parrafo, el nombre grande, una fila de
  datos y la firma. Antes de anadirle un renglon, vuelve a medir con
  `CertificadoDeActividadTest`, que busca por biseccion la hoja minima donde
  todavia cabe — contar hojas no basta, dice si o no y no dice por cuanto.

  Va en grises por lo mismo que el de matricula: se imprime, se fotocopia y se
  archiva, y una fotocopia en blanco y negro no puede convertirlo en otra cosa.
  La marca la pone el logo, que si va a color.
--}}
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Certificado de asistencia — {{ $inscrito->nombre_completo }}</title>
<style>
  /* Margenes mas estrechos arriba y abajo que en el de matricula: en apaisado
     el alto es el recurso escaso y los laterales sobran. */
  @page { margin: 1.3cm 2.2cm 1.6cm 2.2cm; }

  body {
    font-family: DejaVu Sans, sans-serif;
    font-size: 10.5pt;
    line-height: 1.4;
    color: #1a1a1a;
  }

  .cabecera { width: 100%; border-bottom: 1.2pt solid #8a8a8a; padding-bottom: 6pt; }
  .cabecera td { vertical-align: middle; }
  .cabecera-logo { width: 52pt; }
  .cabecera-logo img { width: 42pt; }
  .cabecera-nombre { font-size: 14pt; font-weight: bold; color: #333; }

  h1 {
    font-size: 15pt;
    letter-spacing: 3pt;
    text-align: center;
    text-transform: uppercase;
    margin: 14pt 0 10pt;
  }

  .cuerpo { text-align: center; margin: 0; }

  .nombre {
    font-size: 19pt;
    font-weight: bold;
    text-transform: uppercase;
    text-align: center;
    margin: 10pt 0 2pt;
  }

  .documento { text-align: center; font-size: 10pt; color: #444; margin: 0 0 10pt; }

  .actividad { text-align: center; font-size: 13pt; font-weight: bold; margin: 6pt 0 2pt; }

  /* Los datos van en UNA fila de celdas y no en una lista: en apaisado sobra
     ancho y falta alto, asi que lo que en vertical serian cuatro renglones aqui
     es uno solo. */
  .datos { width: 100%; border-collapse: collapse; margin: 12pt 0 0; }
  .datos td {
    text-align: center;
    padding: 5pt 6pt;
    border-top: 0.8pt solid #ddd;
    border-bottom: 0.8pt solid #ddd;
  }
  .datos .rotulo { display: block; font-size: 8pt; color: #666; text-transform: uppercase; letter-spacing: 0.5pt; }
  .datos .valor { display: block; font-size: 11pt; font-weight: bold; }

  /* La firma va centrada, que es como se firma un diploma, y con
     `page-break-inside: avoid` por lo mismo que el otro certificado: si no
     cabe, el bloque se MUEVE entero y saca una segunda hoja con la firma sola.
     Ese es el sintoma que hay que saber reconocer. */
  .firma { margin: 16pt auto 0; page-break-inside: avoid; }
  .firma td { text-align: center; }
  .firma-imagen { height: 38pt; }
  .firma-hueco { height: 38pt; }
  .firma-linea { border-top: 0.8pt solid #333; width: 230pt; padding-top: 4pt; margin: 0 auto; }
  .firma-nombre { font-weight: bold; }
  .firma-cargo { font-size: 9.5pt; color: #444; }

  .pie {
    position: fixed;
    bottom: -1.1cm;
    left: 0;
    right: 0;
    font-size: 7.5pt;
    color: #777;
    text-align: center;
    border-top: 0.5pt solid #ddd;
    padding-top: 3pt;
  }
</style>
</head>
<body>

<table class="cabecera">
  <tr>
    @if ($logo)
    <td class="cabecera-logo"><img src="{{ $logo }}" alt=""></td>
    @endif
    <td class="cabecera-nombre">{{ $institucion->nombre_institucion }}</td>
  </tr>
</table>

<h1>Certificado de asistencia</h1>

<p class="cuerpo">La dirección de {{ $institucion->nombre_institucion }} hace constar que</p>

<div class="nombre">{{ $inscrito->nombre_completo }}</div>

{{-- El documento es NULABLE a proposito: a quien anade el responsable el dia de
     la clase no se le pide. Un certificado sin numero sigue valiendo; lo que no
     puede es imprimir «Documento n.º» y nada detras. --}}
@if ($inscrito->documento)
<p class="documento">Documento de identidad n.º {{ $inscrito->documento }}</p>
@endif

<p class="cuerpo">
  asistió al {{ mb_strtolower($actividad->etiquetaTipo()) }}
</p>

<div class="actividad">{{ $actividad->nombre }}</div>

<p class="cuerpo">
  dictado por {{ $actividad->responsable->nombre_completo }}@if ($fechas),
  entre el {{ $fechas[0]->translatedFormat('j \d\e F \d\e Y') }} y el
  {{ $fechas[1]->translatedFormat('j \d\e F \d\e Y') }}@endif.
</p>

<table class="datos">
  <tr>
    <td>
      <span class="rotulo">{{ $asistencia['sesiones'] == 1 ? ucfirst($actividad->etiquetaSesion()) : ucfirst($actividad->etiquetaSesion()).'s' }} dictadas</span>
      <span class="valor">{{ $asistencia['sesiones'] }}</span>
    </td>
    <td>
      <span class="rotulo">Asistencias</span>
      <span class="valor">{{ $asistencia['asistidas'] }}</span>
    </td>
    <td>
      <span class="rotulo">Porcentaje de asistencia</span>
      <span class="valor">{{ $asistencia['porcentaje'] }}%</span>
    </td>
    <td>
      <span class="rotulo">Expedido</span>
      <span class="valor">{{ $expedido->translatedFormat('j/m/Y') }}</span>
    </td>
  </tr>
</table>

<table class="firma">
  <tr>
    <td>
      {{--
        Sin firma cargada se deja el hueco de su altura y no se sube la linea:
        el certificado sigue siendo valido firmado a mano encima, y una linea
        pegada al texto no deja sitio para hacerlo.
      --}}
      @if ($firma)
        <img class="firma-imagen" src="{{ $firma }}" alt="">
      @else
        <div class="firma-hueco"></div>
      @endif
      <div class="firma-linea">
        @if ($institucion->firmante_nombre)
        <div class="firma-nombre">{{ $institucion->firmante_nombre }}</div>
        @endif
        @if ($institucion->firmante_cargo)
        <div class="firma-cargo">{{ $institucion->firmante_cargo }}</div>
        @endif
        @if (! $institucion->firmante_nombre && ! $institucion->firmante_cargo)
        <div class="firma-cargo">Firma autorizada</div>
        @endif
      </div>
    </td>
  </tr>
</table>

<div class="pie">
  Documento generado por {{ $institucion->nombre_institucion }}
  el {{ $expedido->format('d/m/Y \a \l\a\s H:i') }}.
</div>

</body>
</html>
