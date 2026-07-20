<?php
/**
 * Phase 18e — pending routed-message cron tick.
 *
 * Run every minute. Each invocation sends any pending message
 * whose scheduled_send_at has passed.
 *
 * Install on the VM:
 *   echo '* * * * * www-data /usr/bin/php /var/www/newui/tools/pending_messages_tick.php >> /var/log/pending_msg_tick.log 2>&1' > /etc/cron.d/pending_msg_tick
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/pending-messages.php';

$r = pending_sweep();
echo '[' . date('Y-m-d H:i:s') . "] pending_sweep: considered={$r['considered']} sent={$r['sent']} failed={$r['failed']}\n";
