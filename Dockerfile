# Imagen de la API (PHP 8.4 + extensiones para Laravel y MySQL; SQLite ya viene incluido)
FROM php:8.4-cli

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libzip-dev \
    && docker-php-ext-install pdo_mysql bcmath zip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Primero las dependencias (aprovecha la caché de capas de Docker)
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

COPY . .
RUN composer dump-autoload --optimize --no-dev --no-scripts \
    && chown -R www-data:www-data storage bootstrap/cache database

COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

# Se ejecuta sin privilegios de root
USER www-data

# Varios procesos del servidor integrado para atender peticiones en paralelo
ENV PHP_CLI_SERVER_WORKERS=4

EXPOSE 8000
ENTRYPOINT ["entrypoint.sh"]
# Usa el puerto que asigne la plataforma (Render define PORT) o 8000 en local
CMD ["sh", "-c", "exec php artisan serve --host=0.0.0.0 --port=${PORT:-8000}"]
