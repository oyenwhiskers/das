#!/bin/sh
set -e

cd /app

mkdir -p \
    storage/app/private \
    storage/app/public \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache

chown -R www-data:www-data storage bootstrap/cache
chmod -R ug+rwx storage bootstrap/cache

# Cloud SQL is only reachable after Dokploy starts the container, so migrate here rather than during image build.
if [ "${1:-}" = "frankenphp" ]; then
    php artisan migrate --force

    i=1
    worker_pids=""
    while [ "$i" -le 5 ]; do
        php artisan queue:work --sleep=3 --tries=3 --timeout=3600 &
        worker_pids="$worker_pids $!"
        i=$((i + 1))
    done

    "$@" &
    web_pid=$!

    shutdown() {
        kill -TERM "$web_pid" $worker_pids 2>/dev/null || true
        wait || true
        exit 0
    }
    trap shutdown TERM INT

    set +e
    wait "$web_pid"
    status=$?
    set -e
    kill -TERM $worker_pids 2>/dev/null || true
    wait || true
    exit "$status"
fi

exec "$@"
