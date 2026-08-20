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

> **Estado: reconstrucción completa y auditada.** Las tres áreas del sistema
> —autoservicio del estudiante, panel de quien dicta y gestión de dirección—
> están terminadas y probadas, con 275 pruebas en verde. Falta el despliegue en
> el servidor.

## Stack

| Capa | |
|---|---|
| Lenguaje | PHP 8.2 |
| Framework | Laravel 12 (la última rama compatible con PHP 8.2) |
| Base de datos | MariaDB 10.5 |
| Plantillas | Blade |
| Autenticación | Sesiones nativas de Laravel, por `username` |
| Interacción | JavaScript plano, sin build step |

Sin Node ni compilación de assets: el CSS va como archivo estático y el JS son
cuatro ficheros sueltos, ninguno imprescindible. Es deliberado — el destino es
hosting compartido. Todo lo que hace el JavaScript es comodidad: las casillas de
selección múltiple, la carga diferida del Panel, el paso entre periodos con el
dedo. Sin él, las pantallas se marcan a mano y los enlaces siguen ahí.

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
- **El rol de administrador no se reparte hacia arriba.** Un director crea y
  edita usuarios, pero no puede nombrar administradores ni tocar la cuenta de
  uno: si pudiera, se daría el rol a sí mismo —o le cambiaría la contraseña al
  administrador— y las tres pantallas que el enrutado le reserva dejarían de
  significar nada.
- **A un mayor de edad no se le discute la salida.** Rechazar una cancelación
  solo cabe con menores, y existe para dar tiempo a hablar con el acudiente antes
  de que un niño se salga por su cuenta.
- **Configurable sin tocar código**: nombre, logo, color de acento, cuántas
  promotorías puede cursar alguien y qué papeles se exigen se editan desde la
  propia interfaz.

## Las tres áreas

**Estudiante** — catálogo de promotorías, inscripción, renovación con encuesta de
satisfacción, sus matrículas y el retiro, confirmación de clases, sus compañeros
y su perfil (foto, documento, papeles y encuesta demográfica).

**Panel** (quien dicta, dirección y administración) — confirmar y rechazar
solicitudes (una a una o por lote), fijar el cupo del periodo, crear grupos y
repartir en ellos a los matriculados (uno a uno o por lote), registrar clases,
pasar lista y consultar la ficha y la trayectoria de cada persona.

**Gestión** (dirección y administración) — el catálogo en cuatro niveles
(departamento → promotoría → grupo → estudiantes) con filtros de grupos por
departamento, promotoría y profesor; la ventana de matrículas, los cupos de todo
el catálogo de una vez, las cancelaciones por resolver (también por lote), los
usuarios con filtros por catálogo y periodo, los ajustes de la institución y las
estadísticas agregadas.

## Informes descargables

Dos, en CSV, que abren con doble clic en Excel y al importar en Hojas de cálculo:

| | |
|---|---|
| **Estudiantes por grupo** | La lista que se lleva impresa al salón: nombre, edad, teléfono y acudiente. Se puede pedir entera, de una promotoría o de un solo grupo, y hay enlace en las tres pantallas donde se entra a un grupo. |
| **Informe completo de la institución** | Todo el padrón con rol, contacto, promotoría, nivel, tiempo cursado y la encuesta demográfica. **Solo administrador**, y con aviso en pantalla antes de descargar. |

El segundo es el único sitio donde la encuesta demográfica sale con nombre y
apellido: en pantalla siempre va agregada. Fue una decisión de dirección, tomada
a sabiendas, para poder reportar a quien financia la institución.

CSV y no `.xlsx` a propósito: un `.xlsx` real pediría `phpoffice/phpspreadsheet`
—unos 10 MB en `vendor/` y bastante memoria por descarga— y el destino es hosting
compartido. Llevan BOM UTF‑8 y separador `;`, que es lo que hace que Excel en
español los abra con las tildes bien y en columnas.

## Horario de los grupos

El horario de un grupo son **datos y no texto**: una fila por sesión en
`sesiones_grupo`, con día (1 = lunes … 6 = sábado), hora de inicio y hora de
fin. Un grupo puede reunirse varios días —«Martes y jueves 4:00 p. m. a 6:00
p. m.»— con un máximo de una sesión por día.

Se crea marcando días en una rejilla, la misma en el Panel y en Gestión. El
motor no admite una sesión que termine antes de empezar, ni dos el mismo día,
ni el domingo.

El texto del horario se **deriva** de las sesiones y no se guarda: cuando
existían las dos cosas acababan discrepando, y entonces no hay forma de saber
cuál miente. Los días que comparten hora se enuncian juntos, que es como lo dice
la gente.

De ahí sale la **rejilla semanal del perfil**: al estudiante los grupos donde
está repartido, a quien dicta los grupos que da, siempre del periodo en curso.
Las filas son las franjas que de verdad se usan y no las horas del reloj.

## Certificado de matrícula

Un PDF que acredita que alguien está matriculado, para llevar a un colegio o a
una empresa. En dos formas: el de **una matrícula** —promotoría, área, grupo y
horario, docente, fecha— con botón en cada fila de «Mis matrículas» y de la
trayectoria; y el **reunido**, con todas las promotorías vigentes del periodo en
curso, desde «Mi perfil» y desde la ficha.

Solo se certifican las matrículas **activas** y las **finalizadas** (activas de
un periodo ya cerrado, que acreditan haber cursado). Una pendiente no: nadie ha
confirmado todavía que esa persona esté en el curso.

Quién puede bajarlo va por el vínculo, no por el rol: el propio estudiante,
dirección, y el profesor **solo el de la promotoría que dicta**. El reunido no se
le ofrece al profesor porque lista promotorías que la ficha le esconde.

La firma que lo sella se carga en **Gestión → Institución**: imagen, nombre y
cargo de quien firma. Se guarda en PNG y no en WebP como el resto —el generador
de PDF no entiende WebP— y, a diferencia del logo, **no se sirve en abierto**:
una firma escaneada en una URL pública se la lleva cualquiera. Sin firma cargada
el certificado se genera igual, con el espacio en blanco para firmarlo a mano.

Lo genera `dompdf` (`barryvdh/laravel-dompdf`). Necesita `dom`, `mbstring` y
`gd`, las tres ya habilitadas en el hosting.

## Estadísticas

Matrícula por departamento y promotoría con permanencia y deserción, mapa de
calor de la actividad de la casa, ranking de profesores por clases dictadas —con
cuántas les verificaron sus estudiantes al lado— y ranking de estudiantes por
constancia, más la encuesta demográfica y la de satisfacción agregadas.

Se camina entre periodos con flechas (o deslizando el dedo en el móvil), lo mismo
que el panel de asistencia de cada perfil. Solo se ofrecen los periodos donde esa
persona tiene algo: una flecha que lleva a un panel vacío no informa de nada.

## Seguridad

Lo que hay puesto, por si alguien tiene que auditarlo o extenderlo:

- **Límite de intentos** en las tres puertas que se pueden empujar sin cuenta. El
  del login cuenta por **usuario + IP** y no por IP sola: una escuela entera sale
  a internet por una sola dirección, y con el contador por IP treinta estudiantes
  entrando desde la sala de cómputo se bloquearían entre sí.
- **Ocho caracteres mínimo** en las contraseñas, declarado una sola vez con
  `Password::defaults()`. No es un puerto: el original no valida la contraseña
  por ninguna parte.
- **Archivos fuera del webroot.** Fotos y documentos viven en
  `storage/app/private` y se entregan por rutas que comprueban antes quién los
  pide. Las fotos se re-codifican a WebP con GD, lo que destruye cualquier carga
  útil incrustada.
- **CSRF** en todos los formularios que cambian estado, y consultas siempre por
  el ORM: no hay una sola concatenación de SQL con datos del usuario.
- **Celdas de CSV neutralizadas**: un valor que empieza por `=`, `+`, `-` o `@`
  lo ejecutaría Excel al abrir el archivo, y aquí los nombres y los barrios los
  escribe el público.

Queda anotado un asunto conocido: **desactivar una cuenta no cierra la sesión que
ya tuviera abierta**. `activo` se comprueba al iniciar sesión y no en cada
petición, así que quien fue dado de baja conserva acceso hasta que su sesión
expire (`SESSION_LIFETIME`, 120 minutos por defecto).

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

331 pruebas. Corren contra **MariaDB, no contra SQLite**, y no es una preferencia: buena parte
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

### Pasos

```bash
composer install --no-dev --optimize-autoloader

cp .env.production.example .env     # NO el .env.example, que es el de desarrollo
# edita el .env: dominio, base de datos y las líneas marcadas «CAMBIAR»

php artisan key:generate            # se genera aquí, no se copia la de local
php artisan migrate --force         # --force: en producción pide confirmación

php artisan config:cache
php artisan route:cache
php artisan view:cache
```

`vendor/` no está versionado, de ahí el primer paso. Las tres cachés del final
son las que hacen que cada petición no vuelva a leer y compilar la configuración,
las rutas y las plantillas; **hay que repetirlas después de cada despliegue**, y
`config:cache` además de cada cambio en el `.env` — con la configuración cacheada,
editar el `.env` deja de tener efecto hasta que se regenera.

### Las cuatro líneas que importan del `.env`

`.env.production.example` ya viene con ellas puestas, pero conviene saber por qué
están:

| | |
|---|---|
| `APP_DEBUG=false` | Con `true`, cualquiera que provoque un error 500 recibe la traza completa: credenciales de la base y el contenido de las consultas, que aquí incluye nombres, teléfonos y documentos de identidad de menores. |
| `APP_ENV=production` | Además de la configuración, es lo único que impide que `php artisan simular` siembre 300 cuentas de prueba con contraseña conocida. |
| `SESSION_SECURE_COOKIE=true` | Sin ella la cookie de sesión viaja también por http plano. |
| `LOG_LEVEL=error` | En `debug` se escribe cada consulta: llena la cuota de disco del plan compartido y deja datos personales en un archivo que nadie vigila. |

### Lo demás

Las fotos y las copias de documentos se guardan en `storage/app/private`, **fuera
de `public/`**, y solo se entregan por una ruta que comprueba antes quién las
pide. Hay que dejarle permiso de escritura a `storage/` y a `bootstrap/cache/`.

Conviene crear **un segundo administrador** antes de abrir el sistema. La cuenta
de un administrador solo la edita otro administrador, así que si se pierde el
acceso al único que hay, ni un director puede recuperarlo: habría que tocar la
base de datos.

Los respaldos se sacan con `./respaldar.sh`, que guarda las dos cosas que no se
pueden reconstruir desde el repositorio —la base **con sus triggers** y los
archivos subidos—. Su carpeta de destino no puede quedar bajo `public/`.

El proyecto **no necesita** el scheduler de Laravel: los plazos se calculan al
leer, no con tareas programadas.

## Licencia

Proyecto privado.
