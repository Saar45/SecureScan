#!/bin/bash
set -e

# Install/update PHP dependencies if composer.json exists
if [ -f /var/www/html/composer.json ]; then
    echo "[entrypoint] Installing PHP dependencies..."
    composer install --no-interaction --optimize-autoloader --working-dir=/var/www/html 2>/dev/null \
        || composer update --no-interaction --optimize-autoloader --working-dir=/var/www/html
    echo "[entrypoint] Composer done."
fi

# Run Doctrine migrations
echo "[entrypoint] Running Doctrine migrations..."
php /var/www/html/bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration
echo "[entrypoint] Migrations done."

# Create reports directory in public (served by Apache)
mkdir -p /var/www/html/public/reports && chmod 777 /var/www/html/public/reports

# Start Apache in foreground
exec apache2-foreground
