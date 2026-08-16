<?php

/**
 * Mitad A de la prueba de concurrencia (ver verificacion_concurrencia.php).
 *
 * Toma el ultimo cupo y RETIENE el cerrojo unos segundos antes de confirmar,
 * para que B se encuentre de verdad con la transaccion abierta. Vive en su
 * propio proceso porque una transaccion no se puede retener desde fuera.
 */

$db = new PDO(
    'mysql:host=127.0.0.1;port=3307;dbname=matriculas;charset=utf8mb4',
    'matriculas', 'matriculas',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$db->beginTransaction();
$db->exec("INSERT INTO matriculas (estudiante_id, promotoria_id, periodo_id, fecha, estado, ranura, created_at, updated_at)
           VALUES (1, 1, 1, NOW(), 'pendiente', 1, NOW(), NOW())");

file_put_contents(__DIR__ . '/.cerrojo_tomado', '1');
sleep(3);

$db->commit();
