<?php
/**
 * Phase 99e talkgroups v2 (2026-06-28) — adds call_type column +
 * pre-seeds the registry with US-focused public-safety / EmComm /
 * statewide / regional talkgroups curated from BrandMeister.
 *
 * Idempotent. INSERT IGNORE on seeds + IF NOT EXISTS on column.
 *
 * Seed curation per Eric's criteria:
 *   - All 50 US statewide TGs (3101-3156)
 *   - Nationwide EmComm (9911 personal mayday, 3100 USA bridge,
 *     759 SKYWARN, 3165 SHARES, 3199 Hurricane Net, 4742 Red Cross)
 *   - TAC channels (310-319, public-service tactical)
 *   - US regional bridges (3169 Midwest, 3172 NE, 3173 MidAtl,
 *     3174 SE, 3175 TX-OK, 3176 SW, 3177 Mountain)
 *   - FEMA Region V — 31673 R5AUXCOMM (Eric's specific ask;
 *     covers MN, WI, IL, IN, MI, OH)
 *   - DCI bridges (3160, 3162)
 *
 * ~75 entries. Eric expected "about 15% of the channels to make
 * our list" — repeaterbook has ~800 TGs total; this is ~9% but
 * skews to the channels most useful to first responders /
 * emergency management / ARES / RACES / SHARES / Skywarn /
 * public safety per Eric's filter.
 *
 * Existing rows untouched (INSERT IGNORE on dmr_id unique key).
 *
 * Run:  php sql/run_99e_talkgroups_v2.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';

$prefix = $GLOBALS['db_prefix'] ?? '';

// ── Add call_type column (idempotent) ───────────────────────────
try {
    $hasCallType = (int) db_fetch_value(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = ? AND COLUMN_NAME = 'call_type'",
        [$prefix . 'talkgroups']
    );
    if (!$hasCallType) {
        db_query(
            "ALTER TABLE `{$prefix}talkgroups`
                ADD COLUMN `call_type` ENUM('group','private') NOT NULL DEFAULT 'group'
                AFTER `description`,
                ADD KEY `idx_call_type` (`call_type`)"
        );
        echo "✓ added call_type column\n";
    } else {
        echo "✓ call_type column already present\n";
    }
} catch (Throwable $e) {
    fwrite(STDERR, "ERROR adding call_type column: " . $e->getMessage() . "\n");
    exit(1);
}

// ── Seed data — [dmr_id, name, description, sort_order, call_type] ──
// sort_order convention:
//   10–19  : top-priority nationwide emergency (9911, 3100)
//   20–29  : SKYWARN / SHARES / EmComm specialty
//   30–39  : TAC channels (310–319)
//   40–49  : US regional bridges (3169–3177)
//   50–59  : FEMA region talkgroups
//   100+   : US statewide (3101 AL → 3156 WY), sort by name
//   200+   : DCI bridges + Public Service specialty

$seed = [
    // Nationwide emergency (top-priority)
    [9911, 'USA Emergency (EMCOM)',          'Personal MAYDAYS, EmComm alerting. Per BrandMeister: "for personal emergency alerting or in other words personal MAYDAYS." Use Emergency Call button or standard MAYDAY voice.', 10, 'group'],
    [3100, 'USA Bridge',                     'National USA bridging network — links state and local EmComm. Cross-network bridge to BrandMeister; runs on DCI/DMRX.', 11, 'group'],

    // EmComm specialty
    [759,  'SKYWARN',                        'NWS weather spotter network. Severe weather monitoring and reporting.', 20, 'group'],
    [3165, 'SHARES',                         'Shared Resources HF/VHF radio network — DHS-affiliated emergency comms.', 21, 'group'],
    [3199, 'Hurricane Net',                  'Active during tropical-storm/hurricane events. Public-service emergency coordination.', 22, 'group'],
    [4742, 'Red Cross',                      'American Red Cross disaster relief coordination.', 23, 'group'],

    // TAC channels (tactical, on-demand for public service)
    [310,  'TAC 310',                        'Tactical channel — public service events and emergency ops. Worldwide.', 30, 'group'],
    [311,  'TAC 311',                        'Tactical channel — public service events and emergency ops. Worldwide.', 31, 'group'],
    [312,  'TAC 312',                        'Tactical channel — public service events and emergency ops. Worldwide.', 32, 'group'],
    [313,  'TAC 313',                        'Tactical channel — public service events and emergency ops. North America.', 33, 'group'],
    [314,  'TAC 314',                        'Tactical channel — public service events and emergency ops. North America.', 34, 'group'],
    [315,  'TAC 315',                        'Tactical channel — public service events and emergency ops. North America.', 35, 'group'],
    [316,  'TAC 316',                        'Tactical channel — public service events and emergency ops. North America.', 36, 'group'],
    [317,  'TAC 317',                        'Tactical channel — public service events and emergency ops. North America.', 37, 'group'],
    [318,  'TAC 318',                        'Tactical channel — public service events and emergency ops. North America.', 38, 'group'],
    [319,  'TAC 319',                        'Tactical channel — public service events and emergency ops. North America.', 39, 'group'],

    // US regional bridges
    [3169, 'Midwest Regional',               'Regional bridge — IA, IL, IN, KS, MI, MN, MO, ND, NE, OH, SD, WI.', 40, 'group'],
    [3172, 'Northeast Regional',             'Regional bridge — CT, MA, ME, NH, NJ, NY, RI, VT.', 41, 'group'],
    [3173, 'MidAtlantic Regional',           'Regional bridge — DC, DE, MD, PA, VA, WV.', 42, 'group'],
    [3174, 'Southeast Regional',             'Regional bridge — AL, AR, FL, GA, KY, LA, MS, NC, SC, TN.', 43, 'group'],
    [3175, 'TX-OK Regional',                 'Regional bridge — OK, TX.', 44, 'group'],
    [3176, 'Southwest Regional',             'Regional bridge — AZ, CA, HI, NM, NV.', 45, 'group'],
    [3177, 'Mountain Regional',              'Regional bridge — AK, CO, ID, MT, OR, UT, WA, WY.', 46, 'group'],

    // FEMA region (Eric's specific ask: R5AUXCOMM)
    [31673, 'FEMA Region V AUXCOMM',         'FEMA Region V (R5AUXCOMM) — MN, WI, IL, IN, MI, OH. Federal Emergency Management auxiliary comms.', 50, 'group'],

    // DCI / Public Service specialty
    [3160, 'DCI 1',                          'Digital Calling Infrastructure — main talkgroup.', 200, 'group'],
    [3162, 'DCI 2',                          'Digital Calling Infrastructure — secondary talkgroup.', 201, 'group'],
    [3190, 'Public Service 1',               'Public service / EmComm events (regional — primarily Washington/PNW).', 202, 'group'],

    // US statewide (all 50 + DC) — alphabetical by state name
    [3101, 'Alabama',         'Alabama statewide.',         100, 'group'],
    [3102, 'Alaska',          'Alaska statewide.',          100, 'group'],
    [3104, 'Arizona',         'Arizona statewide.',         100, 'group'],
    [3105, 'Arkansas',        'Arkansas statewide.',        100, 'group'],
    [3106, 'California',      'California statewide.',      100, 'group'],
    [3108, 'Colorado',        'Colorado statewide.',        100, 'group'],
    [3109, 'Connecticut',     'Connecticut statewide.',     100, 'group'],
    [3110, 'Delaware',        'Delaware statewide.',        100, 'group'],
    [3111, 'Washington, D.C.','District of Columbia.',      100, 'group'],
    [3112, 'Florida',         'Florida statewide.',         100, 'group'],
    [3113, 'Georgia',         'Georgia statewide.',         100, 'group'],
    [3115, 'Hawaii',          'Hawaii statewide.',          100, 'group'],
    [3116, 'Idaho',           'Idaho statewide.',           100, 'group'],
    [3117, 'Illinois',        'Illinois statewide.',        100, 'group'],
    [3118, 'Indiana',         'Indiana statewide.',         100, 'group'],
    [3119, 'Iowa',            'Iowa statewide.',            100, 'group'],
    [3120, 'Kansas',          'Kansas statewide.',          100, 'group'],
    [3121, 'Kentucky',        'Kentucky statewide.',        100, 'group'],
    [3122, 'Louisiana',       'Louisiana statewide.',       100, 'group'],
    [3123, 'Maine',           'Maine statewide.',           100, 'group'],
    [3124, 'Maryland',        'Maryland statewide.',        100, 'group'],
    [3125, 'Massachusetts',   'Massachusetts statewide.',   100, 'group'],
    [3126, 'Michigan',        'Michigan statewide.',        100, 'group'],
    // 3127 MN State already seeded (v1) — INSERT IGNORE will skip
    [3127, 'MN State',        'Minnesota Statewide DMR talkgroup',   10, 'group'],
    [3128, 'Mississippi',     'Mississippi statewide.',     100, 'group'],
    [3129, 'Missouri',        'Missouri statewide.',        100, 'group'],
    [3130, 'Montana',         'Montana statewide.',         100, 'group'],
    [3131, 'Nebraska',        'Nebraska statewide.',        100, 'group'],
    [3132, 'Nevada',          'Nevada statewide.',          100, 'group'],
    [3133, 'New Hampshire',   'New Hampshire statewide.',   100, 'group'],
    [3134, 'New Jersey',      'New Jersey statewide.',      100, 'group'],
    [3135, 'New Mexico',      'New Mexico statewide.',      100, 'group'],
    [3136, 'New York',        'New York statewide.',        100, 'group'],
    [3137, 'North Carolina',  'North Carolina statewide.',  100, 'group'],
    [3138, 'North Dakota',    'North Dakota statewide.',    100, 'group'],
    [3139, 'Ohio',            'Ohio statewide.',            100, 'group'],
    [3140, 'Oklahoma',        'Oklahoma statewide.',        100, 'group'],
    [3141, 'Oregon',          'Oregon statewide.',          100, 'group'],
    [3142, 'Pennsylvania',    'Pennsylvania statewide.',    100, 'group'],
    [3144, 'Rhode Island',    'Rhode Island statewide.',    100, 'group'],
    [3145, 'South Carolina',  'South Carolina statewide.',  100, 'group'],
    [3146, 'South Dakota',    'South Dakota statewide.',    100, 'group'],
    [3147, 'Tennessee',       'Tennessee statewide.',       100, 'group'],
    [3148, 'Texas',           'Texas statewide.',           100, 'group'],
    [3149, 'Utah',            'Utah statewide.',            100, 'group'],
    [3150, 'Vermont',         'Vermont statewide.',         100, 'group'],
    [3151, 'Virginia',        'Virginia statewide.',        100, 'group'],
    [3153, 'Washington',      'Washington statewide.',      100, 'group'],
    [3154, 'West Virginia',   'West Virginia statewide.',   100, 'group'],
    [3155, 'Wisconsin',       'Wisconsin statewide.',       100, 'group'],
    [3156, 'Wyoming',         'Wyoming statewide.',         100, 'group'],

    // MN local (Eric named "MN Metro 2" 31272) — already seeded in v1
    [31272, 'MN Metro 2',     'Minnesota Twin Cities Metro 2 — local SAR', 20, 'group'],
];

// Per Eric (v3, 2026-06-28): seeded talkgroups land DISABLED so the
// admin opts in to the ones they need. See sql/run_99e_talkgroups_v3.php
// for the matching DEFAULT change + renumber + retro-disable.
foreach ($seed as [$dmrId, $name, $desc, $sort, $callType]) {
    try {
        db_query(
            "INSERT IGNORE INTO `{$prefix}talkgroups`
                (dmr_id, name, description, sort_order, enabled, call_type)
             VALUES (?, ?, ?, ?, 0, ?)",
            [$dmrId, $name, $desc, $sort, $callType]
        );
    } catch (Throwable $e) {
        fwrite(STDERR, "  WARN seeding tg {$dmrId}: " . $e->getMessage() . "\n");
    }
}
$count = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}talkgroups`");
echo "✓ seeded talkgroups (table now has {$count} row(s); existing rows untouched)\n";
echo "Done.\n";
