#!/bin/bash
set -e

export APP_USER="koncewicz"
export WWWUSER=$(id -u)
export WWWGROUP=$(id -g)

APP_CONTAINER="wooless-app"
WORDPRESS_APP_CONTAINER="wooless-wordpress-app"

docker compose -f docker-compose.yml -p wooless up -d

echo "Waiting for app container init..."
until docker exec "$APP_CONTAINER" test -f /tmp/container-ready 2>/dev/null; do
  sleep 0.5
done
echo "$APP_CONTAINER ready."

echo "Waiting for wordpress container init..."
until docker exec "$WORDPRESS_APP_CONTAINER" test -f /tmp/container-ready 2>/dev/null; do
  sleep 0.5
done
echo "$WORDPRESS_APP_CONTAINER ready."

# WordPress
./bin/wordpress composer install

if ./bin/wordpress vendor/bin/wp core is-installed 2>/dev/null; then
  ./bin/wordpress vendor/bin/wp language core install pl_PL --activate
fi

docker exec $WORDPRESS_APP_CONTAINER supervisorctl start frankenphp
docker exec $WORDPRESS_APP_CONTAINER supervisorctl start cron

# App
./bin/app composer install
./bin/app npm install
./bin/app npm run build

./bin/app php artisan key:generate --force --no-interaction
./bin/app php artisan storage:unlink
./bin/app php artisan storage:link

./bin/app php artisan config:clear
./bin/app php artisan cache:clear
./bin/app php artisan config:cache
./bin/app php artisan route:cache

docker exec $APP_CONTAINER supervisorctl start ssr
docker exec $APP_CONTAINER supervisorctl start octane
