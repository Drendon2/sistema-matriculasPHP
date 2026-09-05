{{--
  EL RECORDATORIO DE LA ENCUESTA, en la pantalla a la que cae cada quien al
  entrar.

  Pedido por el usuario el 05/09/2026 con el motivo escrito: «las personas no
  responden de manera voluntaria la encuesta demográfica». La encuesta es lo que
  sostiene Gestión → Estadísticas —barrio, estrato, zona, grupo étnico— y es
  también lo que una entidad pública reporta; con la mitad sin contestar, esas
  cifras no describen a nadie.

  PIDE, NO OBLIGA, y esa fue la palabra del encargo: «le solicite amablemente».
  No bloquea ninguna pantalla, no se pone delante de nada y se puede seguir
  usando el sistema sin tocarlo. Un muro aquí dejaría fuera a quien entra con
  prisa a mirar su horario, y el sistema no tiene forma de avisarle por ningún
  otro canal de que se ha quedado encerrado.

  SOLO LE SALE A QUIEN LE FALTA ALGO. Quien ya la contestó no ve nada nunca, así
  que encender el interruptor no molesta a los que ya cumplieron. Y dice CUÁNTAS
  faltan, no «completa tu perfil»: es la diferencia entre una tarea con final
  visible y una que parece que no se acaba nunca.

  NO SE PINTA EN «Mi perfil», que es donde vive la encuesta: ahí el aviso
  quedaría encima del propio formulario que pide rellenar.

  Se apaga entero desde Gestión → Institución (`recordar_encuesta`), también
  pedido: un aviso en cada entrada es de las cosas que cansan, y la institución
  tiene que poder quitarlo sin que nadie toque código.
--}}
@php($encuestaDeQuienMira = $yo?->encuesta)
@php($faltanEnLaEncuesta = $encuestaDeQuienMira === null ? [] : $encuestaDeQuienMira->preguntas_faltantes)
@php($sinEmpezar = $yo !== null && $yo->rol !== '' && $encuestaDeQuienMira === null)

@if ($configuracion->recordar_encuesta && ($sinEmpezar || $faltanEnLaEncuesta))
<div class="recordar-encuesta">
  <p class="recordar-encuesta-texto">
    <strong>Ayúdanos a conocer a quienes participan.</strong>
    @if ($sinEmpezar)
      Todavía no has contestado la encuesta de {{ $configuracion->nombre_institucion }}.
      Son unas pocas preguntas y toma menos de un minuto.
    @else
      Te {{ count($faltanEnLaEncuesta) === 1 ? 'falta' : 'faltan' }}
      <strong>{{ count($faltanEnLaEncuesta) }}
        {{ count($faltanEnLaEncuesta) === 1 ? 'pregunta' : 'preguntas' }}</strong>
      por contestar en la encuesta: {{ implode(', ', $faltanEnLaEncuesta) }}.
    @endif
  </p>
  {{-- Al ancla de la sección, que además se pinta abierta cuando falta algo. --}}
  <a class="btn" href="{{ route('mi-perfil') }}#bloque-encuesta">
    {{ $sinEmpezar ? 'Contestar la encuesta' : 'Terminar la encuesta' }}
  </a>
</div>
@endif
