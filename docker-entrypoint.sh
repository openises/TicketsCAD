#!/bin/bash
#
# TicketsCAD NewUI — Docker entrypoint.
#
# On container start:
#   1. Generate config.php from env vars (unless a config.php was mounted).
#   2. Ensure runtime dirs (uploads, cache) and the out-of-webroot keys dir
#      exist and are writable — these are the paths compose mounts as volumes.
#   3. Wait for the database to accept connections.
#   4. If the database is empty, run the fresh install + create an admin user
#      (+ optional demo seed). If it's already installed, run the idempotent
#      migrations so a newer image auto-upgrades the schema in place.
#   5. Hand off to Apache.
#
# Everything here is idempotent and safe to re-run on every container restart.
set -e
APP=/var/www/html

echo "[entrypoint] TicketsCAD NewUI starting..."

# 1. Configuration ----------------------------------------------------------
if [ ! -f "$APP/config.php" ]; then
    php "$APP/docker-config-gen.php"
else
    echo "[entrypoint] config.php present (mounted) — leaving it untouched."
fi

# 2. Writable runtime dirs + persistent keys (keys live OUTSIDE the webroot) --
for d in "$APP/uploads" "$APP/cache" "$APP/../keys"; do
    mkdir -p "$d"
    chown -R www-data:www-data "$d" 2>/dev/null || true
    chmod -R 775 "$d" 2>/dev/null || true
done
chown www-data:www-data "$APP/config.php" 2>/dev/null || true

# 3. Wait for the database --------------------------------------------------
DBH="${NEWUI_DB_HOST:-db}"
echo "[entrypoint] Waiting for database at ${DBH} ..."
DB_READY=0
for i in $(seq 1 60); do
    if php -r '$c=@mysqli_connect(getenv("NEWUI_DB_HOST")?:"db",getenv("NEWUI_DB_USER")?:"newui",getenv("NEWUI_DB_PASS")?:"newui",getenv("NEWUI_DB_NAME")?:"newui");exit($c?0:1);' 2>/dev/null; then
        echo "[entrypoint] Database is ready."
        DB_READY=1
        break
    fi
    sleep 2
done
[ "$DB_READY" = 1 ] || echo "[entrypoint] WARNING: database not reachable after 120s; continuing anyway."

# 4. Install or migrate -----------------------------------------------------
# "Installed" = the core `user` table already exists. Checked via
# information_schema (robust, no reserved-word quoting) using a temp file so
# there are no shell/PHP quoting hazards.
if [ "${NEWUI_AUTO_INSTALL:-true}" = "true" ] && [ "$DB_READY" = 1 ]; then
    cat > /tmp/installed_check.php <<'PHPCHK'
<?php
require getenv('APP_DIR') . '/config.php';
require getenv('APP_DIR') . '/inc/db.php';
try {
    $n = db_fetch_value(
        "SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = 'user'"
    );
    echo ((int)$n > 0) ? 'yes' : 'no';
} catch (Throwable $e) {
    echo 'no';
}
PHPCHK
    INSTALLED=$(APP_DIR="$APP" php /tmp/installed_check.php 2>/dev/null || echo "no")
    rm -f /tmp/installed_check.php

    if [ "$INSTALLED" != "yes" ]; then
        echo "[entrypoint] Empty database detected — running fresh install..."
        php "$APP/tools/install_fresh.php"
        echo "[entrypoint] Creating admin user..."
        # Never fatal: on any restart where the admin already exists this exits
        # non-zero, and under `set -e` that would abort startup before Apache.
        if [ -n "${ADMIN_PASSWORD}" ]; then
            php "$APP/tools/create_admin.php" --username="${ADMIN_USER:-admin}" --email="${ADMIN_EMAIL:-admin@example.invalid}" --password="${ADMIN_PASSWORD}" \
                || echo "[entrypoint] admin user already exists — skipping creation."
        else
            php "$APP/tools/create_admin.php" --username="${ADMIN_USER:-admin}" --email="${ADMIN_EMAIL:-admin@example.invalid}" \
                || echo "[entrypoint] admin user already exists — skipping creation."
        fi
        if [ "${NEWUI_SEED_DEMO:-false}" = "true" ]; then
            echo "[entrypoint] Seeding demo data..."
            php "$APP/sql/seed_demo_data.php" || echo "[entrypoint] (demo seed reported issues — non-fatal)"
        fi
        echo "[entrypoint] ============================================================"
        echo "[entrypoint]  Install complete. Sign in at ${NEWUI_BASE_URL:-http://localhost:8081}"
        echo "[entrypoint]  (If no ADMIN_PASSWORD was set, the generated one is printed above.)"
        echo "[entrypoint] ============================================================"
    else
        echo "[entrypoint] Existing installation — applying idempotent migrations..."
        php "$APP/tools/install_fresh.php" || echo "[entrypoint] (migration warnings above — non-fatal)"
    fi
fi

echo "[entrypoint] Starting Apache..."
exec "$@"
