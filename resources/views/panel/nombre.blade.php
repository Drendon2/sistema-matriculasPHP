{{--
  El nombre de un estudiante en las tablas del Panel: enlace a su ficha si quien
  mira puede abrirla, texto si no, y detrás las marcas que lo acompañan.

  La condición del enlace sale de `Permisos`, la misma que protege la vista, y no
  de una regla repetida aquí: sin eso un profesor vería enlaces que al pulsarlos
  lo rebotan.

  Las marcas estaban en su propio parcial y se fusionaron aquí. No es capricho:
  este bloque se pinta una vez por estudiante y el Panel de un director con
  trescientos alumnos tiene ~500 filas. Cada `@include` de Blade resuelve el
  nombre de la vista, comprueba la caché contra el disco y monta un ámbito nuevo,
  y a esa escala eso se mide en cientos de milisegundos. Como `marcas` no lo usaba
  nadie más, fusionarlo no cuesta duplicación: solo quita 500 renders anidados.
--}}
@if (\App\Support\Permisos::puedeVerFicha($yo, $e['perfil']))
  <a href="{{ route('detalle-usuario', $e['perfil']) }}">{{ $e['perfil']->nombre_completo }}</a>
@else
  {{ $e['perfil']->nombre_completo }}
@endif
@if ($e['cancelacion'])
  <span class="estado estado-cancelacion_solicitada">Pidió cancelar</span>
@endif
@if ($e['papeles_pendientes'])
  @php($faltan = count($e['papeles_pendientes']))
  <span class="estado estado-papeles"
        title="Le faltan: {{ implode(', ', array_map(fn ($d) => $d->nombre, $e['papeles_pendientes'])) }}">
    Faltan {{ $faltan }} {{ $faltan == 1 ? 'papel' : 'papeles' }}
  </span>
@endif
