{{--
  El formato de autorización de tratamiento de datos y uso de imagen, tal como
  sale impreso para firmarse.

  Como el certificado, NO extiende el layout: lo convierte a PDF dompdf, que
  entiende CSS 2.1 y nada de lo que el layout usa —variables CSS, flex, grid—
  llegaría a pintarse. De ahí que los estilos vayan aquí dentro y en la forma
  antigua: tablas para colocar cosas al lado de otras, medidas en puntos y
  colores escritos.

  Tampoco usa el color de acento de la institución, por la misma razón que el
  certificado: esto se imprime, se firma a mano, se escanea con el celular y se
  archiva. En grises se lee igual en la impresora de la oficina, y una
  fotocopia en blanco y negro no lo convierte en otra cosa. La marca la pone el
  logo, que sí va a color.

  LAS DOS AUTORIZACIONES VAN SEPARADAS, cada una con su par de casillas. No es
  una decisión de maquetación: son finalidades distintas, y la del uso de la
  imagen no puede condicionar la matrícula, así que tiene que poder negarse sin
  negar la otra. Un solo «acepto todo» al pie dejaría la negativa sin sitio
  donde escribirse, y con eso la autorización entera deja de valer.

  LOS DATOS QUE EL SISTEMA SABE VAN IMPRESOS; los que no, salen como una línea
  para escribir a mano. Es la misma plantilla en los dos casos: el formato en
  blanco que baja la administración es este mismo sin nadie dentro.
--}}
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Autorización de tratamiento de datos y uso de imagen — {{ $institucion->nombre_institucion }}</title>
<style>
  /* Los márgenes y el interlineado están MEDIDOS, no elegidos a ojo: con los
     valores de oficina que usa el certificado (1,7cm y 1.45) la versión de
     menor de edad —que lleva un bloque de datos más, el del acudiente— se
     pasaba a una segunda hoja. Un formato para firmar que sale en dos hojas se
     firma en la primera y se pierde la segunda.

     Con los márgenes de oficina del certificado (1,7cm) y su interlineado
     (1.45), el caso peor medido —un menor con acudiente, nombre largo y la URL
     de la política dentro— pedía 797 pt en una carta de 792 y salía en dos
     hojas. Con estos números y con los datos del menor en UNA fila en vez de
     dos, pide 759 y sobran 33.

     Antes de subir cualquiera de estos números, vuelve a medir el alto: la
     sonda binaria de `ConsentimientoTest` busca la hoja mínima donde todavía
     cabe, que es lo que dice cuánta holgura queda — contar hojas solo dice
     sí o no. */
  @page { margin: 1.35cm 1.8cm 1.35cm 1.8cm; }

  body {
    font-family: DejaVu Sans, sans-serif;
    font-size: 9.5pt;
    line-height: 1.35;
    color: #1a1a1a;
  }

  .cabecera { width: 100%; border-bottom: 1.2pt solid #8a8a8a; padding-bottom: 6pt; }
  .cabecera td { vertical-align: middle; }
  .cabecera-logo { width: 52pt; }
  .cabecera-logo img { width: 44pt; }
  .cabecera-nombre { font-size: 14pt; font-weight: bold; color: #333; }

  h1 {
    font-size: 11pt;
    letter-spacing: 0.8pt;
    text-align: center;
    text-transform: uppercase;
    margin: 10pt 0 7pt;
  }

  .cuerpo { text-align: justify; margin: 0 0 5pt; }

  h2 {
    font-size: 8.5pt;
    text-transform: uppercase;
    letter-spacing: 0.6pt;
    color: #444;
    margin: 9pt 0 4pt;
    border-bottom: 0.6pt solid #ccc;
    padding-bottom: 2pt;
  }

  /* La rejilla de datos. Cada celda es una etiqueta encima de un valor o de una
     línea para escribir; en dompdf eso se arma con tabla, no con rejilla. */
  .datos { width: 100%; border-collapse: collapse; margin: 0 0 3pt; }
  .datos td { padding: 2pt 8pt 2pt 0; vertical-align: bottom; width: 50%; }
  .etiqueta { font-size: 7.5pt; color: #555; text-transform: uppercase; letter-spacing: 0.4pt; }
  .valor { border-bottom: 0.6pt solid #777; padding: 1pt 0 2pt; min-height: 12pt; font-weight: bold; }
  /* Una casilla vacía tiene que MEDIR aunque no tenga texto: sin esto la línea
     de escribir a mano sube y se pega a su etiqueta. */
  .valor.vacio { font-weight: normal; }
  .valor.vacio:after { content: " "; }

  /* Cada autorización: el texto y, a la derecha, sus dos casillas. */
  .autorizacion { width: 100%; border-collapse: collapse; margin: 5pt 0; }
  .autorizacion td { vertical-align: top; padding: 0; }
  .autorizacion .texto { text-align: justify; padding-right: 10pt; }
  /* 82pt, y el numero sale de MEDIR. Estuvo en 118 para que cupieran «Sí
     autorizo» y «No autorizo» en un renglon, y con eso la columna del texto se
     estrechaba lo justo para que la version de menor de edad se pasara a una
     segunda hoja. Las etiquetas se acortaron a «Sí» y «No» bajo un rotulo
     comun, que dice lo mismo y ocupa un tercio. */
  .autorizacion .marcar { width: 82pt; }

  .casillas { width: 100%; border-collapse: collapse; }
  .casillas td { text-align: center; padding: 0 2pt; font-size: 8.5pt; }
  .casillas .rotulo {
    font-size: 7pt;
    letter-spacing: 0.5pt;
    text-transform: uppercase;
    color: #555;
    padding-bottom: 3pt;
  }
  /* El cuadro que se marca con lapicero. Va con alto y ancho fijos en puntos
     para que salga cuadrado en el papel y no dependa del tipo de letra. */
  .casilla {
    display: block;
    width: 15pt;
    height: 15pt;
    border: 0.9pt solid #333;
    margin: 0 auto 2pt;
  }

  .nota { font-size: 8.3pt; color: #444; text-align: justify; margin: 4pt 0 0; }

  /* El bloque de la firma cabe entero en la hoja donde empieza. Es la misma
     regla del certificado y por la misma razón: una firma sola en la página
     siguiente no la acepta nadie en ventanilla. Ojo, `avoid` no parte el
     bloque, lo MUEVE entero — si el formato se pasa por tres milímetros, la
     segunda hoja sale con la firma sola. Antes de añadirle nada aquí, cuenta
     las hojas: `ConsentimientoTest` lo hace. */
  .firma { margin-top: 11pt; page-break-inside: avoid; }
  .firma .linea { border-top: 0.8pt solid #333; padding-top: 3pt; }
  .firma td { vertical-align: bottom; padding-right: 14pt; }

  .legal {
    margin-top: 7pt;
    font-size: 7.4pt;
    color: #555;
    text-align: justify;
    border-top: 0.5pt solid #ddd;
    padding-top: 5pt;
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

<h1>
  Autorización para el tratamiento de datos personales y uso de imagen
  @if ($esMenor) — menor de edad @endif
</h1>

{{--
  El párrafo de arriba cambia entero según quién firme, y no es un matiz de
  redacción: un menor de edad no otorga esta autorización por sí mismo (Ley 1581
  de 2012, artículo 7). En el formato del menor quien habla es el acudiente, en
  representación suya.
--}}
@if ($esMenor)
<p class="cuerpo">
  Yo, en calidad de padre, madre o acudiente del niño, niña o adolescente que se
  identifica más abajo, y actuando en su representación, autorizo de manera
  previa, expresa e informada a <strong>{{ $institucion->nombre_institucion }}</strong>
  para lo que se detalla en este documento.
</p>
@else
<p class="cuerpo">
  Yo, identificado como aparece más abajo, autorizo de manera previa, expresa e
  informada a <strong>{{ $institucion->nombre_institucion }}</strong> para lo que
  se detalla en este documento.
</p>
@endif

<h2>{{ $esMenor ? 'Quién participa en los procesos formativos' : 'Quién autoriza' }}</h2>

{{--
  Los tres datos del menor van en UNA fila y no en dos, y eso es alto medido, no
  gusto: la versión de menor de edad pedía 797 pt en una carta de 792 y salía en
  dos hojas. Un renglón de datos son unos 20 pt. Ver el comentario de `@page`.
--}}
<table class="datos">
  <tr>
    <td @if ($esMenor) style="width:46%" @endif>
      <div class="etiqueta">Nombre completo</div>
      <div class="valor @if (! $estudiante) vacio @endif">{{ $estudiante?->nombre_completo }}</div>
    </td>
    <td @if ($esMenor) style="width:27%" @endif>
      <div class="etiqueta">Documento n.º</div>
      <div class="valor @if (! $documento) vacio @endif">{{ $documento }}</div>
    </td>
    @if ($esMenor)
    <td style="width:27%">
      <div class="etiqueta">Fecha de nacimiento</div>
      <div class="valor @if (! $estudiante) vacio @endif">
        {{ $estudiante?->fecha_nacimiento?->translatedFormat('j/m/Y') }}
      </div>
    </td>
    @endif
  </tr>
</table>

@if ($esMenor)
<h2>Quién autoriza, en representación del menor</h2>

<table class="datos">
  <tr>
    <td>
      <div class="etiqueta">Nombre completo del acudiente</div>
      <div class="valor @if (! $acudiente) vacio @endif">{{ $acudiente?->nombre }}</div>
    </td>
    <td>
      <div class="etiqueta">Documento de identidad n.º</div>
      <div class="valor vacio"></div>
    </td>
  </tr>
  <tr>
    <td>
      <div class="etiqueta">Parentesco o calidad en que actúa</div>
      <div class="valor vacio"></div>
    </td>
    <td>
      <div class="etiqueta">Teléfono de contacto</div>
      <div class="valor @if (! $acudiente?->telefono) vacio @endif">{{ $acudiente?->telefono }}</div>
    </td>
  </tr>
</table>
@endif

<h2>Qué se autoriza</h2>

{{--
  Las dos finalidades. Son EXACTAMENTE las que anuncia la política de
  tratamiento de datos: si algún día cambian aquí, hay que cambiarlas también
  en `Support\PoliticaDatos` — un consentimiento que autoriza algo que la
  política no anuncia no vale.
--}}
<table class="autorizacion">
  <tr>
    <td class="texto">
      <strong>1. Tratamiento de mis datos personales.</strong>
      Autorizo a {{ $institucion->nombre_institucion }} a recolectar, almacenar,
      usar y tratar los datos personales, académicos y sociodemográficos
      {{ $esMenor ? 'del menor que represento' : 'que he entregado' }} para la
      gestión de la matrícula y de las actividades formativas, y para el
      <strong>{{ $institucion->finalidadDeDatos() }}</strong>,
      incluyendo su entrega a las autoridades competentes cuando la ley lo exija.
    </td>
    <td class="marcar">
      <table class="casillas">
        <tr><td class="rotulo" colspan="2">Marca una</td></tr>
        <tr>
          <td><span class="casilla"></span>Sí</td>
          <td><span class="casilla"></span>No</td>
        </tr>
      </table>
    </td>
  </tr>
</table>

<table class="autorizacion">
  <tr>
    <td class="texto">
      <strong>2. Uso de la imagen.</strong>
      Autorizo a {{ $institucion->nombre_institucion }} a captar, reproducir y
      divulgar la imagen, la voz y las fotografías o videos
      {{ $esMenor ? 'del menor que represento' : 'de mi persona' }} tomados
      durante las actividades, con el fin de
      <strong>{{ $institucion->finalidadDeImagen() }}</strong>, en piezas
      informativas, publicaciones, redes sociales
      y material de divulgación, sin contraprestación económica alguna.
    </td>
    <td class="marcar">
      <table class="casillas">
        <tr><td class="rotulo" colspan="2">Marca una</td></tr>
        <tr>
          <td><span class="casilla"></span>Sí</td>
          <td><span class="casilla"></span>No</td>
        </tr>
      </table>
    </td>
  </tr>
</table>

<p class="nota">
  Las dos autorizaciones son independientes: <strong>negar la del uso de la
  imagen no afecta la matrícula</strong> ni la participación en ninguna
  actividad. Si no se marca ninguna casilla, se entiende que no se otorga esa
  autorización.
</p>

<div class="firma">
  <table style="width:100%">
    <tr>
      <td style="width:52%">
        <div class="linea">
          <span class="etiqueta">Firma de quien autoriza</span>
        </div>
      </td>
      <td style="width:26%">
        <div class="linea">
          <span class="etiqueta">Documento n.º</span>
        </div>
      </td>
      <td style="width:22%">
        <div class="linea">
          <span class="etiqueta">Fecha</span>
        </div>
      </td>
    </tr>
  </table>
</div>

<p class="legal">
  Esta autorización se otorga conforme a la Ley 1581 de 2012 y al Decreto 1377
  de 2013. Como titular {{ $esMenor ? '—o su representante—' : '' }} tienes
  derecho a conocer, actualizar, rectificar y suprimir tus datos, a solicitar
  prueba de esta autorización, a ser informado sobre el uso que se les ha dado,
  a revocarla y a presentar quejas ante la Superintendencia de Industria y
  Comercio. Los datos sensibles y los de menores de edad son de entrega
  facultativa: nadie está obligado a suministrarlos.
  La política de tratamiento de datos de {{ $institucion->nombre_institucion }}
  se puede consultar en {{ $politica }}.
  @if ($institucion->entidad_correo)
    Para ejercer tus derechos escribe a {{ $institucion->entidad_correo }}.
  @endif
</p>

</body>
</html>
