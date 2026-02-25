#!/bin/sh
set -e

echo "Starting RS-Max..."

# Create log directories
mkdir -p /var/log/nginx /var/log/php /var/log/supervisor
touch /var/log/php/error.log
chown www-data:www-data /var/log/php/error.log

# Ensure storage directories exist with correct permissions
mkdir -p /var/www/html/storage/framework/{sessions,views,cache}
mkdir -p /var/www/html/storage/logs
mkdir -p /var/www/html/storage/app/private/media
mkdir -p /var/www/html/bootstrap/cache
chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache
chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache

# Create .env file from .env.example as base
echo "Setting up .env file..."
if [ -f /var/www/html/.env.example ]; then
    cp /var/www/html/.env.example /var/www/html/.env
else
    touch /var/www/html/.env
fi

# Override .env with Docker environment variables (Coolify injects these)
for var in APP_NAME APP_ENV APP_DEBUG APP_URL APP_KEY \
           DB_CONNECTION DB_HOST DB_PORT DB_DATABASE DB_USERNAME DB_PASSWORD \
           MAIL_MAILER MAIL_HOST MAIL_PORT MAIL_USERNAME MAIL_PASSWORD \
           MAIL_FROM_ADDRESS MAIL_FROM_NAME \
           SESSION_DRIVER CACHE_STORE QUEUE_CONNECTION \
           FACEBOOK_APP_ID FACEBOOK_APP_SECRET FACEBOOK_CONFIG_ID \
           THREADS_APP_ID THREADS_APP_SECRET; do
    val=$(eval echo \$$var)
    if [ -n "$val" ]; then
        # Remove existing line and append new value
        sed -i "/^${var}=/d" /var/www/html/.env
        echo "${var}=${val}" >> /var/www/html/.env
    fi
done

chown www-data:www-data /var/www/html/.env
echo ".env configured"

# Generate app key if not set
if [ -z "$APP_KEY" ] && ! grep -q "^APP_KEY=base64:" /var/www/html/.env; then
    echo "APP_KEY not set, generating one..."
    php artisan key:generate --force
fi

# Wait for database to be ready (if MySQL)
if [ "$DB_CONNECTION" = "mysql" ]; then
    echo "Waiting for MySQL database..."
    max_tries=30
    counter=0
    until php artisan db:monitor --databases=mysql > /dev/null 2>&1 || [ $counter -eq $max_tries ]; do
        echo "   Attempt $counter/$max_tries - Database not ready yet..."
        counter=$((counter + 1))
        sleep 2
    done

    if [ $counter -eq $max_tries ]; then
        echo "Could not connect to database after $max_tries attempts"
        echo "   DB_HOST: $DB_HOST"
        echo "   DB_DATABASE: $DB_DATABASE"
        exit 1
    fi
    echo "Database is ready!"
fi

# Run migrations
echo "Running migrations..."
php artisan migrate --force

# Seed platforms if needed (only if platforms table is empty)
echo "Checking if seeding is needed..."
php artisan db:seed --class=PlatformSeeder --force 2>/dev/null || true

# Clear and optimize caches
echo "Optimizing application..."
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Create storage link if not exists
php artisan storage:link 2>/dev/null || true

echo "RS-Max is ready!"
echo "================================================"

# Execute the main command
exec "$@"
