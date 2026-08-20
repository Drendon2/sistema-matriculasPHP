{{--
  El horario semanal de un grupo, como rejilla de días.

  Lo comparten las dos pantallas que crean grupos —el Panel de quien dicta y
  Gestión— porque la regla del horario es la misma en las dos, y tenerla escrita
  dos veces es como acabó pasando que una promotoría admitiera algo que la otra
  pantalla rechazaba.

  Una fila por día y una casilla que la enciende: así se pueden poner varios
  días sin botones de «añadir» ni JavaScript que mantener. Las horas de una fila
  sin marcar se ignoran, así que desmarcar un día no obliga a borrarlas — quien
  se equivocó puede volver a marcarlo y ahí siguen.

  Contexto:
    $sesiones ... salida de HorarioDeGrupo::paraElFormulario()
--}}
<div class="field">
  <label>Horario</label>
  <span class="campo-info" style="margin:0 0 0.6rem;">
    Marca los días en que se reúne este grupo y ponles hora. Puede ser más de uno.
  </span>

  @error('sesiones')<div class="errorlist" style="color:var(--danger);font-size:0.82rem;margin-bottom:0.5rem;">{{ $message }}</div>@enderror

  <table class="sesiones-rejilla">
    <tbody>
      @foreach (\App\Models\SesionGrupo::DIAS as $dia => $nombre)
      <tr>
        <td class="sesiones-dia">
          <label class="sesiones-check">
            <input type="checkbox" name="sesiones[{{ $dia }}][activo]" value="1"
                   id="sesion-{{ $dia }}" @checked($sesiones[$dia]['activo'])>
            <span>{{ $nombre }}</span>
          </label>
        </td>
        <td>
          <label class="sesiones-etiqueta" for="sesion-{{ $dia }}-desde">Desde</label>
          <input type="time" name="sesiones[{{ $dia }}][desde]" id="sesion-{{ $dia }}-desde"
                 step="300" value="{{ $sesiones[$dia]['desde'] }}">
        </td>
        <td>
          <label class="sesiones-etiqueta" for="sesion-{{ $dia }}-hasta">Hasta</label>
          <input type="time" name="sesiones[{{ $dia }}][hasta]" id="sesion-{{ $dia }}-hasta"
                 step="300" value="{{ $sesiones[$dia]['hasta'] }}">
        </td>
      </tr>
      @error('sesiones.'.$dia.'.desde')
      <tr><td colspan="3" class="errorlist" style="color:var(--danger);font-size:0.82rem;">{{ $message }}</td></tr>
      @enderror
      @error('sesiones.'.$dia.'.hasta')
      <tr><td colspan="3" class="errorlist" style="color:var(--danger);font-size:0.82rem;">{{ $message }}</td></tr>
      @enderror
      @endforeach
    </tbody>
  </table>
</div>

<script>
  // Marcar el día enfoca su hora de inicio, y escribir una hora marca el día.
  // Son las dos mitades del mismo gesto: nadie pone una hora en un día que no
  // pensaba dar, y nadie marca un día para dejarlo sin hora. Sin esto, el
  // formulario rebota con «falta la hora» por un descuido de un clic.
  (function () {
    document.querySelectorAll(".sesiones-rejilla tr").forEach(function (fila) {
      var casilla = fila.querySelector("input[type=checkbox]");
      var horas = fila.querySelectorAll("input[type=time]");
      if (!casilla || !horas.length) { return; }

      casilla.addEventListener("change", function () {
        if (casilla.checked) { horas[0].focus(); }
      });

      horas.forEach(function (hora) {
        hora.addEventListener("input", function () {
          if (hora.value) { casilla.checked = true; }
        });
      });
    });
  })();
</script>
