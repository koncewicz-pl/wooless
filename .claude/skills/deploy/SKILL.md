---
name: deploy
description: Deploy a Wooless release tag to the production server (sklep.wooless.pl) and verify it — pull the tag, keep the server's production-only edits, rebuild images only when the Dockerfiles changed, run Composer/npm installs, database migrations, restart Octane/SSR/FrankenPHP and smoke-test the shop. Use this whenever the user asks to deploy, release, roll out, publish, push to production, update the server, or roll back to a previous version, including right after an upgrade or a hotfix commit.
---

# Deploy

Production is one VPS, reached as `ssh wooless-prod` (an alias in `~/.ssh/config` that holds the
host, user and key; it is not committed), with the repository checked out at `~/wooless` and the
same Docker stack as local development (`./build.sh`, `./up.sh`, `./bin/*`).
Production runs a **release tag**, never a bare branch, so `git describe --tags` on the server
always names what is live and a rollback is just a deploy of the previous tag.

Arguments (`$ARGUMENTS`): the tag to deploy (`v2.2.0`). Without one, deploy the newest `v*` tag
that exists on `origin`. `--rollback` means the tag before the one currently deployed.

## Before deploying

1. The tag must exist on `origin` (`git ls-remote --tags origin`). If the user asks to deploy work
   that is not tagged yet, tag it first — see the release step of the `upgrade-dependencies` skill
   (CHANGELOG entry, annotated tag, push with `--tags`).
2. Read the CHANGELOG section for that tag. Anything under *Removed* that is a WordPress plugin must
   be deactivated on the server before `composer install` deletes its files; the script does this
   automatically by diffing `wordpress/composer.json`, but know that it is going to happen.
3. Remember the production-only edits that live uncommitted on the server and must survive:
   port 80 in `docker-compose.yml`, `--host=sklep.wooless.pl --http-redirect` in
   `docker/app/supervisord.conf`, the production values in `install.sh`. The script stashes
   and re-applies them; a conflict there stops the script and is resolved by hand.

## Deploying

```bash
scp .claude/skills/deploy/scripts/deploy.sh wooless-prod:~/deploy.sh
ssh wooless-prod 'bash ~/deploy.sh v2.2.0'
```

Always copy the script and run it from a file. Feeding it through `ssh 'bash -s' < deploy.sh`
silently stops after the first `./bin/wordpress` or `./bin/app` call, because those wrap
`docker exec -i`, which reads the rest of the script from stdin.

What the script does, in order: deactivates plugins the release removes, stashes the production
edits, checks out the tag (detached HEAD), restores the edits, then either rebuilds images and
restarts the whole stack (`./build.sh && ./down.sh && ./up.sh`, only if `docker/` or
`docker-compose.yml` changed) or just runs the installs, the Vite build, config/route cache and
`supervisorctl restart octane ssr`. Then `wp core update-db`, `wp wc update`, process status, and
a smoke test of `/`, `/products`, `/cart` and the Store API.

The VPS is small. `./build.sh` plus `npm run build` fit, but do not run anything else heavy on
it at the same time. A full rebuild means a few minutes of downtime during
`./up.sh`; an install-only deploy is a few seconds of Octane restart.

## After deploying

- Every URL in the smoke test must return 200 and `supervisorctl status` must show `frankenphp`,
  `cron`, `octane`, `ssr` RUNNING. If FrankenPHP or Octane show `STOPPED Not started`, `./up.sh`
  died early (usually `composer install` refusing an out-of-date lock); start them with
  `docker exec <container> supervisorctl start …` and look at why.
- Check the application log for fresh errors: `docker logs --since 5m wooless-app | grep production.ERROR`
  (Laravel logs to stderr on production, there is no `storage/logs`).
- Then test in the browser (Claude in Chrome): product page → add to cart → checkout → shipping
  with Furgonetka points → the pickup-point map. Two caveats learned the hard way: clicks and
  screenshots only work while the tab is in the foreground (a background tab has no
  `requestAnimationFrame`, so Google Maps will not draw and screenshots time out — this is not an
  application bug); when the tab is in the background, drive the page with `javascript_tool`
  (dispatch `click()`, fetch `/cart/state`) and inspect the DOM instead of taking screenshots.
- Exercise the error paths too, not only the happy ones: the order page after a Przelewy24 return
  (`/checkout/order/<id>?key=…`) is where a Guzzle 8 regression surfaced as a 500 in October 2026
  while every happy-path page was green.
- Report: the tag now live (`git describe --tags`), what changed (from the CHANGELOG), the smoke-test
  results, and anything left uncommitted on the server (`git status --short` should list only the
  three production-edit files).

## Rollback

`bash ~/deploy.sh <previous tag>`. Database migrations from WordPress/WooCommerce are not reverted
by this; if the release moved WooCommerce by a major version, a rollback needs a database restore
as well — say so before doing it.
