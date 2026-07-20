#!/usr/bin/env bash
# Phase 85c — DMR WebSocket proxy launcher (dev / foreground).
# For production use the systemd unit (newui-dmr-proxy.service.example).
set -e
PHP_BIN="${PHP_BIN:-$(command -v php8.2 || command -v php8.3 || command -v php8.4 || command -v php)}"
if [ -z "$PHP_BIN" ]; then
    echo "ERROR: no php binary on PATH. Set PHP_BIN=/path/to/php or install php." >&2
    exit 1
fi
cd "$(dirname "$0")/.."
echo "Using PHP: $PHP_BIN"
exec "$PHP_BIN" proxy/dmr-proxy.php
