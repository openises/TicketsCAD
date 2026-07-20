<?php
/**
 * Phase 16b (2026-06-11) — PAR scheduler tick.
 *
 * Run once per minute by cron. Each invocation:
 *   - Auto-initiates 'scheduled' PAR cycles for active incidents whose
 *     cadence has elapsed
 *   - Marks unit acks as 'missed' when the cycle window has elapsed
 *     without an ack, posts a chat escalation if configured
 *
 * Install on the VM:
 *
 *   sudo install -m 644 /dev/stdin /etc/cron.d/par_tick <<'EOF'
 *   * * * * * www-data /usr/bin/php /var/www/newui/tools/par_tick.php >> /var/log/par_tick.log 2>&1
 *   EOF
 *
 * No-op when par_enabled=0 in settings.
 *
 * Usage: php tools/par_tick.php
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/par.php';

$r = par_run_scheduler();
$ts = date('Y-m-d H:i:s');
echo "[{$ts}] par_tick: cycles_started={$r['cycles_started']} units_missed={$r['units_missed']}";
if (isset($r['reason'])) echo " reason={$r['reason']}";
echo "\n";
