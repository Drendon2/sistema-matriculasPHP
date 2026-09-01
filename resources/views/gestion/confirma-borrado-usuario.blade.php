@extends('layouts.app')

@section('title', 'Eliminar cuenta')

@section('content')
<div class="card" style="max-width:480px;">
@if ($impedimento)
  {{--
    Misma regla que en la confirmacion de los catalogos: preguntar «¿seguro?»
    para negarse despues es hacer perder el viaje, y aqui la respuesta ya se
    sabe. Asi que no hay pregunta ni campo de contraseña, solo el porque y la
    salida.
  --}}
  <h2>No se puede eliminar esta cuenta</h2>
  <p>{{ $impedimento }}</p>
  <p class="campo-info">
    Desactivarla le cierra la puerta y conserva su historial. Eliminarla se lo
    llevaría por delante, y por eso el sistema no lo permite.
  </p>
  <p style="margin-bottom:0;">
    <a href="{{ route('usuario-lista') }}" class="btn btn-secundario">Volver</a>
  </p>
@else
  <h2>¿Eliminar la cuenta de «{{ $usuario->nombre_completo }}»?</h2>

  {{--
    Quien es, dicho con los dos datos por los que se le distingue en la lista.
    Dos personas del mismo nombre son un caso corriente en una casa de la
    cultura, y el usuario es lo unico que no se repite.
  --}}
  <p class="campo-info" style="margin-top:-0.6rem;">
    Usuario <strong>{{ $usuario->user->username }}</strong> · {{ $usuario->rol_display }}
  </p>

  @if ($arrastre)
  <p class="campo-info">
    Se llevará también <strong>{{ $arrastre }}</strong>.
  </p>
  @endif

  <p class="campo-info">
    No se puede deshacer. Si solo quieres cerrarle el acceso,
    <strong>desactívala</strong> desde el listado en vez de eliminarla.
  </p>

  <form method="post" action="{{ $accion }}">
    @csrf

    {{--
      La contraseña de QUIEN BORRA, no la de la cuenta que se va. Una sesion
      abierta en un celular prestado basta para llegar hasta aqui, y este campo
      es lo que comprueba que quien pulsa es la persona y no el aparato.
    --}}
    <label for="password">Escribe tu contraseña para confirmar</label>
    <input type="password" name="password" id="password"
           autocomplete="current-password" required autofocus>
    @error('password')
      <ul class="errorlist"><li>{{ $message }}</li></ul>
    @enderror

    <div style="display:flex;gap:0.6rem;margin-top:0.9rem;">
      <button type="submit" class="btn btn-retirar">Sí, eliminar</button>
      <a href="{{ route('usuario-lista') }}" class="btn btn-secundario">Cancelar</a>
    </div>
  </form>
@endif
</div>
@endsection
