<?php
/**
 * NewUI v4.0 API - System Health Check
 *
 * GET /api/health.php
 *   Returns status of all system components: DB, PHP, OS, Zello proxy, disk, etc.
 *   Admin-only (level <= 1). Non-admins get a 403.
 *
 * Each component returns:
 *   status:  "ok" | "warn" | "error" | "unknown"
 *   message: Human-readable summary
 *   details: Component-specific data (uptime, version, etc.)
 */

ini_set('display_errors', '0');

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/rbac.php';

$prefix = $GLOBALS['db_prefix'] ?? '';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_error('Method not allowed', 405);
}

// Admin only
if (!is_admin()) {
    json_error('Admin access required', 403);
}

$components = [];

// ── 1. Database ──────────────────────────────────────────────────
$components['database'] = checkDatabase($prefix);

// ── 2. PHP Runtime ───────────────────────────────────────────────
$components['php'] = checkPhp();

// ── 3. Host OS ───────────────────────────────────────────────────
$components['os'] = checkOs();

// ── 4. Web Server ────────────────────────────────────────────────
$components['webserver'] = checkWebServer();

// ── 5. Zello Proxy ───────────────────────────────────────────────
$components['zello_proxy'] = checkZelloProxy($prefix);

// ── 6. Disk Space ────────────────────────────────────────────────
$components['disk'] = checkDisk();

// ── 7. Session Storage ───────────────────────────────────────────
$components['sessions'] = checkSessions();

// ── 8. Cache Directory ───────────────────────────────────────────
$components['cache'] = checkCache();

// ── 9. Location Providers ────────────────────────────────────────
// Phase 26C (2026-06-11) — one row per configured location provider
// with last_receive_at + recent error so the System Health page can
// render an expandable card.
$components['location_providers'] = checkLocationProviders($prefix);

// Compute overall status
$overall = 'ok';
foreach ($components as $c) {
    if ($c['status'] === 'error') {
        $overall = 'error';
        break;
    }
    if ($c['status'] === 'warn') {
        $overall = 'warn';
    }
}

// ── Log state transitions to service_events ─────────────────────
logServiceTransitions($components);

// Gather per-table details for Database Info panel
$tableDetails = [];
try {
    $rows = db_fetch_all(
        "SELECT TABLE_NAME AS name, ENGINE AS engine, TABLE_ROWS AS `rows`,
                ROUND((DATA_LENGTH + INDEX_LENGTH) / 1024, 1) AS size_kb,
                TABLE_COLLATION AS collation
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE()
         ORDER BY TABLE_NAME"
    );
    foreach ($rows as &$r) {
        $kb = (float) $r['size_kb'];
        $r['size'] = $kb >= 1024 ? round($kb / 1024, 1) . ' MB' : $kb . ' KB';
        $r['rows'] = (int) $r['rows'];
    }
    unset($r);
    $tableDetails = $rows;
} catch (Exception $e) {}

$dbComp = $components['database']['details'] ?? [];
$phpComp = $components['php']['details'] ?? [];

json_response([
    'overall'        => $overall,
    'timestamp'      => date('Y-m-d H:i:s'),
    'components'     => $components,
    // Flat fields for Database Info panel
    'database'       => db_fetch_value("SELECT DATABASE()"),
    'host'           => $GLOBALS['mysql_host'] ?? 'localhost',
    'server_version' => $dbComp['version'] ?? '',
    'charset'        => db_fetch_value("SELECT @@character_set_database") ?: '',
    'table_count'    => $dbComp['tables'] ?? 0,
    'total_size'     => ($dbComp['size_mb'] ?? 0) . ' MB',
    'php_version'    => $phpComp['version'] ?? PHP_VERSION,
    'app_version'    => 'v4.0.0-dev',
    'tables'         => $tableDetails,
]);

// ═══════════════════════════════════════════════════════════════
//  Component Check Functions
// ═══════════════════════════════════════════════════════════════

function checkDatabase(string $prefix): array
{
    try {
        $start = microtime(true);
        $version = db_fetch_value("SELECT VERSION()");
        $latency = round((microtime(true) - $start) * 1000, 1);

        // Get uptime in seconds
        $uptimeRow = db_fetch_one("SHOW GLOBAL STATUS LIKE 'Uptime'");
        $uptimeSec = $uptimeRow ? (int) $uptimeRow['Value'] : 0;

        // Get some stats
        $threadsRow = db_fetch_one("SHOW GLOBAL STATUS LIKE 'Threads_connected'");
        $threads    = $threadsRow ? (int) $threadsRow['Value'] : 0;

        $questionsRow = db_fetch_one("SHOW GLOBAL STATUS LIKE 'Questions'");
        $questions    = $questionsRow ? (int) $questionsRow['Value'] : 0;

        // Table count
        $tableCount = db_fetch_value(
            "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()"
        );

        // DB size in MB
        $sizeRow = db_fetch_one(
            "SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) AS size_mb
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()"
        );
        $sizeMb = $sizeRow ? (float) $sizeRow['size_mb'] : 0;

        return [
            'status'  => 'ok',
            'message' => 'Connected — ' . $version,
            'details' => [
                'version'     => $version,
                'uptime_sec'  => $uptimeSec,
                'uptime_text' => formatUptime($uptimeSec),
                'latency_ms'  => $latency,
                'threads'     => $threads,
                'queries'     => $questions,
                'tables'      => (int) $tableCount,
                'size_mb'     => $sizeMb,
            ],
        ];
    } catch (Exception $e) {
        return [
            'status'  => 'error',
            'message' => 'Connection failed: ' . $e->getMessage(),
            'details' => [],
        ];
    }
}

function checkPhp(): array
{
    $extensions = get_loaded_extensions();
    $required   = ['pdo', 'pdo_mysql', 'json', 'session', 'mbstring', 'openssl'];
    $missing    = [];
    foreach ($required as $ext) {
        if (!extension_loaded($ext)) {
            $missing[] = $ext;
        }
    }

    $memLimit = ini_get('memory_limit');
    $maxExec  = ini_get('max_execution_time');
    $uploadMax = ini_get('upload_max_filesize');

    $status = empty($missing) ? 'ok' : 'warn';
    $msg    = 'PHP ' . PHP_VERSION;
    if (!empty($missing)) {
        $msg .= ' — missing: ' . implode(', ', $missing);
    }

    return [
        'status'  => $status,
        'message' => $msg,
        'details' => [
            'version'           => PHP_VERSION,
            'sapi'              => PHP_SAPI,
            'memory_limit'      => $memLimit,
            'max_execution_time' => $maxExec,
            'upload_max'        => $uploadMax,
            'extensions_loaded' => count($extensions),
            'missing_required'  => $missing,
            'zend_version'      => zend_version(),
        ],
    ];
}

function checkOs(): array
{
    $os     = PHP_OS_FAMILY;
    $osDesc = php_uname('s') . ' ' . php_uname('r');

    // Try to get uptime
    $uptimeSec = null;
    $uptimeText = 'Unknown';

    if ($os === 'Windows') {
        // Use wmic to get boot time
        $output = @shell_exec('wmic os get LastBootUpTime 2>NUL');
        if ($output) {
            $lines = array_filter(array_map('trim', explode("\n", $output)));
            // Skip header line
            foreach ($lines as $line) {
                if (preg_match('/^(\d{14})/', $line, $m)) {
                    $bootTime = $m[1];
                    $year  = substr($bootTime, 0, 4);
                    $month = substr($bootTime, 4, 2);
                    $day   = substr($bootTime, 6, 2);
                    $hour  = substr($bootTime, 8, 2);
                    $min   = substr($bootTime, 10, 2);
                    $sec   = substr($bootTime, 12, 2);
                    $bootTs = mktime((int)$hour, (int)$min, (int)$sec, (int)$month, (int)$day, (int)$year);
                    if ($bootTs) {
                        $uptimeSec = time() - $bootTs;
                        $uptimeText = formatUptime($uptimeSec);
                    }
                    break;
                }
            }
        }
    } else {
        // Linux/Mac: read /proc/uptime
        if (is_readable('/proc/uptime')) {
            $raw = file_get_contents('/proc/uptime');
            $uptimeSec = (int) floatval($raw);
            $uptimeText = formatUptime($uptimeSec);
        } else {
            $output = @shell_exec('uptime -s 2>/dev/null');
            if ($output) {
                $bootTs = strtotime(trim($output));
                if ($bootTs) {
                    $uptimeSec = time() - $bootTs;
                    $uptimeText = formatUptime($uptimeSec);
                }
            }
        }
    }

    // Server time and timezone
    $tz = date_default_timezone_get();

    return [
        'status'  => 'ok',
        'message' => $osDesc . ' — up ' . $uptimeText,
        'details' => [
            'os'          => $osDesc,
            'os_family'   => $os,
            'hostname'    => php_uname('n'),
            'architecture' => php_uname('m'),
            'uptime_sec'  => $uptimeSec,
            'uptime_text' => $uptimeText,
            'server_time' => date('Y-m-d H:i:s'),
            'timezone'    => $tz,
        ],
    ];
}

function checkWebServer(): array
{
    $software = $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown';
    $protocol = $_SERVER['SERVER_PROTOCOL'] ?? '';
    $port     = $_SERVER['SERVER_PORT'] ?? '';
    $https    = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'Yes' : 'No';
    $docRoot  = $_SERVER['DOCUMENT_ROOT'] ?? '';

    return [
        'status'  => 'ok',
        'message' => $software,
        'details' => [
            'software'  => $software,
            'protocol'  => $protocol,
            'port'      => $port,
            'https'     => $https,
            'doc_root'  => $docRoot,
        ],
    ];
}

function checkZelloProxy(string $prefix): array
{
    // Check if Zello is configured
    try {
        $rows = db_fetch_all("SELECT `name`, `value` FROM `{$prefix}settings` WHERE `name` LIKE 'zello_%'");
        $config = [];
        foreach ($rows as $row) {
            $config[$row['name']] = $row['value'];
        }
    } catch (Exception $e) {
        return [
            'status'  => 'unknown',
            'message' => 'Cannot read Zello settings',
            'details' => [],
        ];
    }

    $service = $config['zello_service'] ?? '';
    if ($service === '') {
        return [
            'status'  => 'unknown',
            'message' => 'Not configured',
            'details' => ['configured' => false],
        ];
    }

    $port = (int) ($config['zello_proxy_port'] ?? 8090);
    if ($port < 1024 || $port > 65535) {
        $port = 8090;
    }

    // Try to connect to the proxy WebSocket port
    $proxyUp = false;
    $sock = @fsockopen('127.0.0.1', $port, $errno, $errstr, 2);
    if ($sock) {
        $proxyUp = true;
        fclose($sock);
    }

    // Check for proxy PID file
    $pidFile = NEWUI_ROOT . '/proxy/zello-proxy.pid';
    $pid = null;
    $proxyUptime = null;
    if (file_exists($pidFile)) {
        $pidData = json_decode(file_get_contents($pidFile), true);
        $pid = $pidData['pid'] ?? null;
        $startedAt = $pidData['started_at'] ?? null;
        if ($startedAt) {
            $startTs = strtotime($startedAt);
            if ($startTs) {
                $proxyUptime = time() - $startTs;
            }
        }
    }

    $status = $proxyUp ? 'ok' : 'error';
    $msg    = $proxyUp
        ? 'Running on port ' . $port . ($proxyUptime ? ' — up ' . formatUptime($proxyUptime) : '')
        : 'Not responding on port ' . $port;

    return [
        'status'  => $status,
        'message' => $msg,
        'details' => [
            'configured'   => true,
            'service_type' => $service,
            'port'         => $port,
            'listening'    => $proxyUp,
            'pid'          => $pid,
            'uptime_sec'   => $proxyUptime,
            'uptime_text'  => $proxyUptime !== null ? formatUptime($proxyUptime) : null,
            'channel'      => $config['zello_dispatch_channel'] ?? '',
        ],
    ];
}

function checkDisk(): array
{
    $root = NEWUI_ROOT;
    $total = @disk_total_space($root);
    $free  = @disk_free_space($root);

    if ($total === false || $free === false) {
        return [
            'status'  => 'unknown',
            'message' => 'Cannot determine disk space',
            'details' => [],
        ];
    }

    $usedPct = round((1 - $free / $total) * 100, 1);
    $status  = 'ok';
    if ($usedPct > 90) {
        $status = 'error';
    } elseif ($usedPct > 80) {
        $status = 'warn';
    }

    return [
        'status'  => $status,
        'message' => $usedPct . '% used — ' . formatBytes($free) . ' free of ' . formatBytes($total),
        'details' => [
            'total_bytes' => $total,
            'free_bytes'  => $free,
            'used_pct'    => $usedPct,
            'total_text'  => formatBytes($total),
            'free_text'   => formatBytes($free),
            'path'        => $root,
        ],
    ];
}

function checkSessions(): array
{
    $savePath = ini_get('session.save_path');
    if ($savePath === '' || $savePath === false) {
        $savePath = sys_get_temp_dir();
    }
    // Strip N;path format
    if (preg_match('/^\d+;(.+)$/', $savePath, $m)) {
        $savePath = $m[1];
    }

    $writable = is_writable($savePath);
    $handler  = ini_get('session.save_handler');

    // Count session files
    $count = 0;
    if (is_dir($savePath)) {
        $files = @glob($savePath . '/sess_*');
        $count = $files !== false ? count($files) : 0;
    }

    $status = $writable ? 'ok' : 'error';
    $msg    = $writable
        ? $handler . ' — ' . $count . ' active sessions'
        : 'Session directory not writable: ' . $savePath;

    return [
        'status'  => $status,
        'message' => $msg,
        'details' => [
            'handler'   => $handler,
            'save_path' => $savePath,
            'writable'  => $writable,
            'count'     => $count,
        ],
    ];
}

function checkCache(): array
{
    $cacheDir = NEWUI_ROOT . '/cache';
    if (!is_dir($cacheDir)) {
        return [
            'status'  => 'warn',
            'message' => 'Cache directory does not exist',
            'details' => ['path' => $cacheDir, 'exists' => false],
        ];
    }

    $writable = is_writable($cacheDir);
    $files = @glob($cacheDir . '/*');
    $count = $files !== false ? count($files) : 0;

    // Sum file sizes
    $totalSize = 0;
    if ($files) {
        foreach ($files as $f) {
            if (is_file($f)) {
                $totalSize += filesize($f);
            }
        }
    }

    $status = $writable ? 'ok' : 'warn';
    $msg    = $writable
        ? $count . ' cached files (' . formatBytes($totalSize) . ')'
        : 'Cache directory not writable';

    return [
        'status'  => $status,
        'message' => $msg,
        'details' => [
            'path'       => $cacheDir,
            'exists'     => true,
            'writable'   => $writable,
            'file_count' => $count,
            'total_size' => $totalSize,
            'size_text'  => formatBytes($totalSize),
        ],
    ];
}

// ═══════════════════════════════════════════════════════════════
//  Helpers
// ═══════════════════════════════════════════════════════════════

function formatUptime(int $seconds): string
{
    if ($seconds < 60) return $seconds . 's';

    $days    = floor($seconds / 86400);
    $hours   = floor(($seconds % 86400) / 3600);
    $minutes = floor(($seconds % 3600) / 60);

    $parts = [];
    if ($days > 0) $parts[] = $days . 'd';
    if ($hours > 0) $parts[] = $hours . 'h';
    if ($minutes > 0) $parts[] = $minutes . 'm';

    return implode(' ', $parts);
}

function formatBytes(float $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }
    return round($bytes, 1) . ' ' . $units[$i];
}

/**
 * Compare current component status against last known state.
 * Log events when transitions occur (ok→error = crash, error→ok = recovered, etc.).
 */
function logServiceTransitions(array $components): void
{
    try {
        $stateTable = db_table('newui_service_state');
        $eventTable = db_table('newui_service_events');
        $now = date('Y-m-d H:i:s');

        // Load current saved states
        $rows = [];
        try {
            $rows = db_fetch_all("SELECT * FROM {$stateTable}");
        } catch (Exception $e) {
            // Table might not exist yet — silently skip
            return;
        }
        $savedStates = [];
        foreach ($rows as $r) {
            $savedStates[$r['service']] = $r;
        }

        foreach ($components as $svcName => $comp) {
            $newStatus = $comp['status'];
            $uptimeSec = $comp['details']['uptime_sec'] ?? null;
            $details   = json_encode($comp['details']);
            $prev = $savedStates[$svcName] ?? null;

            if ($prev === null) {
                // First time seeing this service — insert state, log "start"
                db_query(
                    "INSERT INTO {$stateTable} (service, last_status, last_checked, last_uptime_sec, consecutive_failures)
                     VALUES (?, ?, ?, ?, ?)",
                    [$svcName, $newStatus, $now, $uptimeSec, ($newStatus === 'error' ? 1 : 0)]
                );
                db_query(
                    "INSERT INTO {$eventTable} (service, event_type, detected_at, uptime_seconds, details)
                     VALUES (?, 'start', ?, ?, ?)",
                    [$svcName, $now, $uptimeSec, $details]
                );
                continue;
            }

            $prevStatus = $prev['last_status'];
            $failures = (int) $prev['consecutive_failures'];
            $eventType = null;

            // Detect transitions
            if ($prevStatus !== 'error' && $newStatus === 'error') {
                // Was ok/warn, now error → crash
                $eventType = 'crash';
                $failures = 1;
            } elseif ($prevStatus === 'error' && $newStatus === 'ok') {
                // Was error, now ok → recovered
                $eventType = 'recovered';
                $failures = 0;
            } elseif ($prevStatus === 'error' && $newStatus === 'error') {
                // Still erroring
                $failures++;
            } elseif ($prevStatus === 'ok' && $newStatus === 'warn') {
                $eventType = 'degraded';
            } elseif ($prevStatus === 'warn' && $newStatus === 'ok') {
                $eventType = 'recovered';
                $failures = 0;
            } else {
                // Same state or unknown transition — just update timestamp
                $failures = ($newStatus === 'error') ? $failures + 1 : 0;
            }

            // Detect uptime reset (service restarted)
            if ($uptimeSec !== null && $prev['last_uptime_sec'] !== null) {
                if ((int) $uptimeSec < (int) $prev['last_uptime_sec'] && $newStatus === 'ok') {
                    // Uptime went down but status is ok → service restarted
                    $eventType = 'restart';
                }
            }

            // Log event if state changed
            if ($eventType !== null) {
                db_query(
                    "INSERT INTO {$eventTable} (service, event_type, detected_at, uptime_seconds, details)
                     VALUES (?, ?, ?, ?, ?)",
                    [$svcName, $eventType, $now, $uptimeSec, $details]
                );
            }

            // Update state
            db_query(
                "UPDATE {$stateTable}
                 SET last_status = ?, last_checked = ?, last_uptime_sec = ?, consecutive_failures = ?
                 WHERE service = ?",
                [$newStatus, $now, $uptimeSec, $failures, $svcName]
            );
        }
    } catch (Exception $e) {
        // Don't let event logging break the health check
        // Silently skip
    }
}

/**
 * Phase 26C (2026-06-11) — per-provider health snapshot.
 *
 * Returns rollup status across all configured location providers and
 * a per-provider `details.providers[]` list with: code, name, enabled,
 * last_receive_at, receive_count_24h, last_error.
 *
 * Status rollup:
 *   - "ok"      if all enabled providers received a packet in the last 30 min
 *   - "warn"    if at least one enabled provider is dark (>30 min)
 *   - "error"   if all enabled providers are dark (>30 min) OR no providers configured but feature claims enabled
 *   - "unknown" if no providers configured (feature not in use)
 */
function checkLocationProviders(string $prefix): array {
    $details = ['providers' => []];
    $status = 'unknown';
    $message = 'No location providers configured';

    try {
        $rows = db_fetch_all(
            "SELECT id, code, name, enabled, color, icon, priority
               FROM `{$prefix}location_providers`
              ORDER BY priority ASC, code ASC"
        );
    } catch (Exception $e) {
        return ['status' => 'unknown', 'message' => 'location_providers table missing', 'details' => $details];
    }

    if (!$rows) {
        return ['status' => $status, 'message' => $message, 'details' => $details];
    }

    $now = time();
    $staleSec = 30 * 60;
    $enabledCount = 0;
    $darkEnabledCount = 0;

    foreach ($rows as $r) {
        $pid = (int) $r['id'];
        $enabled = (int) ($r['enabled'] ?? 0) === 1;
        $lastTs = null;
        $count24h = 0;
        try {
            $v = db_fetch_one(
                "SELECT MAX(received_at) AS last_ts,
                        SUM(CASE WHEN received_at > NOW() - INTERVAL 24 HOUR THEN 1 ELSE 0 END) AS c24
                   FROM `{$prefix}location_reports`
                  WHERE provider_id = ?",
                [$pid]
            );
            if ($v) {
                $lastTs = $v['last_ts'] ?: null;
                $count24h = (int) ($v['c24'] ?? 0);
            }
        } catch (Exception $e) {}

        $age = $lastTs ? max(0, $now - strtotime($lastTs)) : null;
        $provStatus = 'unknown';
        // Phase 41: providers that are "browser-driven only" (Internal GPS,
        // Manual entry) have no server-side ingest at all — the absence of
        // location_reports rows just means no mobile-web user has shared
        // their location yet. Treat that as "passive" rather than "error".
        $browserDriven = in_array((string) $r['code'], ['internal_gps', 'manual', 'browser_gps'], true);
        if (!$enabled) {
            $provStatus = 'disabled';
        } elseif ($lastTs === null) {
            $provStatus = $browserDriven ? 'passive' : 'no_data';
        } elseif ($age > $staleSec) {
            $provStatus = 'stale';
        } else {
            $provStatus = 'ok';
        }

        if ($enabled) {
            $enabledCount++;
            // 'passive' is healthy for browser-driven providers — don't
            // count it as "dark" for the aggregate status calculation.
            if (!in_array($provStatus, ['ok', 'passive'], true)) $darkEnabledCount++;
        }

        $details['providers'][] = [
            'id'                => $pid,
            'code'              => $r['code'],
            'name'              => $r['name'],
            'enabled'           => $enabled,
            'icon'              => $r['icon'] ?? '',
            'color'             => $r['color'] ?? '',
            'priority'          => (int) $r['priority'],
            'last_receive_at'   => $lastTs,
            'age_seconds'       => $age,
            'receive_count_24h' => $count24h,
            'status'            => $provStatus,
        ];
    }

    if ($enabledCount === 0) {
        $status = 'unknown';
        $message = 'No providers enabled';
    } elseif ($darkEnabledCount === 0) {
        $status = 'ok';
        $message = "{$enabledCount} provider(s) receiving";
    } elseif ($darkEnabledCount < $enabledCount) {
        $status = 'warn';
        $message = "{$darkEnabledCount}/{$enabledCount} provider(s) stale (>30 min)";
    } else {
        $status = 'error';
        $message = "All {$enabledCount} enabled provider(s) dark";
    }

    return ['status' => $status, 'message' => $message, 'details' => $details];
}
