<?php
/**
 * Probe "job" for tests/test_scheduled_job_runner.php. Stands in for a real
 * tick script so the runner's due / overlap / failure / timeout handling can
 * be driven deterministically. Not a test itself (the leading underscore keeps
 * tools/test_all.php from running it).
 *
 *   ok [text]          print text, exit 0
 *   fail               print to stderr, exit 3
 *   sleep N            sleep N seconds, then exit 0
 *   mark FILE          append one line to FILE, exit 0 (counts runs)
 *   tree PIDFILE       start a grandchild that sleeps 120 s, write its pid to
 *                      PIDFILE, then sleep 120 s itself — a job that hangs AND
 *                      has a child, which a timeout must kill as a tree
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

$mode = $argv[1] ?? 'ok';
switch ($mode) {
    case 'ok':
        echo ($argv[2] ?? 'probe ok') . "\n";
        exit(0);

    case 'fail':
        echo "probe stdout before failing\n";
        fwrite(STDERR, "probe: deliberate failure\n");
        exit(3);

    case 'sleep':
        sleep((int) ($argv[2] ?? 1));
        echo "probe slept\n";
        exit(0);

    case 'mark':
        file_put_contents((string) $argv[2], date('c') . "\n", FILE_APPEND);
        echo "probe marked\n";
        exit(0);

    case 'tree':
        $null = stripos(PHP_OS, 'WIN') === 0 ? 'NUL' : '/dev/null';
        $code = 'sleep(120);';
        $child = proc_open([PHP_BINARY, '-r', $code], [
            0 => ['file', $null, 'r'],
            1 => ['file', $null, 'w'],
            2 => ['file', $null, 'w'],
        ], $pipes, null, null, ['bypass_shell' => true]);
        $st = is_resource($child) ? proc_get_status($child) : ['pid' => 0];
        file_put_contents((string) $argv[2], (string) (int) $st['pid']);
        echo "probe started grandchild {$st['pid']}\n";
        sleep(120);
        exit(0);
}
fwrite(STDERR, "unknown probe mode {$mode}\n");
exit(2);
