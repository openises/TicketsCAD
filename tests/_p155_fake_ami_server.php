<?php
/**
 * A tiny FAKE Asterisk Manager Interface for tests/test_allstar_relay_honesty.php.
 * Not Asterisk and not a model of it: it sends the AMI banner, answers a Login
 * with Success (secret matches) or Error (it does not), answers Logoff, and
 * writes the NAME of every Action it receives to a log file so a test can prove
 * which actions were -- and were not -- sent. Starts with `_`: not a test.
 *
 * Usage: php tests/_p155_fake_ami_server.php <port-file> <log-file> <good-secret> [max-connections]
 * It writes its listening port to <port-file> once ready, serves up to
 * max-connections (default 3) connections, and exits after 20 idle seconds.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

$portFile = (string) ($argv[1] ?? '');
$logFile  = (string) ($argv[2] ?? '');
$secret   = (string) ($argv[3] ?? 'good-secret');
$max      = (int) ($argv[4] ?? 3);
if ($portFile === '' || $logFile === '') { exit(2); }

$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if (!$server) { exit(3); }
$name = stream_socket_get_name($server, false);
file_put_contents($portFile, (string) substr($name, strrpos($name, ':') + 1));
file_put_contents($logFile, '');

for ($served = 0; $served < $max; $served++) {
    $conn = @stream_socket_accept($server, 20);
    if (!$conn) { break; }
    stream_set_timeout($conn, 5);
    fwrite($conn, "Asterisk Call Manager/9.0.0\r\n");
    $block = [];
    while (($line = fgets($conn)) !== false) {
        $line = rtrim($line, "\r\n");
        if ($line !== '') { $block[] = $line; continue; }
        if (!$block) { continue; }
        $fields = [];
        foreach ($block as $l) {
            $p = strpos($l, ':');
            if ($p !== false) { $fields[trim(substr($l, 0, $p))] = trim(substr($l, $p + 1)); }
        }
        $action = $fields['Action'] ?? '(none)';
        file_put_contents($logFile, $action . "\n", FILE_APPEND);
        if ($action === 'Login') {
            $ok = ($fields['Secret'] ?? '') === $secret && ($fields['Username'] ?? '') !== '';
            fwrite($conn, $ok
                ? "Response: Success\r\nMessage: Authentication accepted\r\n\r\n"
                : "Response: Error\r\nMessage: Authentication failed\r\n\r\n");
            if (!$ok) { break; }
        } elseif ($action === 'Logoff') {
            fwrite($conn, "Response: Goodbye\r\nMessage: Thanks for all the fish.\r\n\r\n");
            break;
        } else {
            fwrite($conn, "Response: Error\r\nMessage: Unsupported in the fake\r\n\r\n");
        }
        $block = [];
    }
    fclose($conn);
}
fclose($server);
