<?php

namespace Tests\Unit;

use App\Exceptions\LoteNoCabe;
use PHPUnit\Framework\TestCase;

/**
 * Que le dice el reparto por lote a quien acaba de intentarlo.
 *
 * Estas pruebas van aqui y no en una de Feature por una razon concreta: el
 * camino que importa NO se puede alcanzar por HTTP. `validar()` comprueba tres
 * reglas, pero una matricula que solo cambia de grupo no aumenta la ocupacion,
 * asi que hoy la unica que puede fallar en un reparto es la del cupo del grupo.
 *
 * Justamente por eso el mensaje viejo —que afirmaba falta de cupo siempre—
 * acertaba: por casualidad. Y por eso la rama nueva, la que cuenta el motivo de
 * verdad, no tiene forma de probarse contra una peticion real. Redactar el
 * mensaje es texto puro, asi que se prueba aqui, donde las dos ramas si se
 * alcanzan.
 */
class LoteNoCabeTest extends TestCase
{
    /** El caso corriente: no hay cupo en el grupo. Dice cuantos cabian. */
    public function test_sin_cupo_en_el_grupo_dice_cuantos_cabian(): void
    {
        $excepcion = new LoteNoCabe(
            cabian: 3,
            motivo: ['grupo' => ['El grupo no tiene cupos disponibles para este periodo.']],
            culpable: 'Ana Ruiz',
        );

        $mensaje = $excepcion->explicacion(cuantos: 8, grupo: 'Violín A');

        $this->assertSame(
            'No caben 8 en Violín A: solo quedaban 3 cupos. '
            .'No se asignó a nadie — manda menos estudiantes o amplíale el cupo al grupo.',
            $mensaje
        );
    }

    /** Un solo hueco se dice en singular. */
    public function test_un_solo_cupo_va_en_singular(): void
    {
        $excepcion = new LoteNoCabe(
            cabian: 1,
            motivo: ['grupo' => ['El grupo no tiene cupos disponibles para este periodo.']],
        );

        $this->assertStringContainsString(
            'solo quedaban 1 cupo.',
            $excepcion->explicacion(cuantos: 4, grupo: 'Danza B')
        );
    }

    /**
     * El motivo que NO es de cupo se cuenta tal cual, sin inventarle una causa.
     *
     * Es el fallo que arregla este cambio: antes, cualquier motivo se respondia
     * como si fuera falta de cupo. Hoy esta rama no se alcanza por HTTP, pero el
     * dia que `validar()` gane una comprobacion nueva sera la que hable, y tiene
     * que decir la verdad en vez de un numero de cupos que nadie comprobo.
     */
    public function test_otro_motivo_se_cuenta_tal_cual_y_con_el_nombre(): void
    {
        $excepcion = new LoteNoCabe(
            cabian: 2,
            motivo: ['promotoria' => [
                'Un estudiante puede estar en un máximo de 2 promotorías por periodo, '
                .'y este ya tiene ese cupo ocupado.',
            ]],
            culpable: 'Beto Salas',
        );

        $mensaje = $excepcion->explicacion(cuantos: 5, grupo: 'Teatro A');

        $this->assertStringContainsString('Beto Salas:', $mensaje);
        $this->assertStringContainsString('máximo de 2 promotorías por periodo', $mensaje);
        $this->assertStringContainsString('No se asignó a nadie.', $mensaje);

        // Lo que NO debe decir: la cifra de cupos que nadie comprobo.
        $this->assertStringNotContainsString('quedaban', $mensaje);
        $this->assertStringNotContainsString('cupo al grupo', $mensaje);
    }

    /** Sin nombre —no deberia pasar, pero el mensaje no se rompe por eso—. */
    public function test_sin_nombre_el_mensaje_sigue_siendo_legible(): void
    {
        $excepcion = new LoteNoCabe(
            cabian: 0,
            motivo: ['promotoria' => ['Violín no tiene cupos disponibles para 2026-1.']],
        );

        $this->assertSame(
            'Violín no tiene cupos disponibles para 2026-1. No se asignó a nadie.',
            $excepcion->explicacion(cuantos: 3, grupo: 'Violín A')
        );
    }

    /** Un motivo vacio tampoco puede dejar un mensaje a medias. */
    public function test_sin_motivo_lo_dice_en_vez_de_callarse(): void
    {
        $excepcion = new LoteNoCabe(cabian: 0, motivo: []);

        $this->assertSame(
            'La validación lo rechazó sin dar un motivo. No se asignó a nadie.',
            $excepcion->explicacion(cuantos: 2, grupo: 'Coro')
        );
    }
}
