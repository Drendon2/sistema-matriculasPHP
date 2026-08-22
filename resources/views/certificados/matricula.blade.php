{{--
  El certificado de matricula, tal como sale impreso.

  Es la unica plantilla del proyecto que NO extiende el layout: la convierte a
  PDF dompdf, que entiende CSS 2.1 y nada de lo que el layout usa —variables
  CSS, flex, grid— llega a pintarse. De ahi que los estilos vayan aqui dentro y
  en la forma antigua: tablas para colocar cosas al lado de otras, medidas en
  puntos, colores escritos.

  El certificado NO usa el color de acento de la institucion, y es a proposito:
  esto es un documento oficial que se imprime, se fotocopia y se archiva. Va en
  grises —el mismo negro del texto para el nombre de la entidad, una linea
  discreta debajo— porque asi se lee igual en la impresora de la oficina que en
  pantalla, y una fotocopia en blanco y negro no lo convierte en otra cosa. La
  marca la pone el logo, que si va a color.
--}}
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>{{ $titulo }} — {{ $estudiante->nombre_completo }}</title>
<style>
  /* Los margenes son los de una carta de oficina. El inferior es mayor
     porque ahi va el pie fijo. */
  @page { margin: 1.7cm 2cm 2.2cm 2cm; }

  body {
    font-family: DejaVu Sans, sans-serif;
    font-size: 10.5pt;
    line-height: 1.55;
    color: #1a1a1a;
  }

  .cabecera { width: 100%; border-bottom: 1.2pt solid #8a8a8a; padding-bottom: 8pt; }
  .cabecera td { vertical-align: middle; }
  .cabecera-logo { width: 70pt; }
  .cabecera-logo img { width: 60pt; }
  .cabecera-nombre { font-size: 15pt; font-weight: bold; color: #333; }

  h1 {
    font-size: 13pt;
    letter-spacing: 1.5pt;
    text-align: center;
    text-transform: uppercase;
    margin: 24pt 0 20pt;
  }

  .cuerpo { text-align: justify; }
  .destacado { text-align: center; margin: 16pt 0; }
  .destacado .nombre { font-size: 13pt; font-weight: bold; text-transform: uppercase; }
  .destacado .documento { font-size: 10pt; color: #444; }

  .detalle { width: 100%; border-collapse: collapse; margin: 16pt 0 6pt; }
  .detalle th {
    background: #eee;
    color: #333;
    font-size: 9pt;
    text-align: left;
    padding: 5pt 7pt;
    border-bottom: 0.8pt solid #8a8a8a;
  }
  .detalle td { border-bottom: 0.6pt solid #ddd; padding: 5pt 7pt; font-size: 9.5pt; }

  .ficha { width: 100%; border-collapse: collapse; margin: 14pt 0 6pt; }
  .ficha th {
    width: 30%;
    text-align: left;
    font-weight: normal;
    color: #555;
    padding: 4pt 0;
    vertical-align: top;
  }
  .ficha td { padding: 4pt 0; font-weight: bold; }

  /* El bloque de la firma tiene que caber entero en la misma hoja que el
     texto: un certificado cuya firma cae sola en la pagina siguiente no lo
     acepta nadie en ventanilla. De ahi que este margen sea holgado pero
     contado, y que el resto del documento vaya compacto. */
  .firma { margin-top: 30pt; page-break-inside: avoid; }
  .firma-imagen { height: 46pt; }
  .firma-hueco { height: 46pt; }
  .firma-linea { border-top: 0.8pt solid #333; width: 210pt; padding-top: 4pt; }
  .firma-nombre { font-weight: bold; }
  .firma-cargo { font-size: 9.5pt; color: #444; }

  .pie {
    position: fixed;
    bottom: -1.5cm;
    left: 0;
    right: 0;
    font-size: 7.5pt;
    color: #777;
    text-align: center;
    border-top: 0.5pt solid #ddd;
    padding-top: 4pt;
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

<h1>{{ $titulo }}</h1>

<p class="cuerpo">La dirección de {{ $institucion->nombre_institucion }} hace constar que</p>

<div class="destacado">
  <div class="nombre">{{ $estudiante->nombre_completo }}</div>
  @if ($documento)
  <div class="documento">Documento de identidad n.º {{ $documento }}</div>
  @endif
</div>

<p class="cuerpo">
  @if ($finalizado)
    cursó
  @else
    se encuentra matriculado y con matrícula vigente en
  @endif
  @if ($matriculas->count() === 1)
    la promotoría <strong>{{ $matriculas->first()->promotoria->nombre }}</strong>,
    del área de {{ $matriculas->first()->promotoria->area->nombre }},
  @else
    las <strong>{{ $matriculas->count() }} promotorías</strong> que se relacionan a continuación,
  @endif
  durante el periodo académico <strong>{{ $periodo->nombre }}</strong>, comprendido entre el
  {{ $periodo->fecha_inicio->translatedFormat('j \d\e F \d\e Y') }} y el
  {{ $periodo->fecha_fin->translatedFormat('j \d\e F \d\e Y') }}.
</p>

@if ($matriculas->count() === 1)
  @php($matricula = $matriculas->first())
  <table class="ficha">
    <tr>
      <th>Promotoría</th>
      <td>{{ $matricula->promotoria->nombre }}</td>
    </tr>
    <tr>
      <th>Área</th>
      <td>{{ $matricula->promotoria->area->nombre }}</td>
    </tr>
    @if ($matricula->grupo)
    <tr>
      <th>Grupo</th>
      <td>
        {{ $matricula->grupo->nombre_con_nivel }}
        @if ($matricula->grupo->horario) — {{ $matricula->grupo->horario }} @endif
        @if ($matricula->grupo->salon) — {{ $matricula->grupo->salon }} @endif
      </td>
    </tr>
    @endif
    @if ($matricula->promotoria->profesor)
    <tr>
      <th>Docente a cargo</th>
      <td>{{ $matricula->promotoria->profesor->nombre_completo }}</td>
    </tr>
    @endif
    <tr>
      <th>Fecha de matrícula</th>
      <td>{{ $matricula->fecha->translatedFormat('j \d\e F \d\e Y') }}</td>
    </tr>
    <tr>
      <th>Estado</th>
      <td>{{ $matricula->estado_visible_display }}</td>
    </tr>
  </table>
@else
  <table class="detalle">
    <thead>
      <tr>
        <th>Promotoría</th>
        <th>Área</th>
        <th>Grupo y horario</th>
        <th>Matriculado desde</th>
      </tr>
    </thead>
    <tbody>
      @foreach ($matriculas as $matricula)
      <tr>
        <td>{{ $matricula->promotoria->nombre }}</td>
        <td>{{ $matricula->promotoria->area->nombre }}</td>
        <td>
          @if ($matricula->grupo)
            {{ $matricula->grupo->nombre_con_nivel }}
            @if ($matricula->grupo->horario) — {{ $matricula->grupo->horario }} @endif
          @else
            Sin grupo asignado
          @endif
        </td>
        <td>{{ $matricula->fecha->translatedFormat('j/m/Y') }}</td>
      </tr>
      @endforeach
    </tbody>
  </table>
@endif

<p class="cuerpo">
  Se expide a solicitud del interesado el
  {{ $expedido->translatedFormat('j \d\e F \d\e Y') }}.
</p>

<table class="firma">
  <tr>
    <td>
      {{--
        Cuando no hay firma cargada se deja el hueco de su altura y no se sube
        la linea: el certificado sigue siendo valido firmado a mano encima, y
        una linea pegada al parrafo no deja sitio para hacerlo.
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
