#!/usr/bin/env bash
#
# Respaldo completo antes de desplegar.
#
# Guarda las DOS cosas que no se pueden reconstruir desde el repositorio:
#
#   1. La base de datos, con sus TRIGGERS. Sin ellos el respaldo restaura una
#      base que acepta sobreventa de cupos: el tope no vive solo en el código.
#   2. Los archivos subidos (`storage/app/private`): fotos de perfil y copias de
#      documentos de identidad. No están versionados y no hay forma de volver a
#      pedírselos a la gente.
#
# Las credenciales salen del .env, así que el mismo guion sirve en local y en el
# servidor sin editar nada.
#
#   ./respaldar.sh              # deja el respaldo en ./respaldos/
#   ./respaldar.sh /otra/ruta   # o donde se le diga
#
set -euo pipefail

RAIZ="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DESTINO="${1:-$RAIZ/respaldos}"
SELLO="$(date +%Y%m%d-%H%M%S)"

if [ ! -f "$RAIZ/.env" ]; then
    echo "No encuentro el .env: sin él no sé a qué base conectarme." >&2
    exit 1
fi

# Lee una variable del .env sin ejecutarlo: un `source` haría correr cualquier
# cosa que alguien haya dejado escrita ahí.
leer() {
    grep -E "^$1=" "$RAIZ/.env" | tail -1 | cut -d= -f2- | sed 's/^"//;s/"$//' || true
}

BD="$(leer DB_DATABASE)"
USUARIO="$(leer DB_USERNAME)"
CLAVE="$(leer DB_PASSWORD)"
SERVIDOR="$(leer DB_HOST)"
PUERTO="$(leer DB_PORT)"

: "${BD:?falta DB_DATABASE en el .env}"
: "${SERVIDOR:=127.0.0.1}"
: "${PUERTO:=3306}"

mkdir -p "$DESTINO"
SQL="$DESTINO/$BD-$SELLO.sql"
ARCHIVOS="$DESTINO/archivos-$SELLO.tar.gz"

echo "Respaldando $BD desde $SERVIDOR:$PUERTO"

# --single-transaction: consistente sin bloquear la aplicación mientras corre.
# --triggers: van por defecto, pero se piden explícitamente porque son la mitad
#             de la garantía de cupos y un descuido aquí no se nota hasta que
#             alguien restaura y empieza a sobrevender.
# --routines y --events: hoy no hay ninguno; si algún día los hay, entran solos.
# El `sed` del final NO es cosmético. mysqldump escribe en cada trigger un
# `DEFINER=`usuario`@`host`` con el nombre de ESTA instalación, y al restaurar en
# otra —el servidor, donde el usuario se llama distinto— pasa una de dos: el
# motor rechaza el volcado por falta de privilegio SUPER, o crea el trigger a
# nombre de alguien que no existe y falla la primera vez que alguien se
# matricula. Quitándolo, el trigger queda a nombre de quien restaura.
mysqldump \
    --host="$SERVIDOR" --port="$PUERTO" \
    --user="$USUARIO" --password="$CLAVE" \
    --single-transaction --triggers --routines --events \
    --default-character-set=utf8mb4 \
    "$BD" \
    | sed -E 's/\/\*!5001[73] DEFINER=[^*]*\*\///g' > "$SQL"

echo "  base de datos ... $(du -h "$SQL" | cut -f1)  $SQL"

# Los archivos subidos. Puede no existir todavía en una instalación nueva.
if [ -d "$RAIZ/storage/app/private" ]; then
    tar -czf "$ARCHIVOS" -C "$RAIZ/storage/app" private
    echo "  archivos ........ $(du -h "$ARCHIVOS" | cut -f1)  $ARCHIVOS"
else
    echo "  archivos ........ no hay nada subido todavía"
fi

# Comprobación mínima: un volcado que no trae los triggers no sirve de respaldo,
# y el error más caro es descubrirlo el día que hay que restaurarlo.
TRIGGERS=$(grep -c 'CREATE.*TRIGGER' "$SQL" || true)

if [ "$TRIGGERS" -lt 2 ]; then
    echo ""
    echo "AVISO: el volcado trae $TRIGGERS triggers y deberían ser al menos 2" >&2
    echo "(cupo_promotoria_disponible_insert y _update). Revisa antes de fiarte." >&2
    exit 1
fi

echo "  triggers ........ $TRIGGERS incluidos"
echo ""
echo "Para restaurar:  mysql -u USUARIO -p BASE < $SQL"
