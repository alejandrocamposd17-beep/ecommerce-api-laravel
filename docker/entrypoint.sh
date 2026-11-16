#!/bin/sh
set -e

# En Render la URL pública llega en RENDER_EXTERNAL_URL: se usa para APP_URL y Swagger
if [ -n "$RENDER_EXTERNAL_URL" ]; then
  export APP_URL="${APP_URL:-$RENDER_EXTERNAL_URL}"
  export L5_SWAGGER_CONST_HOST="${L5_SWAGGER_CONST_HOST:-$RENDER_EXTERNAL_URL}"
fi

# Con SQLite (demo) se crea el archivo de la base de datos si no existe
if [ "$DB_CONNECTION" = "sqlite" ]; then
  DB_FILE="${DB_DATABASE:-database/database.sqlite}"
  [ -f "$DB_FILE" ] || touch "$DB_FILE"
fi

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
