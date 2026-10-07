# Changelog

All notable changes to Wooless are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/): a dependency-upgrade sweep is a MINOR release,
fixes between sweeps are PATCH releases, architecture changes are MAJOR releases.
Releases are git tags (`vX.Y.Z`); production runs a tag, never a bare branch.

## [Unreleased]

## [2.2.0] - 2026-10-07

### Added
- `upgrade-dependencies` Claude Code skill (`.claude/skills/`): collects outdated Docker images, Composer
  and npm packages and the Przelewy24 vendor ZIP, rewrites manifests, and documents the upgrade workflow.
- `deploy` Claude Code skill with the production deployment script (deploys a release tag).
- This changelog and retroactive release tags `v2.0.0` … `v2.1.2`.

### Changed
- Przelewy24 plugin is installed from the vendor's versioned ZIP through a Composer `package` repository
  (with checksum) instead of the `koncewicz-pl/woo-przelewy24` GitHub mirror.
- `app/package.json` declares a package name so `npm install` no longer rewrites the lock file.

### Removed
- `acf-to-rest-api` plugin: unused (ACF data reaches the frontend through the `wooless` Store API
  extension), unmaintained upstream, and closed on wordpress.org over CVE-2025-12030.

### Fixed
- 500 on `/checkout/order/{id}` after returning from Przelewy24: Guzzle 8 removed
  `RequestException::hasResponse()`; error bodies are now read from `ResponseException`. Covered by
  `tests/Feature/CheckoutControllerTest.php`.

### Dependencies

#### Runtime

| Package | From | To |
|---|---|---|
| FrankenPHP | 1.12.4 | 1.13.0 |
| Node | 26.4.0 | 26.10.0 |
| PHP | 8.5.7 | 8.5.11 |

#### WordPress (Composer)

| Package | From | To |
|---|---|---|
| koncewicz-pl/acf-to-rest-api | 3.3.4 | — (removed) |
| koncewicz-pl/woo-przelewy24 | 1.1.0 | — (removed) |
| laravel/pint | ^1.29 | ^1.32 |
| pestphp/pest | ^4.7 | ^5.3 |
| przelewy24/woo-przelewy24 | — | 1.2.0 |
| roots/wordpress | 7.0.2 | 7.1.3 |
| vlucas/phpdotenv | ^5.6 | ^5.7 |
| wp-plugin/advanced-custom-fields | 6.8.5 | 6.8.10 |
| wp-plugin/furgonetka | 1.9.4 | 1.9.9 |
| wp-plugin/woocommerce | 10.9.3 | 11.1.2 |

#### Laravel (Composer)

| Package | From | To |
|---|---|---|
| guzzlehttp/promises | ^2.5 | ^3.0 |
| inertiajs/inertia-laravel | ^3.1 | ^3.5 |
| laravel/boost | ^2.4 | ^2.10 |
| laravel/framework | ^13.18 | ^13.35 |
| laravel/octane | ^2.17 | ^2.21 |
| laravel/pint | ^1.29 | ^1.32 |
| laravel/sail | ^1.63 | ^1.68 |
| phpunit/phpunit | ^13.2 | ^13.4 |

#### Frontend (npm)

| Package | From | To |
|---|---|---|
| @egjs/vue3-flicking | ^4.16.1 | ^4.17.1 |
| @googlemaps/js-api-loader | ^2.1.1 | ^2.1.3 |
| @inertiajs/vue3 | ^3.6.0 | ^3.8.0 |
| @vitejs/plugin-vue | ^6.0.7 | ^6.0.9 |
| @vue/server-renderer | ^3.5.39 | ^3.5.43 |
| autoprefixer | ^10.5.2 | ^10.6.1 |
| axios | ^1.18.1 | ^1.20.0 |
| concurrently | ^10.0.3 | ^10.0.5 |
| laravel-vite-plugin | ^3.1.0 | ^3.2.0 |
| postcss | ^8.5.16 | ^8.5.29 |
| vite | ^8.1.3 | ^8.3.3 |
| vue | ^3.5.39 | ^3.5.43 |
| vue-i18n | ^11.4.6 | ^11.4.13 |
## [2.1.2] - 2026-07-27

### Fixed
- 403 from Furgonetka `/points/map`: the API host now follows the plugin's test/production mode.
- `install.sh` used the deprecated `woocommerce_task_list_complete` option.

## [2.1.1] - 2026-07-18

### Security
- WordPress core 7.0 → 7.0.2.

## [2.1.0] - 2026-07-04

### Changed
- Cart-Token handling moved to a Guzzle middleware; cart requests are serialized to stop losing the token.
- Cart state is passed through a session flash after mutations to skip a redundant fetch.
- Internal app → WordPress proxy restored to HTTPS so the WooCommerce REST API works.
- README documents production deployment and the ngrok tunnel.

### Fixed
- PHP 8.5: `PDO::MYSQL_ATTR_SSL_CA` → `Pdo\Mysql::ATTR_SSL_CA` in `config/database.php`.
- Deprecated event API in the checkout map component.

### Dependencies

#### Runtime

| Package | From | To |
|---|---|---|
| FrankenPHP | 1.9.1 | 1.12.4 |
| Node | 25.9.0 | 26.4.0 |
| PHP | 8.4.14 | 8.5.7 |

#### WordPress (Composer)

| Package | From | To |
|---|---|---|
| composer/installers | ^2.2 | ^2.3 |
| koncewicz-pl/woo-przelewy24 | 1.0.18 | 1.1.0 |
| laravel/pint | ^1.27 | ^1.29 |
| pestphp/pest | ^4.0 | ^4.7 |
| roots/bedrock-autoloader | ^1.0 | ^1.1 |
| roots/bedrock-disallow-indexing | ^2.0 | ^2.1 |
| roots/wordpress | 6.9.4 | 7.0 |
| vlucas/phpdotenv | ^5.5 | ^5.6 |
| wp-cli/wp-cli-bundle | ^2.11 | ^2.12 |
| wp-plugin/advanced-custom-fields | 6.8.0 | 6.8.5 |
| wp-plugin/furgonetka | 1.9.3 | 1.9.4 |
| wp-plugin/woocommerce | 10.6.2 | 10.9.3 |
| wp-theme/twentytwentyfive | ^1.0 | ^1.5 |

#### Laravel (Composer)

| Package | From | To |
|---|---|---|
| fakerphp/faker | ^1.23 | ^1.24 |
| guzzlehttp/promises | ^2.0 | ^2.5 |
| inertiajs/inertia-laravel | ^3.0 | ^3.1 |
| laravel/breeze | ^2.3 | ^2.4 |
| laravel/framework | ^13.0 | ^13.18 |
| laravel/octane | ^2.6 | ^2.17 |
| laravel/pail | ^1.1 | ^1.2 |
| laravel/pint | ^1.13 | ^1.29 |
| laravel/sail | ^1.26 | ^1.63 |
| laravel/sanctum | ^4.0 | ^4.3 |
| nunomaduro/collision | ^8.1 | ^8.9 |
| phpunit/phpunit | ^13.0 | ^13.2 |
| tightenco/ziggy | ^2.0 | ^2.6 |

#### Frontend (npm)

| Package | From | To |
|---|---|---|
| @egjs/vue3-flicking | ^4.12.0 | ^4.16.1 |
| @googlemaps/js-api-loader | ^2.0.2 | ^2.1.1 |
| @googlemaps/markerclusterer | ^2.5.3 | ^2.6.2 |
| @inertiajs/vue3 | ^3.0.3 | ^3.6.0 |
| @vitejs/plugin-vue | ^6.0.6 | ^6.0.7 |
| @vue/server-renderer | ^3.4.0 | ^3.5.39 |
| autoprefixer | ^10.4.12 | ^10.5.2 |
| axios | ^1.7.4 | ^1.18.1 |
| concurrently | ^9.0.1 | ^10.0.3 |
| laravel-vite-plugin | ^3.0.1 | ^3.1.0 |
| postcss | ^8.4.31 | ^8.5.16 |
| tailwindcss | ^3.4.17 | ^3.4.19 |
| vite | ^8.0.8 | ^8.1.3 |
| vue | ^3.4.0 | ^3.5.39 |
| vue-i18n | ^11.1.2 | ^11.4.6 |
## [2.0.0] - 2026-04-17

### Changed
- Rewritten as a headless stack: WordPress on Bedrock (Composer-managed core and plugins) as the backend,
  Laravel + Inertia/Vue 3 with SSR as the storefront, both on FrankenPHP in Docker.

## [1.0] - 2025-12-27

- Initial release.

[Unreleased]: https://github.com/koncewicz-pl/wooless/compare/v2.2.0...HEAD
[2.2.0]: https://github.com/koncewicz-pl/wooless/compare/v2.1.2...v2.2.0
[2.1.2]: https://github.com/koncewicz-pl/wooless/compare/v2.1.1...v2.1.2
[2.1.1]: https://github.com/koncewicz-pl/wooless/compare/v2.1.0...v2.1.1
[2.1.0]: https://github.com/koncewicz-pl/wooless/compare/v2.0.0...v2.1.0
[2.0.0]: https://github.com/koncewicz-pl/wooless/compare/v1.0...v2.0.0
[1.0]: https://github.com/koncewicz-pl/wooless/releases/tag/v1.0
