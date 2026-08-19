# Guía de instalación y despliegue

Sistema de Matrículas — versión PHP (Laravel 12 + MariaDB).

Esta guía va de cero a un sistema funcionando: primero en tu computador, después
en el servidor. Está pensada para seguirse en orden y sin saltarse pasos. Cada
paso dice **qué hacer**, **qué debe pasar** si salió bien y, cuando hace falta,
**qué hacer si sale mal**.

Hay cuatro partes:

1. **Instalar en tu computador** — para desarrollar y probar.
2. **Dejar el sistema listo para usarse** — el orden en que hay que cargar los
   datos para que el sistema funcione. Aplica igual en local y en el servidor.
3. **Desplegar en el hosting** — subirlo a internet.
4. **Problemas frecuentes** — qué mirar cuando algo no responde.

---

## Las cinco advertencias que hay que leer antes de nada

Ninguna de estas es teórica: las cinco han costado tiempo o datos.

| | |
|---|---|
| **Nunca `migrate:fresh`** | Ese comando **borra todas las tablas y todos los datos**, sin preguntar. En este proyecto ya vació la base local una vez. En el servidor no se usa jamás. Para aplicar cambios se usa `php artisan migrate`, a secas. |
| **La raíz del dominio apunta a `public/`** | Si apunta a la carpeta del proyecto, quedan expuestos a internet el archivo `.env` —con la contraseña de la base— y el código fuente entero. |
| **En el servidor se copia `.env.production.example`** | No `.env.example`, que es el de desarrollo y trae `APP_DEBUG=true`. Con eso, cualquiera que provoque un error 500 recibe la traza completa: credenciales y datos personales de estudiantes. |
| **Dos administradores, no uno** | La cuenta de un administrador solo la puede editar otro administrador. Si se pierde el acceso al único que hay, ni un director puede recuperarlo: toca entrar a la base de datos a mano. |
| **Sin cupo definido no hay tope** | El límite de una promotoría lo hace cumplir un *trigger* de la base de datos, y ese trigger se apoya en la fila de cupo del periodo. Si no defines cupo para una promotoría, esa promotoría admite matrículas sin límite. |

---

# Parte 1 — Instalar en tu computador

## 1.1 Requisitos

| Programa | Versión | Para qué |
|---|---|---|
| PHP | 8.2 o superior | Ejecuta la aplicación |
| Composer | 2.x | Instala las librerías de PHP |
| MariaDB | 10.5 o superior | La base de datos |
| Git | cualquiera | Descargar y actualizar el código |

PHP necesita estas extensiones activas: **`gd`**, **`exif`**, **`pdo_mysql`**,
**`mbstring`**, **`intl`** y **`zip`**. Sin `gd` y `exif` no se pueden subir
fotos de perfil ni el logo de la institución.

Para comprobar qué tienes:

```bash
php -v
php -m
composer --version
```

En la lista de `php -m` deben aparecer las seis extensiones. Si falta alguna, se
activa quitando el `;` de su línea en el `php.ini` y reiniciando.

> **No hace falta Node ni npm.** El proyecto no compila nada: el CSS es un
> archivo estático y el JavaScript son cuatro ficheros sueltos. Es deliberado,
> porque el destino es hosting compartido.

## 1.2 Descargar el proyecto

```bash
git clone https://github.com/Drendon2/sistema-matriculasPHP.git
cd sistema-matriculasPHP
```

## 1.3 Instalar las librerías

```bash
composer install
```

La carpeta `vendor/` no viaja en el repositorio: se construye aquí. Tarda uno o
dos minutos la primera vez.

## 1.4 Crear las bases de datos

Son **dos**: la de trabajo y la de pruebas. La segunda no es opcional si vas a
correr los tests, y conviene crearlas juntas.

Entra al cliente de MariaDB como administrador:

```bash
mysql -u root -p
```

Y ejecuta, cambiando `ELIGE_UNA_CLAVE` por la que quieras usar:

```sql
CREATE DATABASE matriculas       CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE test_matriculas  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE USER 'matriculas'@'localhost' IDENTIFIED BY 'ELIGE_UNA_CLAVE';

GRANT ALL PRIVILEGES ON matriculas.*      TO 'matriculas'@'localhost';
GRANT ALL PRIVILEGES ON test_matriculas.* TO 'matriculas'@'localhost';
FLUSH PRIVILEGES;
```

Dos detalles que importan:

- **`utf8mb4_unicode_ci`** es obligatorio. Con otra codificación, las tildes y
  las eñes de los nombres se guardan mal y ya no hay vuelta atrás cómoda.
- **`ALL PRIVILEGES` incluye el permiso `TRIGGER`**, y ese permiso hace falta:
  una de las migraciones crea dos triggers. Si le das permisos sueltos al
  usuario, asegúrate de incluir `TRIGGER`.

## 1.5 Configurar el archivo `.env`

```bash
cp .env.example .env
php artisan key:generate
```

Ahora abre `.env` y ajusta estas líneas:

```ini
DB_CONNECTION=mariadb
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=matriculas
DB_USERNAME=matriculas
DB_PASSWORD=ELIGE_UNA_CLAVE

APP_TIMEZONE=America/Bogota
```

> **La zona horaria no es cosmética.** De ella dependen dos reglas de negocio:
> si un grupo ya tiene clase registrada hoy, y el plazo de 48 horas para
> confirmarla. Ajústala antes de cargar datos si la institución está en otro
> huso.

## 1.6 Crear las tablas

```bash
php artisan migrate
```

Debe terminar sin errores y listar 22 migraciones.

**Comprueba de inmediato que los dos triggers quedaron creados**, porque son la
única garantía real contra la sobreventa de cupos:

```bash
php artisan tinker --execute="echo count(DB::select('SHOW TRIGGERS')).PHP_EOL;"
```

Tiene que responder **`2`**. Si responde `0`, el usuario de la base no tiene
permiso `TRIGGER`: concédeselo y vuelve a correr la migración.

## 1.7 Arrancar el servidor

```bash
php artisan serve
```

Abre **http://127.0.0.1:8000**. Verás la pantalla de entrada. Todavía no puedes
entrar: la base está vacía y no existe ninguna cuenta. Eso es el paso siguiente.

## 1.8 Crear el primer administrador

Aquí hay un huevo y una gallina: los usuarios se crean desde Gestión, pero a
Gestión solo entra un administrador, y todavía no hay ninguno. Así que el
primero se crea por línea de comandos. **Es la única cuenta que nace así**; todas
las demás salen ya de la propia aplicación.

Abre la consola interactiva:

```bash
php artisan tinker
```

Y pega esto, cambiando los cuatro valores:

```php
$u = App\Models\User::create([
    'username' => 'tu.usuario',
    'password' => 'tu-contraseña-de-8-o-mas',
    'activo'   => true,
]);

App\Models\Perfil::create([
    'user_id'          => $u->id,
    'rol'              => 'administrador',
    'nombre_completo'  => 'Tu Nombre Completo',
    'fecha_nacimiento' => '1990-01-01',
    'telefono'         => '3000000000',
]);
```

La contraseña se cifra sola: se escribe en claro aquí y el modelo la guarda ya
convertida en hash. Sal con `exit`.

Los cuatro roles válidos son exactamente: `administrador`, `director`,
`profesor`, `estudiante`.

**Camino alternativo, si prefieres no escribir datos a mano:** regístrate en
`/registro` desde el navegador —el formulario público crea la cuenta **sin rol**,
en espera de que alguien se lo asigne— y después ascendéla desde tinker:

```php
$p = App\Models\Perfil::whereRelation('user', 'username', 'tu.usuario')->firstOrFail();
$p->rol = 'administrador';
$p->save();
```

Ya puedes entrar en http://127.0.0.1:8000 con ese usuario y esa contraseña.

## 1.9 Comprobar que la instalación está sana

```bash
php artisan test
```

Son **275 pruebas** y deben pasar todas. Corren contra la base
`test_matriculas`, **no contra la de trabajo**: no tocan tus datos.

Hay además dos guiones que comprueban el esquema contra SQL crudo, incluida la
carrera de dos transacciones por el último cupo:

```bash
php database/verificacion_esquema.php
php database/verificacion_concurrencia.php
```

> Estos dos guiones traen las credenciales de conexión escritas en sus primeras
> líneas, apuntando a la base local. Si los quieres correr contra otra base, hay
> que editar esa línea.

## 1.10 Arranque diario en esta máquina

En el computador donde se desarrolla el proyecto, **MariaDB no está registrada
como servicio de Windows**: hay que levantarla a mano en cada arranque. Y vive en
el **puerto 3307**, porque el 3306 lo ocupa un MySQL80 ajeno al proyecto.

Hay dos guiones en la carpeta padre que lo resuelven:

```powershell
.\arrancar-mariadb.ps1   # solo la base de datos
.\arrancar-app.ps1       # la base, el respaldo, el servidor y el navegador
```

Los dos son idempotentes: se pueden ejecutar siempre al empezar, sin comprobar
nada antes. Para parar el servidor, `Ctrl+C`.

**`arrancar-app.ps1` respalda la base en cada arranque**, antes de levantar el
servidor. El respaldo va al abrir y no al cerrar a propósito: así guarda el
estado con el que terminó la sesión anterior, antes de que la de hoy pueda
romperlo. Si el respaldo falla, avisa pero **no impide trabajar**.

Para sacar uno a mano en cualquier momento:

```powershell
.\respaldar.ps1                 # conserva los 10 más recientes
.\respaldar.ps1 -Conservar 30
```

Ese envoltorio existe porque en Windows `mysqldump` no está en el PATH y el
guion de verdad —`matriculas_php/respaldar.sh`, el mismo que corre en el
servidor— es bash. El envoltorio le indica dónde está el ejecutable y lo lanza
con el bash de Git.

---

# Parte 2 — Dejar el sistema listo para usarse

El sistema recién instalado está vacío: no hay departamentos, ni periodos, ni
promotorías. **El orden de estos pasos importa**, porque cada uno necesita al
anterior. Todo se hace desde el navegador, con la cuenta de administrador.

## 2.1 La institución

**Gestión → Institución** (solo administrador).

| Campo | Qué es |
|---|---|
| Nombre de la institución | Sale en la cabecera de todas las pantallas |
| Logo | Opcional. Se convierte a WebP automáticamente |
| Color de acento | En formato `#rrggbb`. Tiñe botones y enlaces |
| Límite de promotorías por periodo | Cuántas puede tomar una persona a la vez |
| Promotorías visibles para estudiantes | Si el catálogo se ve o no desde fuera |

## 2.2 Los documentos que se le piden al estudiante

En la misma pantalla de Institución. Cada documento que agregues aquí es un
archivo que el estudiante deberá entregar (copia del documento de identidad,
por ejemplo).

Los documentos que dejas de pedir **se desactivan, no se borran**: lo ya
entregado se conserva y se sigue viendo.

## 2.3 El segundo administrador

**Gestión → Usuarios → Nuevo.**

Hazlo ahora, no después. La cuenta de un administrador solo la edita otro
administrador; con uno solo, perder el acceso significa entrar a la base de
datos a mano.

## 2.4 Los directores y profesores

**Gestión → Usuarios → Nuevo**, eligiendo el rol.

| Rol | Qué puede hacer |
|---|---|
| **Administrador** | Todo: institución, estadísticas, copias de documentos de identidad |
| **Director** | El catálogo académico y los usuarios, pero no la institución ni las estadísticas |
| **Profesor** | Su Panel: sus promotorías, sus grupos, sus clases y su asistencia |
| **Estudiante** | Su matrícula, sus clases y sus compañeros |

Los profesores también pueden **registrarse solos** en `/registro`. La cuenta
queda sin rol y solo ve una pantalla de «cuenta pendiente» hasta que un director
o un administrador se lo asigna desde Gestión → Usuarios.

## 2.5 Los departamentos

**Gestión → Departamentos → Nuevo.** Son las áreas artísticas: música, danza,
teatro, artes plásticas. Cada una recibe un color de etiqueta estable.

## 2.6 El periodo, y ponerlo en curso

**Gestión → Periodos → Nuevo:** nombre, fecha de inicio y fecha de fin.

Después hay que **ponerlo en curso** desde **Gestión → Matrículas**. Esto es un
paso aparte y hay que darlo: sin periodo en curso, el catálogo no se ve y no se
puede matricular nadie.

Poner un periodo en curso **cierra el anterior** y deja sus matrículas cerradas.

## 2.7 Las promotorías

**Gestión → Promotorías → Nuevo:** nombre, departamento al que pertenece y
**quién la dicta**. Quien la dicta puede ser un profesor o un director.

## 2.8 Los cupos del periodo

**Gestión → Cupos.** Aquí se define, para cada promotoría y **para ese periodo**,
cuánta gente cabe.

> **Este paso es el que hace que el tope exista.** El límite lo impone un trigger
> de la base de datos que se apoya en la fila de cupo. Una promotoría sin cupo
> definido para el periodo **no tiene fila, no tiene cerrojo y no tiene tope**:
> admite matrículas sin límite.

## 2.9 Los grupos

**Gestión → Grupos → Nuevo**, o desde el Panel de cada promotoría: nivel,
horario, salón y cupo máximo del grupo.

Los grupos son el horario concreto. El estudiante **no elige grupo** al
matricularse: se matricula en la promotoría y quien la dicta le asigna un grupo
después.

## 2.10 Abrir las matrículas

**Gestión → Matrículas**, interruptor de matrículas abiertas.

Mientras estén cerradas, el formulario público de inscripción muestra un aviso y
rechaza los envíos, aunque alguien llegue a la URL directamente.

## 2.11 Cómo se usa a partir de aquí

1. **El público se inscribe** en `/inscripcion`: crea su cuenta y elige sus
   promotorías en un solo formulario. Un menor de edad debe registrar acudiente.
2. **Quien dicta confirma o rechaza** desde su **Panel**. Puede hacerlo en bloque.
3. **Se asigna grupo** a cada matrícula confirmada, también en bloque.
4. **Se registran las clases** y se pasa lista desde el Panel del grupo.
5. **El estudiante confirma su asistencia** desde «Mis clases», dentro del plazo
   de 48 horas.
6. **Los informes** se descargan en CSV desde las pantallas de grupo y desde
   Gestión.

---

# Parte 3 — Desplegar en el hosting

Escrito para Hostinger (PHP 8.2, LiteSpeed, MariaDB), pero sirve para cualquier
hosting compartido equivalente.

## 3.0 Antes de tocar nada, comprueba tres cosas

1. **La versión de PHP del plan es 8.2 o superior**, y tiene activas `gd`,
   `exif`, `pdo_mysql`, `mbstring`, `intl` y `zip`. En hPanel se cambia y se
   revisa desde la configuración de PHP.
2. **Hay acceso SSH**. Sin SSH no se pueden correr `composer`, `artisan` ni las
   migraciones, y el despliegue se complica bastante: habría que subir `vendor/`
   por FTP y ejecutar las migraciones por otros medios.
3. **La raíz del dominio se puede cambiar** para que apunte a una subcarpeta.

## 3.1 Crear la base de datos

Desde hPanel, en la sección de bases de datos MySQL: crea la base y un usuario, y
asígnale todos los permisos.

Anota los tres datos: **nombre de la base, usuario y contraseña**. En hosting
compartido suelen llevar un prefijo de tu cuenta, del estilo `u123456_`.

El servidor de base de datos es casi siempre `localhost` y el puerto `3306`.

## 3.2 Subir el código

Con SSH y Git, que es lo más cómodo para actualizar después:

```bash
cd ~/dominios/tu-dominio.com
git clone https://github.com/Drendon2/sistema-matriculasPHP.git app
cd app
```

Sin Git: comprime el proyecto **sin** `vendor/`, `node_modules/`, `.env` ni
`storage/logs/`, súbelo por el gestor de archivos y descomprímelo allí.

## 3.3 Apuntar el dominio a `public/`

La raíz del dominio (*document root*) tiene que apuntar a la carpeta **`public/`**
del proyecto, no a la carpeta del proyecto.

> Si apunta a la carpeta del proyecto, quedan accesibles por URL el `.env` —con
> la contraseña de la base de datos— y todo el código fuente.

Si el plan no permite cambiar la raíz del dominio, la salida es dejar el proyecto
fuera de `public_html` y mover el contenido de `public/` dentro de `public_html`,
corrigiendo después las dos rutas del archivo `index.php` que apuntan a
`vendor/autoload.php` y a `bootstrap/app.php`.

## 3.4 Instalar las librerías, sin las de desarrollo

```bash
composer install --no-dev --optimize-autoloader
```

`--no-dev` deja fuera PHPUnit y las herramientas de desarrollo.
`--optimize-autoloader` acelera cada petición.

## 3.5 El archivo `.env` de producción

```bash
cp .env.production.example .env
```

> **`.env.production.example`, no `.env.example`.** El segundo es el de
> desarrollo y trae `APP_DEBUG=true`.

Edita el `.env` y rellena todo lo que diga `CAMBIAR`:

```ini
APP_URL=https://tu-dominio.com

DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=u123456_matriculas
DB_USERNAME=u123456_matriculas
DB_PASSWORD=la-contraseña-que-anotaste

MAIL_FROM_ADDRESS="no-responder@tu-dominio.com"
```

Y **verifica** que estas cuatro líneas están tal cual. La plantilla ya las trae,
pero es lo que hay que mirar dos veces:

| | |
|---|---|
| `APP_DEBUG=false` | Con `true`, un error 500 muestra traza completa, credenciales y datos personales de menores |
| `APP_ENV=production` | Es lo único que impide que `php artisan simular` siembre 300 cuentas de prueba con contraseña conocida |
| `SESSION_SECURE_COOKIE=true` | Sin ella la cookie de sesión también viaja por HTTP plano |
| `LOG_LEVEL=error` | En `debug` se escribe cada consulta: llena la cuota de disco y deja datos personales en un archivo que nadie vigila |

## 3.6 Generar la clave de cifrado

```bash
php artisan key:generate
```

**Se genera en el servidor. No se copia la de desarrollo**: es la que cifra las
cookies y las sesiones de la gente real.

## 3.7 Crear las tablas

```bash
php artisan migrate --force
```

`--force` es necesario porque en producción Laravel pide confirmación
interactiva.

> **Nunca `migrate:fresh` ni `migrate:refresh` aquí.** Borran todas las tablas y
> todos los datos.

## 3.8 Comprobar los triggers — no te saltes este paso

```bash
php artisan tinker --execute="echo count(DB::select('SHOW TRIGGERS')).PHP_EOL;"
```

Tiene que responder **`2`**.

Si responde `0`, el usuario de la base **no tiene el permiso `TRIGGER`** y las
migraciones lo pasaron por alto. El sistema arrancará y parecerá funcionar, pero
**los cupos dejarán de tener tope**: dos personas que se matriculen a la vez para
el último sitio entrarán las dos. Concede el permiso desde hPanel y vuelve a
correr la migración de los triggers.

## 3.9 Permisos de escritura

```bash
chmod -R ug+w storage bootstrap/cache
```

Son las dos únicas carpetas donde la aplicación escribe: los logs, las sesiones,
las plantillas compiladas y los archivos que sube la gente.

> **No hace falta `php artisan storage:link`.** Las fotos y las copias de
> documentos se guardan en `storage/app/private`, deliberadamente fuera de
> `public/`, y se entregan por una ruta que comprueba antes quién las pide. Crear
> el enlace las publicaría.

## 3.10 Las tres cachés

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Hacen que cada petición no vuelva a leer y compilar la configuración, las rutas
y las plantillas.

> **Hay que repetirlas después de cada despliegue**, y `config:cache` además
> después de cada cambio en el `.env`. Con la configuración cacheada, editar el
> `.env` no tiene ningún efecto hasta que se regenera la caché. Es la causa más
> común de «cambié la contraseña de la base y sigue fallando».

## 3.11 Crear los dos administradores

Igual que en el paso 1.8, por `php artisan tinker`, y **dos veces**: el tuyo y el
de respaldo.

Después de esto, todas las demás cuentas se crean desde Gestión → Usuarios.

## 3.12 Lista de comprobación final

Antes de darle la dirección a nadie:

- [ ] `https://tu-dominio.com` abre la pantalla de entrada, **con candado**.
- [ ] `https://tu-dominio.com/.env` da **404 o 403**, nunca el contenido del archivo.
- [ ] Entras con el administrador y llegas a Gestión.
- [ ] `SHOW TRIGGERS` devuelve **2**.
- [ ] Subes una foto de perfil y se ve — confirma que `gd` está activa y que
      `storage/` tiene permiso de escritura.
- [ ] Haces una matrícula de prueba de punta a punta y después la borras.
- [ ] Existen **dos** administradores.
- [ ] `php artisan about` no reporta nada raro.

## 3.13 Actualizar el sistema más adelante

Todo lo de esta sección lo hace `desplegar.sh`, que va en el repositorio:

```bash
cd ~/dominios/tu-dominio.com/app
./desplegar.sh
```

Hace, en este orden: respaldo, cerrar al público, `git pull`, dependencias,
migraciones, las tres cachés, comprobar los triggers y volver a abrir. Si algo
falla a mitad, **el sitio vuelve a estar en pie igualmente** — sin eso, una
migración fallida deja la página en mantenimiento hasta que alguien llama por
teléfono a preguntar.

Y si prefieres los pasos sueltos:

```bash
./respaldar.sh                                  # primero el respaldo, siempre
git pull
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

## 3.14 Despliegue automático desde GitHub

El repositorio trae un flujo de trabajo, `.github/workflows/desplegar.yml`, que
en cada `push` a `main`:

1. Levanta MariaDB y corre **las 275 pruebas**.
2. **Solo si pasan todas**, entra por SSH al servidor y ejecuta `desplegar.sh`.

Esa segunda condición es el motivo de que exista. Un `git pull` automático sin
pruebas publica el error en internet treinta segundos después de haberlo
escrito; con la puerta, la suite que ya tienes se convierte en el guardián del
despliegue.

**Mientras no configures los secretos, el despliegue se salta solo** y la
ejecución queda en verde: las pruebas siguen corriendo en cada push, que ya es
útil por sí solo.

### Lo que hay que configurar una vez

En GitHub, en *Settings → Secrets and variables → Actions*, cinco secretos:

| Secreto | Qué es |
|---|---|
| `HOSTINGER_HOST` | Dominio o IP del servidor |
| `HOSTINGER_PUERTO` | Puerto SSH. En Hostinger **no es el 22** |
| `HOSTINGER_USUARIO` | Usuario SSH |
| `HOSTINGER_CLAVE_SSH` | La clave **privada** completa, con sus líneas `BEGIN` y `END` |
| `HOSTINGER_RUTA` | Ruta al proyecto en el servidor |

Y en el servidor, una sola vez:

```bash
ssh-keygen -t ed25519 -C "despliegue github"   # si no tienes ya un par de claves
cat ~/.ssh/id_ed25519.pub >> ~/.ssh/authorized_keys
chmod 600 ~/.ssh/authorized_keys
```

La clave **pública** se queda en el servidor; la **privada** es la que se pega
en `HOSTINGER_CLAVE_SSH`. Nunca al revés, y la privada no se guarda en ningún
archivo del repositorio.

> **Los datos no viajan por aquí, y es deliberado.** Este circuito sube
> **código**, nada más. Los cambios de estructura de la base viajan como
> migraciones, que son archivos del repositorio y las aplica `migrate --force`.
> Los **datos** del servidor son los buenos: no se sobrescriben nunca desde
> local. Si alguna vez quieres trabajar con datos parecidos a los reales, el
> movimiento es el contrario — sacar un respaldo del servidor y restaurarlo en
> tu máquina.

> **Para cambiar el esquema se añade una migración nueva, jamás se edita una
> existente.** Editar una vieja obliga a `migrate:fresh` para que surta efecto, y
> eso borra la base entera. Es exactamente lo que vació la base local una vez.

## 3.15 Respaldos

```bash
./respaldar.sh
```

Guarda las dos cosas que **no** se pueden reconstruir desde el repositorio: la
base de datos **con sus triggers** y los archivos que ha subido la gente.

> La carpeta de destino de los respaldos **no puede quedar bajo `public/`**. Un
> volcado ahí es la base entera —con documentos de identidad— descargable por
> URL.

Súbelos a otro sitio de vez en cuando. Un respaldo que vive solo en el mismo
servidor no es un respaldo.

---

# Parte 4 — Problemas frecuentes

## No puedo entrar con ninguna cuenta

Mira primero si hay usuarios:

```bash
php artisan tinker --execute="echo App\Models\User::count().PHP_EOL;"
```

Si responde `0`, la base está vacía. Casi siempre es porque alguien corrió
`php artisan migrate:fresh`, que borra y recrea todas las tablas. Restaura el
último respaldo:

```bash
mysql -u USUARIO -p NOMBRE_BASE < respaldos/matriculas-AAAAMMDD-HHMMSS.sql
```

El archivo de respaldo ya trae los `DROP TABLE` y los triggers: la restauración
es completa.

Si hay usuarios pero el tuyo no entra, comprueba que la cuenta esté activa: una
cuenta desactivada da el mismo mensaje que una contraseña equivocada, a
propósito, para no confirmarle a un extraño que ese usuario existe.

## «Demasiados intentos»

El login admite **5 intentos por minuto** por usuario y por IP. Se despeja solo
en un minuto, o de inmediato con:

```bash
php artisan cache:clear
```

## Cambié el `.env` y no pasa nada

La configuración está cacheada. Regenérala:

```bash
php artisan config:cache
```

## Error 500 sin ningún detalle

Es el comportamiento correcto en producción. El detalle está en el log:

```bash
tail -n 50 storage/logs/laravel.log
```

Nunca pongas `APP_DEBUG=true` en el servidor para diagnosticar: esa página de
error muestra credenciales y datos personales a cualquiera que la provoque.

## Una promotoría admitió más gente que su cupo

Los triggers no están:

```bash
php artisan tinker --execute="echo count(DB::select('SHOW TRIGGERS')).PHP_EOL;"
```

Si devuelve menos de 2, ver el paso 3.8. Si devuelve 2, revisa que esa promotoría
**tenga cupo definido para ese periodo** en Gestión → Cupos: sin fila de cupo no
hay tope.

## Las fotos no se ven

Tres causas posibles, en este orden: la extensión `gd` no está activa,
`storage/` no tiene permiso de escritura, o el archivo se subió antes de que
existiera la carpeta. El log lo dice.

## Un profesor se registró y no ve nada

Correcto: `/registro` crea la cuenta **sin rol**, y hasta que un director o
administrador se lo asigne desde Gestión → Usuarios, esa persona solo ve la
pantalla de «cuenta pendiente».
