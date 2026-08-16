{{--
  El nombre de un estudiante en las tablas del Panel: enlace a su ficha si quien
  mira puede abrirla, texto si no, y detrás las marcas que lo acompañan.

  La condición del enlace sale de `Permisos`, la misma que protege la vista, y no
  de una regla repetida aquí: sin eso un profesor vería enlaces que al pulsarlos
  lo rebotan.
--}}
@if (\App\Support\Permisos::puedeVerFicha($yo, $e['perfil']))
  <a href="{{ route('detalle-usuario', $e['perfil']) }}">{{ $e['perfil']->nombre_completo }}</a>
@else
  {{ $e['perfil']->nombre_completo }}
@endif
@include('panel.marcas', ['e' => $e])
