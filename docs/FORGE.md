# Deploy Pelevo API on Laravel Forge

Forge replaces the old **Supabase-as-platform** assumption. Pelevo still needs
PostgreSQL *or* MySQL, Redis, object storage, Horizon, and the scheduler — Forge
hosts those pieces on (or beside) your app server.

## What changes vs Supabase

| Concern | Supabase (old docs) | Forge (recommended) |
|---|---|---|
| Database | Managed Postgres + pooler (`DB_SSLMODE=require`) | **MySQL 8** on the Forge server (matches local WAMP migrations) |
| Object storage | Supabase S3-compatible disk (`FILESYSTEM_DISK=supabase`) | **AWS S3 / DO Spaces / R2** via the `s3` disk |
| Redis | External Redis | Forge Redis (same host or managed) |
| Web server | Your own Nginx/Caddy | Forge Nginx site → `api/public` |
| Scheduler | systemd timer units | Forge **Scheduler** checkbox / cron |
| Queue worker | systemd `pelevo-horizon.service` | Forge **Daemon**: `php artisan horizon` |
| HTTPS | Your certs | Forge Let’s Encrypt |
| Env | `.env` on box | Forge Environment UI |

You can still use Postgres on Forge if you prefer. Keep `DB_CONNECTION=pgsql`.
MySQL is the path of least friction because this repo already runs on MySQL locally
and includes a MySQL ledger-immutability migration.

## Site setup

1. Create a Forge server (Ubuntu, PHP **8.3**, Redis, MySQL, Nginx).
2. Install system packages on the server:
   ```bash
   sudo apt-get update
   sudo apt-get install -y ffmpeg
   ```
3. Create a site, e.g. `api.your-domain.com`.
4. Set **Web Directory** to `public` (when the Git root is the `api` folder)  
   **or** point the site root at `…/api/public` if the repo root is `pelevo-v2`.
5. Connect the Git repo. Prefer deploying from the `api/` directory as the site path.
6. Paste `deploy/forge/deploy.sh` into the Forge deploy script (adjust `cd` if needed).
7. Copy `deploy/forge/env.production.example` into Forge → Environment, then fill secrets.
8. Generate `APP_KEY` once:
   ```bash
   php artisan key:generate --show
   ```
9. Enable:
   - **Quick Deploy** (optional)
   - **Scheduler** → `php /home/forge/.../artisan schedule:run`
   - **Daemon** → `php /home/forge/.../artisan horizon` (directory = site path, user `forge`)
10. Issue SSL in Forge.
11. Deploy, then verify:
    ```bash
    php artisan migrate:status
    php artisan horizon:status
    php artisan about
    curl -I https://api.your-domain.com/up
    ```

## Flutter client

Point release builds at the Forge API:

```bash
--dart-define=PELEVO_API_BASE_URL=https://api.your-domain.com/api/v1
```

## Storage notes

- Reel uploads use `MEDIA_UPLOAD_DISK` temporary signed URLs. Use `s3` in production.
- Episode audio currently stores on the `public` disk when uploaded from Creator Studio;
  for multi-server Forge setups, move that to S3 as a follow-up.
- Place Firebase service-account JSON on the server and set `FCM_CREDENTIALS` to that path.
- Do **not** set `FILESYSTEM_DISK=supabase` on Forge unless you intentionally keep
  Supabase only as an S3 bucket provider.

## Optional: keep Supabase storage only

If you want Forge app + MySQL/Redis but still store files in Supabase Storage,
keep the `supabase` disk credentials and set:

```env
FILESYSTEM_DISK=supabase
MEDIA_UPLOAD_DISK=supabase
ADMIN_EXPORT_DISK=supabase
DATA_EXPORT_DISK=supabase
```

That is storage-only — not “hosted on Supabase”.

## Security checklist

- `APP_DEBUG=false`
- Strong `APP_KEY`, DB password, Redis password, `OPERATIONS_READINESS_TOKEN`
- Rotate any secrets that lived in local `.env` before pasting to Forge
- Restrict Horizon to admin auth (`HORIZON_PATH=admin/horizon` already)
- Confirm webhook URLs use `https://api.your-domain.com/webhooks/v1/...`
