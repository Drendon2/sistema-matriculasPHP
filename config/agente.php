<?php

/*
 * La API de solo lectura que consulta el agente de voz (10/10/2026).
 *
 * APAGADA POR DEFECTO, y de dos maneras:
 *
 * - Sin `AGENTE_API_TOKEN_SHA256` la API entera contesta 404: no existe. Es el
 *   estado de produccion hoy. Se guarda la HUELLA del token y no el token, para
 *   que quien lea el `.env` no se lleve una llave que funcione.
 *
 * - Sin `AGENTE_DATOS_PERSONALES=true` lo que habla de una persona (verificar,
 *   sus matriculas, su asistencia) tambien contesta 404, aunque el catalogo
 *   este encendido. Decision del usuario el 10/10/2026: lo personal solo corre
 *   en la demo, porque la voz y la transcripcion pasan por proveedores fuera
 *   del pais y la politica de datos no lo cubre.
 */
return [
    'token_sha256' => env('AGENTE_API_TOKEN_SHA256'),

    'datos_personales' => (bool) env('AGENTE_DATOS_PERSONALES', false),

    // Cuanto dura la sesion que deja una verificacion acertada. Una llamada
    // dura minutos; si se corta, se vuelve a verificar.
    'sesion_minutos' => 15,
];
