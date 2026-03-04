#!/bin/bash
set -e

# --- 1. NETTOYAGE DU CACHE ---
echo "[entrypoint] Cleaning Symfony cache and logs..."
rm -rf /var/www/html/var/cache/*
rm -rf /var/www/html/var/log/*
mkdir -p /var/www/html/var/cache /var/www/html/var/log
chmod -R 777 /var/www/html/var/cache /var/www/html/var/log

# --- 2. INSTALLATION DES DEPENDANCES ---
if [ -f /var/www/html/composer.json ]; then
    echo "[entrypoint] Installing PHP dependencies..."
    composer install --no-interaction --optimize-autoloader --no-scripts --working-dir=/var/www/html
    echo "[entrypoint] Composer done."
fi

# --- 3. ATTENTE DE MYSQL ---
echo "[entrypoint] Waiting for MySQL..."
until nc -z -v -w30 mysql 3306
do
  echo "Waiting for database connection..."
  sleep 5
done
echo "[entrypoint] MySQL is up!"

# --- 4. RÉINITIALISATION DE LA BDD (VERSION LA PLUS FIABLE) ---
echo "[entrypoint] Recreating database to avoid constraint conflicts..."
# On supprime et on recrée pour être sûr que les ID et Foreign Keys sont synchronisés
php /var/www/html/bin/console doctrine:database:drop --force --if-exists --no-interaction
php /var/www/html/bin/console doctrine:database:create --no-interaction
echo "[entrypoint] Synchronizing schema..."
php /var/www/html/bin/console doctrine:schema:update --force --no-interaction
echo "[entrypoint] Database is fresh and ready."

# --- 5. FINALISATION ---
mkdir -p /var/www/html/public/reports && chmod 777 /var/www/html/public/reports

echo "[entrypoint] Starting Apache..."
exec apache2-foreground