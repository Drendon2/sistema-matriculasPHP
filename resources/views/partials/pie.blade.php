{{--
  EL PIE DE PÁGINA. Lo comparten los dos envoltorios —el de con sesión y el
  público— porque el enlace a la política de tratamiento de datos tiene que
  verse en TODAS las pantallas, también en las tres de quien todavía no tiene
  cuenta, que son justo donde alguien entrega sus datos por primera vez.

  VA FUERA DE `<main>`, y eso no es colocación: `acciones.js` responde a una
  acción reemplazando el contenido de `<main>` sin navegar, y `layouts.fragmento`
  —que es literalmente lo que va dentro de `<main>`— no lo incluye. Metido
  dentro, el pie desaparecería tras la primera acción que se hiciera sin
  recargar, sin que nada fallara ni avisara.

  Es a la vez el `<footer>` que un lector de pantalla anuncia como pie de la
  página, así que no lleva `role` ni `aria-` de más: la etiqueta ya lo dice.
--}}
<footer class="pie">
  <a href="{{ route('politica-datos') }}">Tratamiento de datos personales</a>
  <span class="pie-separador" aria-hidden="true">·</span>
  <span class="pie-entidad">{{ $configuracion->nombre_institucion }}</span>
</footer>
