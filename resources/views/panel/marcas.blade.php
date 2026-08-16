{{--
  Las etiquetas que acompañan al nombre en las tres tablas del Panel.

  Van en su propio archivo porque el original las repite verbatim en las tres, y
  la del papel que falta lleva dentro un bucle para armar el `title`: copiada
  tres veces, cualquier cambio se aplicaba en dos.
--}}
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
