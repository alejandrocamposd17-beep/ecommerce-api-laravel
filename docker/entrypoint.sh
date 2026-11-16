#!/bin/sh
set -e

# Descubre los paquetes instalados (se omitió en el build para no requerir .env)
php artisan package:discover --ansi

# Genera APP_KEY si el contenedor arranca sin una
if [ -z "$APP_KEY" ]; then
  echo "APP_KEY no definida: se genera una temporal (defínela en .env para producción)."
  APP_KEY="base64:$(head -c 32 /dev/urandom | base64)"
  export APP_KEY
fi

# Solo el contenedor principal migra y siembra datos (el scheduler usa RUN_MIGRATIONS=false)
if [ "${RUN_MIGRATIONS:-true}" = "true" ]; then
  php artisan migrate --force
  # Los datos de ejemplo se cargan una sola vez; si ya existen, se omite sin detener el contenedor
  php artisan db:seed --force || echo "Datos de ejemplo ya cargados: se omite el seed."
  php artisan l5-swagger:generate
fi

exec "$@"
