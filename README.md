# Sistema de Matrículas — versión PHP

Plataforma de autoservicio para inscripción, confirmación y asignación de grupos
en una escuela de artes (casas de cultura).

Es la **reconstrucción en Laravel + MariaDB** del proyecto original en Django +
PostgreSQL ([`Drendon2/sistema-matriculas`](https://github.com/Drendon2/sistema-matriculas)),
para poder desplegarlo en hosting compartido tradicional — que ejecuta PHP bajo
demanda y no admite un proceso Python persistente.

No es una traducción automática de código: son las mismas reglas de negocio y las
mismas pantallas, reimplementadas sobre otra pila. El proyecto Django sigue siendo
la especificación funcional de referencia.

> **Estado: reconstrucción completa.** Las tres áreas del sistema —autoservicio
> del estudiante, panel de quien dicta y gestión de dirección— están terminadas y
> probadas. Falta el despliegue en el servidor.

## Stack

| Capa | |
|---|---|
| Lenguaje | PHP 8.2 |
| Framework | Laravel 12 (la última rama compatible con PHP 8.2) |
| Base de datos | MariaDB 10.5 |
| Plantillas | Blade |
| Autenticación | Sesiones nativas de Laravel, por `username` |
| Interacción | JavaScript plano, sin build step |

Sin Node ni compilación de assets: el CSS va como archivo estático y el JS son dos
ficheros sueltos. Es deliberado — el destino es hosting compartido.

## Qué resuelve

El público se inscribe desde su casa o su celular en una **promotoría** artística
(música, danza, teatro, pintura…), sin elegir horario. Quien la dicta confirma la
matrícula y luego crea **grupos** según su disponibilidad, repartiendo ahí a los ya
matriculados.

Algunas reglas que no se ven a simple vista:

- **Cupos garantizados por la base de datos**, no solo por la aplicación: un
  trigger con bloqueo de fila impide que dos solicitudes simultáneas se lleven el
  mismo último sitio.
- **Pedir la baja no es lo mismo que darse de baja.** Retirar una solicitud sin
  confirmar es inmediato; salirse de una matrícula activa es una solicitud que
  resuelve dirección, y hasta entonces el estudiante sigue inscrito y su sitio
  sigue ocupado.
- **La clase la verifican los estudiantes, no quien la dictó.** Quien registra la
  clase es parte interesada, así que queda «sin verificar» hasta que varios de
  los que estuvieron den fe, dentro de un plazo de 48 horas. En un grupo de uno o
  dos basta una confirmación: un requisito inalcanzable no verifica nada.
- **Privacidad diferenciada por rol**: quién ve nombre, edad, teléfono, acudiente,
  encuesta o documento de identidad está definido de forma estricta (pensado para
  la Ley 1581 de Colombia). Ningún archivo subido se sirve por URL directa.
- **Configurable sin tocar código**: nombre, logo, color de acento, cuántas
  promotorías puede cursar alguien y qué papeles se exigen se editan desde la
  propia interfaz.

## Las tres áreas

**Estudiante** — catálogo de promotorías, inscripción, renovación con encuesta de
satisfacción, sus matrículas y el retiro, confirmación de clases, sus compañeros
y su perfil (foto, documento, papeles y encuesta demográfica).

**Panel** (quien dicta, dirección y administración) — confirmar y rechazar
solicitudes, fijar el cupo del periodo, crear grupos y repartir en ellos a los
matriculados (uno a uno o por lote), registrar clases, pasar lista y consultar la
ficha y la trayectoria de cada persona.

**Gestión** (dirección y administración) — el catálogo en cuatro niveles
(departamento → promotoría → grupo → estudiantes), la ventana de matrículas, los
cupos de todo el catálogo de una vez, las cancelaciones por resolver, los
usuarios con filtros por catálogo y periodo, los ajustes de la institución y las
estadísticas agregadas.

## Puesta en marcha local

Requisitos: PHP 8.2 (con `gd`, `exif`, `pdo_mysql`, `mbstring`, `intl`, `zip`),
Composer y MariaDB 10.5.

```bash
git clone https://github.com/Drendon2/sistema-matriculasPHP.git
cd sistema-matriculasPHP
composer install
cp .env.example .env
php artisan key:generate
```

Completa las credenciales de tu base de datos en `.env` y luego:

```bash
php artisan migrate
php artisan serve
```

El repositorio no incluye datos de demostración: la base arranca vacía. Crea el
primer usuario y el catálogo desde la propia aplicación.

La zona horaria está en `America/Bogota` y de ella dependen dos reglas —si un
grupo ya tiene clase registrada hoy, y el plazo de 48 horas para confirmarla—,
así que conviene ajustarla en `.env` antes que nada si la institución está en
otro huso.

## Pruebas

```bash
php artisan test
```

167 pruebas. Corren contra **MariaDB, no contra SQLite**, y no es una preferencia: buena parte
de lo que hay que probar son garantías del motor —columnas generadas con índice
único, triggers con `SIGNAL`, `SELECT … FOR UPDATE`— y SQLite no tiene ninguna de
las tres. Necesitan una base `test_matriculas` con los mismos permisos.

Hay además dos guiones que comprueban el esquema contra SQL crudo, incluida la
carrera de dos transacciones por el último cupo:

```bash
php database/verificacion_esquema.php
php database/verificacion_concurrencia.php
```

## Despliegue

El *document root* del dominio tiene que apuntar a `public/`. Dejarlo en la raíz
del proyecto expone el `.env` y el código fuente entero a internet.

`vendor/` no está versionado: se instala en el servidor con
`composer install --no-dev --optimize-autoloader`.

Las fotos y las copias de documentos se guardan en `storage/app/private`, **fuera
de `public/`**, y solo se entregan por una ruta que comprueba antes quién las
pide. Hay que dejarle permiso de escritura a `storage/` y a `bootstrap/cache/`.

El proyecto **no necesita** el scheduler de Laravel: los plazos se calculan al
leer, no con tareas programadas.

## Licencia

Proyecto privado.
