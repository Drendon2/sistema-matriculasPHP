# Product

<!-- impeccable:product-schema 1 -->

## Platform

web

## Users

Tres roles, cada uno con su propia área de la aplicación:

- **Estudiante / público general.** Se matricula desde su casa o su celular en
  una promotoría artística (música, danza, teatro, pintura…) de una casa de
  cultura, sin elegir horario. Gestiona su inscripción, renovación, retiro,
  confirmación de clases, compañeros y perfil.
- **Quien dicta** (profesor/instructor). Confirma o rechaza solicitudes de
  matrícula de su promotoría, crea grupos y reparte en ellos a los ya
  matriculados, registra clases y pasa lista, y ve la ficha de sus estudiantes.
- **Dirección / administración.** Gobierna el catálogo completo (departamento →
  promotoría → grupo → estudiantes), la ventana de matrículas, los cupos, las
  cancelaciones, los usuarios, los ajustes de la institución y las
  estadísticas. El rol de administrador no se puede autoasignar ni delegar
  hacia arriba.

Además, sin cuenta ni matrícula: cualquiera con un enlace compartido se inscribe
a un curso, taller o grupo de proyección (mecanismo aparte, no consume el cupo
de promotorías).

## Product Purpose

Reemplaza el proceso manual/en papel de inscripción, confirmación y asignación
de grupos en una casa de cultura, para una institución que no puede correr un
proceso persistente (hosting compartido tradicional). Éxito es: una persona se
matricula, su cupo queda garantizado incluso ante solicitudes simultáneas, se le
asigna a un grupo con horario sin conflicto, y puede demostrar su matrícula con
un certificado descargable.

## Positioning

Es una plataforma reutilizable y configurable sin tocar código — nombre, logo,
color de acento, cuántas promotorías puede cursar alguien y qué papeles se
exigen se editan desde la propia interfaz — pensada para instituciones que
despliegan en hosting compartido barato: sin proceso persistente, sin build
step de Node, con PHP bajo demanda. Es la reconstrucción en Laravel + MariaDB
de un proyecto original en Django + PostgreSQL que exigía un servidor
persistente; mantiene las mismas reglas de negocio y pantallas sobre otra pila.

Lo que un competidor genérico (un LMS o un formulario de inscripción cualquiera)
no podría copiar sin reescribirse:

- Cupos garantizados por la base de datos (trigger con bloqueo de fila), no
  solo por la aplicación.
- La clase la verifican los estudiantes que asistieron, no quien la dictó.
- Privacidad diferenciada por rol, pensada para la Ley 1581 de Colombia (quién
  ve nombre, edad, teléfono, acudiente, encuesta o documento de identidad).
- Pedir la baja no es lo mismo que darse de baja: retirar una solicitud sin
  confirmar es inmediato, pero salir de una matrícula activa lo resuelve
  dirección.
- A un menor no se le acepta la cancelación sin más: existe para dar tiempo de
  hablar con el acudiente.

## Operating Context

Flujo principal: inscripción → confirmación por quien dicta → asignación a
grupo → asistencia (con verificación por pares) → certificado descargable.

Flujo paralelo, sin cuenta: cursos, talleres y grupos de proyección se crean en
Gestión y generan un enlace público; quien lo abre llena cinco campos y queda
inscrito, sin pasar por el trigger de cupos de promotorías.

El trabajo se organiza por **periodos** (ciclos de tiempo), con estadísticas,
ventana de matrículas y cupos configurados por periodo. Los informes
descargables son CSV (no `.xlsx`, para no cargar `vendor/` con
`phpoffice/phpspreadsheet` en hosting compartido). El despliegue es automático
desde GitHub: cada `push` a `main` corre las pruebas y solo sube si pasan.

## Capabilities and Constraints

- PHP 8.4 + Laravel 12 + MariaDB 10.5 + Blade; sesiones nativas de Laravel
  (login por `username`).
- Deliberadamente sin Node ni build step: CSS estático, JS son ficheros sueltos
  y ninguno es imprescindible (degradación elegante sin JS). Restricción de
  producto, no descuido — el destino es hosting compartido.
- Certificados en PDF vía `dompdf` (necesita `dom`, `mbstring`, `gd`).
- Archivos subidos (fotos, documentos, firma) fuera del webroot, servidos por
  rutas que verifican quién los pide — nunca por URL directa.
- Límite de intentos en las puertas sin cuenta (login, registro, inscripción,
  enlace de actividad); el del login cuenta por usuario + IP, no por IP sola.
- El texto de horario se deriva de sesiones estructuradas (día, hora inicio,
  hora fin) y nunca se guarda como texto libre.
- Configurable sin tocar código: identidad de la institución, límite de
  promotorías por periodo y papeles exigidos.

## Evidence on Hand

Existe un despliegue de referencia en producción (una casa de cultura real,
con las tres áreas terminadas, probadas y auditadas), pero este PRODUCT.md
documenta la plataforma genérica y reutilizable, no la identidad de marca de
esa instalación concreta — la elección deliberada fue no atar el producto a
una sola institución. No hay testimonios, casos de estudio ni cifras de
mercadeo que registrar; no inventar ninguno.

## Product Principles

1. Las reglas de negocio críticas (cupos, límites) se garantizan en la base de
   datos, no solo en la aplicación — la concurrencia es una amenaza real, no
   un caso de borde.
2. El costo y la simplicidad de despliegue mandan sobre la conveniencia de
   desarrollo: sin proceso persistente, sin build step, sin dependencias
   pesadas, porque el destino es hosting compartido barato.
3. Cero fricción para quien no tiene cuenta donde el flujo lo permite (cursos,
   talleres, proyección); cuenta simple donde sí aplica (promotorías).
4. La privacidad diferenciada por rol es un requisito de diseño desde el
   principio, no una capa añadida después.
5. Configurable sin tocar código: cada institución ajusta su identidad y sus
   reglas (cupos, papeles exigidos) desde la propia interfaz.

## Accessibility & Inclusion

Debe cumplir WCAG 2.1 nivel AA.
