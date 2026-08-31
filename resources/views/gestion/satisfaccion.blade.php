{{--
  Cómo valoraron los estudiantes el periodo que cursaron.

  Va agregada y sin nombres: poner «Ana Ruiz — 2 al profesor» en un tablero
  convierte una encuesta en un marcador, y la vez siguiente la gente contesta
  pensando en quién va a leerla. La única excepción es el bloque de seguimiento
  del final, que solo ve quien administra y existe para poder llamar a quien lo
  pasó mal.

  Contexto: $satisfaccion, la salida de `EstadisticasController::satisfaccion()`.
--}}
@if (! $satisfaccion)
  <p class="vacio">
    Todavía nadie ha contestado la encuesta de satisfacción. Se llena al renovar
    matrícula, así que aparecerá cuando los estudiantes del periodo pasado empiecen
    a renovar.
  </p>
@else

<p class="campo-info" style="margin: 0.9rem 0 1.3rem;">
  {{ $satisfaccion['respuestas'] }}
  {{ $satisfaccion['respuestas'] == 1 ? 'respuesta' : 'respuestas' }}
  sobre <strong>{{ $satisfaccion['periodo']->nombre }}</strong>, el último periodo evaluado.
  @if ($satisfaccion['cobertura'] !== null)
    Contestó el <strong>{{ $satisfaccion['cobertura'] }}%</strong> de los
    {{ $satisfaccion['cursaron'] }} que lo cursaron.
  @endif
  La encuesta se llena al renovar, así que quien no volvió tampoco la contestó:
  estas cifras describen a quien siguió, no a quien se fue.
</p>

<div class="dash-resumen">
  <div>
    <span class="dash-stat-num">{{ number_format($satisfaccion['media_general'], 1) }}</span>
    <span class="dash-stat-label">Satisfacción media</span>
  </div>
  <div>
    <span class="dash-stat-num">{{ number_format($satisfaccion['media_profesor'], 1) }}</span>
    <span class="dash-stat-label">Acompañamiento del profesor</span>
  </div>
  <div>
    <span class="dash-stat-num">{{ $satisfaccion['recomienda']['pct_si'] }}%</span>
    <span class="dash-stat-label">Recomendaría</span>
  </div>
  <div>
    <span class="dash-stat-num">{{ $satisfaccion['horario']['pct_si'] }}%</span>
    <span class="dash-stat-label">El horario le funcionó</span>
  </div>
</div>

<div class="dash-grid-2" style="margin-top: 1.7rem;">
  <div>
    <h4>Satisfacción general <span class="h4-nota">(1 a 5)</span></h4>
    @include('gestion.barras', ['filas' => $satisfaccion['general']])
  </div>
  <div>
    <h4>Acompañamiento del profesor <span class="h4-nota">(1 a 5)</span></h4>
    @include('gestion.barras', ['filas' => $satisfaccion['profesor']])
  </div>
</div>

<div class="dash-grid-2" style="margin-top: 1.3rem;">
  <div>
    <h4>¿Recomendaría su promotoría?</h4>
    <div class="dash-split">
      <div class="dash-split-si" style="width: {{ $satisfaccion['recomienda']['pct_si'] }}%;"></div>
      <div class="dash-split-no" style="width: {{ $satisfaccion['recomienda']['pct_no'] }}%;"></div>
    </div>
    <div class="dash-split-leyenda">
      <span><span class="punto" style="background: var(--accent);"></span>Sí ({{ $satisfaccion['recomienda']['si'] }})</span>
      <span><span class="punto" style="background: var(--border-strong);"></span>No ({{ $satisfaccion['recomienda']['no'] }})</span>
    </div>
  </div>
  <div>
    <h4>¿El horario le funcionó?</h4>
    <div class="dash-split">
      <div class="dash-split-si" style="width: {{ $satisfaccion['horario']['pct_si'] }}%;"></div>
      <div class="dash-split-no" style="width: {{ $satisfaccion['horario']['pct_no'] }}%;"></div>
    </div>
    <div class="dash-split-leyenda">
      <span><span class="punto" style="background: var(--accent);"></span>Sí ({{ $satisfaccion['horario']['si'] }})</span>
      <span><span class="punto" style="background: var(--border-strong);"></span>No ({{ $satisfaccion['horario']['no'] }})</span>
    </div>
  </div>
</div>

@if ($satisfaccion['porPromotoria'])
<h4 style="margin-top: 1.7rem;">Por promotoría</h4>
<p class="campo-info" style="margin-top:-0.4rem;">
  De peor a mejor valorada: lo que se viene a mirar aquí es dónde hay un problema.
  El número de respuestas va al lado porque una media de 2,0 sacada de una sola
  respuesta todavía no es un problema.
</p>
<table>
  <thead>
    <tr>
      <th>Promotoría</th>
      <th class="num">Respuestas</th>
      <th>Satisfacción</th>
      <th class="num">Profesor</th>
      <th class="num">Recomiendan</th>
    </tr>
  </thead>
  <tbody>
    @foreach ($satisfaccion['porPromotoria'] as $p)
    <tr>
      <td>
        <span class="tag-dot {{ $p['promotoria']->area->tag_color }}"></span>{{ $p['promotoria']->nombre }}
        <span class="historial-area">{{ $p['promotoria']->area->nombre }}</span>
      </td>
      <td class="num">{{ $p['total'] }}</td>
      <td>
        <div class="stat-bar-fila" style="margin:0;">
          <div class="stat-bar-pista">
            <div class="stat-bar-relleno" style="width: {{ $p['porcentaje'] }}%;"></div>
          </div>
          <span class="stat-bar-num">{{ number_format($p['general'], 1) }}</span>
        </div>
      </td>
      <td class="num">{{ number_format($p['profesor'], 1) }}</td>
      <td class="num">{{ $p['recomiendan'] }}/{{ $p['total'] }}</td>
    </tr>
    @endforeach
  </tbody>
</table>
@endif

@if ($satisfaccion['sinPromotoria'])
<p class="campo-info">
  {{ $satisfaccion['sinPromotoria'] }}
  {{ $satisfaccion['sinPromotoria'] == 1 ? 'respuesta queda' : 'respuestas quedan' }}
  fuera de este desglose: se contestaron cuando la encuesta todavía no distinguía
  promotorías y no se pueden atribuir a ninguna sin inventar. Sí cuentan en las
  medias generales de arriba.
</p>
@endif

@if (count($satisfaccion['porPeriodo']) > 1)
<h4 style="margin-top: 1.7rem;">Satisfacción por periodo</h4>
{{--
  La barra va contra el 5 de la escala y no contra el periodo mejor valorado: en
  una escala acotada, medir cada barra contra la más alta convierte la diferencia
  entre un 4,9 y un 4,8 en un precipicio.
--}}
@foreach ($satisfaccion['porPeriodo'] as $p)
<div class="stat-bar-fila">
  <span class="stat-bar-etiqueta">{{ $p['etiqueta'] }}</span>
  <div class="stat-bar-pista">
    <div class="stat-bar-relleno" style="width: {{ $p['porcentaje'] }}%;"></div>
  </div>
  <span class="stat-bar-num">{{ number_format($p['media'], 1) }}</span>
</div>
@endforeach
<p class="campo-info">
  Media de satisfacción general sobre 5. El número de respuestas cambia de un
  periodo a otro, así que conviene mirarlas junto a la cobertura y no como una
  serie que se pueda comparar sin más.
</p>
@endif

<h4 style="margin-top: 1.7rem;">
  Lo que escribieron
  <span class="h4-nota">({{ count($satisfaccion['comentarios']) }})</span>
</h4>
@if (! $satisfaccion['comentarios'])
  <p class="vacio">Nadie dejó comentario esta vez.</p>
@else
{{-- Sin nombre y sin nada al lado que permita deducir de quién es cada uno. --}}
<ul class="comentarios">
  @foreach ($satisfaccion['comentarios'] as $comentario)
    <li>{{ $comentario }}</li>
  @endforeach
</ul>
@endif

@if ($satisfaccion['veNombres'])
{{--
  El único sitio con nombres, y solo para administración. Se levanta el anonimato
  a propósito y acotado a las notas bajas: el motivo de recoger la encuesta es
  poder hablar con quien lo pasó mal, y para eso hace falta saber a quién llamar.
  Por eso va el teléfono: sin él la lista no sirve para lo único que la justifica.
--}}
<h4 id="seguimiento" style="margin-top: 1.9rem;">
  Para seguimiento
  <span class="h4-nota">(solo administración)</span>
</h4>
<p class="campo-info" style="margin-top:-0.4rem;">
  Quienes puntuaron con <strong>2 o menos</strong> la experiencia o el acompañamiento.
  El resto de esta pantalla es anónimo; esto no, porque existe para poder llamarlos.
</p>
@if (! $satisfaccion['seguimiento'])
  <p class="vacio">Nadie puntuó por debajo de 3. No hay a quién llamar.</p>
@else
<table>
  <thead>
    <tr>
      <th>Estudiante</th>
      <th>A quién llamar</th>
      <th class="num">Experiencia</th>
      <th class="num">Profesor</th>
      <th>¿Recomendaría?</th>
      <th>Comentario</th>
    </tr>
  </thead>
  <tbody>
    @foreach ($satisfaccion['seguimiento'] as $s)
    <tr>
      <td>
        @if (\App\Support\Permisos::puedeVerFicha($yo, $s['perfil']))
          <a href="{{ route('detalle-usuario', $s['perfil']) }}">{{ $s['perfil']->nombre_completo }}</a>
        @else
          {{ $s['perfil']->nombre_completo }}
        @endif
        @if ($s['es_menor'])
          <span class="estado estado-pendiente">Menor de edad</span>
        @endif
      </td>
      {{--
        Con un menor la conversación es con su acudiente, no con él. Si es menor
        y no hay acudiente registrado, se dice: es un hueco que hay que resolver
        antes de llamar a nadie, no un teléfono que se pueda sustituir por el del
        niño.
      --}}
      <td>
        @if (! $s['es_menor'])
          {{ $s['perfil']->telefono }}
        @elseif ($s['acudiente'])
          {{ $s['acudiente']->nombre }}<br>
          <span class="campo-info" style="margin:0;">{{ $s['acudiente']->telefono }} · acudiente</span>
        @else
          <span class="vacio">Sin acudiente registrado</span>
        @endif
      </td>
      <td class="num">{{ $s['general'] }}</td>
      <td class="num">{{ $s['profesor'] }}</td>
      <td>{{ $s['recomendaria'] ? 'Sí' : 'No' }}</td>
      <td>@if ($s['comentario']){{ $s['comentario'] }}@else<span class="vacio">—</span>@endif</td>
    </tr>
    @endforeach
  </tbody>
</table>
@endif
@endif

@endif
