<?php

namespace Tests;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Cada `actingAs` empieza con la sesion vacia: otra persona es otro
     * navegador.
     *
     * Existe por `AuthenticateSession`, que ata la sesion al hash de la
     * contrasena de quien la abrio. `actingAs` cambia de usuario pero CONSERVA
     * la sesion del anterior, asi que la segunda persona de un mismo test
     * llegaba con un hash que no era el suyo y el middleware la echaba. No es
     * un caso real: `/entrar` va detras del middleware `guest` —quien tiene
     * sesion abierta ni siquiera alcanza el controlador de login— y cerrar
     * sesion llama a `invalidate()`, que vacia la sesion entera.
     *
     * Va aqui y no repetido en cada prueba a proposito: son cinco pruebas hoy y
     * seria una trampa nueva para cada una que se escriba manana, con un
     * sintoma —un redirect a /entrar sin motivo— que no apunta a su causa.
     *
     * No rompe nada de lo que ya habia: ninguna prueba prepara la sesion antes
     * de `actingAs` (no hay un solo `withSession` ni un `from()` en la suite), y
     * lo que una peticion deje en sesion sobrevive, porque el vaciado ocurre
     * antes de la peticion y no despues.
     */
    public function actingAs(Authenticatable $user, $guard = null)
    {
        $this->flushSession();

        return parent::actingAs($user, $guard);
    }
}
