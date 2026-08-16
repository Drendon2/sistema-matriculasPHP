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
    <option value="">{{ $obligatorio ? '-- elige una --' : '-- prefiero no responder --' }}</option>
    @foreach ($opciones as $valor => $texto)
      <option value="{{ $valor }}" @selected((string) $actual === (string) $valor)>{{ $texto }}</option>
    @endforeach
  </select>
  @error($campo)<div class="errorlist" style="color:var(--danger);font-size:0.82rem;">{{ $message }}</div>@enderror
</div>
