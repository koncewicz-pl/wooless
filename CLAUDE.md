@app/CLAUDE.md

# Wooless

Headless WordPress project. WordPress serves as the backend (CMS + REST API), Laravel serves as the frontend and communicates with WordPress via API.

## Architecture

- `wordpress/` — WordPress using Bedrock (Composer-based, environment config via `.env`)
- `app/` — Laravel frontend (consumes WordPress REST API)
- Each component runs in a separate Docker container (see `docker-compose.yml`)

## Running commands inside containers

**Never use `docker exec` directly.** Use the scripts in `bin/`:

- `./bin/app <command>` — runs a command in the Laravel container (e.g. `./bin/app php artisan migrate`)
- `./bin/wordpress <command>` — runs a command in the WordPress container (e.g. `./bin/wordpress wp plugin list`)

## MCP Servers

Frontend application has a dedicated Laravel Boost MCP server. Always use the correct server for the application you are working on:

- **App** → `laravel-boost-app` MCP server (tools prefixed `mcp__laravel-boost-app__`)

## Server domain

The port, the Caddy site address and the HTTP→HTTPS redirect (empty `CADDY_GLOBAL_OPTIONS`, port 80 in `docker-compose.yml`) are set in `app/config/octane.php` (`port`, `caddy.env`); the domain comes from `OCTANE_HOST` at the top of `app/.env` (exactly one). `OCTANE_REDIRECT_HOSTS` (comma-separated list, empty = `www.<OCTANE_HOST>`) names the aliases that a second site block in `app/Caddyfile` redirects permanently to the main domain; Caddy obtains certificates for them too, so every alias needs a DNS record pointing at the server. The same block passes `WORDPRESS_CONTAINER_URL` and `WORDPRESS_HOST` (the main domain, sent to WordPress as `X-Forwarded-Host`) to the WordPress proxy in `app/Caddyfile`. `APP_URL` is set explicitly. Supervisor runs `php artisan octane:frankenphp --caddyfile=/var/www/Caddyfile` with no `--host`/`--port`/`--https`; production therefore has no uncommitted edits in `docker/` or the scripts. Locally `OCTANE_HOST=localhost` (the alias `www.localhost` gets an internal-CA certificate); after changing these values run `./bin/app php artisan config:cache` and `docker exec wooless-app supervisorctl restart octane`.

WordPress has its own domain in `wordpress/.env` (`WP_HOME`), together with the `WP_ADMIN_*` account that `install.sh` creates.

## Tests

`app/phpunit.xml` points to separate (non-existent) config, route and event cache paths, so `./bin/app php artisan test --compact` works even when `up.sh` has cached the config in the running container.
