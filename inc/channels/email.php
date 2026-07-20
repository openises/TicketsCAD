<?php
/**
 * Channel: Email (alias for SMTP)
 *
 * Provides an 'email' channel code as a user-friendly alias for the SMTP
 * transport. This allows routing rules to use 'email' as a destination
 * without requiring knowledge of the underlying SMTP implementation.
 *
 * Delegates all operations to the SMTP channel handler (smtp.php).
 * The SMTP channel must be loaded first — channels load alphabetically,
 * so email.php loads before smtp.php. We guard against this with a
 * deferred registration that checks if the SMTP functions exist.
 */

// Defer registration until all channels are loaded.
// The broker auto-loads channels alphabetically, so smtp.php loads after email.php.
// We register a wrapper that checks for the SMTP handler at call time.

broker_register('email', [
    'name'    => 'Email',
    'send'    => '_email_send',
    'receive' => '_email_receive',
    'status'  => '_email_status'
]);

/**
 * Send via SMTP channel.
 */
function _email_send(array $message) {
    if (function_exists('_smtp_send')) {
        return _smtp_send($message);
    }
    return ['success' => false, 'error' => 'SMTP channel not loaded'];
}

/**
 * Email does not support receiving (no IMAP/POP3 client).
 */
function _email_receive($limit = 50) {
    return [];
}

/**
 * Delegate status check to SMTP.
 */
function _email_status() {
    if (function_exists('_smtp_status')) {
        return _smtp_status();
    }
    return 'not_configured';
}
