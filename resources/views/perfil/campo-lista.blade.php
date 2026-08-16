{{--
  Un campo de la encuesta demográfica: desplegable con su lista cerrada.

  Las opciones salen de `EncuestaDemografica::OPCIONES`, la misma tabla que usa
  la ficha para traducir el código guardado a su texto. Repetir aquí las listas
  sería tener dos vocabularios que se separan en cuanto alguien añada una opción.

  Contexto:
    $campo ......... nombre de la columna
    $etiqueta ...... el texto de la <label>
    $obligatorio ... si no admite dejarlo en blanco (por defecto, no)
--}}
@php($obligatorio = $obligatorio ?? false)
@php($opciones = \App\Models\EncuestaDemografica::OPCIONES[$campo])
@php($actual = old($campo, $encuesta?->{$campo}))

<div class="field">
  <label for="{{ $campo }}">{{ $etiqueta }}</label>
  <select name="{{ $campo }}" id="{{ $campo }}" @required($obligatorio)>
    {{--
      El texto del hueco dice qué pasa si se deja así, y por eso no es el mismo
      en los dos casos: en un campo obligatorio es una instrucción, y en uno
      opcional es ya una respuesta. Va neutro («elige» y no «elige una») porque
      la misma plantilla sirve para género, estrato y nivel educativo.
    --}}
    <option value="">{{ $obligatorio ? '-- elige --' : '-- prefiero no responder --' }}</option>
    @foreach ($opciones as $valor => $texto)
      <option value="{{ $valor }}" @selected((string) $actual === (string) $valor)>{{ $texto }}</option>
    @endforeach
  </select>
  @error($campo)<div class="errorlist" style="color:var(--danger);font-size:0.82rem;">{{ $message }}</div>@enderror
</div>
