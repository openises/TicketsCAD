#!/usr/bin/env bash
#
# Install the TicketsCAD APRS-IS listener on a host (training or
# Bloomington). Idempotent — re-running is safe.
#
# What it does:
#   1. apt install python3-pip + pip install aprslib + mysql-connector-python
#   2. Generates /etc/ticketscad-aprs.conf from the install's config.php
#      (DB host/user/pass/name), 0600 owned by www-data
#   3. Copies the systemd unit into place
#   4. systemctl daemon-reload, enable + start
#   5. Tails the first 20 lines of journal to confirm it started
#
# Pre-reqs:
#   - /var/www/newui/services/aprs/aprs_listener.py + .service exist
#   - sudo access
#
# Run as:  sudo bash /var/www/newui/services/aprs/install.sh
set -euo pipefail

NEWUI=/var/www/newui
SERVICE=ticketscad-aprs-listener.service
CONFIG=/etc/ticketscad-aprs.conf
SRC_DIR="$NEWUI/services/aprs"

if [[ ! -f "$SRC_DIR/aprs_listener.py" ]]; then
    echo "ERROR: $SRC_DIR/aprs_listener.py not found" >&2
    exit 1
fi

echo "==> 1. Installing Python deps"
apt-get install -y --no-install-recommends python3-pip >/dev/null
# mysql-connector-python + aprslib are pip-only on current Debian/Ubuntu.
# --break-system-packages required on PEP 668 systems (Debian 12+).
pip3 install --break-system-packages aprslib mysql-connector-python >/dev/null 2>&1 \
    || pip3 install aprslib mysql-connector-python >/dev/null

echo "==> 2. Generating $CONFIG from config.php"
if [[ ! -f "$CONFIG" ]]; then
    php -r "
        require '$NEWUI/config.php';
        \$cfg = [
            'db_host' => \$GLOBALS['db_host'],
            'db_user' => \$GLOBALS['db_user'],
            'db_pass' => \$GLOBALS['db_pass'],
            'db_name' => \$GLOBALS['db_name'],
        ];
        echo json_encode(\$cfg, JSON_PRETTY_PRINT) . PHP_EOL;
    " > "$CONFIG"
    chown www-data:www-data "$CONFIG"
    chmod 0600 "$CONFIG"
    echo "    [ok] generated $CONFIG (0600, www-data)"
else
    echo "    [skip] $CONFIG already exists — not overwriting"
fi

echo "==> 3. Installing systemd unit"
cp "$SRC_DIR/$SERVICE" /etc/systemd/system/
systemctl daemon-reload

echo "==> 4. Enabling + starting"
systemctl enable "$SERVICE"
systemctl restart "$SERVICE"
sleep 2

echo "==> 5. Status + first journal lines"
systemctl --no-pager --lines=0 status "$SERVICE" || true
echo
journalctl -u "$SERVICE" --no-pager --lines=20 || true

echo
echo "==> Done. To watch live:  journalctl -fu $SERVICE"
echo "    To stop:              sudo systemctl stop $SERVICE"
echo "    To restart:           sudo systemctl restart $SERVICE"
