<?php

/**
 * Prueba de concurrencia del cupo: dos peticiones peleando por el ULTIMO sitio.
 *
 * Es la unica razon por la que el trigger existe. La validacion del modelo no
 * basta: entre que comprueba el cupo y escribe la fila hay una ventana, y dos
 * matriculas simultaneas pasan las dos por ella. Sin el `FOR UPDATE` sobre la
 * fila de `cupos_promotoria`, esta prueba termina con dos matriculas en una
 * promotoria de cupo 1.
 *
 * Se ejecuta con:  php database/verificacion_concurrencia.php
 */

$dsn = 'mysql:host=127.0.0.1;port=3307;dbname=matriculas;charset=utf8mb4';
$opt = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];
$cerrojo = __DIR__ . '/.cerrojo_tomado';

$db = new PDO($dsn, 'matriculas', 'matriculas', $opt);

// Escenario limpio: Violin con cupo 1 y nadie inscrito.
$db->exec("SET FOREIGN_KEY_CHECKS = 0");
foreach (['confirmaciones_clase','asistencias','clases','matriculas','grupos',
          'cupos_promotoria','promotorias','perfiles','periodos','areas','users'] as $t) {
    $db->exec("TRUNCATE TABLE $t");
}
$db->exec("SET FOREIGN_KEY_CHECKS = 1");

$db->exec("INSERT INTO users (id, username, password, activo, created_at, updated_at)
           VALUES (1,'ana','x',1,NOW(),NOW()), (2,'beto','x',1,NOW(),NOW())");
$db->exec("INSERT INTO perfiles (id, user_id, rol, nombre_completo, fecha_nacimiento, telefono, created_at, updated_at)
           VALUES (1,1,'estudiante','Ana Ruiz','2000-05-01','3000000000',NOW(),NOW()),
                  (2,2,'estudiante','Beto Diaz','2000-06-01','3000000001',NOW(),NOW())");
$db->exec("INSERT INTO areas (id, nombre, created_at, updated_at) VALUES (1,'Musica',NOW(),NOW())");
$db->exec("INSERT INTO periodos (id, nombre, fecha_inicio, fecha_fin, activo, matriculas_abiertas, created_at, updated_at)
           VALUES (1,'2026-1','2026-01-15','2026-06-30',1,1,NOW(),NOW())");
$db->exec("INSERT INTO promotorias (id, nombre, area_id, created_at, updated_at)
           VALUES (1,'Violin',1,NOW(),NOW())");
$db->exec("INSERT INTO cupos_promotoria (promotoria_id, periodo_id, cupo_maximo, created_at, updated_at)
           VALUES (1,1,1,NOW(),NOW())");
@unlink($cerrojo);

echo "Escenario: Violin, cupo 1, nadie inscrito. Ana y Beto lo piden a la vez.\n\n";

// A arranca en su propio proceso y retiene el cerrojo 3 segundos.
$cmd = 'start /B "" ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/verificacion_concurrencia_a.php');
pclose(popen($cmd, 'r'));

$t0 = microtime(true);
while (!file_exists($cerrojo) && microtime(true) - $t0 < 15) {
    usleep(50000);
}
if (!file_exists($cerrojo)) {
    exit("La transaccion A nunca llego a tomar el cerrojo.\n");
}
echo "A (Ana): matricula escrita, transaccion ABIERTA, cerrojo tomado.\n";

$b = new PDO($dsn, 'matriculas', 'matriculas', $opt);
$b->exec("SET innodb_lock_wait_timeout = 20");
$b->beginTransaction();

echo "B (Beto): pide el mismo ultimo sitio...\n";
$inicio = microtime(true);
$sobreventa = false;
try {
    $b->exec("INSERT INTO matriculas (estudiante_id, promotoria_id, periodo_id, fecha, estado, ranura, created_at, updated_at)
              VALUES (2, 1, 1, NOW(), 'pendiente', 1, NOW(), NOW())");
    $b->commit();
    $sobreventa = true;
    printf("B: PASO tras %.2fs -> el cerrojo no sirvio.\n", microtime(true) - $inicio);
} catch (PDOException $e) {
    printf("B: rechazado tras esperar %.2fs (estuvo bloqueado en el FOR UPDATE).\n", microtime(true) - $inicio);
    echo "B: {$e->getMessage()}\n";
    $b->rollBack();
}

$total = $db->query("SELECT COUNT(*) FROM matriculas
                     WHERE promotoria_id=1 AND periodo_id=1 AND estado <> 'retirada'")->fetchColumn();

echo "\nMatriculas finales en una promotoria de cupo 1: {$total}\n";
@unlink($cerrojo);

if ($sobreventa || $total != 1) {
    echo "FALLO: hubo sobreventa.\n";
    exit(1);
}
echo "CORRECTO: la carrera quedo serializada, sin sobreventa.\n";
