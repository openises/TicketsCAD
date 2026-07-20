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

# 3. Build and start (app + database)
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
| `app_cache`   | `/var/www/html/cache`     | Map-tile cache.                                          |
| `app_keys`    | `/var/www/keys`           | 2FA + RSA field-encryption keys (kept out of the webroot).|

> **Back up `db_data` and `app_keys` together.** The keys decrypt data stored in
> the database; a database restored without its matching keys cannot read
> encrypted fields (2FA secrets, encrypted form fields).

Back up the database at any time:
```bash
docker compose exec db sh -c 'exec mariadb-dump -uroot -p"$MARIADB_ROOT_PASSWORD" "$MARIADB_DATABASE"' > backup.sql
```

---

## 5. Upgrading

```bash
git pull
docker compose up -d --build
```

On start the app re-runs the idempotent migrations, so a newer image brings the
schema up to date automatically. Nothing is dropped; already-applied migrations
are skipped.

---

## 6. Common operations

```bash
docker compose ps                 # container status
docker compose logs -f app        # follow app logs
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

1. Log in as an admin → **Settings → Communications** (Zello and/or DMR).
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
| App log stuck on "Waiting for database"   | DB still initializing on first boot — wait; `docker compose logs db` for errors.   |
| "database not reachable after 120s"       | Wrong `NEWUI_DB_PASS` vs. what the DB was first created with. If you changed it after first boot, the `db_data` volume still has the old one — `docker compose down -v` to reset (destroys data) or fix the password to match. |
| Login page loads but assets are 404       | `.htaccess` disabled — the image enables `AllowOverride All`; if you customized Apache, restore it. |
| Forgot the generated admin password       | `docker compose exec app php tools/create_admin.php --username=admin --email=you@example.com` prints a fresh temp password (first login forces a change). |
| Want a clean slate                        | `docker compose down -v && docker compose up -d --build` (deletes ALL data).       |

---

Questions or problems? Open an issue at
https://github.com/openises/TicketsCAD/issues.
