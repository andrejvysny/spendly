# Self-Hosting Guide

Deploy Spendly with a single Docker container. The image includes FrankenPHP (web server), queue worker, and task scheduler — no external dependencies required.

## Quick Start

```bash
curl -fsSL https://raw.githubusercontent.com/andrejvysny/spendly/main/scripts/setup.sh | bash
```

This downloads the compose file, generates an app key, and starts Spendly on `http://localhost`.

## Manual Setup

### 1. Create a directory

```bash
mkdir spendly && cd spendly
```

### 2. Create `compose.yml`

```yaml
services:
    app:
        image: ghcr.io/andrejvysny/spendly:main
        container_name: spendly
        restart: unless-stopped
        # Must exceed S6_KILL_GRACETIME (300s) so a queued bank sync gets to finish before Docker kills the container.
        stop_grace_period: 330s
        ports:
            - '80:80'
            - '443:443'
            - '443:443/udp'
        environment:
            APP_KEY: ${APP_KEY}
            APP_URL: ${APP_URL:-http://localhost}
            APP_ENV: production
            APP_DEBUG: 'false'
            # Domain for the bundled Caddy's automatic Let's Encrypt HTTPS; leave unset behind your own reverse proxy.
            SERVER_NAME: ${SERVER_NAME:-}
            # Trusted proxy IPs/CIDRs, or '*' to trust all (default). Required behind a TLS-terminating reverse proxy.
            TRUSTED_PROXIES: ${TRUSTED_PROXIES:-*}
            DB_CONNECTION: sqlite
            DB_DATABASE: /app/database/database.sqlite
            SESSION_DRIVER: database
            QUEUE_CONNECTION: database
            CACHE_STORE: database
            LOG_CHANNEL: stderr
            LOG_LEVEL: warning
            GOCARDLESS_SECRET_ID: ${GOCARDLESS_SECRET_ID:-}
            GOCARDLESS_SECRET_KEY: ${GOCARDLESS_SECRET_KEY:-}
        volumes:
            - app_database:/app/database
            - app_storage:/app/storage
            - caddy_data:/data
            - caddy_config:/config

volumes:
    app_database:
    app_storage:
    caddy_data:
    caddy_config:
```

This mirrors `compose.prod.yml` (what `scripts/setup.sh` actually downloads) — see that file for the full variable list.

### 3. Create `.env` and generate app key

```bash
echo "APP_KEY=" > .env
echo "APP_URL=http://localhost" >> .env

docker compose pull
docker run --rm --entrypoint="" \
  -v "$(pwd)/.env:/app/.env" \
  ghcr.io/andrejvysny/spendly:main \
  php artisan key:generate --force
```

### 4. Start

```bash
docker compose up -d
```

Spendly is now running at `http://localhost`. Register your first account in the browser.

## What's Inside the Container

The single container runs three services via s6-overlay:

| Service       | Description                                                                                 |
| ------------- | ------------------------------------------------------------------------------------------- |
| **Octane**    | FrankenPHP web server (Caddy), port 80 (+ 443 when `SERVER_NAME` is set)                    |
| **Worker**    | Queue worker for background jobs (`default` + `gocardless` queues)                          |
| **Scheduler** | Laravel task scheduler (cron equivalent) — see [Queue & Scheduler](#queue--scheduler) below |

On first boot, the init script automatically:

- Creates the SQLite database file
- Runs migrations
- Enables WAL mode for SQLite
- Caches config, routes, views, and events

## Configuration

### Environment Variables

All configuration is via environment variables. Key options:

| Variable                | Default             | Description                                                                                                                                 |
| ----------------------- | ------------------- | ------------------------------------------------------------------------------------------------------------------------------------------- |
| `APP_KEY`               | _(required)_        | Encryption key (base64:...)                                                                                                                 |
| `APP_URL`               | `http://localhost`  | **Public** URL of your instance — must be the real internet-facing `https://` URL if you use GoCardless bank sync (see box below)           |
| `SERVER_NAME`           | _(unset)_           | Domain for the bundled Caddy's automatic Let's Encrypt HTTPS. Unset = plain HTTP on :80 for reverse-proxy setups. See [HTTPS](#https) below |
| `TRUSTED_PROXIES`       | `*`                 | Trusted proxy IPs/CIDRs, or `*` to trust all. See [Reverse Proxy](#reverse-proxy-nginxtraefik) below                                        |
| `PORT`                  | `80`                | Host port mapping                                                                                                                           |
| `REGISTRATION_ENABLED`  | `true`              | Set to `false` after creating your account to close sign-up. See [Closing registration](#closing-registration) below                        |
| `MAIL_MAILER`           | `log`               | Mail driver (`smtp`, `log`, etc.)                                                                                                           |
| `MAIL_HOST`             | —                   | SMTP host                                                                                                                                   |
| `MAIL_PORT`             | —                   | SMTP port                                                                                                                                   |
| `MAIL_USERNAME`         | —                   | SMTP username                                                                                                                               |
| `MAIL_PASSWORD`         | —                   | SMTP password                                                                                                                               |
| `MAIL_FROM_ADDRESS`     | `noreply@localhost` | Sender email address                                                                                                                        |
| `GOCARDLESS_SECRET_ID`  | —                   | Instance-wide GoCardless API secret ID (bank sync) — see [GoCardless Bank Sync](#gocardless-bank-sync)                                      |
| `GOCARDLESS_SECRET_KEY` | —                   | Instance-wide GoCardless API secret key                                                                                                     |
| `GOCARDLESS_USE_MOCK`   | `false`             | Use mock bank data instead of the real API                                                                                                  |

### Using a Different Database

SQLite is the default and works well for personal use. To use MySQL or PostgreSQL instead:

```yaml
environment:
    DB_CONNECTION: mysql # or pgsql
    DB_HOST: db
    DB_PORT: 3306 # or 5432
    DB_DATABASE: spendly
    DB_USERNAME: spendly
    DB_PASSWORD: secret
```

The container includes drivers for SQLite, MySQL, and PostgreSQL.

### HTTPS

HTTPS is **opt-in**, via `SERVER_NAME`:

- **`SERVER_NAME` set** (e.g. `spendly.example.com`): the bundled Caddy server automatically obtains and renews a Let's Encrypt certificate for that domain and serves on 80 + 443. This requires the domain's DNS to point at this host and ports 80/443 to be reachable from the internet (Let's Encrypt's HTTP-01 challenge needs port 80).
- **`SERVER_NAME` unset** (the default): Caddy serves plain HTTP on port 80 only, with `auto_https` disabled. This is the correct mode when a separate reverse proxy (Nginx, Traefik, Caddy on the host, Cloudflare Tunnel, etc.) is terminating TLS in front of the container.

```yaml
ports:
    - '80:80'
    - '443:443'
    - '443:443/udp' # HTTP/3
environment:
    SERVER_NAME: spendly.example.com
    APP_URL: https://spendly.example.com
```

Add volumes to persist certificate data across restarts:

```yaml
volumes:
    - caddy_data:/data
    - caddy_config:/config
```

> **`APP_URL` must be the real public `https://` URL of your instance if you use GoCardless bank sync.** The bank-authorization callback is a Laravel signed URL built from `APP_URL`; if it doesn't match what the bank actually redirects back to, the signature check fails and the connection is rejected. This applies whether TLS is terminated by the bundled Caddy (`SERVER_NAME` set) or by your own reverse proxy.

### Closing registration

A publicly reachable instance accepts sign-ups from anyone who finds the URL. Once
your own account exists, set:

```yaml
environment:
    REGISTRATION_ENABLED: 'false'
```

and restart. `/register` then returns 404 and the "Sign up" link disappears from the
login page. The route stays registered internally, so nothing else breaks.

Order matters: bring the instance up with registration open, create your account,
then turn it off and restart.

### Reverse Proxy (Nginx/Traefik)

If running behind a reverse proxy, leave `SERVER_NAME` unset, keep only port 80 exposed to the proxy, and configure the proxy to forward to the container and terminate TLS. Two things must also be set correctly or GoCardless callbacks and generated links will be wrong:

- **`APP_URL`** — your real public `https://` URL (not `http://` — Laravel needs this to generate correct signed callback URLs even though the container itself only sees plain HTTP).
- **`TRUSTED_PROXIES`** — defaults to `*` (trust all), which is fine when the proxy is the only thing that can reach the container (e.g. same Docker network, no other route in). Restrict it to your proxy's IP/CIDR if the container is otherwise reachable directly. Either way, the proxy must forward `X-Forwarded-Proto`/`X-Forwarded-For` (or `Forwarded`) so Laravel knows the original request was HTTPS — without that, generated URLs (including the GoCardless callback) come out as `http://` and signature verification fails.

### GoCardless Bank Sync

Bank sync credentials come from one of two sources, in this precedence order:

1. **Per-user override** — a Secret ID/Key pair a user enters themselves in **Settings > Bank Data**. Only that user's sync uses it. Entering only one of the two fields is rejected (it's an error, not a silent fallback to the instance pair).
2. **Instance-wide credentials** — `GOCARDLESS_SECRET_ID`/`GOCARDLESS_SECRET_KEY` set on the container, shared by every user who hasn't set a personal override. This is the simplest setup for a single-user or trusted-household instance.

To enable it at the instance level:

1. Create a free account at [GoCardless Bank Account Data](https://bankaccountdata.gocardless.com/)
2. Generate API credentials (Secret ID + Secret Key)
3. Add to your environment:

```yaml
environment:
    GOCARDLESS_SECRET_ID: your-secret-id
    GOCARDLESS_SECRET_KEY: your-secret-key
```

Users can still add their own personal override afterwards in Settings > Bank Data if they'd rather not share the instance credentials' request quota.

Set `GOCARDLESS_USE_MOCK: 'true'` instead to use fixture-based mock bank data (no real credentials, no real API calls) — useful for demos or trying Spendly out.

Remember the `APP_URL`/HTTPS requirement above: without a correct public HTTPS `APP_URL`, the "Connect your bank" flow fails at the callback step.

### Queue & Scheduler

Bank syncs never run inline — connecting an account or clicking "Sync" queues a job (`gocardless` queue) and the UI polls for the result. The scheduler drives the rest automatically; nothing here needs cron set up separately:

| Task                        | Cadence          | What it does                                                                                                                                                        |
| --------------------------- | ---------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `gocardless:dispatch-sync`  | Every 4 hours    | Queues a sync job for every account due one (skips accounts synced within the last `GOCARDLESS_MIN_SYNC_INTERVAL_HOURS`, default 8h), staggered a few seconds apart |
| `gocardless:retry-failures` | Every 30 minutes | Retries individual transactions that failed validation/mapping during a sync, with backoff                                                                          |
| `gocardless:check-consent`  | Daily at 05:30   | Expires bank connections whose 90-day consent has lapsed, warns on ones about to lapse, polls stale connection statuses                                             |
| `exchange-rates:fetch`      | Daily at 06:00   | Refreshes multi-currency exchange rates                                                                                                                             |
| `recurring:detect`          | Daily            | Detects recurring payments                                                                                                                                          |

The 4-hourly / 8-hour-minimum-interval schedule is deliberately conservative: GoCardless's free tier caps each account to a small number of API calls per endpoint per day, and burning that quota on accounts nothing changed for defeats the point. You can watch sync progress per account on the account detail page (it polls a `sync-status` endpoint after queuing) or check `gocardless_sync_status` directly in the database if scripting against it.

## Operations

### View Logs

```bash
docker logs -f spendly
```

### Backup

SQLite database and uploads are stored in Docker volumes:

```bash
# Backup database
docker cp spendly:/app/database/database.sqlite ./backup.sqlite

# Or backup the volume directly
docker run --rm -v spendly_app_database:/data -v $(pwd):/backup alpine \
  cp /data/database.sqlite /backup/spendly-backup-$(date +%Y%m%d).sqlite
```

### Restore

```bash
docker compose down
docker run --rm -v spendly_app_database:/data -v $(pwd):/backup alpine \
  cp /backup/backup.sqlite /data/database.sqlite
docker compose up -d
```

### Update

```bash
docker compose pull
docker compose up -d
```

Migrations run automatically on container start — no manual step needed for schema changes.

**Pinning a version.** `:main` and `:latest` move with every push, so `docker compose pull`
gives you whatever was built last and there is no way back to the previous image. Every build
also publishes an immutable `sha-<commit>` tag:

```yaml
image: ghcr.io/andrejvysny/spendly:sha-<full-commit-sha>
```

Pin that if you want reproducible deploys and a rollback target.

**Verifying the image.** Images are signed with cosign, keyless, bound to the build
workflow's identity:

```bash
cosign verify ghcr.io/andrejvysny/spendly:main \
  --certificate-identity-regexp '^https://github.com/andrejvysny/spendly/' \
  --certificate-oidc-issuer https://token.actions.githubusercontent.com
```

They also carry SBOM and provenance attestations (`docker buildx imagetools inspect`).

**If you connected a bank before this instance had GoCardless requisition tracking** (i.e. the account has always synced fine, but a bank connection was made a while ago), run the backfill once so consent-expiry warnings and reconnect detection work for it:

```bash
docker compose exec app php artisan gocardless:backfill-requisitions --all
```

This is safe to run repeatedly and does not touch anything that already backfilled correctly.

### Health Check

The container includes a built-in health check hitting `/up` every 15 seconds:

```bash
docker inspect --format='{{.State.Health.Status}}' spendly
```

### Configuration Diagnostics

Every container start runs `php artisan spendly:check-config` (visible in the logs) and warns about
common misconfigurations — an `APP_URL` that would break bank redirects, missing GoCardless
credentials, `APP_DEBUG` left on, or a `sync` queue driver in production. It never blocks startup.
Run it yourself any time:

```bash
docker compose exec app php artisan spendly:check-config        # human-readable
docker compose exec app php artisan spendly:check-config --json # machine-readable
docker compose exec app php artisan about                       # includes a Spendly section
```

## Resource Requirements

- **RAM:** 256MB minimum, 512MB recommended
- **Disk:** ~1.7GB for the image + database storage
- **CPU:** 1 core minimum

Set Go memory limit to ~90% of container memory if restricting resources:

```yaml
environment:
    GOMEMLIMIT: 450MiB # for 512MB container limit
```
