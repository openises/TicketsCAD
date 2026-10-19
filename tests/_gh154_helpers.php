<?php
/**
 * GH#154 — shared helpers for the ICS-forms tests (not a test itself: the
 * leading `_` keeps tools/test_all.php from running it).
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/ics-form-types.php';
require_once __DIR__ . '/../inc/ics-forms-builtin.php';

$GLOBALS['GH154_PASS'] = 0;
$GLOBALS['GH154_FAIL'] = 0;

function gh154_t(string $label, bool $cond, string $detail = ''): void
{
    if ($cond) {
        $GLOBALS['GH154_PASS']++;
        echo "[PASS] {$label}\n";
    } else {
        $GLOBALS['GH154_FAIL']++;
        echo "[FAIL] {$label}" . ($detail !== '' ? " -- {$detail}" : '') . "\n";
    }
}

function gh154_done(): void
{
    $p = $GLOBALS['GH154_PASS'];
    $f = $GLOBALS['GH154_FAIL'];
    echo "\n=== {$p} passed, {$f} failed ===\n";
    exit($f > 0 ? 1 : 0);
}

/**
 * Drive the REAL api/ics-forms.php through tests/_gh154_ics_api_probe.php.
 * proc_open with an ARRAY command: escapeshellarg() mangles JSON quotes on
 * Windows.
 */
function gh154_probe(string $method, int $uid, string $payload, string $endpoint = 'api/ics-forms.php'): array
{
    $cmd = [PHP_BINARY ?: 'php', __DIR__ . '/_gh154_ics_api_probe.php', $method, (string) $uid, $payload, $endpoint];
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
    if (!is_resource($proc)) {
        return ['status' => 0, 'body' => 'proc_open failed'];
    }
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);
    $lines = array_values(array_filter(array_map('trim', explode("\n", (string) $out)), 'strlen'));
    $last = $lines ? end($lines) : '';
    $j = json_decode($last, true);
    if (!is_array($j)) {
        return ['status' => 0, 'body' => substr((string) $out . (string) $err, 0, 600)];
    }
    return $j;
}

/** The first value of a select's options (string or {value,label}). */
function gh154_first_option(array $f): string
{
    $o = $f['options'][0] ?? '';
    return is_array($o) ? (string) ($o['value'] ?? $o['label'] ?? '') : (string) $o;
}

/** A distinctive value for one input of a given type. */
function gh154_sample_value(array $f, string $sentinel)
{
    switch ((string) ($f['type'] ?? 'text')) {
        case 'date':           return '2026-03-04';
        case 'time':           return '13:37';
        case 'datetime-local': return '2026-03-04T13:37';
        case 'number':         return '7';
        case 'checkbox':       return true;
        case 'select':         return gh154_first_option($f);
        default:               return $sentinel;
    }
}

/** A column's sample value (columns use '1' for a ticked checkbox). */
function gh154_sample_cell(array $c, string $sentinel)
{
    if (($c['type'] ?? '') === 'checkbox') {
        return '1';
    }
    return gh154_sample_value($c, $sentinel);
}

/**
 * Sample data for a template: every input filled, two rows in every table
 * (every labelled row for a fixed-row table). Text values are sentinels
 * "S<tag>_<key>" so a test can look for each one in the editor's data and on
 * paper. Legacy (earlier-version) fields are left out — they are only for
 * data saved before.
 */
function gh154_sample_data(array $tpl, string $tag): array
{
    $d = [];
    foreach ($tpl['fields'] as $f) {
        $type = (string) ($f['type'] ?? 'text');
        if (in_array($type, ['section_header', 'signature'], true) || !empty($f['legacy'])) {
            continue;
        }
        $key = (string) $f['key'];
        if ($type === 'table') {
            $n = !empty($f['fixed_rows']) ? count($f['fixed_rows']) : 2;
            $rows = [];
            for ($r = 0; $r < $n; $r++) {
                $row = [];
                foreach ($f['columns'] as $c) {
                    if (!empty($c['legacy'])) {
                        continue;
                    }
                    $row[$c['key']] = gh154_sample_cell($c, 'S' . $tag . '_' . $key . '_' . $c['key'] . '_' . $r);
                }
                $rows[] = $row;
            }
            $d[$key] = $rows;
            continue;
        }
        $d[$key] = gh154_sample_value($f, 'S' . $tag . '_' . $key);
    }
    return $d;
}

/** Every text sentinel in a data set (strings starting with "S<tag>_"). */
function gh154_sentinels(array $d, string $tag): array
{
    $out = [];
    array_walk_recursive($d, function ($v) use (&$out, $tag) {
        if (is_string($v) && strpos($v, 'S' . $tag . '_') === 0) {
            $out[] = $v;
        }
    });
    return $out;
}

/**
 * The values the editor would show for $data under $tpl: every input's value,
 * every visible table cell; earlier-version fields/columns only when they
 * hold data (that is when the editor shows them).
 */
function gh154_displayed_values(array $tpl, array $data): array
{
    $vals = [];
    foreach ($tpl['fields'] as $f) {
        $type = (string) ($f['type'] ?? 'text');
        if (in_array($type, ['section_header', 'signature'], true)) {
            continue;
        }
        $key = (string) $f['key'];
        if ($type === 'table') {
            $rows = isset($data[$key]) && is_array($data[$key]) ? $data[$key] : [];
            foreach ($f['columns'] as $c) {
                foreach ($rows as $r) {
                    if (is_array($r) && isset($r[$c['key']]) && is_scalar($r[$c['key']])) {
                        $vals[] = (string) $r[$c['key']];
                    }
                }
            }
            continue;
        }
        $v = $data[$key] ?? '';
        if (is_scalar($v)) {
            $vals[] = (string) $v;
        }
    }
    return $vals;
}

/** Does any displayed value contain $needle? */
function gh154_shown(array $vals, string $needle): bool
{
    foreach ($vals as $v) {
        if (strpos($v, $needle) !== false) {
            return true;
        }
    }
    return false;
}
