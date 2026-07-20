<?php
/**
 * Broker message tables migration (QA automation, 2026-07-07).
 *
 * Fresh installs load sql/base_schema.sql, whose `chat_messages` and
 * `messages` tables are the legacy v3.44 shape (no channel/body/recipient
 * columns). The modern broker schema was only ever applied lazily —
 * _chat_ensure_schema() runs on the first chat SEND, and the manual tool
 * tools/fix_chat_tables.php did the rest — so on a fresh install every
 * broker/messages READ path (and the test suite) hit "Unknown column
 * 'body'" until someone happened to send a chat message.
 *
 * This wraps tools/fix_chat_tables.php as a real migration so every
 * install gets the modern schema at install/upgrade time. The tool is
 * idempotent: modern tables are detected and skipped; legacy tables with
 * data are snapshotted to *_legacy_YYYYMMDD before conversion.
 */

chdir(__DIR__ . '/..');
require_once __DIR__ . '/../tools/fix_chat_tables.php';
