#!/usr/bin/env bash
set -euo pipefail

image=${1:?Supply the application image}
key="base64:$(openssl rand -base64 32)"
container=$(docker run -d --rm --network none --tmpfs /config \
    -e "APP_KEY=$key" -e DB_CONNECTION=sqlite "$image")
trap 'docker stop "$container" >/dev/null 2>&1 || true' EXIT

for attempt in $(seq 1 60); do
    if docker exec "$container" curl -fsS --max-time 2 http://127.0.0.1/api/healthcheck; then
        docker exec "$container" php /app/www/artisan migrate:status --no-interaction | grep 'create_network_monitor_tables.*Ran'
        status=$(docker exec "$container" curl -sS -H 'Accept: application/json' -o /dev/null -w '%{http_code}' http://127.0.0.1/api/network-monitor/v1/devices)
        test "$status" = 401
        exit
    fi
    sleep 2
done
docker logs --tail 40 "$container"
exit 1
