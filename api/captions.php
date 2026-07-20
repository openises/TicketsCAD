<?php
/**
 * NewUI v4.0 API — Captions / i18n
 *
 * GET               — List all captions (all languages)
 * GET ?lang=X       — Get captions for a specific language
 * GET ?search=X     — Search captions by key or value
 * POST action=save  — Create or update a caption
 * POST action=delete — Delete a caption by id
 * POST action=import — Bulk import captions from JSON
 * POST action=export — Export all captions as JSON
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/rbac.php';
require_once __DIR__ . '/../inc/audit.php';

// Suppress display_errors to keep JSON clean
$prevDisplay = ini_get('display_errors');
ini_set('display_errors', '0');

$prefix = $GLOBALS['db_prefix'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];
$table  = "`{$prefix}captions_i18n`";

// ── GET — read operations ────────────────────────────────────
if ($method === 'GET') {

    $lang   = isset($_GET['lang'])   ? trim($_GET['lang'])   : '';
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';

    try {
        if ($search !== '') {
            // Search by key or value
            $like = '%' . $search . '%';
            $rows = db_fetch_all(
                "SELECT `id`, `caption_key`, `lang`, `value`, `category`, `created_at`, `updated_at`
                 FROM {$table}
                 WHERE `caption_key` LIKE ? OR `value` LIKE ?
                 ORDER BY `caption_key`, `lang`",
                [$like, $like]
            );
        } elseif ($lang !== '') {
            // Filter by language
            $rows = db_fetch_all(
                "SELECT `id`, `caption_key`, `lang`, `value`, `category`, `created_at`, `updated_at`
                 FROM {$table}
                 WHERE `lang` = ?
                 ORDER BY `caption_key`",
                [$lang]
            );
        } else {
            // All captions, all languages
            $rows = db_fetch_all(
                "SELECT `id`, `caption_key`, `lang`, `value`, `category`, `created_at`, `updated_at`
                 FROM {$table}
                 ORDER BY `caption_key`, `lang`"
            );
        }
    } catch (Exception $e) {
        // Table may not exist
        $rows = [];
    }

    // Also report available languages
    $langs = [];
    try {
        $langRows = db_fetch_all("SELECT DISTINCT `lang` FROM {$table} ORDER BY `lang`");
        foreach ($langRows as $lr) {
            $langs[] = $lr['lang'];
        }
    } catch (Exception $e) {
        $langs = ['en'];
    }

    json_response([
        'captions'  => $rows,
        'languages' => $langs,
        'total'     => count($rows)
    ]);
}

// ── POST — write operations ──────────────────────────────────
if ($method === 'POST') {

    // CSRF check
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $token = $input['csrf_token'] ?? '';
    if (!csrf_verify($token)) {
        json_error('Invalid CSRF token', 403);
    }

    // RBAC: require manage_config permission (or legacy admin level)
    if (!rbac_can('action.manage_config') && !is_admin()) {
        json_error('Admin access required', 403);
    }

    $action = $input['action'] ?? 'save';

    // ── Save (create or update) ──────────────────────────────
    if ($action === 'save') {
        $id       = isset($input['id']) && $input['id'] !== '' ? (int) $input['id'] : null;
        $key      = trim($input['caption_key'] ?? $input['key'] ?? '');
        $lang     = trim($input['lang'] ?? 'en');
        $value    = trim($input['value'] ?? '');
        $category = trim($input['category'] ?? 'general');

        if ($key === '') {
            json_error('Caption key is required');
        }
        if ($value === '') {
            json_error('Caption value is required');
        }

        // Sanitize lang
        $lang = preg_replace('/[^a-zA-Z0-9\-]/', '', strtolower(substr($lang, 0, 8)));
        if ($lang === '') $lang = 'en';

        try {
            if ($id) {
                // Update by id
                db_query(
                    "UPDATE {$table} SET `caption_key` = ?, `lang` = ?, `value` = ?, `category` = ?
                     WHERE `id` = ?",
                    [$key, $lang, $value, $category, $id]
                );
            } else {
                // Upsert by key+lang
                db_query(
                    "INSERT INTO {$table} (`caption_key`, `lang`, `value`, `category`)
                     VALUES (?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), `category` = VALUES(`category`)",
                    [$key, $lang, $value, $category]
                );
                $id = (int) db_insert_id();
                // db_insert_id returns 0 on update; fetch the actual id
                if (!$id) {
                    $row = db_fetch_one(
                        "SELECT `id` FROM {$table} WHERE `caption_key` = ? AND `lang` = ?",
                        [$key, $lang]
                    );
                    $id = $row ? (int) $row['id'] : 0;
                }
            }
            audit_log('config', 'update', 'caption', $id, "Saved caption '{$key}' ({$lang})");
            json_response(['saved' => true, 'id' => $id]);
        } catch (Exception $e) {
            json_error('Save failed: ' . $e->getMessage(), 500);
        }
    }

    // ── Delete ───────────────────────────────────────────────
    if ($action === 'delete') {
        $id = (int) ($input['id'] ?? 0);
        if (!$id) {
            json_error('Missing id');
        }
        try {
            db_query("DELETE FROM {$table} WHERE `id` = ?", [$id]);
            audit_log('config', 'delete', 'caption', $id, "Deleted caption #{$id}");
            json_response(['deleted' => true]);
        } catch (Exception $e) {
            json_error('Delete failed: ' . $e->getMessage(), 500);
        }
    }

    // ── Export ────────────────────────────────────────────────
    if ($action === 'export') {
        $exportLang = trim($input['lang'] ?? '');
        try {
            if ($exportLang !== '') {
                $rows = db_fetch_all(
                    "SELECT `caption_key`, `lang`, `value`, `category`
                     FROM {$table} WHERE `lang` = ? ORDER BY `caption_key`",
                    [$exportLang]
                );
            } else {
                $rows = db_fetch_all(
                    "SELECT `caption_key`, `lang`, `value`, `category`
                     FROM {$table} ORDER BY `lang`, `caption_key`"
                );
            }
            json_response([
                'export'  => $rows,
                'count'   => count($rows),
                'version' => NEWUI_VERSION
            ]);
        } catch (Exception $e) {
            json_error('Export failed: ' . $e->getMessage(), 500);
        }
    }

    // ── Import ───────────────────────────────────────────────
    if ($action === 'import') {
        $items = $input['captions'] ?? [];
        if (!is_array($items) || empty($items)) {
            json_error('No captions provided for import');
        }

        $imported = 0;
        $errors   = 0;
        foreach ($items as $item) {
            $key      = trim($item['caption_key'] ?? $item['key'] ?? '');
            $lang     = trim($item['lang'] ?? 'en');
            $value    = trim($item['value'] ?? '');
            $category = trim($item['category'] ?? 'general');

            if ($key === '' || $value === '') {
                $errors++;
                continue;
            }

            $lang = preg_replace('/[^a-zA-Z0-9\-]/', '', strtolower(substr($lang, 0, 8)));
            if ($lang === '') $lang = 'en';

            try {
                db_query(
                    "INSERT INTO {$table} (`caption_key`, `lang`, `value`, `category`)
                     VALUES (?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), `category` = VALUES(`category`)",
                    [$key, $lang, $value, $category]
                );
                $imported++;
            } catch (Exception $e) {
                $errors++;
            }
        }

        audit_log('config', 'import', 'captions', null, "Imported {$imported} captions ({$errors} errors)");
        json_response([
            'imported' => $imported,
            'errors'   => $errors,
            'total'    => count($items)
        ]);
    }

    // Unknown action
    json_error('Unknown action: ' . $action, 400);
}

// ── Unsupported method ───────────────────────────────────────
ini_set('display_errors', $prevDisplay);
json_error('Method not allowed', 405);
