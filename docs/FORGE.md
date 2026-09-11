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
3. Create a site for **`pelevo.com`** (and `www` if you want).
4. Set **Web Directory** to `public` when the Git root is the `api` folder  
   **or** point the site root at `…/api/public` if the repo root is `pelevo-v2`.
5. Connect the Git repo. Prefer deploying from the `api/` directory as the site path.
6. Paste `deploy/forge/deploy.sh` into the Forge deploy script (adjust `cd` if needed).
7. Copy `deploy/forge/env.production.example` into Forge → Environment, then set at minimum:
   ```env
   APP_URL=https://pelevo.com
   SESSION_DOMAIN=.pelevo.com
   SANCTUM_STATEFUL_DOMAINS=pelevo.com,www.pelevo.com
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
10. Issue SSL in Forge for `pelevo.com`.
11. Deploy, then verify:
    ```bash
    php artisan migrate:status
    php artisan horizon:status
    php artisan about
    curl -I https://pelevo.com/up
    ```

## Admin dashboard access

URL: `https://pelevo.com/admin/login`  
(Forge sites also accept `https://pelevo.com/admin` → redirects to login.)

Create or reset operators over SSH (site path may vary):

```bash
cd /home/forge/pelevo.com/current   # or the site directory Forge shows
php artisan pelevo:ensure-admin johnchibuikem20@gmail.com --name="John" --password='…' --role=superadmin
php artisan pelevo:ensure-admin info@pelevo.com --name="Pelevo Ops" --password='…' --role=superadmin
```

First login flow:

1. Email + password
2. Enrol an authenticator app (TOTP) — save the recovery codes
3. Land on `/admin` dashboard

Do **not** put admin passwords in the Forge Environment UI long-term; create them with the artisan command, then rotate if they were shared in chat.

If roles were never seeded (fresh migrate without `db:seed`), `pelevo:ensure-admin` creates the RBAC matrix automatically.

## Podcast Index (search discovery)

Search discovery needs live credentials on the **server**. Local `api/.env` is not used by Forge.

1. Forge → Site → **Environment** — set (copy from local `api/.env`):
   ```env
   PODCAST_INDEX_ENABLED=true
   PODCAST_INDEX_BASE_URL=https://api.podcastindex.org/api/1.0
   PODCAST_INDEX_API_KEY=
   PODCAST_INDEX_API_SECRET=
   PODCAST_INDEX_USER_AGENT=Pelevo/1.3
   ```
2. **Deploy** (deploy script runs `config:cache`). Editing `.env` without redeploy leaves empty keys baked in the config cache.
3. Or paste the same key/secret in **Admin → Integrations → Podcast Index** → Save → Test (DB override works at runtime).
4. Verify over SSH:
   ```bash
   cd /home/forge/pelevo.com/current
   php artisan pelevo:check-podcast-index
   ```
   You want `api_key loaded` / `api_secret loaded` = yes, and a sample feed list.

Empty results with log `Podcast Index API key or secret is missing` means step 1–2 (or 3) was skipped.


## Episodes after search discovery

Podcast Index only discovers the **show**. Episodes come from the show’s RSS feed via the `rss` Horizon queue (`HydrateRssFeed`).

If a show opens with “No episodes”:

```bash
php artisan horizon:status          # must be running
php artisan pelevo:hydrate-show "Wardrobe Memo" --sync
```

`--sync` hydrates immediately in the console (useful when Horizon was down). Without `--sync`, the job is queued on `rss`.



Release builds default to the live API:

```bash
--dart-define=PELEVO_API_BASE_URL=https://pelevo.com/api/v1
```

(`PELEVO_API_BASE_URL` may be omitted in release; the app falls back to that URL.)

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
- Confirm webhook URLs use `https://pelevo.com/webhooks/v1/...`
