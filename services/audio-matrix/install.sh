#!/usr/bin/env bash
#
# Install the TicketsCAD audio-matrix service (Phase 114c + Phase 152
# Communications Console v2) on a host. Idempotent -- re-running is safe.
# Modeled directly on services/aprs/install.sh's shape.
#
# What it does:
#   1. Installs the one new Python dependency this phase adds (`websockets`,
#      preferring the Debian package; mysql-connector-python is a fallback
#      pip install for hosts that don't already have it).
#   2. Generates /etc/ticketscad-audio-matrix.conf from the install's own
#      config.php (DB host/user/pass/name) PLUS a freshly generated
#      control_token (openssl rand -hex 32) -- 0600, owned by www-data.
#      Skipped if the file already exists, so re-running never rotates a
#      token a running install is already using.
#   3. Copies the systemd unit into place, daemon-reload, enable + restart.
#   4. Writes matrix_control_url / matrix_control_token into the `settings`
#      table (read by inc/matrix-control-client.php via get_variable()) so
#      the PHP console can actually reach the service that was just
#      installed -- this step ALWAYS runs (even on a re-run over an
#      existing conf file) so the two sides can never drift out of sync.
#   5. Curls the control plane's own /health endpoint (loopback-only, no
#      auth required for GET) to confirm it's actually answering.
#
# Deliberately does NOT flip dmr.mode to "live" -- that's the separate,
# more careful live-RF cutover this project's own docs already treat as
# "careful, reversible, needs Eric's go" and this installer does not
# decide it. browser.mode IS set to "live" by default: without it, NONE
# of Phase 152's new capabilities (Matrix Audio / Join Intercom / physical
# PTT) can function at all -- unlike the DMR leg, joining is per-operator,
# requires an explicit click + a real getUserMedia permission prompt +
# screen.console RBAC, and never touches real amateur-radio RF on its own.
#
# Usage:
#   sudo bash /var/www/newui/services/audio-matrix/install.sh [--base-url URL]
#
# --base-url sets php_base_url (Phase 152 prerequisite #7) so a channel
# join / TX start-stop fans out over SSE to every OTHER console viewer, not
# just the tab that joined. Omit it and the service still works correctly
# -- each session's own tab still gets its own state, there's just no
# cross-workstation fanout (service.py's own documented graceful
# degradation).
set -euo pipefail

NEWUI=/var/www/newui
SERVICE=ticketscad-audio-matrix.service
CONFIG=/etc/ticketscad-audio-matrix.conf
SRC_DIR="$NEWUI/services/audio-matrix"
BASE_URL=""

while [[ $# -gt 0 ]]; do
    case "$1" in
        --base-url) BASE_URL="${2:-}"; shift 2 ;;
        *) echo "Unknown argument: $1" >&2; exit 1 ;;
    esac
done

if [[ ! -f "$SRC_DIR/service.py" ]]; then
    echo "ERROR: $SRC_DIR/service.py not found" >&2
    exit 1
fi

echo "==> 1. Installing Python deps"
if apt-get install -y --no-install-recommends python3-websockets >/dev/null 2>&1; then
    echo "    [ok] python3-websockets via apt"
else
    echo "    [fallback] apt package unavailable -- trying pip"
    pip3 install --break-system-packages websockets >/dev/null 2>&1 \
        || pip3 install websockets >/dev/null
fi
python3 -c "import mysql.connector" 2>/dev/null || {
    echo "    [fallback] mysql-connector-python missing -- installing via pip"
    pip3 install --break-system-packages mysql-connector-python >/dev/null 2>&1 \
        || pip3 install mysql-connector-python >/dev/null
}

echo "==> 2. Generating $CONFIG from config.php"
if [[ ! -f "$CONFIG" ]]; then
    TOKEN="$(openssl rand -hex 32)"
    php -r "
        require '$NEWUI/config.php';
        \$cfg = [
            'db_host' => \$GLOBALS['db_host'],
            'db_user' => \$GLOBALS['db_user'],
            'db_pass' => \$GLOBALS['db_pass'],
            'db_name' => \$GLOBALS['db_name'],
            'control_port' => 18092,
            'control_token' => '$TOKEN',
            'dmr' => ['mode' => 'off', 'bridge_url' => 'http://127.0.0.1:18091', 'channel_key' => 'dmr_bm:3127'],
            'browser' => ['mode' => 'live', 'host' => '0.0.0.0', 'port' => 18093],
            'php_base_url' => '$BASE_URL',
        ];
        echo json_encode(\$cfg, JSON_PRETTY_PRINT) . PHP_EOL;
    " > "$CONFIG"
    chown www-data:www-data "$CONFIG"
    chmod 0600 "$CONFIG"
    echo "    [ok] generated $CONFIG (0600, www-data) -- dmr.mode=off, browser.mode=live"
else
    echo "    [skip] $CONFIG already exists -- not overwriting (token stays stable)"
fi

echo "==> 3. Installing systemd unit"
cp "$SRC_DIR/$SERVICE" /etc/systemd/system/
systemctl daemon-reload
systemctl enable "$SERVICE"
systemctl restart "$SERVICE"
sleep 2

echo "==> 4. Syncing matrix_control_url / matrix_control_token into the settings table"
php "$SRC_DIR/configure-php-settings.php" "$CONFIG"

echo "==> 5. Status + health check"
systemctl --no-pager --lines=0 status "$SERVICE" || true
echo
CONTROL_PORT="$(php -r "echo json_decode(file_get_contents('$CONFIG'), true)['control_port'] ?? 18092;")"
curl -fsS "http://127.0.0.1:${CONTROL_PORT}/health" && echo || echo "    [warn] /health did not respond -- check: journalctl -u $SERVICE --no-pager --lines=40"

echo
echo "==> Done. To watch live:  journalctl -fu $SERVICE"
echo "    To stop:              sudo systemctl stop $SERVICE"
echo "    To restart:           sudo systemctl restart $SERVICE"
echo "    dmr.mode is 'off' by default -- flip it in $CONFIG + restart only"
echo "    when you're deliberately ready for the live-RF DMR cutover."
