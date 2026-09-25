# Pelevo production operations

Pelevo runs Laravel behind Nginx. Production hosting is expected on
**[Laravel Forge](./FORGE.md)** (MySQL or Postgres + Redis + S3-compatible
storage). Supabase is optional and only as Postgres/storage if you choose it.

## Forge (preferred)

See [`FORGE.md`](./FORGE.md) for the full checklist.

Minimum Forge processes:

1. Nginx site → `public/`
2. Scheduler: `php artisan schedule:run` every minute
3. Daemon: `php artisan horizon`
4. Deploy script: `deploy/forge/deploy.sh`

Verify:

```shell
php artisan schedule:list
php artisan horizon:status
curl -fsS https://api.your-domain.com/up
```

## systemd (non-Forge Linux hosts)

1. Copy the units in `deploy/systemd` to `/etc/systemd/system`.
2. Adjust `User`, `Group`, `WorkingDirectory`, and the PHP binary path.
3. Run:

   ```shell
   sudo systemctl daemon-reload
   sudo systemctl enable --now pelevo-scheduler.timer pelevo-horizon.service
   ```

4. Deploy with:

   ```shell
   php artisan migrate --force
   php artisan config:cache
   php artisan route:cache
   php artisan view:cache
   php artisan horizon:terminate
   ```

5. Verify:

   ```shell
   systemctl status pelevo-scheduler.timer pelevo-horizon.service
   php artisan schedule:list
   php artisan horizon:status
   ```

The Admin Operations page must report a scheduler heartbeat within
`SCHEDULER_HEARTBEAT_MAX_AGE_SECONDS`.

## Cron fallback

If systemd timers / Forge scheduler are unavailable, install this host-level cron entry:

```cron
* * * * * cd /home/forge/api.your-domain.com/current && /usr/bin/php artisan schedule:run --no-interaction >> /dev/null 2>&1
```

The Laravel web process must never receive permission to edit system crontabs
or systemd units.

## Production services

- **Database:** `DB_CONNECTION=pgsql` on Forge (PostgreSQL). Local WAMP may still use MySQL.
- **Cache/queues:** `QUEUE_CONNECTION=redis` and `CACHE_STORE=redis`.
- Horizon must consume every queue listed in `config/operations.php`, including `media`. Reel processing stays `queued` forever if `supervisor-media` is down.
- `MEDIA_PROCESS_INLINE` must be `false` in production. Mux reel jobs are never run inside the HTTP request.
- `OPERATIONS_READINESS_TOKEN` must be a long random secret available only to
  the infrastructure probe.
- Object-storage credentials (`AWS_*` or optional `SUPABASE_STORAGE_*`) stay
  server-side and must never be sent to Flutter or browser clients.
