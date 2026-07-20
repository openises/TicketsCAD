#!/usr/bin/env bash
# NewUI v4.0 — Zello WebSocket proxy launcher (Linux/macOS)
#
# Foreground launcher for development. For production use the systemd unit
# in `proxy/newui-zello-proxy.service.example` instead — it restarts on
# failure, logs to /var/log/newui/, and runs as the web-server user.

set -e

# Resolve the directory this script lives in, regardless of how it was invoked.
DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
ROOT=$(cd "$DIR/.." && pwd)

PHP_BIN=${PHP_BIN:-$(command -v php)}
if [ -z "$PHP_BIN" ]; then
    echo "ERROR: php binary not found on PATH (set PHP_BIN=/path/to/php)" >&2
    exit 1
fi

cd "$ROOT"
exec "$PHP_BIN" "$DIR/zello-proxy.php" "$@"
