<?php

namespace App\Support;

use App\Models\Perfil;
use Illuminate\Support\Facades\Log;

/**
 * Deja constancia de una accion que no se puede deshacer (F-02).
 *
 * El diagnostico del 24/08 conto CERO llamadas a `Log::` en 14.255 lineas de
 * aplicacion. Cuando algo falle en produccion, lo unico que queda es la
 * excepcion de Laravel: sin contexto de negocio no hay forma de responder a
 * «¿quien retiro esta matricula?» ni a «¿cuantas inscripciones se perdieron?».
 *
 * Va por el canal `auditoria`, que tiene el nivel escrito en la configuracion y
 * no heredado de `LOG_LEVEL`. Importa: en produccion `LOG_LEVEL=error`, asi que
 * un `Log::info` suelto se descartaria justo donde hace falta.
 *
 * NO es un sustituto de una tabla de auditoria. Un archivo rota y se pierde, y
 * no se puede consultar desde una pantalla. Es lo barato que responde la
 * pregunta el dia que se hace, y no cuesta un esquema nuevo ni una migracion
 * sobre produccion. Si algun dia direccion pide ver esto en una pantalla, esa
 * es otra conversacion y otra tabla.
 *
 * QUE SE GUARDA Y QUE NO: identificadores, nunca nombres ni documentos. Un id
 * se resuelve consultando la base cuando de verdad haga falta; un nombre en un
 * archivo de texto que vive 180 dias es una copia de datos personales fuera de
 * la base, sin permisos y sin nadie vigilandola. La unica excepcion del
 * proyecto esta en `InscripcionActividadController`, donde el documento SI se
 * registra porque la persona entra sin cuenta y ese numero es lo unico que
 * permite volver a encontrarla para avisarle de que no quedo inscrita.
 */
class Auditoria
{
    public const CANAL = 'auditoria';

    /**
     * @param  array<string, mixed>  $datos  identificadores del objeto tocado
     * @param  Perfil|null  $quien  quien lo hizo; null si lo hizo el sistema
     */
    public static function registrar(string $accion, array $datos, ?Perfil $quien = null): void
    {
        Log::channel(self::CANAL)->info($accion, ['quien' => $quien?->id] + $datos);
    }
}
