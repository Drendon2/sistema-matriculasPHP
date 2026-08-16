{{--
  Una pregunta de la encuesta de satisfacción, como fila de opciones marcables.

  Por defecto es la escala del 1 al 5; pasando `opciones` sirve igual para las
  dos preguntas de sí/no. Es la misma forma en las cinco a propósito: son un
  trámite corto, y cambiar de control a mitad de una tarjeta hace que se lea como
  si cada pregunta fuera de otra parte.

  Contexto:
    $campo ....... nombre del input
    $enunciado ... la pregunta
    $opciones .... valor => etiqueta (por defecto, 1..5)
--}}
@php($opciones = $opciones ?? \App\Models\EncuestaSatisfaccion::ESCALA)

<div class="encuesta-pregunta">
  <p class="encuesta-enunciado">{{ $enunciado }}</p>
  <div class="encuesta-escala">
    @foreach ($opciones as $valor => $etiqueta)
    <label>
      <input type="radio" name="{{ $campo }}" value="{{ $valor }}"
             @checked((string) old($campo) === (string) $valor)> {{ $etiqueta }}
    </label>
    @endforeach
  </div>
  @error($campo)<div class="errorlist" style="color:var(--danger);font-size:0.82rem;">{{ $message }}</div>@enderror
</div>
