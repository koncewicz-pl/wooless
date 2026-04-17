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
