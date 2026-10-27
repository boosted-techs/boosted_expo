#!/bin/bash
set -euo pipefail
exec 9>/run/lock/boosted-one-deploy.lock
flock -n 9
release=/srv/boosted-one/releases/20261024-1
cd "$release"
app_uid=$(id -u boosted-one)
compose() {
    runuser -u boosted-one -- env HOME=/srv/boosted-one DOCKER_HOST=unix:///run/user/${app_uid}/docker.sock docker compose -f "$release/deploy/compose.production.yaml" "$@"
}
compose config --quiet
compose up -d --wait --wait-timeout 120 postgres redis
compose run --rm --no-deps web php artisan migrate --force --no-interaction
compose run --rm --no-deps web php artisan boostedone:provision-admin developer@boostedtechs.com --no-interaction
compose run --rm --no-deps web php artisan expo:backup --json
compose up -d --wait --wait-timeout 120
ln -sfn "$release" /srv/boosted-one/current
cp "$release/deploy/nginx/boostedone.conf" /etc/nginx/sites-available/boostedone
nginx -t
systemctl reload nginx
curl --fail --silent --show-error https://boostedone.online/up >/dev/null
compose ps
