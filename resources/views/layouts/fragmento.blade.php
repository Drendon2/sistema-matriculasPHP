{{--
  El envoltorio SIN pagina: es, literalmente, lo que va dentro de <main> en
  `layouts.app`.

  Que sean las mismas dos lineas y en el mismo orden no es casualidad ni se
  puede tocar por separado: `acciones.js` mete esto en el <main> que ya existe,
  asi que lo que salga de aqui tiene que ser lo mismo que habria salido de una
  carga normal. Si un dia el layout mete algo mas dentro de <main>, va aqui
  tambien — y al reves.

  Los mensajes siguen teniendo UN solo sitio, que es este. Es lo que evita que
  el aviso de una accion se pinte en un lugar cuando la pagina se carga entera y
  en otro cuando se repinta sin recargar.
--}}
@include('partials.mensajes')
@yield('content')
