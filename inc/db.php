<?php
/**
 * NewUI v4.0 - PDO Database Layer
 *
 * Clean PDO-based database abstraction. All queries use prepared statements.
 * No legacy mysql_* or mysqli compatibility needed — this is a fresh start.
 */

/**
 * Get the singleton PDO connection.
 *
 * @return PDO
 * @throws RuntimeException if connection fails
 */
function db(): PDO
{
    static $pdo = null;

    if ($pdo !== null) {
        return $pdo;
    }

    global $db_host, $db_user, $db_pass, $db_name;

    $dsn = "mysql:host={$db_host};dbname={$db_name};charset=utf8mb4";

    try {
        $pdo = new PDO($dsn, $db_user, $db_pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        // 2026-06-11 — Eric caught a timezone drift: PHP stamps
        // datetimes with PHP's local timezone but the DB's NOW() /
        // CURDATE() ran in UTC, so every
        //   WHERE problemend >= DATE_SUB(NOW(), INTERVAL N MINUTE)
        // came out 4-6 hours off and "Closed Today" picked the wrong
        // calendar day. Sync the DB session's time_zone to PHP's
        // offset on connect (e.g., '-04:00') so NOW(), CURDATE(), and
        // TIMESTAMPDIFF compare apples to apples with the values
        // PHP writes.
        try {
            $offset = (new DateTime('now'))->format('P'); // e.g., -04:00
            // Phase 41 (Sonar S2077): MySQL/MariaDB SET TIME_ZONE doesn't
            // support placeholders. Validate the format format() returns
            // — strict +HH:MM / -HH:MM — before interpolating so a future
            // PHP version that returns something unexpected can't smuggle
            // SQL through the string concatenation.
            if (!preg_match('/^[+-]\d{2}:\d{2}$/', $offset)) {
                throw new Exception('unexpected offset format');
            }
            $pdo->exec("SET time_zone = '{$offset}'"); // NOSONAR
        } catch (Exception $tzErr) {
            // MariaDB always supports literal offsets; if SET TIME_ZONE
            // fails for some reason, keep going — the original mismatch
            // bug is still better than no DB at all.
        }
    } catch (PDOException $e) {
        error_log("NewUI DB connection failed: " . $e->getMessage());
        throw new RuntimeException("Database connection failed");
    }

    return $pdo;
}

/**
 * Execute a query and return the PDOStatement.
 *
 * @param string $sql    SQL with ? or :named placeholders
 * @param array  $params Values to bind
 * @return PDOStatement
 */
function db_query(string $sql, array $params = []): PDOStatement
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

/**
 * Fetch all rows as associative arrays.
 */
function db_fetch_all(string $sql, array $params = []): array
{
    return db_query($sql, $params)->fetchAll();
}

/**
 * Fetch a single row as an associative array, or null.
 */
function db_fetch_one(string $sql, array $params = []): ?array
{
    $row = db_query($sql, $params)->fetch();
    return $row ?: null;
}

/**
 * Fetch a single scalar value (first column of first row).
 */
function db_fetch_value(string $sql, array $params = [])
{
    return db_query($sql, $params)->fetchColumn();
}

/**
 * Get the last auto-increment ID.
 */
function db_insert_id(): string
{
    return db()->lastInsertId();
}

/**
 * Get the table name with prefix. Sanitizes to prevent injection.
 *
 * @param string $table Base table name (e.g., 'ticket', 'user')
 * @return string Backtick-quoted prefixed table name
 */
function db_table(string $table): string
{
    global $db_prefix;
    $name = preg_replace('/[^a-zA-Z0-9_]/', '', ($db_prefix ?? '') . $table);
    return "`{$name}`";
}
