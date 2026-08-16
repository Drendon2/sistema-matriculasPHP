@extends('layouts.app')

@section('title', "Salir de {$matricula->promotoria->nombre}")

@section('content')
<a href="{{ route('mis-matriculas') }}" class="volver">&larr; Volver a mis matrículas</a>

<h2>Vas a salir de {{ $matricula->promotoria->nombre }}</h2>

<p class="aviso">
  @if ($matricula->cancelacion_es_rechazable)
    Tu solicitud queda registrada y la resuelve la dirección. Como eres menor de edad,
    hablarán antes con tu acudiente; <strong>mientras tanto sigues inscrito</strong> y tu
    cupo sigue siendo tuyo.
  @else
    Tu solicitud queda registrada y la resuelve la dirección. <strong>Sigues inscrito</strong>
    hasta que la tramiten, así que tu cupo no se libera todavía.
  @endif
</p>

<form method="post" action="{{ route('mis-matriculas.retirar', $matricula) }}">
  @csrf

  @if ($yaValoro)
    <p class="campo-info">
      Ya nos contaste cómo te fue en {{ $matricula->promotoria->nombre }}. Gracias.
    </p>
  @else
  {{--
    La encuesta se pide aquí y no después porque este es el único momento en que
    la persona sigue estando: quien se va no vuelve a entrar a contestar nada.

    Y NO es obligatoria. Quien se marcha puede estar molesto o tener una urgencia,
    y poner cinco preguntas entre esa persona y la puerta es a la vez una grosería
    y una forma segura de recoger respuestas puestas al azar para poder salir. Por
    eso hay una salida clara debajo, y no escondida.
  --}}
  <div class="card">
    <h3>¿Nos cuentas cómo te fue?</h3>
    <p class="campo-info" style="margin-top:-0.4rem;">
      Es voluntario y es lo único que le dice a {{ $configuracion->nombre_institucion }}
      qué mejorar. No lo lee tu profesor con tu nombre al lado.
    </p>

    @include('estudiante.escala', [
      'campo' => 'satisfaccion_general',
      'enunciado' => '¿Qué tan satisfecho quedaste con el proceso?',
    ])

    @include('estudiante.escala', [
      'campo' => 'calificacion_profesor',
      'enunciado' => '¿Cómo calificas el acompañamiento del profesor?',
    ])

    @include('estudiante.escala', [
      'campo' => 'horario_funciono',
      'enunciado' => '¿El horario te funcionó?',
      'opciones' => [1 => 'Sí', 0 => 'No'],
    ])

    @include('estudiante.escala', [
      'campo' => 'recomendaria',
      'enunciado' => "¿Recomendarías {$matricula->promotoria->nombre} a alguien más?",
      'opciones' => [1 => 'Sí', 0 => 'No'],
    ])

    <div class="encuesta-pregunta">
      <label for="comentario">¿Por qué te vas? (opcional)</label>
      <textarea name="comentario" id="comentario" rows="3">{{ old('comentario') }}</textarea>
      @error('comentario')<div class="errorlist" style="color:var(--danger);font-size:0.82rem;">{{ $message }}</div>@enderror
    </div>
  </div>
  @endif

  <p style="display:flex;gap:0.6rem;align-items:center;flex-wrap:wrap;">
    <button type="submit" class="btn btn-retirar">
      {{ $yaValoro ? 'Confirmar mi salida' : 'Enviar y salir' }}
    </button>
    @unless ($yaValoro)
      {{--
        Envía el mismo formulario con las respuestas en blanco: el controlador
        guarda la encuesta solo si vino contestada.
      --}}
      <button type="submit" class="btn btn-secundario" name="sin_contestar" value="1" formnovalidate>
        Salir sin contestar
      </button>
    @endunless
    <a class="volver" href="{{ route('mis-matriculas') }}">Mejor me quedo</a>
  </p>
</form>

<script>
  // «Salir sin contestar» tiene que mandar la encuesta vacía de verdad: si la
  // persona ya marcó algo y luego cambia de idea, esas marcas seguirían viajando
  // y se guardaría una respuesta que decidió no dar.
  (function () {
    var boton = document.querySelector('[name="sin_contestar"]');
    var form = boton && boton.closest("form");
    if (!boton || !form) { return; }
    boton.addEventListener("click", function () {
      form.querySelectorAll('input[type="radio"]').forEach(function (r) { r.checked = false; });
      var comentario = form.querySelector('[name="comentario"]');
      if (comentario) { comentario.value = ""; }
    });
  })();
</script>
@endsection
