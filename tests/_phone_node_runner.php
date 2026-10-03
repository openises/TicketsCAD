<?php
/**
 * Shared helper for the Phase 155 phone tests that run a Node/jsdom script
 * (tests/_phone_*_node.js, all built on tests/_phone_widget_harness.js).
 *
 * Usage from a test file:
 *   require_once __DIR__ . '/_phone_node_runner.php';
 *   $r = phone_node_run('_phone_register_scope_node.js');   // null => SKIP printed
 *   foreach ($r as [$name, $ok]) { t($name, $ok); }
 *
 * The Node script prints one `PASS|name|detail` or `FAIL|name|detail` line per
 * check. Anything else it prints is echoed (so a thrown error is visible).
 * A missing Node or jsdom is a SKIP for that part, never a failure; CI installs
 * jsdom, so the checks run there. File name starts with `_`: not a test.
 */
require_once __DIR__ . '/_test_node_probe.php';

if (!function_exists('phone_node_run')) {

    /** @return array{0:?string,1:bool} [node binary or null, jsdom available] */
    function phone_node_env(): array {
        static $cached = null;
        if ($cached !== null) { return $cached; }
        $node = test_probe_cli(['node', 'node.exe']);
        $jsdom = false;
        if ($node !== null) {
            $probe = test_run_cli([$node, '-e', "require('jsdom');console.log('jsdom-ok')"]);
            $jsdom = is_string($probe) && strpos($probe, 'jsdom-ok') !== false;
        }
        return $cached = [$node, $jsdom];
    }

    /**
     * Run tests/<script> under node with the repo root as argv[2] and return
     * a list of [name, bool ok], or null (after printing SKIP) when Node or
     * jsdom is unavailable.
     */
    function phone_node_run(string $script, array $extraArgs = []): ?array {
        [$node, $jsdom] = phone_node_env();
        if ($node === null || !$jsdom) {
            echo "SKIP: Node and the jsdom package are required for the browser-behaviour checks in "
               . $script . " (npm install jsdom); static checks still ran\n";
            return null;
        }
        $root = str_replace('\\', '/', realpath(__DIR__ . '/..'));
        $argv = array_merge([$node, __DIR__ . '/' . $script, $root], $extraArgs);
        $raw = test_run_cli($argv);
        $results = [];
        if (!is_string($raw) || trim($raw) === '') {
            return [[$script . ' produced output', false]];
        }
        foreach (preg_split('/\R/', trim($raw)) as $line) {
            if (strpos($line, 'PASS|') === 0 || strpos($line, 'FAIL|') === 0) {
                $parts = explode('|', $line, 3);
                $label = $parts[1] . (isset($parts[2]) && $parts[2] !== '' && $parts[0] === 'FAIL' ? ' (' . $parts[2] . ')' : '');
                $results[] = [$label, $parts[0] === 'PASS'];
            } else {
                echo "  [node] " . $line . "\n";
            }
        }
        if (!$results) { $results[] = [$script . ' reported no checks (it probably crashed)', false]; }
        return $results;
    }
}
