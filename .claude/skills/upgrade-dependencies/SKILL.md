---
name: upgrade-dependencies
description: Upgrade every dependency of the Wooless stack in one go — Docker base images (FrankenPHP/PHP, Node), Composer packages in app/ (Laravel) and wordpress/ (Bedrock, WordPress core, WooCommerce and other plugins), and npm packages in app/ — then rebuild, retest, fix deprecations and commit. Use this whenever the user wants to update, upgrade, bump or refresh dependencies, packages, PHP, Node, Laravel, WordPress, WooCommerce or plugins, asks "what's outdated", or mentions a security release of WordPress/WooCommerce/Laravel, even if they name only one package — the full sweep is the default here. Also use it for a report-only check of what is outdated.
---

# Upgrade dependencies

End-to-end dependency upgrade for this repository. The point of the skill is that nobody has to
enumerate packages by hand: a bundled script collects everything that is outdated, proposes the
new constraints in the style this repo already uses, and can write them into the manifests.
Your job is the judgement around it — order of operations, reading the diff, fixing what breaks,
and leaving the stack running and tested.

Arguments (`$ARGUMENTS`):
- `report` / `dry-run` / `check` — only run the checker and present the report, change nothing.
- `--no-commit` — do everything but leave the changes uncommitted.
- Package names (e.g. `woocommerce`, `laravel/framework`) — the user cares about those first; still run the full sweep, but call out those packages explicitly in the summary.

## Where things live

| What | Where | How it is run |
|---|---|---|
| Base images | `docker/app/Dockerfile`, `docker/wordpress/Dockerfile` | `./build.sh`, then `./down.sh && ./up.sh` |
| Laravel PHP deps | `app/composer.json` + `composer.lock` | `./bin/app composer …` |
| WordPress / plugins | `wordpress/composer.json` + `composer.lock` (plugins come from `repo.wp-packages.org`, pinned to exact versions) | `./bin/wordpress composer …` |
| Przelewy24 plugin | a `"type": "package"` entry in the `repositories` of `wordpress/composer.json` pointing at the vendor's ZIP, plus an exact pin on `przelewy24/woo-przelewy24` | `./bin/wordpress composer update przelewy24/woo-przelewy24` |
| Frontend | `app/package.json` + `package-lock.json` | `./bin/app npm …` |

Never call `docker exec` yourself; `./bin/app` and `./bin/wordpress` wrap it with the right user.
Both Dockerfiles must use the same FrankenPHP and PHP version — the app and WordPress share one
PHP runtime story and drifting them apart has no upside.

## Workflow

### 0. Preconditions

- `git status` must be clean (apart from untracked noise). An upgrade diff is big; mixing it with
  unrelated work makes the eventual revert impossible. Stop and ask if it is dirty.
- The stack must be running (`docker ps` shows `wooless-app` and `wooless-wordpress-app`).
  If not, run `./up.sh` — the checker needs Composer and npm inside the containers.

### 1. Collect what is outdated

```bash
python3 .claude/skills/upgrade-dependencies/scripts/check_outdated.py
```

The report has five sections: Docker images, Composer (app), Composer (wordpress), npm, and the
Przelewy24 plugin. For every package it shows the current constraint, installed and latest versions,
the proposed new constraint and whether the jump crosses a major version. Add `--json` if you want
to post-process it.

The Przelewy24 section exists because that plugin is not on any Composer repository. PayPro ships
it only as a ZIP on przelewy24.pl, so `wordpress/composer.json` declares it as a `package`
repository with a `dist.url` and `dist.shasum`. Composer therefore knows exactly one version and
`composer outdated` will never report it; the script instead reads the vendor's update manifest
(the same JSON the plugin's own updater polls, `P24_WooCommerce_*.ini`) and compares its `version`
with the declared one.

If the user asked for `report`/`dry-run`, present this report (as a short table per section, majors
first) and stop here.

### 2. Decide the version policy

Most of this is mechanical; the decisions worth a moment are:

- **PHP minor.** The report offers two FrankenPHP tags: same PHP minor (newest patch) and newest
  PHP minor. Default to the newest minor — the project has always followed PHP closely — unless
  `./bin/app composer why-not php <version>` or `./bin/wordpress composer why-not php <version>`
  names a blocking package. If blocked, take the same-minor tag and say so in the summary.
- **Node.** Use the newest even-numbered major (the LTS line); the report prints it.
- **Majors.** Composer/npm majors are bumped by default (that is what previous upgrades did, e.g.
  `concurrently` 9→10), except packages in the hold list inside the script (`DEFAULT_HOLDS`).
  `tailwindcss` is held on v3 because v4 is a rewrite, not an update. If a major turns out to need a
  migration you are not asked to do, add `--hold <package>` and mention the skipped package in the
  summary rather than silently leaving it.
- **Abandoned** packages: flag them in the summary; do not swap them for a replacement on your own.
- **Dev-branch** packages (`dev-main …`, currently `wp-cli/wp-cli-bundle`) carry no version to
  derive a constraint from; the script leaves them alone and `composer update` moves them anyway.

Read `references/past-upgrades.md` now — it lists what broke the last times and where to look.

### 3. Bump the base images first

Edit the three `FROM` lines with the tags the report prints (FrankenPHP in both Dockerfiles, Node in
`docker/app/Dockerfile`), then rebuild and restart:

```bash
./build.sh
./down.sh && ./up.sh
```

Images go first so that `composer update` resolves packages against the PHP that will actually run
them. `./up.sh` already runs `composer install`, `npm install` and `npm run build` with the old
lock files — a cheap check that the new runtime still executes the current code before you move
anything else.

Do not run `--apply` (step 4) before this restart. `./up.sh` uses `set -e`, and `composer install`
exits non-zero when `composer.json` constraints are no longer satisfied by the lock file, so the
script dies right after the WordPress `composer install`: FrankenPHP and cron in the WordPress
container, and Octane and SSR in the app container, are never started, and the app side of
`./up.sh` never runs. The symptom is a 502 from `/wordpress/wp-json/...` (connection refused from
the proxy). Recovery: `docker exec wooless-wordpress-app supervisorctl start frankenphp cron` and
`docker exec wooless-app supervisorctl start octane ssr`, then finish steps 4–5 by hand. Always
check that `./up.sh` printed `frankenphp: started`, `cron: started`, `ssr: started`,
`octane: started` at the end — it is the only confirmation that it reached its last line.

### 4. Bump the package manifests and update lock files

```bash
python3 .claude/skills/upgrade-dependencies/scripts/check_outdated.py --apply
./bin/wordpress composer update
./bin/app composer update
./bin/app npm install
./bin/app npm run build
```

`--apply` rewrites constraints in place (text substitution, so indentation and key order survive).
Style it reproduces: caret constraints become `^major.minor` in Composer and `^x.y.z` in npm; exact
pins (WordPress core, every WP plugin, `koncewicz-pl/*`, `przelewy24/*`) become the exact latest
version. Review `git diff -- app/composer.json wordpress/composer.json app/package.json` before
running the updates; it should read like the previous upgrade commit.

For Przelewy24, `--apply` does more than edit a pin: it follows the manifest's `package` URL through
the vendor's redirect to the versioned ZIP (the file name changes between releases, e.g.
`woocommerce-10-…` → `woocommerce-11-…`, so it must not be guessed), downloads it, checks that the
archive has the single `woo-przelewy24/` root Composer expects and that its plugin header declares
the manifest's version, and only then rewrites `version`, `dist.url`, `dist.shasum` and the pin.
The shasum is what lets Composer verify every future download. If any of those checks fails the
entry is left untouched and the report says why. The install path stays `web/app/plugins/woo-przelewy24`
(installer-paths use `{$name}`), so `install.sh` and its `wp plugin activate woo-przelewy24` need no change.
Also read the manifest's `tested` field (printed in the report): it is the highest WooCommerce
version the plugin was tested with, which matters when WooCommerce itself is being bumped.

If `composer update` reports a conflict, prefer loosening the single package that conflicts
(or holding its major) over downgrading unrelated ones.

After the updates, look for transitive majors the report could not show (it only lists direct
dependencies): `git diff app/composer.lock wordpress/composer.lock | grep -E '^\+\s+"version": "v?[0-9]+\.0\.'`
is a cheap first pass, then compare with `git show HEAD:app/composer.lock` for the same package.
For each transitive major, read `vendor/<vendor>/<package>/UPGRADING.md` or `CHANGELOG.md` and grep
`app/` for the removed APIs. Guzzle 7 → 8 arrived this way in October 2026 (via `guzzlehttp/promises`
^3) and removed `RequestException::hasResponse()`/`getResponse()`, which only showed up in
production as a 500 on the order page — see `references/past-upgrades.md`.

Before committing, run `./bin/app npm install` a second time on the finished lock and check that
`git diff app/package-lock.json` is empty. npm writes the lock differently when it resolves a
changed `package.json` than when the lock is already in sync, and the two disagree about the root
package's derived `name` (the project has no `name` in `package.json`, so npm takes it from the
mount directory, `/var/www` → `"www"`). Committing the first state means the very next
`npm install` on the server dirties the lock by one line. The same idempotency check is cheap for
Composer too: `composer install` after `composer update` must print "Nothing to install, update or remove".

### 5. Apply runtime changes and smoke-test

WordPress (database schema follows core/WooCommerce versions):

```bash
./bin/wordpress vendor/bin/wp core update-db
./bin/wordpress vendor/bin/wp wc update
./bin/wordpress vendor/bin/wp plugin list
./bin/wordpress vendor/bin/wp core version
```

Laravel:

```bash
./bin/app php artisan config:clear && ./bin/app php artisan config:cache && ./bin/app php artisan route:cache
docker exec wooless-app supervisorctl restart octane ssr   # the one docker exec exception: supervisor's socket is root-only, same as in up.sh
curl -sk -o /dev/null -w '%{http_code}\n' https://localhost/
curl -sk -o /dev/null -w '%{http_code}\n' https://localhost/wordpress/wp-json/wc/store/v1/products
```

Both URLs should return 200. Add the shop pages the Laravel app serves itself:
`https://localhost/products`, `https://localhost/products/<slug>` (take `slug` of the first product
from the Store API response; the WordPress `permalink` is not a frontend URL) and
`https://localhost/posts`. Then look for deprecations the new PHP/Laravel/Vue surfaced:

```bash
./bin/app php artisan test --compact
./bin/wordpress vendor/bin/pest
docker logs --since 10m wooless-app 2>&1 | grep -iE 'deprecat|error' | sort | uniq -c | head
./bin/app grep -iE 'deprecat' storage/logs/laravel.log | tail
```

Pint: `--dirty` does not work inside the containers (the `.git` directory is outside the mounted
project), so run Pint only on the PHP files you actually changed, e.g.
`./bin/app vendor/bin/pint app/Http/Controllers/CartController.php`. Running bare `pint` would
reformat the whole project and bury the upgrade in style churn.

The feature tests render Inertia pages through the running SSR server with partial fixture data,
so `SSR ERROR Cart/Index … reading 'length'` lines in `docker logs wooless-app` whose timestamps
match the test run are noise. Judge SSR by curling real pages afterwards and confirming the error
counter in the logs does not move.

Fix what you find in project code (not in `vendor/`). Past examples are in
`references/past-upgrades.md` — a PHP minor bump renamed PDO constants, a WooCommerce bump deprecated
an option used by `install.sh`, a Vue/Inertia bump deprecated an event API in a page component.
Keep these fixes small and in the same commit; they are part of "the upgrade works".

The Laravel Boost guidelines in `app/CLAUDE.md` embed package versions and go stale after an
upgrade. `php artisan boost:update` exists but refuses to run until `boost:install` has been done
in this checkout (as of October 2026 it has not); `boost:install` is interactive and rewrites the
guidelines wholesale, so leave it to the user and mention the stale header in the summary.

### 6. Summarise, commit, and release

Present a short summary: the three image versions, counts of bumped packages per manifest, the
majors that were crossed, anything held or abandoned, and the code fixes made. Then commit (unless
`--no-commit`), matching the repository's one-line imperative style with a trailing period:

```
Upgrade dependencies.
```

If the only change is a single security bump, name it instead, like the existing
`Update WordPress core to 7.0.2 to patch security vulnerability.` A lone Przelewy24 bump reads
`Update Przelewy24 plugin to 1.2.0.`

**Release.** The project uses semantic versioning with annotated git tags; production deploys a tag
(see the `deploy` skill). A dependency sweep is a MINOR release, a fix or security bump between sweeps
is a PATCH, an architecture change is a MAJOR. After the upgrade commit:

1. Generate the dependency table and add a `## [X.Y.0] - YYYY-MM-DD` section to `CHANGELOG.md`
   (Keep a Changelog format; move anything from `[Unreleased]` into it, add the compare link at the bottom):
   ```bash
   python3 .claude/skills/upgrade-dependencies/scripts/changelog_deps.py <previous tag> HEAD
   ```
   Put the table under `### Dependencies`; code fixes made during the upgrade go under `### Fixed`.
2. Commit the changelog (`Release X.Y.0.`), then tag and push:
   ```bash
   git tag -a vX.Y.0 -m "Wooless X.Y.0 — <one line: runtime + headline packages>"
   git push && git push --tags
   ```
3. A fix discovered after the release (like the Guzzle 8 order-page 500) gets its own commit, a
   `## [X.Y.1]` changelog section and tag `vX.Y.1`; do not amend a tag that has been pushed.

Then offer to deploy the tag with the `deploy` skill.

## When the script cannot help

- Docker Hub unreachable → the report says so; look the tags up at
  https://hub.docker.com/r/dunglas/frankenphp/tags and https://hub.docker.com/_/node and continue.
- Containers down → run `./up.sh`; do not try to run Composer/npm on the host, the host has no PHP.
- A package not found by `composer outdated` (e.g. a VCS-only dependency) → check its GitHub
  releases page and pin by hand.
- Przelewy24 manifest unreachable or its layout changed → open https://www.przelewy24.pl/do-pobrania/woocommerce,
  download the ZIP, compute `sha1sum`, and edit the `package` entry by hand (version, url, shasum, pin).
  The manifest URL is read from the installed plugin's `WC_P24_UPDATE_URL` constant, so a vendor
  rename is picked up automatically once the plugin itself has been updated.
- Vendor removed an old ZIP → `composer install` of that exact lock will fail; move the pin forward
  (the fix is always "upgrade", never "mirror it again").
