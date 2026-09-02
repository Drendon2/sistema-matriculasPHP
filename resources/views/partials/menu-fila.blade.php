{{--
  EL MENU DE UNA FILA: lo que se puede hacer con ese registro, plegado.

  «Editar» y «Eliminar» no son lo que se viene a hacer a una lista de catálogo
  —se entra a mirar o a bajar un nivel— y estaban puestos como lo único pulsable
  de cada fila, así que parecían la función principal de la pantalla. Aquí pasan
  a un botón discreto que los guarda.

  Recibe `$opciones`, una lista de:

    texto ..... lo que se lee
    url ....... a dónde lleva; si falta, la opción sale APAGADA
    modal ..... si se abre en el modal de `acciones.js`
    borrar .... si es la acción destructiva (se pinta en rojo)
    porque .... el `title` de una opción apagada, que dice por qué no se puede

  Y `$etiqueta`, para que un lector de pantalla no oiga «botón» catorce veces
  seguidas sin saber de cuál fila es.

  Es un `<details>` nativo: llega con el teclado, lo anuncian los lectores de
  pantalla y funciona sin JavaScript. Y NO lleva `id` a propósito — `acciones.js`
  solo conserva abiertos los `<details>` que tienen uno, así que repintar la
  lista tras una acción lo devuelve cerrado, que es lo que uno espera y evita
  que quede abierto sobre una fila recién borrada.
--}}
<details class="menu-fila">
  <summary class="menu-fila-boton" title="Acciones" aria-label="Acciones de {{ $etiqueta }}">
    {{-- Tres puntos dibujados, no el carácter «⋮»: el sistema tiene sus iconos
         en SVG y una tipografía no garantiza ni el tamaño ni el centrado. --}}
    <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
      <circle cx="12" cy="5" r="1.7"/>
      <circle cx="12" cy="12" r="1.7"/>
      <circle cx="12" cy="19" r="1.7"/>
    </svg>
  </summary>
  <div class="menu-fila-panel">
    @foreach ($opciones as $opcion)
      @if (empty($opcion['url']))
        {{-- Apagada y a la vista, no escondida: si desapareciera, enterarse de
             que algo está protegido exigiría pulsarla y que te lo nieguen. --}}
        <span class="menu-fila-inerte" title="{{ $opcion['porque'] ?? '' }}">{{ $opcion['texto'] }}</span>
      @else
        <a href="{{ $opcion['url'] }}"
           @class(['menu-fila-borrar' => ! empty($opcion['borrar'])])
           @if (! empty($opcion['modal'])) data-modal @endif>{{ $opcion['texto'] }}</a>
      @endif
    @endforeach
  </div>
</details>
