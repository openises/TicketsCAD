-- ============================================================
-- NewUI v4.0 — Captions / i18n Schema
-- ============================================================
-- The legacy `captions` table (capt/repl) stores simple string
-- overrides with no language support.  This new table adds
-- multilingual support with a caption_key + lang unique pair.
-- The i18n helper checks this table first, then falls back to
-- the legacy captions table for backward compatibility.
-- ============================================================

CREATE TABLE IF NOT EXISTS `captions_i18n` (
    `id`          INT          AUTO_INCREMENT PRIMARY KEY,
    `caption_key` VARCHAR(128) NOT NULL,
    `lang`        VARCHAR(8)   NOT NULL DEFAULT 'en',
    `value`       TEXT         NOT NULL,
    `category`    VARCHAR(64)  NOT NULL DEFAULT 'general',
    `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_key_lang` (`caption_key`, `lang`),
    KEY `idx_lang` (`lang`),
    KEY `idx_category` (`category`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Seed: common UI labels (English defaults) ────────────────

INSERT IGNORE INTO `captions_i18n` (`caption_key`, `lang`, `value`, `category`) VALUES
-- Navigation
('nav.dashboard',       'en', 'Dashboard',        'nav'),
('nav.incidents',       'en', 'Incidents',         'nav'),
('nav.new_incident',    'en', 'New Incident',      'nav'),
('nav.roster',          'en', 'Roster',            'nav'),
('nav.teams',           'en', 'Teams',             'nav'),
('nav.facilities',      'en', 'Facilities',        'nav'),
('nav.equipment',       'en', 'Equipment',         'nav'),
('nav.vehicles',        'en', 'Vehicles',          'nav'),
('nav.scheduling',      'en', 'Scheduling',        'nav'),
('nav.search',          'en', 'Search',            'nav'),
('nav.reports',         'en', 'Reports',           'nav'),
('nav.settings',        'en', 'Settings',          'nav'),
('nav.logout',          'en', 'Logout',            'nav'),
-- Buttons
('btn.save',            'en', 'Save',              'button'),
('btn.cancel',          'en', 'Cancel',            'button'),
('btn.delete',          'en', 'Delete',            'button'),
('btn.edit',            'en', 'Edit',              'button'),
('btn.add',             'en', 'Add',               'button'),
('btn.close',           'en', 'Close',             'button'),
('btn.submit',          'en', 'Submit',            'button'),
-- Form labels
('form.address',        'en', 'Address',           'form'),
('form.city',           'en', 'City',              'form'),
('form.state',          'en', 'State',             'form'),
('form.zip',            'en', 'Zip Code',          'form'),
('form.phone',          'en', 'Phone',             'form'),
('form.name',           'en', 'Name',              'form'),
('form.description',    'en', 'Description',       'form'),
('form.notes',          'en', 'Notes',             'form'),
-- Status
('status.open',         'en', 'Open',              'status'),
('status.closed',       'en', 'Closed',            'status'),
('status.pending',      'en', 'Pending',           'status');
