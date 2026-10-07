#!/bin/bash
# Deploy a Wooless release tag on the production server.
#
# Run ON THE SERVER from a file (never via `ssh 'bash -s' < script`: ./bin/* use `docker exec -i`,
# which swallows the rest of a script fed through stdin):
#   scp deploy.sh wooless-prod:~/deploy.sh && ssh wooless-prod 'bash ~/deploy.sh v2.2.0'
# (`wooless-prod` is an alias in ~/.ssh/config with the host, user and key; it is not committed.)
set -euo pipefail

TAG="${1:?usage: deploy.sh <tag>   e.g. deploy.sh v2.2.0}"
cd ~/wooless

echo "== current: $(git describe --tags --always) -> target: $TAG"
git fetch -q --tags origin
git rev-parse -q --verify "refs/tags/$TAG" >/dev/null || { echo "tag $TAG not found on origin"; exit 1; }
PREV=$(git rev-parse HEAD)

echo "== 1/6 plugins removed by this release are deactivated while their files still exist"
removed_plugins() {
  python3 - "$PREV" "$TAG" <<'PY'
import json, subprocess, sys
def plugins(ref):
    data = json.loads(subprocess.check_output(["git", "show", f"{ref}:wordpress/composer.json"], text=True))
    return {name.split("/")[-1] for name in data.get("require", {}) if name.startswith(("wp-plugin/", "koncewicz-pl/", "przelewy24/"))}
print("\n".join(sorted(plugins(sys.argv[1]) - plugins(sys.argv[2]))))
PY
}
for plugin in $(removed_plugins); do
  echo "deactivating $plugin"; ./bin/wordpress vendor/bin/wp plugin deactivate "$plugin" || true
done

echo "== 2/6 checkout $TAG, keeping the production-only edits"
git stash push -q -m "prod-local-edits-$(date +%F-%H%M)" || true
git checkout -q --detach "$TAG"
git stash pop -q || { echo "!! stash pop conflict: resolve by hand (git status), production edits are in the stash"; exit 1; }
git status --short

if ! git diff --quiet "$PREV" "$TAG" -- docker/ docker-compose.yml; then
  echo "== 3/6 Dockerfiles or compose changed: rebuild and restart the stack"
  ./build.sh
  ./down.sh
  ./up.sh
else
  echo "== 3/6 images unchanged: install dependencies and reload processes"
  ./bin/wordpress composer install --no-interaction 2>&1 | tail -3
  ./bin/app composer install --no-interaction 2>&1 | tail -3
  ./bin/app npm install 2>&1 | tail -2
  ./bin/app npm run build 2>&1 | grep -E 'built in|error' || true
  ./bin/app php artisan config:clear -q && ./bin/app php artisan config:cache -q && ./bin/app php artisan route:cache -q
  docker exec wooless-app supervisorctl restart octane ssr
fi

echo "== 4/6 database migrations"
./bin/wordpress vendor/bin/wp core update-db
./bin/wordpress vendor/bin/wp wc update

echo "== 5/6 processes"
docker exec wooless-wordpress-app supervisorctl status
docker exec wooless-app supervisorctl status

echo "== 6/6 smoke test"
for path in / /products /cart "/wordpress/wp-json/wc/store/v1/products"; do
  curl -s -o /dev/null -w "%{http_code} %{time_total}s https://sklep.wooless.pl$path\n" "https://sklep.wooless.pl$path"
done
./bin/wordpress vendor/bin/wp plugin list --fields=name,status,version
echo "== deployed: $(git describe --tags)"
