{{--
  La celda de marcas de una fila de asistencia, sea de una clase o de una sesion
  de actividad. Paso 1 de B-01, la mitad de Blade; la del guardado es
  `App\Support\PaseDeLista`.

  Espera:
    $idDeQuien   int     matricula_id o inscrito_id, el que nombra el campo
    $estado      string  lo marcado hoy, '' si nadie lo ha marcado
    $estados     array   valor => etiqueta, del modelo de asistencia
    $puedeMarcar bool    si quien mira puede escribir en esta hoja
    $marca       string  OPCIONAL, clase CSS del chip de solo lectura

  Las tres ramas son la misma decision de siempre: se pinta el control si esta
  persona puede marcar; si no, lo ya marcado; y si no hay nada, que no hay nada.
  «Sin marcar» va como texto y no como una cuarta opcion a proposito, porque no
  es un estado que nadie elija: es la ausencia de fila.

  OJO CON $marca, que es la unica diferencia que queda entre las dos pantallas.
  En promotorias el chip de solo lectura va coloreado (`ResumenAsistencia::MARCA`
  lo mapea a los chips de estado de matricula: verde, rojo y ambar); en
  actividades sale sin color, y no por decision sino porque nadie lo puso. Se
  deja OPCIONAL para que este parcial no cambie como se ve ninguna de las dos:
  unificarlo es tocar el aspecto de una pantalla que esta en produccion y esa no
  es una decision que tome un refactor.
--}}
@if ($puedeMarcar)
  @foreach ($estados as $valor => $etiqueta)
  <label class="asistencia-opcion asistencia-opcion-{{ $valor }}">
    <input type="radio" name="{{ \App\Support\PaseDeLista::PREFIJO }}{{ $idDeQuien }}" value="{{ $valor }}"
           @checked($estado === $valor)>{{ $etiqueta }}
  </label>
  @endforeach
@elseif ($estado)
  <span class="estado {{ $marca ?? '' }}">{{ $estados[$estado] ?? '' }}</span>
@else
  <span class="vacio">Sin marcar</span>
@endif
