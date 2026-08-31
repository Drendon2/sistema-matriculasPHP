# Plan: llevar la estética hacia Kinto

## Contexto

El sistema de diseño actual ("Tinta y Papel", documentado en `DESIGN.md` por la skill "impeccable" que el usuario corrió antes en esta misma sesión) ya es una paleta cálida de papel + tinta con un solo acento — conceptualmente muy cerca de Kinto (`https://kinto-nextjs-template.vercel.app/`), que es también "papel cálido + tinta + un acento cálido disperso, editorial". El usuario pide acercar los valores estéticos concretos (color, tipografía, sombra) a los de Kinto.

Se investigó Kinto extrayendo su CSS compilado real (no una descripción genérica):
- **Paleta clara**: fondo `#f4f3ee`, texto `#1f1c18` (tinta casi negra, no `#000`), un acento ámbar/dorado `#bc8b4c` usado disperso (foco, hover, un resplandor), un verde salvia `#668667` como semántica de "éxito" separada del acento, botón primario en la propia tinta oscura (no en el acento).
- **Tipografía**: DM Serif Display (400, con itálica) para TODOS los títulos, DM Sans para cuerpo, JetBrains Mono para datos.
- **Radios**: base `10px` (escala 6/8/10/14/18).
- **Sombras**: suaves pero visibles (`rgba(0,0,0,.10)` en capas), no solo borde.

El usuario ya resolvió, con trade-offs explicados, las cuatro decisiones que más cambiaban el alcance:

1. **Acento**: reemplazo simple de valor — se mantiene "un solo acento en todo" (botones, enlaces, foco, `--activa`), solo cambia el tono de verde a dorado/ámbar. (Se descartó adoptar la estructura de dos tonos de Kinto —tinta oscura como color primario de botón, ámbar solo disperso— por ser más invasiva.)
2. **Sombra**: se adopta la sombra suave de Kinto, revirtiendo parcialmente la regla "el borde, no la sombra" que `DESIGN.md` acababa de fijar.
3. **Tipografía**: se agrega una serif autoalojada para títulos (mismo espíritu que DM Serif Display de Kinto), manteniendo Public Sans para el cuerpo.
4. **Modo oscuro**: fuera de alcance.

**Hallazgo importante que ajusta el alcance de la decisión 1**: el ámbar literal de Kinto (`#bc8b4c`) está pensado para usarse sobre fondo casi negro (focos, resplandores), no como relleno de botón con texto blanco encima. Contraste calculado texto blanco sobre `#bc8b4c` = **3.03:1** — no pasa AA (4.5:1) para el texto de nuestros botones (13.6px, bold, no califica como "texto grande"). `PRODUCT.md` exige WCAG 2.1 AA. Por eso el plan usa un dorado más oscuro y saturado (`#966b1f`, contraste con blanco ≈ 4.75:1) que sigue leyéndose como "ámbar/dorado cálido" pero cumple el mismo estándar que ya exige el proyecto — no el hex literal de Kinto.

## Enfoque

Casi todo el cambio vive en `:root` de dos archivos CSS (los tokens ya gobiernan casi todos los componentes vía variables) más una fuente nueva autoalojada. Actualizar también `DESIGN.md` (es el documento que la propia skill "impeccable" lee/escribe, y la referencia humana del sistema) para que quede consistente con lo que realmente hay en el CSS.

## 1. Paleta — `public/css/app.css` y `public/css/publico.css` (`:root`, ambos archivos, duplicados hoy sin compartir)

| Token | Valor actual | Valor nuevo | Nota |
|---|---|---|---|
| `--bg` | `#f6f4ef` | `#f4f3ee` | Ajuste fino hacia el bone exacto de Kinto (ya casi idéntico) |
| `--surface` | `#ffffff` | `#fefdfc` | Kinto no usa blanco puro para tarjetas |
| `--surface-alt` | `#efece3` | `#e8e6e1` | |
| `--border` | `#e5e1d5` | `#d9d7d2` | |
| `--border-strong` | `#d3cebd` | `#c4c0b7` | Mismo salto proporcional que hoy |
| `--ink` | `#26251f` | `#1f1c18` | Tinta de Kinto, casi negra pero cálida |
| `--ink-soft` | `#726e60` | `#625f5b` | Verificar AA con la herramienta de contraste ya existente en Gestión → Institución antes de dar por cerrado |
| `--accent` | `#1f5c42` (verde) | `#966b1f` (dorado) | Ver justificación de contraste arriba — NO usar `#bc8b4c` literal |
| `--accent-dark` | `#163f2d` | `#6d4c17` | Hover/activo; contraste con blanco ≈ 7.8:1 |
| `--accent-soft` | `#dfeae3` | `#f3ede3` | Este sí puede tomarse directo de Kinto (`--accent-subtle`): es solo fondo, no lleva texto blanco encima |
| `--ocre` | `#a97b2f` | **sin cambio** | Sigue distinguiéndose del nuevo acento por contexto (cifra grande y en negrita vs. control interactivo), no hace falta separarlos más por matiz |
| `--danger`, `--danger-bg`, `--tag-*` | — | **sin cambio** | No son parte de la identidad "Kinto"; tocarlos no estaba pedido |

`--activa`/`--activa-bg` no se tocan (ya son `var(--accent)`/`var(--accent-soft)`, heredan el nuevo dorado automáticamente).

**Consistencia de semilla**: el color de acento por defecto de una institución nueva vive en dos sitios más, desalineados hoy incluso con el verde actual (`#0a7a59`, el verde ORIGINAL, no el `#1f5c42` de `DESIGN.md`):
- `database/migrations/2026_08_15_100000_create_configuracion_institucion_table.php:30` — `default('#0a7a59')`
- `app/Console/Commands/Instalar.php:261` — `'color_acento' => '#0a7a59'` (semilla de `--ejemplo`)

Actualizar los dos a `#966b1f` para que una institución nueva nazca ya con el dorado. Cambiar el `default()` de una migración ya aplicada es seguro — solo afecta bases nuevas (`migrate:fresh`, que es lo que corre la suite de tests vía `RefreshDatabase`) y no las que ya corrieron esa migración.

## 2. Sombra — reintroducir elevación visible

`public/css/app.css`, en `:root` (ambos archivos):
```css
--shadow: 0 1px 3px rgba(31,28,24,0.08), 0 1px 2px rgba(31,28,24,0.06);
--shadow-lift: 0 4px 6px rgba(31,28,24,0.10), 0 10px 20px rgba(31,28,24,0.12);
```
(Dos capas como Kinto, pero teñidas con nuestra tinta cálida —`31,28,24`, el nuevo `--ink` en RGB— en vez del negro puro de Kinto, para no romper la calidez del sistema.)

Buscar dónde la reescritura de "impeccable" dejó `.card`/`.tarjeta-enlace`/tablas apoyadas solo en `border` (según `DESIGN.md`: *"La Regla del Borde, no la Sombra"*) y **reincorporar `box-shadow: var(--shadow)` junto al borde** (no en vez de él — Kinto también usa borde fino + sombra suave a la vez, no uno u otro). Mismo tratamiento para `dialog.modal` y cualquier elemento flotante que ya use `--shadow-lift`.

Opcional (nota, no obligatorio): Kinto usa un resplandor de color en botones al hacer foco/hover. Los inputs ya hacen algo parecido (`box-shadow: 0 0 0 3px var(--accent-soft)` en `:focus`). Se puede extender el mismo tratamiento a `.btn:hover`/`:focus-visible` como pulido menor si el tiempo lo permite; no es parte central del plan.

## 3. Tipografía — serif autoalojada para títulos

Nueva familia: **DM Serif Display** (la misma que usa Kinto — es una fuente libre de Google Fonts, autoalojable como `.woff2`, coherente con la restricción de `PRODUCT.md` de cero petición externa). Regular e itálica, peso único 400 (Kinto tampoco la usa en negrita).

- Descargar los dos archivos (`dm-serif-display-400.woff2`, `dm-serif-display-400-italic.woff2`) una sola vez vía `curl`/`wget` desde el CDN oficial de Google Fonts (fetch de una sola vez al implementar, igual que ya se hizo con Public Sans/JetBrains Mono — no es una dependencia en vivo) y guardarlos en `public/fonts/`.
- En `public/css/app.css` y `publico.css`: dos `@font-face` nuevos, más un token `--font-heading: "DM Serif Display", Georgia, "Times New Roman", serif;` junto a `--font-body`/`--font-mono` existentes.
- Aplicar `--font-heading` a **`h2` y `h3`** (títulos de pantalla y de tarjeta/sección — tamaños donde una serif de display se ve bien). **No aplicarlo a `h4`**: por `DESIGN.md`, `h4` es un subtítulo en versalitas de `0.78rem` — una serif de display a ese tamaño y en mayúsculas pequeñas se lee mal (las display serif están pensadas para tamaños grandes). `h4` se queda en Public Sans 700, sin cambios.
- El cuerpo de texto, formularios, tablas y botones **no cambian de fuente** (siguen en Public Sans) — el pedido del usuario fue "títulos", y tocar la fuente de formularios/tablas es el área de mayor riesgo de legibilidad (y la regla de los 16px en campos ya está resuelta y no hay que reabrirla).

## 4. Radios — ajuste menor

`--radius` de `12px` a `10px` (coincide con la base real de Kinto). `--radius-sm` (`8px`), `--radius-lg` (`18px`) y `--radius-dato` (`3px`) ya coinciden o están cerca de la escala de Kinto — sin cambio.

## 5. `DESIGN.md` — actualizar el documento fuente

Es el documento que la propia skill "impeccable" usa como entrada (`designPath` en `.impeccable/live/config.json`) y la referencia humana del sistema — dejarlo desalineado del CSS real invita a que la próxima ejecución de la skill, o la próxima persona, parta de una descripción falsa. Actualizar:
- **Colors**: nuevos valores de `--accent`/`--accent-dark`/`--accent-soft` y de los neutros, con la misma prosa razonada que ya tiene el archivo (por qué este dorado y no el ámbar literal de Kinto — el argumento de contraste de este plan sirve tal cual).
- **Typography**: agregar la sección de la serif de títulos, con la misma regla de "nunca en h4" explicada.
- **Elevation & Depth**: reescribir "La Regla del Borde, no la Sombra" — ya no aplica tal cual; documentar que ahora se usa borde fino + sombra suave juntos, con la razón (acercarse a Kinto).
- **Shapes**: actualizar `12px` → `10px` en la tarjeta.
- **Do's and Don'ts**: el "Don't introducir un segundo acento" sigue siendo válido (seguimos con uno solo, solo cambió de matiz) — no tocar esa regla.

No hace falta tocar `PRODUCT.md` — nada de lo que describe (roles, posicionamiento, restricciones técnicas) cambia con este rediseño.

## 6. Qué NO toca este plan

- `.estado` (sistema de punto + palabra para matrículas), `.tag-*` (colores por área), el mapa de calor de asistencia (`.cal-*`) — son componentes de dominio propios de esta app, sin equivalente en la estética de Kinto (una plantilla de marketing), y `DESIGN.md` ya documenta por qué no se deben tocar sin repetir el trabajo de validación de contraste que tuvieron.
- El mecanismo de personalización por institución (`<style>` en `layouts/app.blade.php`/`publico.blade.php` que sobreescribe `--accent`/`--accent-dark`/`--accent-soft` desde `ConfiguracionInstitucion`) — sigue intacto; el cambio de este plan es solo el valor por defecto antes de que una institución elija el suyo.
- Modo oscuro (explícitamente fuera de alcance).
- Body/formularios/tablas en fuente distinta de Public Sans.

## 7. Verificación

1. `php artisan test` — no debería haber ningún test que dependa de valores hex concretos de colores/sombras/radios (son estilos, no comportamiento); confirmar que sigue en verde (514 antes de este cambio).
2. Servidor real (`php artisan serve --host=0.0.0.0 --port=8010`): revisar visualmente que:
   - Los títulos de pantalla (`h2`) y de tarjeta (`h3`) se vean en la nueva serif; los subtítulos pequeños (`h4`, versalitas) sigan en Public Sans y sean legibles.
   - Botones primarios, enlaces y el estado "activa" ahora en dorado, no verde.
   - Tarjetas con sombra suave visible además del borde.
   - Fondo, tarjetas y bordes con el tono papel/tinta ligeramente ajustado.
3. Contraste: usar el validador ya existente en Gestión → Institución (el que dispara `test_un_acento_demasiado_claro_avisa_del_contraste`) probando manualmente el nuevo `#966b1f` como si fuera un acento elegido por un administrador, para confirmar que no dispara la advertencia de acento demasiado claro.
4. Revisar que `.cal-*` (mapa de calor) y `.estado-*` sigan viéndose igual que antes (no deberían tocarse, pero conviene confirmar que ningún selector genérico los alcanzó por accidente).
5. Instalar de cero (`php artisan instalar --ejemplo` sobre una base limpia, o revisar la migración) para confirmar que el nuevo `color_acento` por defecto (`#966b1f`) queda sembrado.

### Archivos críticos
- `public/css/app.css`
- `public/css/publico.css`
- `public/fonts/` (dos archivos nuevos: DM Serif Display regular + itálica)
- `DESIGN.md`
- `database/migrations/2026_08_15_100000_create_configuracion_institucion_table.php` (default de `color_acento`)
- `app/Console/Commands/Instalar.php` (semilla de `--ejemplo`)
