{{--
  La barra de paginacion, unica para todas las listas.

  Es una vista propia y no la de Laravel porque las que trae por defecto son de
  Tailwind, y aqui no hay Tailwind: el CSS es de mano y vive en
  `public/css/app.css`. Con la de fabrica la barra saldria sin un solo estilo.

  Anterior/Siguiente y el rango, sin numeros de pagina. Quien busca a una
  persona concreta usa los filtros de arriba, que es mas rapido que adivinar en
  que pagina cayo; los numeros anadirian markup y CSS para un gesto que casi
  nadie hace. El rango si va, porque «51-100 de 308» es lo que responde a
  «¿cuanta gente hay?», que es la pregunta de verdad.

  Los extremos se pintan como <span> inerte y no como enlace muerto: un <a> sin
  href no recibe foco y un lector de pantalla no lo anuncia, mientras que
  aria-disabled si dice que el control existe y ahora no se puede usar.
--}}
@if ($paginator->hasPages())
<nav class="paginacion" role="navigation" aria-label="Paginación">
  @if ($paginator->onFirstPage())
    <span class="paginacion-btn paginacion-btn-inerte" aria-disabled="true">Anterior</span>
  @else
    <a class="paginacion-btn" href="{{ $paginator->previousPageUrl() }}" rel="prev">Anterior</a>
  @endif

  <span class="paginacion-rango">
    {{ $paginator->firstItem() }}–{{ $paginator->lastItem() }} de {{ $paginator->total() }}
  </span>

  @if ($paginator->hasMorePages())
    <a class="paginacion-btn" href="{{ $paginator->nextPageUrl() }}" rel="next">Siguiente</a>
  @else
    <span class="paginacion-btn paginacion-btn-inerte" aria-disabled="true">Siguiente</span>
  @endif
</nav>
@endif
