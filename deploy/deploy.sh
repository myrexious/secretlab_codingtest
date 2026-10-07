#!/usr/bin/env bash
#
# Runs on the VPS at /srv/kv/deploy.sh, mode 750, owned by the deploy user.
#
# This is a FORCED COMMAND. The CI key in ~deploy/.ssh/authorized_keys is
# registered as:
#
#   command="/srv/kv/deploy.sh",no-port-forwarding,no-agent-forwarding,no-X11-forwarding,no-pty ssh-ed25519 AAAA... kv-ci
#
# so this script is the only thing that key can do. That matters: the deploy
# account is in the docker group, which is root-equivalent, and it owns two
# other projects' .env files. A plain key would reach all of it.

set -euo pipefail

APP_DIR=/srv/kv
COMPOSE="docker compose -f ${APP_DIR}/compose.yml"

cd "$APP_DIR"

echo "==> Pulling the image that passed CI"
$COMPOSE pull

echo "==> Starting"
$COMPOSE up -d --remove-orphans

echo "==> Waiting for the container to report healthy"
for _ in $(seq 1 30); do
    status=$($COMPOSE ps --format json app | sed -n 's/.*"Health":"\([a-z]*\)".*/\1/p' | head -1)
    [ "$status" = "healthy" ] && break
    sleep 2
done

if [ "${status:-}" != "healthy" ]; then
    echo "!! Container never became healthy. Recent logs:" >&2
    $COMPOSE logs --tail 50 app >&2
    exit 1
fi

echo "==> Running migrations"
$COMPOSE exec -T app php artisan migrate --force

echo "==> Caching configuration and routes"
$COMPOSE exec -T app php artisan config:cache
$COMPOSE exec -T app php artisan route:cache

echo "==> Removing images left behind by previous deploys"
docker image prune -f --filter 'until=168h'

echo "==> Done"
