{{--
  La celda de marcas de una fila de asistencia, sea de una clase o de una sesion
  de actividad. Paso 1 de B-01, la mitad de Blade; la del guardado es
  `App\Support\PaseDeLista`.

  Espera:
    $idDeQuien   int     matricula_id o inscrito_id, el que nombra el campo
    $estado      string  lo marcado hoy, '' si nadie lo ha marcado
    $estados     array   valor => etiqueta, del modelo de asistencia
    $puedeMarcar bool    si quien mira puede escribir en esta hoja

  Las tres ramas son la misma decision de siempre: se pinta el control si esta
  persona puede marcar; si no, lo ya marcado; y si no hay nada, que no hay nada.
  «Sin marcar» va como texto y no como una cuarta opcion a proposito, porque no
  es un estado que nadie elija: es la ausencia de fila.

  El color del chip de solo lectura se DERIVA del estado, aqui dentro. Antes lo
  calculaba el controlador de promotorias y se lo pasaba al parcial, y el de
  actividades no se lo pasaba: el mismo estado salia coloreado en una pantalla y
  gris en la otra, no por decision sino porque nadie lo puso. Derivarlo aqui es
  lo que hace que no puedan volver a separarse, porque ya no hay nada que
  acordarse de pasar.
--}}
@if ($puedeMarcar)
  @foreach ($estados as $valor => $etiqueta)
  <label class="asistencia-opcion asistencia-opcion-{{ $valor }}">
    <input type="radio" name="{{ \App\Support\PaseDeLista::PREFIJO }}{{ $idDeQuien }}" value="{{ $valor }}"
           @checked($estado === $valor)>{{ $etiqueta }}
  </label>
  @endforeach
@elseif ($estado)
  <span class="estado {{ \App\Support\ResumenAsistencia::MARCA[$estado] ?? '' }}">{{ $estados[$estado] ?? '' }}</span>
@else
  <span class="vacio">Sin marcar</span>
@endif
