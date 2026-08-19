#!/usr/bin/env bash
#
# Despliegue en el servidor. Lo ejecuta GitHub Actions por SSH después de que
# las pruebas hayan pasado, pero también sirve a mano:
#
#   ./desplegar.sh
#
# El orden no es negociable. `composer install` antes de `migrate` porque una
# migración nueva puede usar código nuevo; las cachés al final porque cachean
# justo lo que acaba de cambiar.
#
# Variables de entorno opcionales:
#
#   SIN_RESPALDO=1   se salta el respaldo previo. Solo para una instalación
#                    recién creada, donde todavía no hay nada que perder.
#   RAMA=otra        despliega otra rama en vez de main.
#
set -euo pipefail

RAIZ="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
RAMA="${RAMA:-main}"

cd "$RAIZ"

# El sitio vuelve a estar en pie pase lo que pase. Sin esto, una migración que
# falle a mitad deja la página en mantenimiento indefinidamente: el error se
# arregla en cinco minutos, pero nadie se entera hasta que alguien llama.
levantar() {
    php artisan up > /dev/null 2>&1 || true
}
trap levantar EXIT

echo "== Respaldo previo"

if [ "${SIN_RESPALDO:-0}" = "1" ]; then
    echo "  omitido por SIN_RESPALDO=1"
else
    # Deliberadamente sin `|| true`: si no se puede respaldar, no se despliega.
    # Una migración sin red debajo es la forma más cara de perder datos.
    ./respaldar.sh
fi

echo ""
echo "== Cerrando al público"
php artisan down --retry=15

echo ""
echo "== Trayendo el código"
# --ff-only: si alguien tocó archivos en el servidor, esto se niega en vez de
# fabricar una fusión que nadie ha revisado.
git pull --ff-only origin "$RAMA"

echo ""
echo "== Dependencias"
composer install --no-dev --optimize-autoloader --no-interaction

echo ""
echo "== Migraciones"
# --force porque en producción artisan pide confirmación interactiva y aquí no
# hay nadie para darla. NUNCA `migrate:fresh` ni `migrate:refresh`: borran la
# base entera.
php artisan migrate --force

echo ""
echo "== Cachés"
php artisan config:cache
php artisan route:cache
php artisan view:cache

echo ""
echo "== Comprobando los triggers de cupos"
# Se comprueba en cada despliegue y no solo al instalar: una migración futura
# que recree la tabla puede llevárselos por delante, y el sistema seguiría
# pareciendo sano mientras los cupos dejan de tener tope.
TRIGGERS=$(php artisan tinker --execute="echo count(DB::select('SHOW TRIGGERS'));" 2>/dev/null | tr -dc '0-9')

if [ "${TRIGGERS:-0}" -lt 2 ]; then
    echo "AVISO: hay $TRIGGERS triggers y deberían ser 2." >&2
    echo "Los cupos NO tienen tope ahora mismo. Revísalo antes de abrir matrículas." >&2
fi

echo "  $TRIGGERS triggers"

echo ""
echo "== Abriendo al público"
php artisan up
trap - EXIT

echo ""
echo "Desplegado: $(git rev-parse --short HEAD) — $(git log -1 --pretty=%s)"
