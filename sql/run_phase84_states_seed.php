<?php
/**
 * Phase 84-followup: seed the states_translator table.
 *
 * Symptom: on training the State dropdown on new-incident.php showed
 * only "—" because states_translator had 0 rows. Eric tried entering
 * "Cedar Rapids IA" and couldn't pick a state.
 *
 * Idempotent: INSERT IGNORE on (id, name, code). Safe to re-run.
 *
 * Usage:  php sql/run_phase84_states_seed.php
 */

require_once __DIR__ . '/../config.php';

$prefix = $GLOBALS['db_prefix'] ?? '';

echo "Phase 84 follow-up — states_translator seed\n";
echo "==========================================\n\n";

try {
    $before = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}states_translator`");
} catch (Exception $e) {
    echo "[ERR] states_translator table missing — run the base schema first\n";
    exit(1);
}
echo "Rows before: {$before}\n";

$rows = [
    [1,  'Alabama',              'AL'], [2,  'Alaska',         'AK'],
    [3,  'Arizona',              'AZ'], [4,  'Arkansas',       'AR'],
    [5,  'California',           'CA'], [6,  'Colorado',       'CO'],
    [7,  'Connecticut',          'CT'], [8,  'Delaware',       'DE'],
    [9,  'Florida',              'FL'], [10, 'Georgia',        'GA'],
    [11, 'Hawaii',               'HI'], [12, 'Idaho',          'ID'],
    [13, 'Illinois',             'IL'], [14, 'Indiana',        'IN'],
    [15, 'Iowa',                 'IA'], [16, 'Kansas',         'KS'],
    [17, 'Kentucky',             'KY'], [18, 'Louisiana',      'LA'],
    [19, 'Maine',                'ME'], [20, 'Maryland',       'MD'],
    [21, 'Massachusetts',        'MA'], [22, 'Michigan',       'MI'],
    [23, 'Minnesota',            'MN'], [24, 'Mississippi',    'MS'],
    [25, 'Missouri',             'MO'], [26, 'Montana',        'MT'],
    [27, 'Nebraska',             'NE'], [28, 'Nevada',         'NV'],
    [29, 'New Hampshire',        'NH'], [30, 'New Jersey',     'NJ'],
    [31, 'New Mexico',           'NM'], [32, 'New York',       'NY'],
    [33, 'North Carolina',       'NC'], [34, 'North Dakota',   'ND'],
    [35, 'Ohio',                 'OH'], [36, 'Oklahoma',       'OK'],
    [37, 'Oregon',               'OR'], [38, 'Pennsylvania',   'PA'],
    [39, 'Rhode Island',         'RI'], [40, 'South Carolina', 'SC'],
    [41, 'South Dakota',         'SD'], [42, 'Tennessee',      'TN'],
    [43, 'Texas',                'TX'], [44, 'Utah',           'UT'],
    [45, 'Vermont',              'VT'], [46, 'Virginia',       'VA'],
    [47, 'Washington',           'WA'], [48, 'West Virginia',  'WV'],
    [49, 'Wisconsin',            'WI'], [50, 'Wyoming',        'WY'],
    [51, 'England',              'UK'], [52, 'District of Columbia', 'DC'],
];

$inserted = 0;
foreach ($rows as $r) {
    try {
        db_query(
            "INSERT IGNORE INTO `{$prefix}states_translator` (id, name, code) VALUES (?, ?, ?)",
            [$r[0], $r[1], $r[2]]
        );
        $inserted++;
    } catch (Exception $e) {
        echo "[WARN] {$r[1]}: " . $e->getMessage() . "\n";
    }
}

$after = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}states_translator`");
echo "Rows after: {$after} (attempted {$inserted} inserts; existing rows preserved)\n";
echo "\nDone.\n";
