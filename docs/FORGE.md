# Deploy Pelevo API on Laravel Forge

Forge hosts the Pelevo API with **PostgreSQL**, Redis, object storage, Horizon,
and the scheduler on (or beside) your app server.

## Stack (current)

| Concern | Forge setup |
|---|---|
| App server | Ubuntu, PHP **8.3**, Nginx — region e.g. Frankfurt |
| Database | **PostgreSQL** on the Forge app server (`DB_CONNECTION=pgsql`) |
| Object storage | **AWS S3 / DO Spaces / R2** via the `s3` disk |
| Redis | Forge Redis (same host) |
| Web server | Forge Nginx → `public` |
| Scheduler | Forge **Scheduler** → `php artisan schedule:run` |
| Queue worker | Forge **Daemon** → `php artisan horizon` |
| HTTPS | Forge Let’s Encrypt |
| Env | Forge Environment UI (see `deploy/forge/env.production.example`) |

Local WAMP may still use MySQL. Production CI and Forge use Postgres; ledger
integrity triggers are Postgres-only and run when `DB_CONNECTION=pgsql`.

## Create the server

1. Type: **App server**
2. Region: e.g. **Frankfurt**
3. Size: **Medium** (2 vCPU / 4 GB) is a solid start for API + Horizon + Redis
4. Open **Advanced settings** and select **PostgreSQL** (not MySQL)
5. PHP **8.3**, enable **Redis**
6. Create the server, then save the **sudo** and **database** passwords Forge shows

Sudo password = SSH/`forge` user elevation only.  
Database password = Laravel `DB_PASSWORD` (and Postgres user `forge`).

## Site setup

1. Install packages on the server:
   ```bash
   sudo apt-get update
   sudo apt-get install -y ffmpeg php8.3-pgsql php8.3-redis
   ```
2. In Forge → Database, create database **`pelevo`** (user `forge` is fine).
3. Create a site, e.g. `api.your-domain.com`.
4. Set **Web Directory** to `public` when the Git root is the `api` folder  
   **or** point the site root at `…/api/public` if the repo root is `pelevo-v2`.
5. Connect the Git repo. Prefer deploying from the `api/` directory as the site path.
6. Paste `deploy/forge/deploy.sh` into the Forge deploy script (adjust `cd` if needed).
7. Copy `deploy/forge/env.production.example` into Forge → Environment, then set:
   ```env
   DB_CONNECTION=pgsql
   DB_HOST=127.0.0.1
   DB_PORT=5432
   DB_DATABASE=pelevo
   DB_USERNAME=forge
   DB_PASSWORD=<Forge database password>
   DB_SSLMODE=prefer
   ```
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

If you want Forge app + Postgres/Redis but still store files in Supabase Storage,
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
- Never put the Forge **sudo** password in Laravel `.env`
- Rotate any secrets that were pasted into chat before going live
- Restrict Horizon to admin auth (`HORIZON_PATH=admin/horizon` already)
- Confirm webhook URLs use `https://api.your-domain.com/webhooks/v1/...`
