<?php
/**
 * Personnel sub-navigation bar.
 * Include on all personnel pages for consistent navigation.
 *
 * Usage: $personnel_active = 'roster'; include 'inc/personnel-nav.php';
 */
if (!isset($personnel_active)) $personnel_active = '';

$personnel_links = [
    ['href' => 'roster.php',     'icon' => 'person-lines-fill', 'label' => 'Roster',         'key' => 'roster'],
    ['href' => 'teams.php',      'icon' => 'people-fill',       'label' => 'Teams',          'key' => 'teams'],
    ['href' => 'scheduling.php', 'icon' => 'calendar-week',     'label' => 'Scheduling',     'key' => 'scheduling'],
    ['href' => 'vehicles.php',   'icon' => 'truck',             'label' => 'Vehicles',       'key' => 'vehicles'],
    ['href' => 'equipment.php',  'icon' => 'box-seam',          'label' => 'Equipment',      'key' => 'equipment'],
];

$personnel_config_links = [
    ['href' => 'settings.php#certifications',   'icon' => 'patch-check',  'label' => 'Certifications',  'key' => 'certifications'],
    ['href' => 'settings.php#ics-positions',    'icon' => 'shield-check', 'label' => 'ICS Positions',   'key' => 'ics-positions'],
    ['href' => 'settings.php#training',         'icon' => 'mortarboard',  'label' => 'Training',        'key' => 'training'],
    ['href' => 'settings.php#member-types',     'icon' => 'tags',         'label' => 'Member Types',    'key' => 'member-types'],
    ['href' => 'settings.php#member-statuses',  'icon' => 'toggle-on',   'label' => 'Member Statuses', 'key' => 'member-statuses'],
];
?>
<nav class="d-flex align-items-center gap-1 mb-3 pb-2 border-bottom flex-wrap" aria-label="Personnel navigation">
    <?php foreach ($personnel_links as $pl): ?>
        <a href="<?php echo $pl['href']; ?>"
           class="btn btn-sm <?php echo $personnel_active === $pl['key'] ? 'btn-primary' : 'btn-outline-secondary'; ?>">
            <i class="bi bi-<?php echo $pl['icon']; ?> me-1"></i><?php echo $pl['label']; ?>
        </a>
    <?php endforeach; ?>
    <span class="border-start mx-1" style="height:20px;opacity:0.3"></span>
    <?php foreach ($personnel_config_links as $pl): ?>
        <a href="<?php echo $pl['href']; ?>"
           class="btn btn-sm <?php echo $personnel_active === $pl['key'] ? 'btn-primary' : 'btn-outline-secondary'; ?>"
           title="<?php echo $pl['label']; ?>">
            <i class="bi bi-<?php echo $pl['icon']; ?> me-1"></i><span class="d-none d-lg-inline"><?php echo $pl['label']; ?></span>
        </a>
    <?php endforeach; ?>
</nav>
