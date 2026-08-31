#!/bin/sh
set -e

APP_DIR=/var/www/html

if [ -f "$APP_DIR/artisan" ]; then
    cd "$APP_DIR"

    # Primer arranque: crea el .env local.
    if [ ! -f .env ]; then
        cp .env.example .env
        chown app:app .env
    fi

    # Genera APP_KEY solo si todavia no hay una. Se escribe en este .env,
    # que esta bind-mounteado, asi que persiste entre reinicios.
    if ! grep -qE '^APP_KEY=base64:.+' .env; then
        su -s /bin/sh -c "php artisan key:generate --force" app
    fi
fi

# Cede el control al entrypoint original de la imagen php (docker-php-entrypoint).
exec docker-php-entrypoint "$@"
