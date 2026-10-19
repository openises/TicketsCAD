# Deploying TicketsCAD NewUI with Docker

This is the fastest way to stand up a complete TicketsCAD NewUI instance —
application **and** database — on any machine with Docker. One command builds
the app image, starts MariaDB, installs the schema, creates an admin account,
and serves the dispatch console.

> **Who this is for:** anyone evaluating TicketsCAD or running it on a single
> host (a home lab, a squad's server, a cloud VM). For a hardened,
> internet-facing deployment, also read the "Production notes" section at the
> end.

---

## 1. Prerequisites

- **Docker Engine 24+** and the **Docker Compose plugin** (`docker compose`,
  not the older `docker-compose`). On Debian/Ubuntu:
  ```bash
  curl -fsSL https://get.docker.com | sudo sh
  sudo usermod -aG docker "$USER"   # log out/in so you can run docker without sudo
  ```
- **~2 GB free disk** for the images, plus room for your data.
- **git** to clone the repository.

That's it — you do **not** need PHP, Composer, or MariaDB installed on the host.
Everything runs inside containers.

---

## 2. Quick start

```bash
# 1. Get the code
git clone https://github.com/openises/TicketsCAD.git ticketscad
cd ticketscad

# 2. Create your environment file and set passwords
cp .env.example .env
nano .env          # change NEWUI_DB_PASS and DB_ROOT_PASSWORD at minimum

# 3. Build and start (app + database + the scheduler that runs the background jobs)
docker compose up -d --build

# 4. Watch the first-run install finish (schema + admin user)
docker compose logs -f app
```

On first start the app container waits for the database, installs the full
schema, applies every migration, and creates the admin account. When you see:

```
[entrypoint]  Install complete. Sign in at http://localhost:8081
[entrypoint] Starting Apache...
```

open **http://localhost:8081** in your browser.

### Your admin password

- If you set `ADMIN_PASSWORD` in `.env`, use that.
- If you left it blank, a random one was generated — find it in the log:
  ```bash
  docker compose logs app | grep -iE "password|temp"
  ```
  You'll be prompted to choose your own password on first login.

---

## 3. Configuration reference

All settings are environment variables in `.env` (read automatically by
`docker compose`):

| Variable            | Default                  | Purpose                                                                 |
|---------------------|--------------------------|-------------------------------------------------------------------------|
| `NEWUI_DB_NAME`     | `newui`                  | Database name (created automatically).                                  |
| `NEWUI_DB_USER`     | `newui`                  | Database user.                                                          |
| `NEWUI_DB_PASS`     | `newui`                  | Database password — **change this.**                                    |
| `DB_ROOT_PASSWORD`  | `change-me-root`         | MariaDB root password — **change this.**                                |
| `NEWUI_PORT`        | `8081`                   | Host port the app is published on (`http://localhost:<port>`).          |
| `NEWUI_BASE_URL`    | `http://localhost:8081`  | Public URL used to build links/cookies. Set to your `https://` host in production. |
| `ADMIN_USER`        | `admin`                  | First-run admin username.                                               |
| `ADMIN_EMAIL`       | `admin@example.invalid`  | First-run admin email.                                                  |
| `ADMIN_PASSWORD`    | *(blank → auto-gen)*     | First-run admin password. Blank = generated + printed to the log.       |
| `NEWUI_SEED_DEMO`   | `false`                  | `true` seeds demo incident types + sample units/facilities.            |

After editing `.env`, apply changes with `docker compose up -d` (recreates the
containers). Note that `ADMIN_*` and `NEWUI_SEED_DEMO` only take effect on the
**first** install (an empty database) — they don't rotate an existing admin's
password.

---

## 4. Data persistence

Your data lives in named Docker volumes, so it survives `docker compose down`
and image rebuilds:

| Volume        | Mounted at                | Holds                                                    |
|---------------|---------------------------|---------------------------------------------------------|
| `db_data`     | `/var/lib/mysql`          | The entire database.                                     |
| `app_uploads` | `/var/www/html/uploads`   | Attachments, photos, uploaded files.                    |
| `app_cache`   | `/var/www/html/cache`     | Weather-overlay tiles and NWS lookups, and `job-state/` — each background job's lock and last-run time, shared by `app` and `scheduler` (§4a). |
| `app_tile_cache` | `/var/www/tile-cache`  | Basemap tiles fetched by `api/tile-proxy.php`. **Outside `/var/www/html`** — this cache records which map areas the install has viewed, which inside the webroot would be readable without logging in. Regenerable, but keep it on a volume: a rebuild that empties it makes the install re-fetch every tile at once, which is the load spike tile providers ask us not to cause. |
| `app_geocode_cache` | `/var/www/geocode-cache` | Address-lookup cache (`inc/geocode.php`). **Outside `/var/www/html`**, same reasoning as `app_tile_cache`; regenerable but worth keeping on a volume so a rebuild doesn't re-hit the geocoding provider for every cached address at once. |
| `app_backups` | `/var/www/backups`        | Archives written by `tools/backup_run.php` and Settings → Backup / Maintenance. **Outside `/var/www/html`** — that is the Apache DocumentRoot, and an archive inside it was downloadable by anyone who guessed the filename (v4.2.3). |
| `app_keys`    | `/var/www/keys`           | 2FA + RSA field-encryption keys (kept out of the webroot), and — in its `tts/` subdirectory — the text-to-speech API keys saved under Settings → Voice & Speech. |
| `app_zello_audio` | `/var/www/zello-audio` | Zello voice-message recordings (GHSA-x9x6-w4fg-pmcc). **Outside `/var/www/html`**, mounted on BOTH the `app` service and the `zello-proxy` service (the proxy is what actually writes recordings). Irreplaceable, not regenerable — this is precious data, not a cache. |

**Anything NOT on this list lives in the container's writable layer and is
destroyed by `docker compose up -d --build`,** which replaces the container.
That is why `app_backups` exists: without it, a backup taken with
`docker compose exec app php tools/backup_run.php --force` was deleted by the
very next command of the documented upgrade — in the one scenario the backup
exists for.

> **Back up `db_data` and `app_keys` together.** The keys decrypt data stored in
> the database; a database restored without its matching keys cannot read
> encrypted fields (2FA secrets, encrypted form fields).

### One-time step if your compose file predates `app_backups`

Adding the volume does **not** import what was already in the old container —
Docker only seeds a new named volume from the *image*, never from a running
container's writable layer. If you have backups inside the container, rescue
them **before** the first rebuild with the new compose file:

```bash
# 1. With the OLD container still running:
docker compose cp app:/var/www/html/backups ./backups-rescued   # old path

# 2. Now pull the new code + rebuild (this is what would have destroyed them):
git pull && docker compose up -d --build

# 3. Put them back on the new volume:
docker compose cp ./backups-rescued/. app:/var/www/backups
docker compose exec app chown -R www-data:www-data /var/www/backups
```

If `docker compose cp` reports the path does not exist, you had no on-container
backups and there is nothing to migrate — just rebuild.

### One-time step if you saved text-to-speech API keys on an older version

In v4.2.27 and earlier, the API keys you saved under **Settings → Voice & Speech**
(Deepgram, an OpenAI-compatible server) were written to
`/var/www/html/keys/tts` — inside the container's writable layer, so the update
command above destroyed them (the engine then quietly fell back to Piper). They
now live in `/var/www/keys/tts`, on the `app_keys` volume. If you have saved such
a key and are still on the old container, rescue it before the first rebuild:

```bash
docker compose cp app:/var/www/html/keys/tts ./tts-keys-rescued   # old path
git pull && docker compose up -d --build
docker compose cp ./tts-keys-rescued/. app:/var/www/keys/tts
docker compose exec app chown -R www-data:www-data /var/www/keys/tts
```

If `docker compose cp` reports the path does not exist you never saved a key —
nothing to do. If the container was already rebuilt, just paste the key again
under Voice & Speech; it is stored in the right place now.

### Getting a backup off the box

A volume survives rebuilds, but it still lives on this host. Copy the archive
somewhere else (the 3-2-1 rule — see `docs/BACKUP-RECOVERY-RUNBOOK.md`):

```bash
docker compose cp app:/var/www/backups ./ticketscad-backups
```

…or use **Settings → Backup / Maintenance → Download Full Backup**, which streams
the archive straight to the browser's machine.

Back up the database at any time:
```bash
docker compose exec db sh -c 'exec mariadb-dump -uroot -p"$MARIADB_ROOT_PASSWORD" "$MARIADB_DATABASE"' > backup.sql
```

---

## 4a. Background jobs (the `scheduler` service)

TicketsCAD has jobs that must run on a timer whether or not anyone is signed in.
On a Linux server they are systemd timers; on Windows, a Task Scheduler entry.
In Docker they are run by the **`scheduler` service** in `docker-compose.yml`,
which runs `php tools/run-scheduled-jobs.php --loop` from the same image as the
app.

| Job | Every | What it does |
|---|---|---|
| `inbound_calls_tick` | 15 s | Moves finished calls out of wrap-up on the Inbound Calls page, and flags a claimed call whose dispatcher has gone quiet. |
| `pending_messages_tick` | 1 min | Sends queued notifications (push, webhooks, SMS, e-mail, Slack) and messages held for a security label. |
| `par_tick` | 1 min | Starts PAR checks that are due and marks units that did not answer. |
| `scheduled_incidents_tick` | 1 min | Opens Scheduled incidents at their booked time and dispatches units reserved for them. |
| `channel_receive_tick` | 1 min | Polls Telegram / Slack for inbound messages (only when turned on). |
| `webhook_retry` | 1 min | Retries failed webhook deliveries. |
| `matrix_expiry_warning` | 1 min | Warns before a Communications Console patch-rail bridge expires. |
| `weather_poll` | 1 min | Polls NWS weather alerts (only when turned on). |
| `org_relationship_activation_cleanup` | 5 min | Closes out expired cross-org activation windows in the audit trail. |
| `matrix_regulatory_audit` | 15 min | Checks patch-rail routes for an unacknowledged amateur/commercial mix. |
| `backup` | hourly | Takes the automatic backup when one is due (Settings → Backup / Maintenance sets how often). |
| `expire_grants` | hourly | Removes role grants whose expiry has passed. |
| `audit_log_purge`, `message_log_purge` | daily | Retention purges (only when a retention period is set). |

**Before this service existed, nothing in the Docker deployment ran any of
these** (reported on the project's Google Group, 2026-10-17): finished calls
never left the Inbound Calls page, a unit that missed a PAR check was never
marked, Scheduled incidents opened only when someone loaded the incident list,
and Settings → System Health listed every job as "never run".

How it behaves:

- It **does not** install or migrate the database — the `app` container does
  that. The scheduler waits until the database is installed and any migration
  the app is applying has finished, then starts.
- Each job runs as its own process, so one that fails cannot stop the others. A
  job that hangs is killed (with anything it started) after a generous timeout
  and runs again at its next interval.
- Never two copies of a job at once. Each job's lock and last-run time live in
  `cache/job-state` on the `app_cache` volume, which `app` and `scheduler`
  share — so a restarted scheduler resumes its schedule rather than running
  everything again, and a `--once` run in the `app` container (below) cannot
  overlap the scheduler.
- Work that went undone while nothing was running is **not** acted on
  retroactively. Every job ignores work more than `sched_stale_cutoff_min`
  minutes (default 60) past due and records it as expired, with the reason —
  so starting the scheduler on an install that has never had one does not
  send a burst of month-old notifications or PAR alarms. Failed webhook
  deliveries older than that are dead-lettered (and can still be replayed)
  rather than sent.

Check that it is working:

```bash
docker compose ps scheduler            # "Up ... (healthy)"
docker compose logs -f scheduler       # failures, the hourly/daily jobs' output, a summary every 15 min
docker compose exec scheduler php tools/run-scheduled-jobs.php --list   # every job + when it last started
```

…and **Settings → System Health → Scheduled background jobs** shows each job's
last successful run; the counts climb as the jobs run.

Run one job now (any job key from `--list`):

```bash
docker compose exec scheduler php tools/run-scheduled-jobs.php --once --force --only=backup
```

### If your compose file predates the `scheduler` service

Update to a `docker-compose.yml` that has it and run `docker compose up -d --build`;
the new service starts on its own. If you maintain your own compose file, copy
the `scheduler:` block from the shipped one — it needs the same image, the same
`NEWUI_DB_*` environment and **the same volumes** as `app`.

### Coolify and other platforms

If your platform deploys this repository's `docker-compose.yml` (Coolify's
"Docker Compose" build pack does), the `scheduler` service comes with it —
nothing more to do.

If you run only the app container, run the jobs from the platform's scheduler
instead, **every minute**, inside the app container, as the web server user:

```bash
su -s /bin/sh www-data -c "php /var/www/html/tools/run-scheduled-jobs.php --once"
```

In Coolify that is a **Scheduled Task** on the application with the frequency
`* * * * *` and the command above. With a host crontab it is:

```cron
* * * * * docker exec -u www-data <app-container> php tools/run-scheduled-jobs.php --once
```

`--once` runs whatever is due and exits, so the daily jobs still run once a day
and backups only when due. Run it as `www-data`, not root: files a job creates
(a backup archive, a cache directory) must belong to the user the web server
reads them as. Running both the `scheduler` service **and** a `--once` task is
safe (they share the per-job locks) but pointless — pick one.

---

## 5. Upgrading

Schema migrations run **automatically** on container start — so an upgrade is just
"get the newer code, rebuild the image, restart." **Back up your database first**
(a migration changes the schema in place):

```bash
# 0. If your compose file predates the app_backups volume, do the one-time
#    rescue in §4 FIRST — step 3 below destroys anything not on a volume.

# 1. Back up first (see §4). This writes to the host, so it is safe either way.
docker compose exec db sh -c 'exec mariadb-dump -uroot -p"$MARIADB_ROOT_PASSWORD" "$MARIADB_DATABASE"' > backup-$(date +%F).sql

#    (Or use TicketsCAD's own backup, which now lands on the app_backups volume
#     and survives the rebuild:  docker compose exec app php tools/backup_run.php --force)

# 2. Get the new version. For production, pin to a released tag (a known-good
#    version) rather than bleeding-edge main:
git fetch --tags
git checkout v4.0.1                       # newest tag: git tag -l | sort -V | tail -1
#   (or, to track the latest development: git pull)

# 3. Rebuild + restart. The --build is REQUIRED — a plain `git pull` alone does
#    NOT update the running container (it keeps the old built image).
docker compose up -d --build
```

**How migrations are handled:** the container entrypoint runs `tools/install_fresh.php`
on every start. On an empty database it installs the full schema; on an existing
one it applies only the pending migrations. Every migration is tracked in the
`_migrations` table, so already-applied ones are skipped and **nothing is dropped**.
It's idempotent and safe to re-run on every restart.

**Confirm it worked:** migrations are non-fatal by design (a failure never blocks
the app from starting), so after any upgrade check the log:

```bash
docker compose logs app | grep -iE 'migration|install|error'
```

**If an upgrade misbehaves,** roll back to your backup and the previous tag:

```bash
docker compose exec -T db sh -c 'exec mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" "$MARIADB_DATABASE"' < backup-YYYY-MM-DD.sql
git checkout v4.0.0 && docker compose up -d --build
```

---

## 6. Common operations

```bash
docker compose ps                 # container status
docker compose logs -f app        # follow app logs
docker compose logs -f scheduler  # follow the background jobs (see §4a)
docker compose logs -f db         # follow database logs
docker compose restart app        # restart just the app
docker compose down               # stop (data volumes preserved)
docker compose down -v            # stop AND DELETE all data volumes (destructive!)
docker compose exec app bash      # shell inside the app container
```

Run a health check from inside the container:
```bash
docker compose exec app php tools/health_check.php   # if present in your build
```
…or open the **System Status** (or **Diagnostics**) health page in the web UI.

---

## 7. Using your own `config.php`

By default the entrypoint generates `config.php` from the environment variables
above. If you'd rather manage the full config yourself (extra settings, custom
SMTP, etc.), create a `config.php` from `config.example.php` and mount it — the
entrypoint will detect it and leave it alone. Uncomment this line in
`docker-compose.yml`:

```yaml
    volumes:
      - ./config.php:/var/www/html/config.php:ro
```

---

## 8. Production notes

The quick-start is plaintext HTTP on port 8081 — perfect for evaluation, not for
an internet-facing deployment. For production:

- **Put it behind TLS.** Run a reverse proxy (Caddy, nginx, or Traefik) that
  terminates HTTPS and forwards to the app container, and set `NEWUI_BASE_URL`
  to your `https://` hostname. TicketsCAD enforces secure cookies when the base
  URL is `https://`.
- **Use strong, unique passwords** for `NEWUI_DB_PASS` and `DB_ROOT_PASSWORD`.
- **Don't publish the database port.** The compose file keeps MariaDB on the
  internal Docker network only — leave it that way.
- **Back up `db_data` + `app_keys` on a schedule** (see §4).
- **Voice features are opt-in** via a compose profile — see §8a. The
  **hardware** radio bridges (native DMR/AMBE to BrandMeister, Meshtastic) need a
  physical radio/serial device and run on the host, not in this stack — see their
  docs under `docs/` (`RADIO-DMR-INSTALL.md`, Meshtastic guide).

---

## 8a. Voice features (Zello + DMR push-to-talk)

The browser radio widget's push-to-talk needs a small WebSocket relay per network
(Zello, DMR). These ship as an **optional compose profile** that **reuses the app
image** (nothing extra to build) and are off by default:

```bash
docker compose --profile voice up -d          # app + db + zello-proxy + dmr-proxy
```

That's it — the app image is already wired to reverse-proxy the browser's
`wss://<host>/zello-ws` and `wss://<host>/dmr-ws` connections to the two relay
containers (`zello-proxy` on 8090, `dmr-proxy` on 8092). You do **not** publish
those ports; they stay on the internal Docker network.

Then, in the app, enable and configure the channels:

1. Log in as an admin → **Settings → Communications & Integrations** (Zello and/or DMR).
2. Enter your Zello credentials (Work network + username/password, or Consumer
   token) and/or your DMR bridge channels. Leave the proxy **ports at their
   defaults** (8090 / 8092) so the built-in WebSocket routing matches.
3. Open the radio widget on the dashboard — it connects through the app to the
   relay. Green status = connected.

Notes:
- **Behind your own TLS reverse proxy** (Caddy/nginx/Traefik, §8): make sure it
  WebSocket-upgrades `/zello-ws` and `/dmr-ws` in addition to normal traffic, or
  push-to-talk won't connect.
- **Turn it off** by bringing the stack up without the profile again
  (`docker compose up -d`) — the relays stop; the core CAD is unaffected.
- The relays read their settings from the same database; they carry **no** state
  of their own.
- This profile covers **Zello and the DMR *relay***. The native **DMR/AMBE bridge
  to BrandMeister** and **Meshtastic** need real radio hardware on the host and
  are deployed separately (see their docs).

---

## 9. Troubleshooting

| Symptom                                   | Likely cause / fix                                                                 |
|-------------------------------------------|------------------------------------------------------------------------------------|
| `dependency failed to start: container ticketscad_db is unhealthy` | The database container **exited** before it became healthy — the app then refuses to start. Get the real reason with `docker compose logs db`. See "Database won't start on a small host" below — on Raspberry Pi and low-RAM VMs this is almost always out-of-memory or a 32-bit OS. |
| App log stuck on "Waiting for database"   | DB still initializing on first boot — wait; `docker compose logs db` for errors.   |
| Container is up but no TicketsCAD in the browser | You're at the wrong address. The app is at **`http://localhost:8081`** (the port after the colon) — **not** `:8080`, and **not** a folder path like `/ticketscad` or `/ticketscad_newui` (those are the *container* names, not URLs). A plain `http://localhost` showing "Apache2 Ubuntu Default Page" / "It works!" is your host's own web server, not TicketsCAD — ignore it and use `:8081`. New to this? See [GETTING-STARTED-FOR-BEGINNERS.md](GETTING-STARTED-FOR-BEGINNERS.md). |
| "database not reachable after 120s"       | Wrong `NEWUI_DB_PASS` vs. what the DB was first created with. If you changed it after first boot, the `db_data` volume still has the old one — `docker compose down -v` to reset (destroys data) or fix the password to match. |
| Login page loads but assets are 404       | `.htaccess` disabled — the image enables `AllowOverride All`; if you customized Apache, restore it. |
| Forgot the generated admin password       | `docker compose exec app php tools/create_admin.php --username=admin --email=you@example.com` prints a fresh temp password (first login forces a change). |
| Want a clean slate                        | `docker compose down -v && docker compose up -d --build` (deletes ALL data).       |
| `docker compose pull` fails with `pull access denied for ticketscad-newui` (or `repository does not exist`) | Don't run `docker compose pull` for this project — the app image (`ticketscad-newui:local`) is built **locally**, on your own machine, from this repo's Dockerfile; it is never published to Docker Hub or any registry, so there's nothing there to pull. This is expected, not a sign anything is broken. To get the latest version, use `git pull` then `docker compose up -d --build` (see §5) — that's the whole update, no separate pull step. If a plain `docker compose up -d` (no `--build`, no prior `pull`) won't bring your containers back up either, see §5 and the troubleshooting rows above for the real error from `docker compose logs`. |

### Database won't start on a small host (Raspberry Pi, low-RAM VMs)

If `docker compose up` ends with `dependency failed to start: container
ticketscad_db is unhealthy`, the MariaDB container exited before it finished
starting. Always look at the real reason first:

```bash
docker compose logs db
```

On a Raspberry Pi or a small VM, the cause is almost always one of these:

1. **Out of memory.** MariaDB gets OOM-killed — often while the app image is
   still building in parallel (that build is CPU/RAM heavy and can take 15+
   minutes on a Pi). The db log ends abruptly or the host shows an OOM message.
   - Check free memory: `free -h`.
   - Give the Pi swap if it has little RAM (a 2 GB swapfile is plenty), or build
     the image and start the database in two steps so they don't compete:
     `docker compose build` first, then `docker compose up -d`.
   - 1 GB Pis are tight; 2 GB or more is comfortable.

2. **32-bit operating system.** MariaDB 11 only ships 64-bit images
   (`arm64`/`amd64`). On a Pi, run the **64-bit** Raspberry Pi OS —
   `uname -m` should print `aarch64`, not `armv7l`/`armhf`.

3. **A half-initialized data volume** from an earlier failed attempt. Start
   completely clean (this deletes any data, which is fine on a first install):
   `docker compose down -v` then `docker compose up -d`.

Once `docker compose logs db` shows `mariadbd: ready for connections`, the app
container will start on its own.

---

Questions or problems? Open an issue at
https://github.com/openises/TicketsCAD/issues.
