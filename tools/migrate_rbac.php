<?php
require __DIR__ . '/../config.php';

echo "=== Migrating legacy user levels to RBAC roles ===\n";

$levelToRole = [
    0 => 1,  // Super → Super Admin
    1 => 2,  // Admin → Org Admin
    2 => 3,  // Operator → Dispatcher
    3 => 5,  // Guest → Read-Only
    4 => 6,  // Unit → Field Unit
    5 => 5,  // Stats → Read-Only
    6 => 5,  // Service → Read-Only
    7 => 5,  // Facility → Read-Only
    8 => 5,  // Member → Read-Only
];

$users = db_fetch_all("SELECT id, user, level FROM `user`");
$migrated = 0;
foreach ($users as $u) {
    $level = (int) $u['level'];
    $roleId = $levelToRole[$level] ?? 5;
    $existing = db_fetch_all(
        "SELECT id FROM `user_roles` WHERE user_id = ? AND role_id = ?",
        [(int) $u['id'], $roleId]
    );
    if (empty($existing)) {
        db_query("INSERT INTO `user_roles` (user_id, role_id) VALUES (?, ?)", [(int) $u['id'], $roleId]);
        $roleName = db_fetch_value("SELECT name FROM `roles` WHERE id = ?", [$roleId]);
        echo "  User '{$u['user']}' (level {$level}) → role '{$roleName}'\n";
        $migrated++;
    } else {
        echo "  User '{$u['user']}' already has role assigned\n";
    }
}
echo "\nMigrated $migrated of " . count($users) . " users.\n";
