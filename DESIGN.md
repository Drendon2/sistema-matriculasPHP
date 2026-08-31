---
name: Sistema de Matrículas
description: Autoservicio de inscripción y gestión académica para una casa de cultura
colors:
  bg: "#f6f4ef"
  surface: "#ffffff"
  surface-alt: "#efece3"
  border: "#e5e1d5"
  border-strong: "#d3cebd"
  ink: "#26251f"
  ink-soft: "#726e60"
  accent: "#1f5c42"
  accent-dark: "#163f2d"
  accent-soft: "#dfeae3"
  ocre: "#a97b2f"
  danger: "#b22e22"
  danger-bg: "#fbe4e1"
typography:
  body:
    fontFamily: "Public Sans, -apple-system, BlinkMacSystemFont, Segoe UI, Roboto, Helvetica Neue, Arial, sans-serif"
    fontWeight: 400
  heading:
    fontFamily: "Public Sans, -apple-system, BlinkMacSystemFont, Segoe UI, Roboto, Helvetica Neue, Arial, sans-serif"
    fontWeight: 700
  mono:
    fontFamily: "JetBrains Mono, ui-monospace, SF Mono, Cascadia Code, Segoe UI Mono, Consolas, Liberation Mono, monospace"
    fontWeight: 600
rounded:
  sm: "8px"
  md: "12px"
  lg: "18px"
  dato: "3px"
components:
  button-primary:
    backgroundColor: "{colors.accent}"
    textColor: "#ffffff"
    rounded: "{rounded.sm}"
    padding: "0.65rem 1.2rem"
  button-primary-hover:
    backgroundColor: "{colors.accent-dark}"
  button-secondary:
    backgroundColor: "transparent"
    textColor: "{colors.accent}"
    rounded: "{rounded.sm}"
  card:
    backgroundColor: "{colors.surface}"
    rounded: "{rounded.md}"
    padding: "1.6rem 1.85rem"
---

# Design System: Sistema de Matrículas

## Overview

**Creative North Star: "Tinta y Papel"**

Un cuaderno de registro bien llevado, no una vitrina de producto: superficie de papel cálido, tinta verde profunda reservada a lo que está vivo, y cifras que se leen como mediciones porque llevan su propia tipografía de instrumento. La prioridad es que administración, dirección y profesores —que comparten las mismas pantallas resolviendo tareas distintas— lean el estado de las cosas de un vistazo, sin decodificar una metáfora. Nada de mundos temáticos (sellos de aduana, osciloscopios, tablones de corcho): se descartaron en el propio proceso de dirección por pedirle al lector un trabajo de traducción que un cuaderno de registro no exige. La calidez viene del fondo color papel y de un acento verde con cuerpo, no de ilustración ni de decoración.

**Key Characteristics:**
- Superficie color papel cálido (no el cliché "cream" de IA — un gris-piedra tibio, no amarillo)
- Un solo acento con cuerpo (verde tinta), nunca varios matices compitiendo
- Cifras grandes en tipografía monoespaciada de instrumento — nunca en la fuente de cuerpo
- Estado = punto + palabra en texto plano, jamás tachado
- Sombras casi planas; el borde fino hace el trabajo que antes hacía la sombra pesada

## Colors

Paleta restringida: neutros cálidos, un acento, un tono de archivo para cifras. Ningún estado usa más de dos colores a la vez.

### Primary
- **Tinta verde** (`#1f5c42`): botones primarios, enlaces, lo que está activo o confirmado. Reemplaza al verde anterior (`#0a7a59`), más profundo para leer como tinta y no como semáforo de SaaS.

### Neutral
- **Papel** (`#f6f4ef`): fondo de página.
- **Superficie** (`#ffffff`): tarjetas, tablas, campos.
- **Superficie tenue** (`#efece3`): paneles secundarios, franjas de tabla.
- **Borde** (`#e5e1d5`) / **Borde firme** (`#d3cebd`): líneas finas en vez de sombra — es lo que separa las tarjetas ahora.
- **Tinta** (`#26251f`): texto principal, y el estado "pendiente" (a propósito: es lo único que todavía pide una decisión).
- **Tinta suave** (`#726e60`, 5.1:1 sobre blanco — AA): etiquetas, texto secundario, y los estados "retirada" y "finalizada" — comparten tono porque ninguno de los dos exige acción; la forma del punto los distingue, no el color.

### Named Rules
**La Regla del Instrumento.** JetBrains Mono queda reservada estrictamente a cifras (números grandes, contadores, códigos) y a encabezados de tabla. Nunca aparece en un párrafo ni en un botón — el momento en que aparece la monoespaciada, el lector sabe que está viendo un dato, no una instrucción.

**La Regla de la Tinta de Archivo.** El ocre (`#a97b2f`, 3.8:1 — AA solo en texto grande) vive exclusivamente en cifras grandes en negrita (≥20px). Nunca en texto corrido: por debajo de ese tamaño no alcanza el contraste mínimo, y es justo la razón de que estas cifras sean grandes.

## Typography

**Cuerpo y títulos:** Public Sans (con la pila de sistema como respaldo).
**Cifras y datos:** JetBrains Mono.

**Carácter:** una sola familia humanista para todo el texto de lectura —nada de una fuente "de marca" aparte— y una monoespaciada seria, no decorativa, para todo lo que se mide o se cuenta. Autoalojadas ambas en `/public/fonts` como `.woff2`: cero petición externa, cero fuga de IP de quien visita — coherente con el cuidado de datos de menores que documenta `PRODUCT.md` y con el "sin build step" del stack.

### Hierarchy
- **h2** (700, 1.75rem): título de pantalla.
- **h3** (700, 1.2rem): título de tarjeta o sección.
- **h4** (700, 0.78rem, versalitas): subtítulo de bloque.
- **Body** (400, 1rem): texto corrido y de formulario.
- **Cifra grande** (JetBrains Mono 700, ≥1.4rem, tinta de archivo): el número que resume una tarjeta — estudiantes, matrículas activas, cupos.
- **Cifra de tabla** (JetBrains Mono 600, 0.68–0.9rem, tinta suave): encabezados de columna y valores tabulares en fila.

### Named Rules
**La Regla de los 16px** (heredada, sigue vigente). Ningún campo de formulario baja de `1rem` en ningún breakpoint: por debajo, Safari en iPhone hace zoom automático al enfocar.

## Layout

Contenedor central de 960px, tarjetas apiladas con `gap`, sin rejilla rígida de columnas salvo donde el contenido es tabular. Densidad moderada: suficiente aire para escanear una tabla larga sin que cada fila compita por atención. Responsive mobile-first en los breakpoints existentes (≤640px): objetivos de toque de 44px mínimo, nada que dependa de `:hover`.

## Elevation & Depth

**La Regla del Borde, no la Sombra.** El sistema es plano por defecto: una tarjeta se separa del fondo por un borde de 1px (`--border`), no por una sombra pesada. La sombra que queda (`--shadow`, `--shadow-lift`) es casi imperceptible — un residuo de profundidad para modales y elementos flotantes, nunca el recurso principal para dar jerarquía. Esto es un cambio deliberado frente al sistema anterior, que apoyaba cada tarjeta y tabla en una sombra de dos capas.

## Shapes

Radios moderados y consistentes: `12px` en tarjetas y tablas, `18px` en el cuadro de autenticación, `8px` en botones y campos. La única excepción documentada sigue siendo `3px` en las celdas de calendario del mapa de calor — son datos, no controles, y un radio de botón ahí se leería como un círculo.

## Components

### Botones
- **Forma:** radio `8px`, altura mínima 44px.
- **Primario:** fondo tinta verde (`--accent`), texto blanco, `hover` a `--accent-dark`.
- **Secundario / blanco / texto:** sin relleno, borde o subrayado — igual que antes, sin cambios de forma.

### Marcador de estado (`.estado`)
Ya no es una píldora con fondo y borde: es un punto de 7px y una palabra en texto plano, en línea. Sin mayúsculas, sin caja, sin tachado.
- **Activa:** punto lleno en tinta verde, texto en tinta.
- **Pendiente:** punto en anillo hueco (tinta), texto en tinta y negrita — es el único estado que to­davía pide una decisión, por eso es el más oscuro.
- **Retirada:** punto en anillo partido (una barra diagonal cruza el anillo), texto en tinta suave.
- **Finalizada:** punto lleno en tinta suave, texto en tinta suave.
- **Cancelación solicitada:** único estado que conserva tinta de alerta (`--danger`) — es el único con plazo real.

### Tarjetas
- **Esquinas:** `12px`.
- **Fondo:** superficie blanca sobre papel.
- **Borde:** 1px `--border` — hace el trabajo que antes hacía la sombra.
- **Padding interno:** `1.6rem–1.85rem`.

### Tablas
- **Encabezado:** JetBrains Mono, versalitas, tinta suave, fondo transparente sobre borde firme.
- **Filas:** borde inferior fino, sin cebra; `hover` con superficie tenue.
- **Cifras:** alineadas a la derecha, `font-variant-numeric: tabular-nums` donde aplica.

## Do's and Don'ts

### Do:
- **Do** usar el punto + palabra para cualquier estado nuevo; si hace falta un quinto estado, dale una forma de punto propia antes que un color nuevo.
- **Do** reservar JetBrains Mono a cifras y encabezados de tabla — nunca a título, botón o párrafo.
- **Do** apoyar la separación entre bloques en el borde de 1px, no en sombra.
- **Do** verificar contraste AA antes de introducir cualquier tono de texto nuevo — dos de los tonos de este sistema (`--ink-soft`, `--ocre`) llegaron aquí después de corregir una versión que no pasaba.

### Don't:
- **Don't** tachar texto para comunicar un estado cerrado o cancelado — es la razón de ser de este rediseño.
- **Don't** introducir un segundo acento de marca (el azul `--activa` original quedó retirado; "activa" es simplemente el verde).
- **Don't** usar el ocre de archivo en texto corrido ni en cifras pequeñas — por debajo de texto grande no pasa AA.
- **Don't** tocar la paleta del mapa de calor de asistencia (`.cal-*`) — está validada aparte por banda de luminosidad y separación bajo daltonismo, documentado en `app.css`.
