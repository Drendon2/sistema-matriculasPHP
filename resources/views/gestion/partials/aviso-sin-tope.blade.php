{{--
  QUÉ SE QUEDA FUERA DE «Cupos disponibles».

  Una promotoría sin cupo definido para el periodo NO tiene tope: admite a quien
  llegue. O sea que no aporta ningún número a esa suma, y la cifra de al lado
  sería correcta con una etiqueta que miente por omisión — el mismo fallo que ya
  tuvo esta misma cinta cuando decía «1 · Cursos y talleres» contando tres cosas.

  Va en un parcial y no copiado en las dos pantallas porque son dos: la portada
  de Gestión y Estadísticas. Copiado, el día que alguien afine la frase la
  afinaría en una sola y las dos empezarían a decir cosas distintas del mismo
  número — que es justo lo que `Support\ResumenInstitucion` existe para evitar
  del lado del cálculo.

  DESAPARECE cuando todas tienen tope, que es el estado normal: un aviso que sale
  siempre deja de leerse.
--}}
@if ($sinTope > 0)
  <p class="campo-ayuda" style="margin-top:-0.9rem;margin-bottom:1.4rem;">
    @if ($sinTope == 1)
      Una promotoría no tiene cupo definido en este periodo, así que admite a
      quien llegue y no entra en esa cuenta.
    @else
      {{ $sinTope }} promotorías no tienen cupo definido en este periodo, así que
      admiten a quien llegue y no entran en esa cuenta.
    @endif
  </p>
@endif
