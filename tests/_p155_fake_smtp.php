<?php
/**
 * A tiny SMTP server for tests/test_notification_adapters.php - just enough of
 * RFC 5321 to let the real _smtp_relay() talk to something and to record
 * EXACTLY what it said, so the test can assert that a hostile recipient or body
 * cannot smuggle a command.
 *
 * Usage: php tests/_p155_fake_smtp.php <port-file> <transcript-file>
 *
 *   - listens on 127.0.0.1 on a free port and writes the port number to <port-file>
 *   - writes every line it RECEIVES to <transcript-file> as "C: <line>" and every
 *     reply it sends as "S: <line>"; a line "--- DATA END ---" marks the end of a
 *     DATA block the way the server understood it (a lone "." line)
 *   - RCPT TO an address containing the word "reject" is answered 550
 *   - serves connections one after another until it is killed
 *
 * It is a test double, not a mail server: no auth, no TLS, no persistence.
 */
if (PHP_SAPI !== 'cli') { exit('CLI only'); }
$portFile = $argv[1] ?? '';
$logFile  = $argv[2] ?? '';
if ($portFile === '' || $logFile === '') { fwrite(STDERR, "usage\n"); exit(2); }

$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if (!$server) { fwrite(STDERR, "listen failed: $errstr\n"); exit(1); }
$name = stream_socket_get_name($server, false);
file_put_contents($portFile, (string) substr(strrchr($name, ':'), 1));

function say($conn, string $line, string $log): void
{
    fwrite($conn, $line . "\r\n");
    file_put_contents($log, 'S: ' . $line . "\n", FILE_APPEND);
}

while (true) {
    $conn = @stream_socket_accept($server, 3600);
    if (!$conn) continue;
    stream_set_timeout($conn, 10);
    file_put_contents($logFile, "=== connection ===\n", FILE_APPEND);
    say($conn, '220 p155.test ESMTP ready', $logFile);
    $inData = false;
    while (($line = fgets($conn)) !== false) {
        $line = rtrim($line, "\r\n");
        file_put_contents($logFile, 'C: ' . $line . "\n", FILE_APPEND);
        if ($inData) {
            if ($line === '.') {
                $inData = false;
                file_put_contents($logFile, "--- DATA END ---\n", FILE_APPEND);
                say($conn, '250 2.0.0 queued', $logFile);
            }
            continue;
        }
        $cmd = strtoupper(substr($line, 0, 4));
        if ($cmd === 'EHLO' || $cmd === 'HELO') say($conn, '250 p155.test', $logFile);
        elseif ($cmd === 'MAIL') say($conn, '250 2.1.0 ok', $logFile);
        elseif ($cmd === 'RCPT') {
            if (stripos($line, 'reject') !== false) say($conn, '550 5.1.1 no such user', $logFile);
            else say($conn, '250 2.1.5 ok', $logFile);
        }
        elseif ($cmd === 'DATA') { $inData = true; say($conn, '354 go ahead', $logFile); }
        elseif ($cmd === 'QUIT') { say($conn, '221 bye', $logFile); break; }
        elseif ($cmd === 'RSET' || $cmd === 'NOOP') say($conn, '250 ok', $logFile);
        else say($conn, '502 5.5.2 command not recognized', $logFile);
    }
    fclose($conn);
}
