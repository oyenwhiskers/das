# DAS production deployment

Dokploy builds this repository from the `Dockerfile` and injects environment variables at runtime. The image does not contain `.env`, `APP_KEY`, or database passwords. Each start of the web container runs `php artisan migrate --force` and `php artisan db:seed --force`, then FrankenPHP and five queue workers. The image build itself does not migrate or seed: Cloud SQL is not available while Dokploy is building the image.

## Image

- PHP 8.4 with FrankenPHP (Caddy, classic `php_server`, not Octane). `composer.json` allows `^8.3`; the lockfile's Symfony 8.1 packages require PHP 8.4.1 or newer.
- Python 3 + `pypdf` (from `requirements.txt`) so compressed PDFs can be split when FPDI cannot — required for multi-page bank packs on the server.
- Web root: `/app/public`
- Container port: **80**
- Health check: `GET /up`

HTTPS stops at Dokploy. The app trusts forwarded proxy headers.

## Dokploy application

- Source: GitHub repository `das`, branch `main`
- Build type: Dockerfile
- Build context: `.`
- Dockerfile: `Dockerfile`
- Port: `80`
- Health check path: `/up`

### Persistent volume (required for uploads)

Without this, every redeploy wipes uploaded PDFs. The database still shows scans as Completed, but Preview returns **404 | Not Found**.

Where to go in Dokploy:

1. Open the **DAS** application (the web service that runs this Dockerfile).
2. Open the service → **Advanced** → **Mounts** (or **Volumes**, depending on Dokploy version).
3. Add a volume mount:
   - **Mount path:** `/app/storage/app`
   - Volume type: bind or named volume (either is fine; keep the same mount across redeploys).
4. Save, then **Redeploy** / restart the service.
5. Re-upload any document that was scanned before the volume existed. Old files cannot come back.

That path holds uploaded scans and generated PDFs. Do **not** mount `/app/storage`. The BIMBSEC templates live in `storage/templates` inside the image and would be hidden by a mount of the whole storage directory.

After a successful upload, the file should appear under `/app/storage/app/private/document-scans/` inside the container.

## Environment

Set these in Dokploy. Keep every existing `APP_*`, `DB_*`, `SESSION_*`, `QUEUE_*`, `CACHE_*`, `FILESYSTEM_*`, `ADMIN_*`, `GEMINI_*`, `AI_SCAN_*`, `MAIL_*`, `REDIS_*`, `AWS_*`, and `VITE_*` value already configured there.

Production values that must be present:

```
APP_ENV=production
APP_DEBUG=false
APP_KEY=
APP_URL=http://hairaa-capital-das-das-kr1xw9-cc2638-34-21-149-115.sslip.io
LOG_CHANNEL=stderr
LOG_LEVEL=info

DB_CONNECTION=mysql
DB_HOST=
DB_PORT=3306
DB_DATABASE=das_production
DB_USERNAME=das_app
DB_PASSWORD=

SESSION_DRIVER=database
QUEUE_CONNECTION=database
DB_QUEUE_RETRY_AFTER=3660
CACHE_STORE=database
FILESYSTEM_DISK=local
```

`DB_HOST` is the Cloud SQL address reachable from the VM. It is not `127.0.0.1` and MySQL is not part of this container. Use `https://` in `APP_URL` when Dokploy serves TLS for that host. Set `MYSQL_ATTR_SSL_CA` only when Cloud SQL requires a CA file that is mounted into the container.

Keep existing `VITE_*` variables in Dokploy. The current asset build does not read them.

## Queue workers

The web container starts five `queue:work` processes after migrate:

```
php artisan queue:work --sleep=3 --tries=3 --timeout=3600
```

`--timeout` stays below `DB_QUEUE_RETRY_AFTER` (3660). Do not add a second worker service for the same database or jobs will be processed twice. On stop, the entrypoint signals the web server and the workers. Docker's default stop timeout is 10 seconds, so a long job can be killed and becomes available again after the retry window.

## Scheduler

Nothing is scheduled today. Run this as a separate command when tasks are added. It does not migrate and it does not start FrankenPHP or the five workers:

```
php artisan schedule:work
```

## First deployment

The web container migrates and seeds on startup. `DatabaseSeeder` only runs `AdminUserSeeder`, which creates or updates the user from `ADMIN_NAME`, `ADMIN_EMAIL`, and `ADMIN_PASSWORD`. Every restart sets that account's password back to `ADMIN_PASSWORD`.

Optional, after the environment is confirmed:

```
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Run `php artisan optimize:clear` before the next deploy if a cached config from an older container is still on a persisted disk. `bootstrap/cache` is not on the volume, so a new container does not keep that cache.
