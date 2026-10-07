# Lessons from previous upgrades

What previous upgrade commits in this repo changed besides the manifests, and the checks that
would have caught each one early. Read before step 3 of the workflow.

## Commit `233de7a` — "Upgrade dependencies." (PHP 8.4 → 8.5, FrankenPHP 1.9 → 1.12, Node 25 → 26)

- **PDO constants renamed in PHP 8.5.** `PDO::MYSQL_ATTR_SSL_CA` → `Pdo\Mysql::ATTR_SSL_CA` in
  `app/config/database.php` (two occurrences, mysql and mariadb connections). The upstream Laravel
  skeleton ships this change; when bumping a PHP minor, diff `app/config/*.php` against the current
  `laravel/laravel` skeleton for similar renames.
- Everything else was constraint bumps: Laravel 13.0 → 13.18, Inertia 3.0 → 3.1, WordPress 6.9.4 →
  7.0, WooCommerce 10.6.2 → 10.9.3, ACF 6.8.0 → 6.8.5, Furgonetka 1.9.3 → 1.9.4,
  `koncewicz-pl/woo-przelewy24` 1.0.18 → 1.1.0 (the mirror that has since been replaced, see below), Vue 3.4 → 3.5, Vite 8.0 → 8.1, `concurrently` 9 → 10 (major).
- `tailwindcss` stayed on `^3.4.x` although v4 existed — intentional, the project targets Tailwind v3.

## Przelewy24 plugin: GitHub mirror replaced by the vendor's ZIP (October 2026)

- Until then the plugin was mirrored in `github.com/koncewicz-pl/woo-przelewy24` and every release
  meant copying ~270 files into that repo, tagging, and bumping `koncewicz-pl/woo-przelewy24` here.
  Now `wordpress/composer.json` has a `"type": "package"` repository entry for
  `przelewy24/woo-przelewy24` whose `dist` points at the vendor's versioned ZIP with a `shasum`, and
  the pin in `require` matches the entry's `version`. The mirror repo stays on GitHub as an archive.
- Verified while switching: the vendor still serves every older ZIP
  (`woocommerce-10-przelewy24-1.0.18.zip`, `…-1.1.0.zip`, `woocommerce-11-przelewy24-1.1.3.zip`), so
  `composer.lock` stays reproducible; the ZIP has one root folder (`woo-przelewy24/`) which Composer
  flattens into `web/app/plugins/woo-przelewy24`; `composer update przelewy24/woo-przelewy24`
  removed the old package and installed the new one in a single partial update.
- The manifest the vendor publishes (`P24_WooCommerce_11.ini`, JSON despite the extension) carries
  `version`, a `package` URL that 302-redirects to the versioned ZIP, and `tested` (WooCommerce).
  The plugin appends `?t=<date>` to the URL for cache busting; strip it when reading the constant.
- `koncewicz-pl/acf-to-rest-api` (a mirror of `airesvsg/acf-to-rest-api` whose only change was
  allowing `composer/installers` v2) was removed in October 2026 instead of being converted. Nothing
  consumed its `/wp-json/acf/v3/*` endpoints: the frontend gets ACF data through the `wooless`
  plugin's Store API extension (`get_fields()` directly). Upstream is unmaintained (last push
  December 2024), wordpress.org closed the plugin on 2026-01-02 over CVE-2025-12030 (IDOR in
  `update_item_permissions_check()`), and `repo.wp-packages.org` no longer serves it. If anyone
  asks to bring it back, the answer is "why?" — the ACF `acf/v3` REST endpoints are not needed here.

## Commit `74ff457` — "Upgrade dependencies." (October 2026: Guzzle 7 → 8 came in through `guzzlehttp/promises` ^3)

- Bumping the direct `guzzlehttp/promises` constraint to `^3.0` let Composer pull `guzzlehttp/guzzle`
  8.x and `guzzlehttp/psr7` 3.x as transitive upgrades — nothing in the report said "Guzzle major",
  because Guzzle itself is not a direct dependency. **Guzzle 8 removed `RequestException::hasResponse()`
  and `getResponse()`; the response now lives only on `ResponseException` (parent of
  `BadResponseException`, `ClientException`, `ServerException`).** Every `catch (RequestException $e)`
  that then read `$e->getResponse()` had to become `catch (ResponseException $e)`, and
  `instanceof RequestException && hasResponse()` became `instanceof ResponseException`.
- It was not caught before the production deploy because no test exercised the rejection branch of
  the order page; the unit tests passed and the smoke test only loaded pages with happy responses.
  The 500 surfaced on `/checkout/order/{id}` right after the Przelewy24 return, where WooCommerce
  answers 401 `woocommerce_rest_invalid_billing_email` for guests and the controller tried to read
  the error body. Fixed in the following commit together with `tests/Feature/CheckoutControllerTest.php`.
- Lesson for the report: after `composer update`, diff the lock for transitive majors too, e.g.
  `git diff app/composer.lock | grep -E '^[-+]\s+"version"'` around `guzzlehttp/`, `symfony/`,
  `league/`, and grep the app for APIs the upgrade guide (`vendor/<pkg>/UPGRADING.md`) says are gone.

## Commit `7d965d5` — "Replace deprecated woocommerce_task_list_complete option in install.sh."

- A WooCommerce bump deprecated an option that `install.sh` sets during seeding. After bumping
  WooCommerce, grep `install.sh` for `wp option` / `wp wc` calls and check each option/command
  still exists in the new version (`./bin/wordpress vendor/bin/wp option get <name>`).

## Commit `3104d07` — "Update WordPress core to 7.0.2 to patch security vulnerability."

- WordPress core is pinned exactly in `wordpress/composer.json` (`roots/wordpress`), so security
  patches do not arrive via `composer update` alone — the pin has to move. The checker reports it
  like any other package.

## Commits `2c99bda` / `5ffc0d6` — "Fix deprecated call." / "Fix event name."

- An earlier Vue/Inertia bump deprecated an event API used in `app/resources/js/Pages/Checkout/Map.vue`.
  After `npm run build`, read the Vite output for deprecation warnings and open the shop pages that
  use third-party components (map/Furgonetka points, flicking carousel on the home page).

## Commit `85ff645` — "Upgrade wp, php and js dependencies." (older, pre-Bedrock)

- Vendored plugin code changed massively because plugins were committed to the repo at the time.
  Today plugins are Composer-managed under `wordpress/web/app/plugins/` (ignored), so a plugin bump
  should only touch `wordpress/composer.json` and `composer.lock`. If `git status` shows plugin
  files, something is wrong with the installer paths.

## General checklist derived from the above

1. PHP minor bump → diff `app/config/` against the upstream skeleton; run the test suite; grep logs for "Deprecated".
2. WooCommerce bump → `wp wc update`; verify options/commands used by `install.sh`.
3. WordPress core bump → `wp core update-db`; check `wp plugin list` shows everything active.
4. Vue/Inertia/Vite bump → `npm run build` warnings; smoke-test checkout map and home carousel.
5. Laravel minor bump → `php artisan test`; `config:cache` and `route:cache` must succeed.
6. Przelewy24 bump → check the manifest's `tested` WooCommerce version against the WooCommerce pin;
   after `composer update przelewy24/woo-przelewy24` confirm `wp plugin list` shows the new version
   and smoke-test the checkout payment step.
